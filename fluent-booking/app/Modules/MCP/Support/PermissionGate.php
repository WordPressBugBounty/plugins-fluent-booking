<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Maps MCP abilities onto FluentBooking's capability model. The caller is a
 * WordPress user on an application password, so every check delegates to
 * PermissionManager, the same layer the admin REST policies use.
 *
 * Three layers:
 *   1. isEnabled()  the master switch, off by default. MCPInit only registers
 *                   the server when it is on.
 *   2. transport()  may this user reach the endpoint at all.
 *   3. readGate() / bookingWriteGate() / scheduleWriteGate()  per-ability
 *                   permission_callbacks, which keep write tools out of a
 *                   read-only account's tools/list.
 *
 * Layer 3 only answers "may this account use this kind of tool". Per-record
 * checks (BookingWriter::canWriteBooking(), PermissionManager::canWriteCalendar())
 * run inside the tool, since the permission_callback doesn't have the record.
 *
 * Tool annotations (readonly / destructive) are client hints; this is the
 * enforcement boundary.
 */
class PermissionGate
{
    /**
     * Own option, not a key in `_fluent_booking_enabled_modules`:
     * SettingsController::updateGlobalModules() coerces every value there to
     * 'yes'/'no', so it can't hold the toolsets array. Autoloaded, so the
     * boot-time isEnabled() check costs no query.
     */
    const OPTION_KEY = '_fluent_booking_mcp_settings';

    /**
     * Toolsets are a setting because every tool definition stays in the
     * client's context all session (~500 tokens each; docs/mcp-server-spec.md
     * §3.2). `core` can't be switched off.
     */
    const TOOLSET_CORE = 'core';

    const TOOLSET_SCHEDULING = 'scheduling';

    const TOOLSET_PAYMENTS = 'payments';

    /**
     * Permission sets that may change bookings. `manage_own_calendar` is the
     * base host grant; the per-record check in the tool keeps a host to their
     * own bookings.
     */
    const BOOKING_WRITE_CAPS = [
        'manage_own_calendar',
        'manage_all_bookings',
        'manage_all_data',
    ];

    /**
     * Permission sets that may change event types and availability schedules.
     */
    const SCHEDULE_WRITE_CAPS = [
        'manage_own_calendar',
        'manage_other_calendars',
        'manage_other_availabilities',
        'manage_all_data',
    ];

    /**
     * permission_callback for read-only abilities. Reaching the endpoint is
     * enough; each tool scopes its own query to what the caller may see.
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
     * them. Every tool uses this so lists and detail reads agree on scope.
     * Cached per request.
     *
     * @return array|false false means "no restriction"
     */
    public static function readableCalendarIds()
    {
        static $cache = [];

        $userId = get_current_user_id();

        // Keyed by permission set and blog too: grants can change within one
        // request (the permission-matrix gate does this), and a multisite
        // request can switch blogs.
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
     * ones CalendarService::isSharedCalendar() would admit them to. Three
     * narrow queries instead of hydrating every calendar with its events.
     *
     * `settings` is PHP-serialized, so team_members can't be filtered in SQL.
     * The LIKE only narrows the rows; the in_array below decides.
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
     * True when the caller may read bookings beyond their own calendars. One
     * helper so list and report tools can't drift apart on scope.
     *
     * @return bool
     */
    public static function canSeeAllBookings()
    {
        return PermissionManager::userCanSeeAllBookings();
    }

    /**
     * The `meta.scope` marker, from the same check the query uses.
     *
     * @return string
     */
    public static function currentScope()
    {
        return self::canSeeAllBookings() ? MCPHelper::SCOPE_ALL : MCPHelper::SCOPE_OWN;
    }

    /**
     * Transport gate for the `fluent-booking` server. Replaces the adapter's
     * default `current_user_can('read')`, which every subscriber passes and is
     * too loose for attendee contact data. Per-ability checks still run on top.
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

        // Keyed by blog, in case a multisite request switches sites.
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
     * The master switch. Off by default.
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
     * The capability is re-checked here even though callers sit behind
     * SettingsPolicy, because the FluentToolkit toggle path delegates auth to
     * another plugin. `manage_options`, not is_super_admin: it's a per-site option.
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
     * Toolsets currently exposed. Always includes `core`.
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
     * Every toolset the server knows about, with its label. `payments` is only
     * offered when Pro is active.
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
     * Coerce a toolset list to known slugs, always including `core`.
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
