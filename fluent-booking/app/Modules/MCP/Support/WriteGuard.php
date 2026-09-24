<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Safety rails for destructive MCP writes. Tool annotations are only hints;
 * the real protection is here.
 *
 * 1. Confirm tokens. A dry_run returns a preview and a token bound to the
 *    record's current state and to the exact parameters previewed. Executing
 *    needs the token back with the same parameters, so an agent can't act on a
 *    record that has since changed, or run a different change than the one
 *    that was approved.
 * 2. Idempotency keys. A retry with the same key returns the first result
 *    instead of booking twice. This must wrap the token check, see idempotent().
 *
 * Records live in wp_options rather than transients: INSERT IGNORE gives us an
 * atomic claim (get + delete transient is a race), and an object-cache flush
 * can't drop an idempotency record and let a duplicate write through.
 *
 * Contract: every ability annotated `destructive => true`, including Pro's,
 * must treat dry_run as non-mutating and pass each write through confirm().
 * create-booking and manage-booking are the reference. The mcp:permissions
 * gate dry-runs every destructive action and fails if any row count moves.
 *
 * @since 2.2.6
 */
class WriteGuard
{
    const CONFIRM_TTL = 300;   // 5 minutes to confirm a previewed action.

    const IDEM_TTL = 86400;    // remember an idempotency key for a day.

    // Kept short: option_name is indexed at 191 chars and every key ends in an md5.
    const STORE_PREFIX = 'fcal_mcp_g_';

    const CONFIRM_NEXT_STEP = 'Call this tool again with EXACTLY the same parameters plus confirm_token (and an idempotency_key) to execute. Changing any parameter invalidates the token.';

    /**
     * Build a dry-run preview with a confirm token.
     *
     * @param string $tool         Ability name.
     * @param string $entityKey    Target id, e.g. "booking:42".
     * @param string $fingerprint  The target's mutable state; a change rejects the token.
     * @param array  $preview      The preview payload.
     * @param string $paramsDigest From paramsDigest(); different parameters reject the token.
     *
     * @return array
     */
    public static function preview($tool, $entityKey, $fingerprint, array $preview, $paramsDigest = '')
    {
        // wp_generate_password() uses random_int; wp_generate_uuid4() can fall back to mt_rand.
        $token = substr(wp_hash($tool . '|' . $entityKey . '|' . $fingerprint . '|' . wp_generate_password(32, false, false)), 0, 32);

        self::write(self::confirmKey($tool, $entityKey), [
            'token'       => $token,
            'fingerprint' => (string) $fingerprint,
            'params'      => (string) $paramsDigest,
        ], self::CONFIRM_TTL);

        return [
            'dry_run'            => true,
            'preview'            => $preview,
            'confirm_token'      => $token,
            'expires_in_seconds' => self::CONFIRM_TTL,
        ];
    }

