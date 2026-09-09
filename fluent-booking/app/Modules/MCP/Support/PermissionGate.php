<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Maps MCP abilities onto FluentBooking's existing capability model.
 *
 * The MCP caller IS a WordPress user authenticating with an application
 * password, so there is no parallel permission system here — every check
 * delegates to PermissionManager, the same layer the admin REST policies use.
 * Its eight permission keys (allPermissionSets()) are the whole vocabulary.
 *
 * Three layers, in order:
 *
 *   1. isEnabled()   — the master switch. Ships off; MCPInit only registers the
 *                      server when it is on, so a site that never turns it on
 *                      pays nothing.
 *   2. transport()   — can this user reach the endpoint at all? Every ability's
 *                      permission_callback starts here.
 *   3. readGate() / bookingWriteGate() / scheduleWriteGate() — the per-ability
 *                      permission_callbacks. These are what keep a write tool
 *                      out of a read-only account's tools/list in the first
 *                      place, rather than letting it be advertised and then
 *                      refused at execute time.
 *
 * Layer 3 is a gate, not the whole check. It answers "may this account use this
 * KIND of tool at all"; the per-record question ("this booking, this calendar")
 * is answered inside the tool by BookingWriter::canWriteBooking(),
 * PermissionManager::canWriteCalendar() and friends, because it needs the record
 * and the permission_callback does not have it.
 *
 * MCP tool annotations (readonly / destructive) are UX hints for the client.
 * THIS is the enforcement boundary.
 */
class PermissionGate
{
    /**
     * Dedicated option rather than a key in the `_fluent_booking_enabled_modules`
     * blob: SettingsController::updateGlobalModules() coerces every value in
     * that blob to the scalar 'yes'/'no', so it cannot carry the toolsets array
     * without changing a writer that pro and the admin UI both depend on.
     * Autoloaded, so the boot-time isEnabled() check costs no extra query.
     */
    const OPTION_KEY = '_fluent_booking_mcp_settings';

    /**
     * Toolsets, and which ship on. See docs/mcp-server-spec.md §3.2 — every tool
     * definition stays resident in the client's context for the whole session
     * (~500 tokens each, measured), so exposure is a setting rather than a fixed
     * decision. `core` is not switchable: a server with no tools is not a server.
     */
    const TOOLSET_CORE = 'core';

    const TOOLSET_SCHEDULING = 'scheduling';

    const TOOLSET_PAYMENTS = 'payments';

    /**
     * Permission sets that may change bookings. `manage_own_calendar` is here
     * because it is the base host grant: a host can always act on their own
     * bookings, and the per-record check inside the tool is what stops them
     * acting on anybody else's.
     */
    const BOOKING_WRITE_CAPS = [
        'manage_own_calendar',
        'manage_all_bookings',
        'manage_all_data',
    ];

    /**
     * Permission sets that may change scheduling configuration — event types
     * and availability schedules.
     */
    const SCHEDULE_WRITE_CAPS = [
        'manage_own_calendar',
        'manage_other_calendars',
        'manage_other_availabilities',
        'manage_all_data',
    ];

    /**
     * permission_callback for every read-only ability: reaching the endpoint is
     * the whole bar, because holding any FluentBooking permission implies being
     * allowed to see *something*, and each tool scopes its own query to
     * whatever that something is.
     *
     * @param mixed $request
     * @return true|\WP_Error
     */
    public static function readGate($request = null)
    {
        return self::transport($request);
    }

    /**
     * permission_callback for the booking write tools.
     *
     * @param mixed $request
     * @return true|\WP_Error
     */
    public static function bookingWriteGate($request = null)
    {
        return self::writeGate(self::BOOKING_WRITE_CAPS, __('Your account can read bookings but not change them.', 'fluent-booking'));
    }

    /**
     * permission_callback for the scheduling configuration write tools.
     *
     * @param mixed $request
     * @return true|\WP_Error
     */
    public static function scheduleWriteGate($request = null)
    {
        return self::writeGate(self::SCHEDULE_WRITE_CAPS, __('Your account can read scheduling settings but not change them.', 'fluent-booking'));
    }

    /**
     * @param array  $caps
     * @param string $message
     * @return true|\WP_Error
     */
    private static function writeGate($caps, $message)
    {
        $transport = self::transport();

        if (is_wp_error($transport)) {
            return $transport;
        }

        if (!PermissionManager::userCan($caps)) {
            return MCPHelper::error('permission_denied', $message, ['required_any_of' => $caps]);
        }

        return true;
    }

