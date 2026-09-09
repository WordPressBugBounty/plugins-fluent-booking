<?php

namespace FluentBooking\App\Modules\MCP\Support;

defined('ABSPATH') || exit;

/**
 * A short-lived exclusive hold on one event/slot/host combination.
 *
 * Availability is answered by a query and a booking is written by a separate
 * INSERT, with the whole slot engine in between. Nothing in the schema stops two
 * rows landing on the same host at the same minute — there is no unique index
 * over (event, host, start_time), and there cannot be a simple one, because
 * group events legitimately seat several bookings in one slot. So "check, then
 * write" is a race, and re-checking immediately before the write narrows it
 * without closing it.
 *
 * That race has always existed on the public booking page, where the two
 * requests have to arrive within milliseconds of each other. It matters more
 * here: an agent retries on its own initiative, several agents can hold
 * credentials for the same site, and the confirm round-trip deliberately puts a
 * human-length pause between the preview and the write.
 *
 * `add_option()` is the primitive, for the same reason WriteGuard uses it: it
 * bottoms out in one INSERT against the unique index on `option_name`, so
 * exactly one of N concurrent callers gets `true` back. That is a real mutual
 * exclusion, unlike a get-then-set on a transient, and it needs no new table and
 * no MySQL-specific advisory lock (`GET_LOCK` is unavailable on some managed
 * hosts and is per-connection, which connection pooling makes unreliable).
 *
 * Deliberately NOT a general-purpose lock: the TTL is seconds, a failure to
 * acquire is reported to the caller rather than waited on, and an expired lock
 * is stolen rather than honoured. A booking that cannot be written in fifteen
 * seconds has a bigger problem than contention.
 *
 * WHAT THIS DOES NOT DO, stated plainly so the next reader does not assume more
 * than it delivers: acquireInterval() claims every bucket a booking touches, so
 * partial overlaps on one host collide across event types. But only MCP writes
 * take these locks. The public booking page and the admin UI take none, so a
 * booking made there races exactly as it always has, and availability
 * re-checking remains the only defence against it.
 *
 * @since 2.3.0
 */
class SlotLock
{
    /**
     * Long enough for a slot query plus an insert and its hooks, short enough
     * that a fatal mid-write frees the slot before anyone notices.
     */
    const TTL = 15;

    const PREFIX = 'fcal_mcp_slot_';

    /**
     * Lock granularity, in seconds. The finest slot interval the plugin offers,
     * so a booking aligned to any configurable duration claims whole buckets.
     */
    const BUCKET = 900;

    /**
     * A day of buckets. A booking cannot legitimately need more, and a bad end
     * time must not turn into an unbounded row-insert loop.
     */
    const MAX_BUCKETS = 96;