    /**
     * Check a confirm_token against the target's current state and parameters.
     *
     * @param string $tool
     * @param string $entityKey
     * @param string $currentFingerprint
     * @param string $token
     * @param string $paramsDigest Digest of the parameters being executed.
     *
     * @return true|\WP_Error
     */
    public static function confirm($tool, $entityKey, $currentFingerprint, $token, $paramsDigest = '')
    {
        if (empty($token)) {
            return MCPHelper::error(
                'confirmation_required',
                __('This action cannot be undone. Call again with dry_run:true to preview it, then pass the returned confirm_token to execute.', 'fluent-booking'),
                ['next_step' => 'set dry_run:true']
            );
        }

        $key    = self::confirmKey($tool, $entityKey);
        $stored = self::read($key);

        if (!is_array($stored) || empty($stored['token'])) {
            return MCPHelper::error(
                'confirmation_expired',
                __('Your confirmation has expired. Run a fresh dry_run to preview and get a new confirm_token.', 'fluent-booking'),
                ['next_step' => 'set dry_run:true']
            );
        }

        if (!hash_equals((string) $stored['token'], (string) $token)) {
            return MCPHelper::error(
                'confirmation_invalid',
                __('The confirm_token does not match. Run a fresh dry_run.', 'fluent-booking'),
                ['next_step' => 'set dry_run:true']
            );
        }

        if ((string) $stored['fingerprint'] !== (string) $currentFingerprint) {
            self::delete($key);
            return MCPHelper::error(
                'state_changed',
                __('The record changed since you previewed it. Run a fresh dry_run to see the current state before executing.', 'fluent-booking'),
                ['next_step' => 'set dry_run:true']
            );
        }

        // The token approves the previewed change, not just the record. Otherwise
        // a preview without a refund could be executed with refund_payment:true.
        if ((string) $stored['params'] !== (string) $paramsDigest) {
            self::delete($key);
            return MCPHelper::error(
                'parameters_changed',
                __('These are not the parameters you previewed. A confirm_token authorises the exact change it was issued for. Run a fresh dry_run with the parameters you actually want.', 'fluent-booking'),
                ['next_step' => 'set dry_run:true']
            );
        }

        // Single use, claimed atomically so only one of two concurrent requests
        // gets through. Keyed on the token rather than the booking, so a fresh
        // token for the same booking isn't blocked for the rest of the TTL.
        if (!self::claim(self::usedKey($token), 1, self::CONFIRM_TTL)) {
            return MCPHelper::error(
                'confirmation_expired',
                __('That confirm_token has already been used. Run a fresh dry_run.', 'fluent-booking'),
                ['next_step' => 'set dry_run:true']
            );
        }

        self::delete($key);

        return true;
    }

    /**
     * Run $fn at most once per idempotency key, scoped to user, tool and
     * entity. Without a key, $fn just runs.
     *
     * This must be the outermost wrapper, with confirm() inside $fn. The other
     * way round, confirm() consumes the token and the retry fails before it
     * ever reaches the cached result.
     *
     * Only a reference to the result is stored, never the response itself: the
     * response holds attendee PII, and wp_options ends up in exports and
     * staging clones. $replay rebuilds the response from the live record.
     *
     * @param string        $tool
     * @param string        $entityKey
     * @param string        $key
     * @param callable      $fn
     * @param string        $paramsDigest
     * @param callable|null $replay Rebuilds the response from the stored reference.
     *
     * @return mixed
     */
    public static function idempotent($tool, $entityKey, $key, callable $fn, $paramsDigest = '', $replay = null)
    {
        if (empty($key)) {
            return $fn();
        }

        $cacheKey = self::idemKey($tool, $entityKey, $key);
        $lockKey  = $cacheKey . '_lock';

        $cached = self::read($cacheKey);

        if (is_array($cached) && array_key_exists('ref', $cached)) {
            // Same key, different parameters: refuse, or a second reschedule
            // would report success without moving anything.
            if ((string) Arr::get($cached, 'params', '') !== (string) $paramsDigest) {
                return MCPHelper::error(
                    'idempotency_conflict',
                    __('This idempotency_key was already used for a different request. Use a fresh key for a new change; reuse a key only when retrying the identical call.', 'fluent-booking'),
                    ['next_step' => 'retry with a new idempotency_key']
                );
            }

            $ref = (array) $cached['ref'];

            if (is_callable($replay)) {
                $rebuilt = call_user_func($replay, $ref);

                if (is_array($rebuilt)) {
                    return self::flagReplay($rebuilt);
                }
            }

            return self::flagReplay(MCPHelper::success($ref));
        }

        // Claim before running, so two concurrent retries can't both execute.
        if (!self::claim($lockKey, 1, 120)) {
            return MCPHelper::error(
                'in_progress',
                __('Another call with this idempotency_key is still running. Wait for it to finish rather than retrying — retrying is what this key exists to make safe.', 'fluent-booking'),
                ['next_step' => 'poll with get-booking, or retry in a few seconds']
            );
        }

        try {
            $result = $fn();

            // Only successes are recorded, so a failure stays retryable. If the
            // record can't be written, say so rather than invite a duplicate retry.
            if (!is_wp_error($result)) {
                if (!self::write($cacheKey, ['ref' => self::resultRef($result), 'params' => (string) $paramsDigest], self::IDEM_TTL)
                    && is_array($result)) {
                    $result['idempotency_warning'] = __('This change was applied, but the idempotency record could not be stored. Do not retry with the same key — check the result before acting again.', 'fluent-booking');
                }
            }

            return $result;
        } finally {
            self::delete($lockKey);
        }
    }