    /**
     * Calendar ids this caller may read, or false when they may read all of
     * them.
     *
     * The one answer to "which calendars can this account see", so the context
     * payload, the event-type list, the reference lists and the availability
     * tools cannot drift into showing each other's users different sites. Before
     * this there were three spellings of the question — `user_id = me`,
     * `hasAllCalendarAccess()` and `canReadCalendar()` — and the last is
     * strictly the widest, so a list built on the first would hide an event type
     * that the detail read would happily return.
     *
     * Resolved once per request: it walks every calendar, and the tools that
     * need it call it several times.
     *
     * @return array|false false means "no restriction"
     */
    public static function readableCalendarIds()
    {
        static $cache = [];

        $userId = get_current_user_id();

        // Keyed by the permission SET, not just the user id. A user's grants can
        // change inside one request — the permission-matrix gate does exactly
        // that, granting one set at a time to a single probe account — and a
        // cache keyed on the id alone would answer every later set with the
        // first set's calendars.
        // Blog id included as well: a request that switches site mid-flight on
        // multisite would otherwise reuse the first site's calendar ids.
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 0;

        $key = $blogId . '|' . $userId . '|' . md5((string) wp_json_encode(PermissionManager::getUserPermissions()));

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if (PermissionManager::hasAllCalendarAccess(true)) {
            return $cache[$key] = false;
        }

        return $cache[$key] = self::resolveReadableCalendarIds($userId);
    }

    /**
     * The calendars a restricted user may read: the ones they own, plus the
     * ones CalendarService::isSharedCalendar() would admit them to.
     *
     * Three narrow reads rather than hydrating every Calendar with its events.
     * The old loop pulled the site's whole calendar and event set into PHP to
     * produce a handful of ids, on every MCP request, because each tool call is
     * its own request.
     *
     * team_members cannot be filtered in SQL: `settings` is PHP-serialized, not
     * JSON, so JSON_EXTRACT errors on it. The LIKE narrows the rows worth
     * unserializing; the in_array below is what decides.
     *
     * @param int $userId
     *
     * @return array
     */
    private static function resolveReadableCalendarIds($userId)
    {
        global $wpdb;

        $calendars = $wpdb->prefix . (new Calendar())->getTable();
        $events    = $wpdb->prefix . (new CalendarSlot())->getTable();

        $owned = $wpdb->get_col($wpdb->prepare("SELECT `id` FROM `{$calendars}` WHERE `user_id` = %d", $userId)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from the models' getTable(), not from request input

        // isSharedCalendar()'s first branch: the user owns an event on it.
        $hosting = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT `calendar_id` FROM `{$events}` WHERE `user_id` = %d", $userId)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from the models' getTable(), not from request input

        $readable = array_merge(array_map('intval', $owned), array_map('intval', $hosting));

        // and its second: the user is listed in an event's team_members.
        $candidates = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from the models' getTable(), not from request input
            $wpdb->prepare(
                "SELECT `calendar_id`, `settings` FROM `{$events}` WHERE `settings` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                '%' . $wpdb->esc_like('i:' . (int) $userId . ';') . '%'
            ),
            ARRAY_A
        );

        foreach ($candidates as $candidate) {
            $calendarId = (int) $candidate['calendar_id'];

            if (in_array($calendarId, $readable, true)) {
                continue;
            }

            $settings = maybe_unserialize($candidate['settings']);

            if (!is_array($settings)) {
                continue;
            }

            $members = array_map('intval', (array) Arr::get($settings, 'team_members', []));

            if (in_array((int) $userId, $members, true)) {
                $readable[] = $calendarId;
            }
        }

        return array_values(array_unique($readable));
    }

    /**
     * Apply readableCalendarIds() to a query on any table with a calendar_id.
     *
     * @param object $query
     * @param string $column
     * @return object
     */
    public static function scopeToReadableCalendars($query, $column = 'calendar_id')
    {
        $ids = self::readableCalendarIds();

        if ($ids === false) {
            return $query;
        }

        // whereIn with an empty set must return nothing, not everything.
        return $query->whereIn($column, $ids ? $ids : [0]);
    }

    /**
     * True when the caller may read bookings beyond their own calendars.
     * Wrapped rather than inlined because list + report tools all branch on it
     * and must branch identically — a scope check that drifts between two tools
     * is a data leak, not a style issue.
     *
     * @return bool
     */
    public static function canSeeAllBookings()
    {
        return PermissionManager::userCanSeeAllBookings();
    }

    /**
     * The scope marker for `meta.scope`, derived from the same check the query
     * uses so the two can never disagree.
     *
     * @return string
     */
    public static function currentScope()
    {
        return self::canSeeAllBookings() ? MCPHelper::SCOPE_ALL : MCPHelper::SCOPE_OWN;
    }

    /**
     * Transport gate for the `fluent-booking` server: may this request reach the
     * endpoint at all?
     *
     * The adapter's default gate is `current_user_can('read')`, which every
     * subscriber on the site passes — far too loose for a surface that returns
     * attendee names, emails and phone numbers. Per-ability permission_callbacks
     * still run on top; a host who gets through here still cannot cancel someone
     * else's booking.
     *
     * @param mixed $request unused; the adapter passes the REST request
     * @return true|\WP_Error
     */
    public static function transport($request = null)
    {
        if (!self::isEnabled()) {
            return MCPHelper::error(
                'disabled',
                __('The FluentBooking MCP server is disabled. Enable it in FluentBooking → Settings → MCP for AI Agents.', 'fluent-booking')
            );
        }

        if (!is_user_logged_in()) {
            return MCPHelper::error(
                'unauthorized',
                __('Authentication is required to access the FluentBooking MCP server.', 'fluent-booking')
            );
        }

        if (!PermissionManager::currentUserHasAnyPermission()) {
            return MCPHelper::error(
                'forbidden',
                __('Your account does not have FluentBooking access.', 'fluent-booking')
            );
        }

        return true;
    }