    /**
     * Take the lock for one slot, or return false when someone else holds it.
     *
     * @param int      $eventId
     * @param string   $startTimeUtc 'Y-m-d H:i:s'
     * @param int|null $hostId       null on single-host events
     *
     * @return string|false the lock key to pass to release(), or false
     */
    public static function acquire($eventId, $startTimeUtc, $hostId = null)
    {
        global $wpdb;

        $key   = self::key($eventId, $startTimeUtc, $hostId);
        $owner = wp_generate_password(20, false, false);

        // Read the stored bytes, not get_option()'s unserialized (and possibly
        // cached) copy: the steal below deletes by exact value.
        $existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s",
                $key
            )
        );

        $record = ($existing === null) ? null : maybe_unserialize($existing);

        // Steal an expired lock: a request that died mid-write must not hold a
        // slot closed until the daily cleanup runs. Conditional on the value
        // just read, so of two requests racing the same expired lock the slower
        // one cannot delete the winner's fresh row and then insert its own.
        if (is_array($record) && !empty($record['expires']) && $record['expires'] < time()) {
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s",
                    $key,
                    $existing
                )
            );

            wp_cache_delete($key, 'options');
        }

        // INSERT IGNORE, the primitive core uses for its own locks
        // (WP_Upgrader::create_lock). Deliberately not add_option(): that issues
        // ON DUPLICATE KEY UPDATE and reports success to a caller whose row
        // already existed, which is no mutual exclusion at all.
        $inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
                $key,
                maybe_serialize(['expires' => time() + self::TTL, 'owner' => $owner])
            )
        );

        // The row went in behind the options cache's back.
        wp_cache_delete($key, 'options');

        $notoptions = wp_cache_get('notoptions', 'options');

        if (is_array($notoptions) && isset($notoptions[$key])) {
            unset($notoptions[$key]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }

        if (!$inserted) {
            return false;
        }

        // The handle carries the owner, so release() can prove it holds this
        // lock rather than a successor's.
        return $key . '|' . $owner;
    }

    /**
     * Claim one instant for every host a booking would occupy, all or nothing.
     *
     * A collective event books each of its hosts, and two single-host event
     * types can share an owner, so the constrained resource is a SET of people
     * rather than one. Partial claims are released before returning, so a
     * caller never holds half a slot.
     *
     * @param int    $eventId
     * @param string $startTimeUtc
     * @param array  $hostIds
     *
     * @return array|false handles to pass to releaseAll(), or false
     */
    public static function acquireAll($eventId, $startTimeUtc, $hostIds)
    {
        $handles = [];

        foreach (array_unique(array_map('intval', (array) $hostIds)) as $hostId) {
            $handle = self::acquire($eventId, $startTimeUtc, $hostId);

            if (!$handle) {
                self::releaseAll($handles);

                return false;
            }

            $handles[] = $handle;
        }

        return $handles;
    }

    /**
     * Claim every bucket a booking would occupy, for every host it would
     * occupy, all or nothing.
     *
     * Keying on the start instant alone let two bookings that overlap without
     * sharing a start — 10:00 for thirty minutes and 10:15 for fifteen, through
     * different event types — take independent keys and interleave. Overlapping
     * intervals always share an instant, so claiming every bucket an interval
     * touches makes them collide; intervals that merely abut do not, so a
     * booking ending at 10:30 still leaves 10:30 free.
     *
     * @param int    $eventId
     * @param string $startTimeUtc 'Y-m-d H:i:s'
     * @param string $endTimeUtc   'Y-m-d H:i:s'
     * @param array  $hostIds
     *
     * @return array|false handles to pass to releaseAll(), or false
     */
    public static function acquireInterval($eventId, $startTimeUtc, $endTimeUtc, $hostIds)
    {
        $buckets = self::buckets($startTimeUtc, $endTimeUtc);

        if (!$buckets) {
            return false;
        }

        $handles = [];

        foreach (array_unique(array_map('intval', (array) $hostIds)) as $hostId) {
            foreach ($buckets as $bucket) {
                $handle = self::acquire($eventId, $bucket, $hostId);

                if (!$handle) {
                    self::releaseAll($handles);

                    return false;
                }

                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * The bucket starts an interval touches, half open so a booking ending on a
     * boundary does not claim the bucket beginning there.
     *
     * @return array of 'Y-m-d H:i:s'
     */
    private static function buckets($startTimeUtc, $endTimeUtc)
    {
        $start = strtotime($startTimeUtc . ' UTC');
        $end   = strtotime($endTimeUtc . ' UTC');

        if (!$start) {
            return [];
        }

        if (!$end || $end <= $start) {
            $end = $start + 1;
        }

        $buckets = [];

        for ($t = $start - ($start % self::BUCKET); $t < $end; $t += self::BUCKET) {
            $buckets[] = gmdate('Y-m-d H:i:s', $t); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

            if (count($buckets) >= self::MAX_BUCKETS) {
                break;
            }
        }

        return $buckets;
    }

    /**
     * @param array|string|false $handles
     *
     * @return bool whether every lease is still held
     */
    public static function renewAll($handles)
    {
        foreach ((array) $handles as $handle) {
            if (!self::renew($handle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array|string|false $handles
     */
    public static function releaseAll($handles)
    {
        foreach ((array) $handles as $handle) {
            self::release($handle);
        }
    }

    /**
     * Re-assert a lease this caller still owns, pushing its expiry out.
     *
     * The lease is taken before isSpotAvailable(), which fans out through
     * `fluent_booking/remote_booked_events` to a live FreeBusy call per
     * connected calendar per host. On a team event with a cold cache that can
     * outrun TTL before a single row is written — and acquire() steals an
     * expired lease unconditionally, so the race this class exists to close
     * reopens exactly when the check is slow.
     *
     * Conditional on the exact stored bytes, so a caller whose lease was
     * already stolen gets false rather than stamping over the new owner.
     *
     * @param string|false $handle the value returned by acquire()
     *
     * @return bool whether the caller still holds the lock
     */
    public static function renew($handle)
    {
        global $wpdb;

        if (!$handle || strpos($handle, '|') === false) {
            return false;
        }

        list($key, $owner) = explode('|', $handle, 2);

        $existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s",
                $key
            )
        );

        if ($existing === null) {
            return false;
        }

        $record = maybe_unserialize($existing);

        if (!is_array($record) || !isset($record['owner']) || !hash_equals((string) $record['owner'], $owner)) {
            return false;
        }

        $renewed = maybe_serialize(['expires' => time() + self::TTL, 'owner' => $owner]);

        $updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
                $renewed,
                $key,
                $existing
            )
        );

        wp_cache_delete($key, 'options');

        // MySQL reports CHANGED rows, not matched ones, so renewing inside the
        // same second as the last write is a no-op update and reports zero.
        // The row is still ours and still current, which is what was asked.
        return $updated ? true : ($renewed === $existing);
    }

    /**
     * Release a lock this caller actually holds.
     *
     * The owner check is the point. A lease can expire while a slow booking hook
     * is still running; another request then legitimately takes the slot, and an
     * ownerless `delete_option()` from the first request would free the second
     * one's lock while it was still working.
     *
     * The check and the delete are two statements, so the delete carries the
     * proof with it and matches the exact value checked. A successor's row holds
     * a different owner token, so a lapsed owner's delete matches nothing.
     *
     * @param string|false $handle the value returned by acquire()
     */
    public static function release($handle)
    {
        global $wpdb;

        if (!$handle || strpos($handle, '|') === false) {
            return;
        }

        list($key, $owner) = explode('|', $handle, 2);

        $existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s",
                $key
            )
        );

        if ($existing === null) {
            return;
        }

        $record = maybe_unserialize($existing);

        if (!is_array($record) || !isset($record['owner']) || !hash_equals((string) $record['owner'], $owner)) {
            return;
        }

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s",
                $key,
                $existing
            )
        );

        wp_cache_delete($key, 'options');
    }

    /**
     * Host is part of the key: on a team event two hosts genuinely can be booked
     * for the same minute, and locking the slot across all of them would turn a
     * correctness guard into a throughput problem.
     *
     * @return string
     */
    private static function key($eventId, $startTimeUtc, $hostId)
    {
        // Keyed on the HOST once one is known, not the event: the constrained
        // resource is the person, and keying on the event let two requests book
        // the same host at one instant through different event types.
        //
        // Round robin has no host until isSpotAvailable() settles one, so it
        // falls back to the event and the caller takes a second, host-keyed
        // lock afterwards.
        $scope = $hostId ? 'h' . (int) $hostId : 'e' . (int) $eventId;

        return self::PREFIX . md5($scope . '|' . $startTimeUtc);
    }
}