    /**
     * Mark a response as a replay. Always in `meta`, so agents check one place.
     *
     * @param array $response
     *
     * @return array
     */
    private static function flagReplay($response)
    {
        if (!isset($response['meta']) || !is_array($response['meta'])) {
            $response['meta'] = [];
        }

        $response['meta']['idempotent_replay'] = true;

        return $response;
    }

    /**
     * Reduce a write's response to the identifiers a replay can rebuild from.
     *
     * @param mixed $result
     *
     * @return array
     */
    private static function resultRef($result)
    {
        if (!is_array($result)) {
            return [];
        }

        $ref = [];

        // Identity and outcome fields only, no attendee data.
        foreach (['id', 'action', 'created', 'message'] as $key) {
            if (isset($result['data'][$key]) && is_scalar($result['data'][$key])) {
                $ref[$key] = $result['data'][$key];
            }
        }

        $bookingId = Arr::get($result, 'data.booking.id');

        if ($bookingId) {
            $ref['booking_id'] = (int) $bookingId;
        }

        return $ref;
    }

    /**
     * A stable digest of the parameters that change what a call does. The
     * control parameters are left out, since they differ between a preview,
     * its execution and a retry.
     *
     * @param array $params
     * @param array $ignore Extra keys to exclude.
     *
     * @return string
     */
    public static function paramsDigest($params, $ignore = [])
    {
        $params = (array) $params;

        foreach (array_merge(['dry_run', 'confirm_token', 'idempotency_key'], (array) $ignore) as $key) {
            unset($params[$key]);
        }

        return md5((string) wp_json_encode(self::canonicalize($params)));
    }

    /**
     * Sort keys recursively so parameter order doesn't break the match.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? $value : (string) wp_json_encode($value);
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = self::canonicalize($item);
        }

        // Associative arrays are order-insensitive; lists are not.
        if (array_keys($out) !== range(0, count($out) - 1)) {
            ksort($out);
        }

        return $out;
    }

    /**
     * A booking's fingerprint. updated_at catches edits we don't track
     * separately, like a note or payment change.
     *
     * @param \FluentBooking\App\Models\Booking $booking
     *
     * @return string
     */
    public static function bookingFingerprint($booking)
    {
        return implode('|', [
            $booking->status,
            $booking->start_time,
            $booking->updated_at instanceof \DateTimeInterface
                ? $booking->updated_at->format('Y-m-d H:i:s')
                : $booking->updated_at,
        ]);
    }

    // Batch size x passes caps one run, to stay inside a 30s Action Scheduler tick.
    const PURGE_BATCH = 500;

    const PURGE_MAX_PASSES = 40;

    /**
     * Delete expired records. Runs daily, since nothing else prunes options.
     *
     * @return int rows removed
     */
    public static function purgeExpired()
    {
        global $wpdb;

        $removed = 0;
        $now     = time();
        $offset  = 0;

        for ($pass = 0; $pass < self::PURGE_MAX_PASSES; $pass++) {
            // Read values in the same query instead of get_option() per row.
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_id ASC LIMIT %d OFFSET %d",
                    $wpdb->esc_like(self::STORE_PREFIX) . '%',
                    $wpdb->esc_like(SlotLock::PREFIX) . '%',
                    self::PURGE_BATCH,
                    $offset
                ),
                ARRAY_A
            );

            if (!$rows) {
                break;
            }

            $expired = [];

            foreach ($rows as $row) {
                $record = maybe_unserialize($row['option_value']);

                // Keep live records. Rows without a valid envelope can never
                // be used, so they go too.
                if (is_array($record) && !empty($record['expires']) && $record['expires'] >= $now) {
                    continue;
                }

                $expired[] = $row['option_name'];
            }

            if ($expired) {
                $placeholders = implode(', ', array_fill(0, count($expired), '%s'));

                $removed += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", $expired) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- %s placeholders built by array_fill(); values passed to prepare()
                );

