<?php

namespace FluentBooking\App\Modules\MCP\Support;

defined('ABSPATH') || exit;

/**
 * A short-lived exclusive hold on one slot for one host.
 *
 * Checking availability and inserting the booking is a race, and the schema
 * can't close it: group events seat several bookings in one slot, so there is
 * no unique index on (event, host, start_time). MCP makes the race likelier
 * because agents retry and the confirm round-trip adds a human-length pause.
 *
 * The lock is an INSERT IGNORE on the unique `option_name` index, so exactly
 * one concurrent caller wins. GET_LOCK is avoided: some managed hosts disable
 * it, and it is per-connection, which connection pooling breaks.
 *
 * Not a general-purpose lock: the TTL is seconds, a failed acquire returns
 * instead of waiting, and an expired lock is stolen.
 *
 * Only MCP writes take these locks. Bookings from the public page and admin UI
 * don't, so against those, availability re-checking is still the only guard.
 *
 * @since 2.3.0
 */
class SlotLock
{
    // Covers a slot query plus the insert and its hooks; short enough that a
    // fatal mid-write frees the slot quickly.
    const TTL = 15;

    const PREFIX = 'fcal_mcp_slot_';

    // Lock granularity in seconds: the finest slot interval the plugin offers.
    const BUCKET = 900;

    // A day of buckets, so a bad end time can't become an unbounded insert loop.
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

        // Steal an expired lock left by a request that died mid-write. Deleting
        // by the value just read stops a slower racer from deleting the
        // winner's fresh row.
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

        // Same primitive as WP_Upgrader::create_lock. Not add_option(): its ON
        // DUPLICATE KEY UPDATE reports success even when the row already existed.
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

        // The owner in the handle lets release() prove it holds this lock and
        // not a successor's.
        return $key . '|' . $owner;
    }

    /**
     * Claim one instant for every host a booking would occupy, all or nothing.
     *
     * A collective event books each of its hosts. Partial claims are released
     * before returning false.
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
     * Overlapping bookings with different starts (10:00 for 30 min, 10:15 for
     * 15) always share a bucket, so they collide. Abutting ones don't.
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
     * Bucket starts an interval touches. Half open, so a booking ending on a
     * boundary doesn't claim the next bucket.
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
     * isSpotAvailable() can make a live FreeBusy call per calendar per host,
     * which on a team event can outlast TTL, and acquire() steals expired
     * leases. Renewing before the write keeps the lock held.
     *
     * Matches the exact stored bytes, so a caller whose lease was stolen gets
     * false instead of overwriting the new owner.
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

        // MySQL counts changed rows, not matched ones, so a renew within the
        // same second reports zero even though the row is still ours.
        return $updated ? true : ($renewed === $existing);
    }

    /**
     * Release a lock this caller actually holds.
     *
     * A lease can expire during a slow booking hook and be taken by another
     * request; a plain delete_option() would then free the successor's lock.
     * The delete matches the exact value checked, so a lapsed owner's delete
     * matches nothing.
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
     * Keyed on the host, since the person is the constrained resource: it blocks
     * one host double-booked via two event types, while two team hosts can
     * still take the same minute. Round robin has no host until
     * isSpotAvailable() picks one, so it keys on the event and the caller takes
     * a host-keyed lock afterwards.
     *
     * @return string
     */
    private static function key($eventId, $startTimeUtc, $hostId)
    {
        $scope = $hostId ? 'h' . (int) $hostId : 'e' . (int) $eventId;

        return self::PREFIX . md5($scope . '|' . $startTimeUtc);
    }
}