    /**
     * The stored MCP settings, defaults merged in.
     *
     * @param bool $cached
     * @return array
     */
    public static function getSettings($cached = true)
    {
        static $settings = null;
        static $forBlog = null;

        // Keyed by blog: a mid-request site switch on multisite would otherwise
        // hand the second site the first site's toolset selection.
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 0;

        if ($cached && $settings !== null && $forBlog === $blogId) {
            return $settings;
        }

        $forBlog = $blogId;

        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = [
            'enabled'  => Arr::get($stored, 'enabled') === 'yes' ? 'yes' : 'no',
            'toolsets' => self::sanitizeToolsets(Arr::get($stored, 'toolsets', [])),
        ];

        return $settings;
    }

    /**
     * The master switch. Ships off.
     *
     * @return bool
     */
    public static function isEnabled()
    {
        $settings = self::getSettings();

        return Arr::get($settings, 'enabled') === 'yes';
    }

    /**
     * Persist the master switch.
     *
     * Enabling MCP opens the whole tool surface, so the capability is
     * re-checked here even though every caller is already behind
     * SettingsPolicy: the FluentToolkit toggle path delegates authorization to
     * an external plugin, and defence in depth at the write is cheaper than
     * trusting that. `manage_options` (not is_super_admin) is correct — this is
     * a per-site plugin setting stored in a per-site option.
     *
     * @param bool $enabled
     * @return bool the persisted state
     */
    public static function setEnabled($enabled)
    {
        if (!current_user_can('manage_options')) {
            return self::isEnabled();
        }

        return self::saveSettings(['enabled' => $enabled ? 'yes' : 'no']);
    }

    /**
     * Toolsets currently exposed. `core` is always present even if a stored
     * value somehow omits it.
     *
     * @return array
     */
    public static function enabledToolsets()
    {
        $settings = self::getSettings();

        return self::sanitizeToolsets(Arr::get($settings, 'toolsets', []));
    }

    /**
     * @param string $toolset
     * @return bool
     */
    public static function isToolsetEnabled($toolset)
    {
        return in_array($toolset, self::enabledToolsets(), true);
    }

    /**
     * Persist toolset selection.
     *
     * @param array $toolsets
     * @return array the persisted toolsets
     */
    public static function setToolsets($toolsets)
    {
        if (!current_user_can('manage_options')) {
            return self::enabledToolsets();
        }

        self::saveSettings(['toolsets' => self::sanitizeToolsets($toolsets)]);

        return self::enabledToolsets();
    }

    /**
     * Every toolset the server knows about, with its label. `payments` is
     * advertised only when Pro is active — offering a switch that cannot do
     * anything is worse than not offering it.
     *
     * @return array keyed by toolset slug
     */
    public static function availableToolsets()
    {
        $toolsets = [
            self::TOOLSET_CORE       => [
                'label'       => __('Core', 'fluent-booking'),
                'description' => __('Bookings, availability, event types, diagnostics and reporting. Always on.', 'fluent-booking'),
                'locked'      => true,
            ],
            self::TOOLSET_SCHEDULING => [
                'label'       => __('Scheduling setup', 'fluent-booking'),
                'description' => __('Let the agent create and edit event types and availability schedules.', 'fluent-booking'),
                'locked'      => false,
            ],
        ];

        if (defined('FLUENT_BOOKING_PRO_DIR_FILE')) {
            $toolsets[self::TOOLSET_PAYMENTS] = [
                'label'       => __('Payments', 'fluent-booking'),
                'description' => __('Let the agent read booking orders and transactions.', 'fluent-booking'),
                'locked'      => false,
            ];
        }

        return $toolsets;
    }

    /**
     * Coerce a stored / submitted toolset list to known slugs, always including
     * `core`.
     *
     * @param mixed $toolsets
     * @return array
     */
    private static function sanitizeToolsets($toolsets)
    {
        if (!is_array($toolsets)) {
            $toolsets = [];
        }

        $known = [self::TOOLSET_CORE, self::TOOLSET_SCHEDULING, self::TOOLSET_PAYMENTS];

        $toolsets = array_values(array_intersect($known, array_map('sanitize_text_field', $toolsets)));

        if (!in_array(self::TOOLSET_CORE, $toolsets, true)) {
            array_unshift($toolsets, self::TOOLSET_CORE);
        }

        return $toolsets;
    }

    /**
     * Merge-write into the option so setEnabled() and setToolsets() cannot
     * clobber each other, then bust the static cache.
     *
     * @param array $changes
     * @return bool the persisted enabled state
     */
    private static function saveSettings($changes)
    {
        $current = self::getSettings(false);

        update_option(self::OPTION_KEY, array_merge($current, (array) $changes), true);

        $settings = self::getSettings(false);

        return Arr::get($settings, 'enabled') === 'yes';
    }
}