                foreach ($expired as $name) {
                    self::forgetCached($name);
                }
            }

            // Skip past the rows we kept.
            $offset += count($rows) - count($expired);

            if (count($rows) < self::PURGE_BATCH) {
                break;
            }
        }

        wp_cache_delete('alloptions', 'options');

        return $removed;
    }

    /**
     * Take a short exclusive window for one repeatable action.
     *
     * @param string $key caller-scoped identifier
     * @param int    $ttl seconds the window lasts
     *
     * @return bool true when the caller may proceed
     */
    public static function cooldown($key, $ttl)
    {
        return self::claim(self::STORE_PREFIX . 'cd_' . md5($key), 1, $ttl);
    }

    /**
     * Atomic claim: exactly one of N concurrent callers gets true.
     *
     * Uses INSERT IGNORE, as core's WP_Upgrader::create_lock() does. Not
     * add_option(): it runs INSERT ... ON DUPLICATE KEY UPDATE, so a second
     * caller updates the row and is also told it succeeded.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl
     *
     * @return bool true when this caller took the claim
     */
    private static function claim($key, $value, $ttl)
    {
        // Clear an expired claim before inserting, never after, so we can't
        // remove a lock someone else just took.
        $existing = self::readRaw($key);

        if (is_array($existing) && !empty($existing['expires']) && $existing['expires'] < time()) {
            self::delete($key);
        }

        return self::insertIgnore($key, ['value' => $value, 'expires' => time() + (int) $ttl]);
    }

    /**
     * @param string $key
     * @param array  $record
     * @return bool true when the row did not exist and this call created it
     */
    private static function insertIgnore($key, $record)
    {
        global $wpdb;

        $inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
                $key,
                maybe_serialize($record)
            )
        );

        // We bypassed the options API, so clear any stale `notoptions` entry.
        self::forgetCached($key);

        return (bool) $inserted;
    }

    /**
     * Drop an option from the object cache, including the negative cache.
     *
     * @param string $key
     */
    private static function forgetCached($key)
    {
        wp_cache_delete($key, 'options');

        $notoptions = wp_cache_get('notoptions', 'options');

        if (is_array($notoptions) && isset($notoptions[$key])) {
            unset($notoptions[$key]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }

    /**
     * The stored record and its envelope, without read()'s expiry check.
     *
     * @param string $key
     * @return array|null
     */
    private static function readRaw($key)
    {
        $record = get_option($key);

        return is_array($record) ? $record : null;
    }

    /**
     * @param string $key
     * @return array|null
     */
    private static function read($key)
    {
        $record = get_option($key);

        if (!is_array($record) || empty($record['expires'])) {
            return null;
        }

        if ($record['expires'] < time()) {
            delete_option($key);
            return null;
        }

        return isset($record['value']) && is_array($record['value']) ? $record['value'] : null;
    }

    /**
     * @param string $key
     * @param array  $value
     * @param int    $ttl
     */
    private static function write($key, $value, $ttl)
    {
        $record = ['value' => $value, 'expires' => time() + (int) $ttl];

        if (self::insertIgnore($key, $record)) {
            return true;
        }

        $updated = update_option($key, $record, false);

        self::forgetCached($key);

        // update_option() returns false for an unchanged value, so read it back.
        if ($updated) {
            return true;
        }

        $stored = self::readRaw($key);

        return is_array($stored) && isset($stored['value']) && $stored['value'] === $value;
    }

    /**
     * @param string $key
     */
    private static function delete($key)
    {
        delete_option($key);

        self::forgetCached($key);
    }

    private static function confirmKey($tool, $entityKey)
    {
        // Per user, so one user can't consume another user's token.
        return self::STORE_PREFIX . 'c' . get_current_user_id() . '_' . md5($tool . '|' . $entityKey);
    }

    private static function idemKey($tool, $entityKey, $key)
    {
        return self::STORE_PREFIX . 'i' . get_current_user_id() . '_' . md5($tool . '|' . $entityKey . '|' . $key);
    }

    // Tokens are unguessable and per user, so the token alone is a unique key.
    private static function usedKey($token)
    {
        return self::STORE_PREFIX . 'u_' . md5((string) $token);
    }
}
