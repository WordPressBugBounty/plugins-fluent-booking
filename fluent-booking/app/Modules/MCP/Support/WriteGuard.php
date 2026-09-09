<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Safety rails for mutating MCP tools. Annotations are UX hints, not safety —
 * this is where real protection lives for the writes that touch someone's
 * calendar (create, reschedule, cancel).
 *
 * Two mechanisms:
 *
 *  1. Dry-run + confirmation token. A destructive action called with
 *     dry_run:true computes the effect, binds it to BOTH the target's current
 *     state (a fingerprint) AND the exact parameters that were previewed (a
 *     parameter digest), stashes a short-lived record, and returns a preview.
 *     To execute, the caller passes that confirm_token back with the SAME
 *     parameters. If the record changed in the meantime the fingerprint no
 *     longer matches; if the caller changed what it is asking for, the digest
 *     no longer matches. Either way we force a fresh preview — so an agent can
 *     neither act on a booking somebody else already moved, nor execute a
 *     different change from the one a human approved.
 *
 *  2. Idempotency keys. The caller passes an idempotency_key; the first
 *     execution for that key is recorded, and a retry with the same key returns
 *     the first result instead of booking or emailing twice. This is the guard
 *     against an agent re-issuing a create after a timeout, so it MUST wrap the
 *     confirm-token check rather than sit inside it — see idempotent().
 *
 * Storage is a dedicated options-backed store rather than transients. Two
 * reasons, both of which the transient API cannot give us:
 *
 *  - Atomic claim. `INSERT IGNORE` against the unique index on `option_name`
 *    lets exactly one of N concurrent requests claim a key — the primitive core
 *    uses for its own locks. `get_transient()` followed by `delete_transient()`
 *    is a read-then-write race: two agents holding the same token both read it
 *    before either deletes, and both execute. (`add_option()` is not a
 *    substitute; see claim().)
 *  - Durability. An object-cache flush drops transients. Losing a confirm token
 *    degrades safely (a fresh dry-run is required); losing an idempotency
 *    record does not — it degrades into the duplicate write the key existed to
 *    prevent.
 *
 * CONTRACT (enforced by scripts/check-mcp-budget.php and
 * scripts/check-mcp-permissions.php): every ability whose annotations include
 * `destructive => true` MUST treat `dry_run` as non-mutating for EVERY action it
 * exposes, and MUST route each mutating action through confirm() before
 * mutating. `create-booking` and `manage-booking` are the reference
 * implementations. When adding a new destructive ability — here or in Pro, which
 * registers under the same namespace via fluent_booking/mcp_loaded — follow this
 * contract; the permission-matrix gate calls every action of every destructive
 * tool with dry_run:true and fails the build if any row count moves.
 *
 * @since 2.2.6
 */
class WriteGuard
{
    const CONFIRM_TTL = 300;   // 5 minutes to confirm a previewed action.

    const IDEM_TTL = 86400;    // remember an idempotency key for a day.

    /**
     * Option-name prefix for the record store. Kept short: option_name is
     * indexed at 191 characters and every key here ends in an md5.
     */
    const STORE_PREFIX = 'fcal_mcp_g_';

    const CONFIRM_NEXT_STEP = 'Call this tool again with EXACTLY the same parameters plus confirm_token (and an idempotency_key) to execute. Changing any parameter invalidates the token.';

    /**
     * Build a dry-run preview response with a confirmation token bound to both
     * the target's current state and the parameters being previewed.
     *
     * @param string $tool        Ability name (namespacing the token).
     * @param string $entityKey   Stable id of the target, e.g. "booking:42".
     * @param string $fingerprint A string capturing the mutable state we care
     *                            about (e.g. "scheduled|2026-09-01 14:00:00").
     *                            If this differs at execute time, the token is
     *                            rejected.
     * @param array  $preview     The human/agent-facing preview payload.
     * @param string $paramsDigest Digest of the parameters this preview
     *                            describes, from paramsDigest(). If the caller
     *                            executes with different parameters, the token
     *                            is rejected.
     *
     * @return array
     */
    public static function preview($tool, $entityKey, $fingerprint, array $preview, $paramsDigest = '')
    {
        // wp_generate_password draws from wp_rand, which prefers random_int;
        // wp_generate_uuid4 falls back to mt_rand. For a token that gates a
        // write, take the stronger source.
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
     * Validate a confirm_token against the target's current fingerprint and the
     * parameters it was minted for.
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

        // The token authorises the change that was PREVIEWED, not merely the
        // record it was previewed against. Without this an agent could preview a
        // cancellation with no refund, then execute the same cancellation with
        // refund_payment:true on the strength of the operator's approval of the
        // first one.
        if ((string) $stored['params'] !== (string) $paramsDigest) {
            self::delete($key);
            return MCPHelper::error(
                'parameters_changed',
                __('These are not the parameters you previewed. A confirm_token authorises the exact change it was issued for. Run a fresh dry_run with the parameters you actually want.', 'fluent-booking'),
                ['next_step' => 'set dry_run:true']
            );
        }

        // One-shot, and atomically so: two concurrent requests holding the same
        // token both reach this line, and claim() lets exactly one through.
        //
        // Keyed on the TOKEN, not on the entity. Keying it on the entity would
        // make the marker outlive the token it describes and block the next
        // legitimately-minted token for the rest of the TTL — so an agent that
        // previewed and cancelled one booking could not preview and reschedule
        // the same booking for another five minutes, and would be told its fresh
        // token was "already used".
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
     * Run $fn at most once per idempotency key (per user + tool + entity). A
     * repeat call with the same key on the SAME entity returns the first
     * result. If no key is supplied, $fn runs normally (no dedupe) — keys are
     * recommended but not forced.
     *
     * ORDERING MATTERS. This must be the OUTERMOST wrapper on a destructive
     * write, with the confirm() check inside $fn. The reverse — confirm() first,
     * idempotency inside — cannot work: confirm() consumes the token, so the
     * retry this method exists to absorb is rejected as `confirmation_expired`
     * before the cached result is ever consulted, and the agent's recovery path
     * is a fresh dry_run and a second booking.
     *
     * The key is entity-scoped so reusing one idempotency_key across different
     * records (e.g. "cancel-1" for two bookings) can't replay the first
     * booking's result and silently skip the second mutation.
     *
     * WHAT IS STORED is a reference, never the response. The response carries
     * BookingProjector::full() — unmasked email, phone, country, internal note
     * and every answer the attendee gave — and wp_options is the table most
     * likely to end up in a support export or a staging clone, where no
     * exporter or eraser keyed on the booking tables would ever find it. The
     * replay callback rebuilds the response from the live record instead.
     *
     * @param string        $tool
     * @param string        $entityKey
     * @param string        $key
     * @param callable      $fn
     * @param string        $paramsDigest
     * @param callable|null $replay Rebuilds the response from the stored
     *                              reference. Without one a replay returns the
     *                              reference itself.
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
            // A key identifies one attempt at one change, not a licence to skip
            // any later change. Reusing a key with DIFFERENT parameters — say a
            // second reschedule of the same booking to a new time — would
            // otherwise return the first call's success and quietly perform no
            // move at all, which is the worst of both worlds: the agent is told
            // it worked and nothing happened.
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

        // Claim the key before running, not after. get-then-set would let two
        // concurrent retries of the same request both miss and both execute,
        // which is the failure the key exists to prevent.
        if (!self::claim($lockKey, 1, 120)) {
            return MCPHelper::error(
                'in_progress',
                __('Another call with this idempotency_key is still running. Wait for it to finish rather than retrying — retrying is what this key exists to make safe.', 'fluent-booking'),
                ['next_step' => 'poll with get-booking, or retry in a few seconds']
            );
        }

        // Everything from here to the release is inside try/finally, recording
        // the result included: a mutation that succeeded and a record that was
        // never written is exactly the divergence the key exists to prevent, so
        // a failure to persist has to be reported rather than swallowed.
        try {
            $result = $fn();

            if (!is_wp_error($result)) {
                // Only successful results are recorded — a failure should stay
                // retryable with the same key.
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
     * Mark a response as a replay, in `meta` and nowhere else.
     *
     * The two return paths above used to place it differently — the rebuilt one
     * merged into the envelope, the reference one into `data` — so an agent
     * checking one place missed the other and re-issued a write it had already
     * made, which is the failure the key exists to prevent.
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

        // A whitelist of identity and outcome fields, all scalar and none of
        // them attendee data. Anything richer is rebuilt by the replay
        // callback from the live record.
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
     * A stable digest of the parameters that actually change what a call does.
     *
     * The three control parameters are excluded by definition: `dry_run` differs
     * between the preview and the execution, `confirm_token` is absent from the
     * preview, and `idempotency_key` legitimately varies between a call and its
     * retry. Everything else is binding.
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
     * Recursively sort keys so an agent that emits the same parameters in a
     * different order still matches its own preview.
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
     * Fingerprint for a booking: everything a caller could act on stale.
     * Deliberately includes updated_at so an edit we do not otherwise model
     * (a note change, a payment transition) still invalidates a pending token.
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

    /**
     * How many rows one SELECT of the purge reads, and how many such passes it
     * makes before giving up for the day. The product is the ceiling on a
     * single run: enough for a busy site, bounded enough that the daily task
     * cannot outrun a 30-second Action Scheduler tick and be killed mid-sweep.
     */
    const PURGE_BATCH = 500;

    const PURGE_MAX_PASSES = 40;

    /**
     * Drop every expired record. Wired to the daily scheduler — the store is
     * options-backed, so unlike transients nothing prunes it for us.
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
            // option_value comes back with the name. Reading it here rather
            // than calling get_option() per row turns three queries a row into
            // one query a batch.
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

                // Delete only rows whose stored expiry is genuinely in the
                // past, and re-check the value we just read rather than
                // trusting the name alone: a preview that renewed the record
                // between the SELECT and the DELETE would otherwise have its
                // fresh token swept away.
                if (is_array($record) && !empty($record['expires']) && $record['expires'] >= $now) {
                    continue;
                }

                // A row with no usable envelope is a leftover from an older
                // format or a partial write; it can never be honoured, so it
                // goes too.
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

            // Rows that survived stay in the table, so the next batch has to
            // start past them rather than re-reading the same live records.
            $offset += count($rows) - count($expired);

            if (count($rows) < self::PURGE_BATCH) {
                break;
            }
        }

        wp_cache_delete('alloptions', 'options');

        return $removed;
    }

    /**
     * Atomic claim: exactly one of N concurrent callers gets true.
     *
     * `INSERT IGNORE` against the unique index on `option_name`, which is the
     * primitive WordPress core itself uses for locking
     * (`WP_Upgrader::create_lock()`). Notably NOT `add_option()`: that looks
     * atomic and is not. Core checks existence first and then issues
     *
     *     INSERT ... ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)
     *
     * so a second caller whose row already exists performs an UPDATE, changes
     * the value (our expiry differs), gets a non-zero affected-row count, and is
     * told it took the claim. Two callers, two `true`s, no mutual exclusion —
     * and for a token consumption or a refund that is the whole ballgame.
     * `INSERT IGNORE` returns 0 rows when the key exists, which is the answer we
     * actually need.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl
     *
     * @return bool true when this caller took the claim
     */
    /**
     * Take a short exclusive window for one repeatable action.
     *
     * @param string $key    caller-scoped identifier
     * @param int    $ttl    seconds the window lasts
     *
     * @return bool true when the caller may proceed
     */
    public static function cooldown($key, $ttl)
    {
        return self::claim(self::STORE_PREFIX . 'cd_' . md5($key), 1, $ttl);
    }

    private static function claim($key, $value, $ttl)
    {
        // A stale claim must not block forever: clear an expired one, then try.
        // Deliberately before the insert and never after — stealing a claim we
        // did not place is how one caller frees another caller's live lock.
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

        // The row went in behind the options cache's back, so a `notoptions`
        // entry saying it does not exist has to go.
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
     * The stored record with its envelope, without the expiry check read()
     * applies. Used where the expiry itself is the thing being inspected.
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

        // update_option() returns false when the stored value is already
        // identical, which is a success for our purposes — so confirm by
        // reading rather than trusting the return.
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
        // User-scoped: a token minted by one operator/session can't be consumed
        // by another, even for the same booking.
        return self::STORE_PREFIX . 'c' . get_current_user_id() . '_' . md5($tool . '|' . $entityKey);
    }

    private static function idemKey($tool, $entityKey, $key)
    {
        return self::STORE_PREFIX . 'i' . get_current_user_id() . '_' . md5($tool . '|' . $entityKey . '|' . $key);
    }

    /**
     * The one-shot marker for a single token. Tokens are already unguessable and
     * user-scoped, so the token alone identifies the consumption.
     */
    private static function usedKey($token)
    {
        return self::STORE_PREFIX . 'u_' . md5((string) $token);
    }
}
