<?php

namespace FluentBooking\App\Modules\MCP\Tools;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Discovery: `get-booking-context` is the "call this first" tool. One call,
 * no parameters, tells the agent who it is, what it may do, the site's time
 * conventions, headline counts and every valid enum, so other schemas don't
 * have to restate them.
 *
 * Reference lists are inlined only while small; past the limit they become a
 * count plus a pointer, so the payload doesn't grow with the site.
 */
class ContextTools
{
    const CACHE_TTL = 60;

    const CACHE_PREFIX = 'fluent_booking_mcp_context_';

    /**
     * Above this many rows a reference list is summarised rather than inlined.
     *
     * Sized to the ≤1,200 token budget in docs/mcp-server-spec.md §10: base
     * payload ~1,770 bytes, plus 12 calendar rows (~55 bytes) and 12 event-type
     * rows (~115 bytes) is ~3,800 bytes ≈ 1,090 tokens. Redo the sum before raising it.
     */
    const INLINE_LIST_LIMIT = 12;

    /**
     * Statuses the `status` column holds that Booking::getBookingStatus()'s
     * label map doesn't list. A value missing from the input_schema enum makes
     * those bookings unreachable, since the call is rejected outright.
     *
     *  - reserved: written at checkout for payment-pending bookings.
     *  - no_show:  settable via SchedulesController::patchBooking().
     *  - approved: TimeSlotService::getBookedSlots() treats it as occupying a slot.
     *
     * Keep this in step with the writers, not with the label map.
     */
    const PERSISTED_ONLY_STATUSES = ['reserved', 'no_show', 'approved'];

    /**
     * Statuses Booking::getBookingStatus() has a label for. Its map is private,
     * so it's mirrored here rather than read by reflection.
     */
    const LABELLED_STATUSES = ['scheduled', 'rescheduled', 'completed', 'pending', 'cancelled', 'rejected'];

    /**
     * Every value the `status` column can hold, labelled or not.
     *
     * @return array
     */
    public static function bookingStatuses()
    {
        return array_values(array_unique(array_merge(self::LABELLED_STATUSES, self::PERSISTED_ONLY_STATUSES)));
    }

    /**
     * The computed period buckets list-bookings accepts: the helper's list
     * (so filter-added buckets show up), plus the two that
     * Booking::scopeApplyComputedStatus() honours but the admin dropdown omits.
     *
     * @return array
     */
    public static function bookingPeriods()
    {
        $periods = array_keys((array) Helper::getBookingPeriodOptions());

        return array_values(array_unique(array_merge($periods, ['no_show', 'latest_bookings'])));
    }

    /**
     * Event-type discriminators on both fcal_calendar_slots.event_type and
     * fcal_bookings.event_type.
     *
     * @return array
     */
    public static function eventTypes()
    {
        return CalendarSlot::getEventTypes();
    }

    /**
     * Every enum the agent is told to trust. Shared with the tool schemas so
     * the two can't disagree.
     *
     * @return array
     */
    public static function enums()
    {
        return [
            'booking_statuses' => self::bookingStatuses(),
            'booking_periods'  => self::bookingPeriods(),
            'event_types'      => self::eventTypes(),
            'calendar_types'   => ['simple', 'team', 'event'],
            'payment_statuses' => ['pending', 'paid', 'failed', 'refunded', 'partially-paid', 'partially-refunded'],
        ];
    }

    /**
     * @return array
     */
    public static function definitions()
    {
        return [
            'fluent-booking/get-booking-context' => [
                'label'               => __('Get booking context', 'fluent-booking'),
                'description'         => __('Call this first. Returns who you are, what you may do, the site timezone and current time, valid enum values for every filter, headline counts, and small reference lists of hosts, calendars and event types.', 'fluent-booking'),
                'input_schema'        => [
                    'type' => 'object',
                    // stdClass, not []: an empty array serialises as a JSON
                    // array and clients reject `"properties": []`.
                    'properties' => new \stdClass(),
                ],
                'annotations'         => [
                    'title'      => __('Get booking context', 'fluent-booking'),
                    'readonly'   => true,
                    'idempotent' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'getContext'],
            ],
        ];
    }

    /**
     * Build (or serve from cache) the context payload. Cached per user, since
     * it holds the caller's permissions and permission-scoped counts.
     *
     * @param array $params unused
     * @return array
     */
    public static function getContext($params = [])
    {
        $cacheKey = self::cacheKey();

        $cached = get_transient($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $timezone = DateTimeHelper::getTimeZone();
        $settings = Helper::getGlobalSettings();

        $payload = MCPHelper::success([
            'you'          => self::identity($timezone),
            'site'         => self::site($timezone, $settings),
            'counts'       => self::counts(),
            'enums'        => self::enums(),
            'reference'    => self::referenceLists(),
            'terminology'  => self::terminology(),
        ], [
            'timezone' => $timezone,
            'scope'    => PermissionGate::currentScope(),
        ], __('Use the enum values above verbatim in filters. Times you send are treated as UTC unless you pass an explicit timezone.', 'fluent-booking'));

        set_transient($cacheKey, $payload, self::CACHE_TTL);

        return $payload;
    }

    /**
     * Invalidate every user's cached context. The cache is per user and the
     * hooks fire as the editor, so bump a version in the key rather than
     * deleting one user's entry.
     */
    public static function invalidateCache()
    {
        $option = self::CACHE_PREFIX . 'version';

        // Not autoloaded: only MCP reads it, and autoloading would flush
        // alloptions on every calendar write.
        update_option($option, (int) get_option($option, 0) + 1, false);
    }

    /**
     * Per-user, per-site, per-version cache key. The blog id is included
     * because an object cache isn't guaranteed to isolate keys per site.
     *
     * @return string
     */
    private static function cacheKey()
    {
        $version = (int) get_option(self::CACHE_PREFIX . 'version', 0);

        return self::CACHE_PREFIX . get_current_blog_id() . '_' . get_current_user_id() . '_' . $version;
    }

    /**
     * Who the agent is acting as. Permission keys are echoed verbatim so a
     * permission_denied can be traced to the missing grant.
     *
     * @param string $timezone
     * @return array
     */
    private static function identity($timezone)
    {
        $user = wp_get_current_user();

        $permissions = PermissionManager::getUserPermissions();

        return [
            'user_id'              => (int) get_current_user_id(),
            'display_name'         => $user ? $user->display_name : '',
            'permissions'          => array_values((array) $permissions),
            'can_see_all_bookings' => PermissionGate::canSeeAllBookings(),
            'can_manage_all_data'  => PermissionManager::userCan('manage_all_data'),
            'scope'                => PermissionGate::currentScope(),
            'timezone'             => $timezone,
        ];
    }

    /**
     * Site conventions the agent would otherwise guess: timezone, current time,
     * week start and clock format.
     *
     * @param string $timezone
     * @param array  $settings
     * @return array
     */
    private static function site($timezone, $settings)
    {
        $nowUtc = gmdate('Y-m-d H:i:s'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        return array_merge(
            [
                'timezone'    => $timezone,
                'week_starts' => Arr::get($settings, 'administration.start_day', 'sun'),
                'time_format' => Arr::get($settings, 'time_format', '12'),
                'currency'    => Arr::get($settings, 'payments.currency', 'USD'),
                'locale'      => get_locale(),
                'version'     => defined('FLUENT_BOOKING_VERSION') ? FLUENT_BOOKING_VERSION : '',
                'pro_active'  => defined('FLUENT_BOOKING_PRO_DIR_FILE'),
                'toolsets'    => PermissionGate::enabledToolsets(),
            ],
            MCPHelper::timePair($nowUtc, $timezone, 'now')
        );
    }

    /**
     * Headline counts, scoped exactly like the list tools' queries.
     *
     * @return array
     */
    private static function counts()
    {
        $seesAll = PermissionGate::canSeeAllBookings();
        $userId  = get_current_user_id();

        $bookingQuery = Booking::query();

        if (!$seesAll) {
            // Matches BookingTools::buildQuery(). A bare host_user_id filter
            // would miss bookings where the caller is a secondary host.
            $bookingQuery->whereHostAccess($userId);
        }

        $upcoming = (clone $bookingQuery)
            ->where('end_time', '>=', gmdate('Y-m-d H:i:s')) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ->where('status', 'scheduled')
            ->count();

        $pending = (clone $bookingQuery)->whereIn('status', ['pending', 'reserved'])->count();

        return [
            'calendars'         => self::visibleCalendarQuery()->count(),
            'event_types'       => self::visibleEventTypeQuery()->count(),
            'upcoming_bookings' => (int) $upcoming,
            'pending_bookings'  => (int) $pending,
        ];
    }

    /**
     * @return array
     */
    private static function referenceLists()
    {
        return [
            'calendars'   => self::inlineList(
                self::visibleCalendarQuery(),
                // list-reference-data is in the `scheduling` toolset; on a
                // core-only site there's nothing to point at.
                PermissionGate::isToolsetEnabled(PermissionGate::TOOLSET_SCHEDULING)
                    ? 'fluent-booking/list-reference-data'
                    : '',
                function ($calendar) {
                    return [
                        'id'      => (int) $calendar->id,
                        'title'   => $calendar->title,
                        'type'    => $calendar->type,
                        'user_id' => (int) $calendar->user_id,
                    ];
                }
            ),
            'event_types' => self::inlineList(
                self::visibleEventTypeQuery(),
                // Not list-reference-data: it has no `event_types` kind.
                'fluent-booking/get-event-types',
                function ($slot) {
                    return [
                        'id'          => (int) $slot->id,
                        'calendar_id' => (int) $slot->calendar_id,
                        'title'       => $slot->title,
                        'duration'    => (int) $slot->duration,
                        'event_type'  => $slot->event_type,
                        'status'      => $slot->status,
                    ];
                }
            ),
        ];
    }

    /**
     * Inline a list, or summarise it past INLINE_LIST_LIMIT.
     *
     * @param object   $query     a model query, already permission-scoped
     * @param string   $getWith   the tool that returns this list in full; '' when
     *                            no enabled toolset exposes one
     * @param callable $projector row => compact array
     * @return array
     */
    private static function inlineList($query, $getWith, $projector)
    {
        $total = (clone $query)->count();

        if ($total > self::INLINE_LIST_LIMIT) {
            $summary = [
                'total'   => (int) $total,
                'inlined' => false,
            ];

            if ($getWith) {
                $summary['get_with'] = $getWith;
            }

            return $summary;
        }

        $items = $query->get();

        $mapped = [];

        foreach ($items as $item) {
            $mapped[] = call_user_func($projector, $item);
        }

        return [
            'total'   => (int) $total,
            'inlined' => true,
            'items'   => $mapped,
        ];
    }

    /**
     * Calendars this caller may read, via the shared visibility helper so
     * this and the list tools agree.
     *
     * @return object
     */
    private static function visibleCalendarQuery()
    {
        return PermissionGate::scopeToReadableCalendars(Calendar::query(), 'id');
    }

    /**
     * Event types on the calendars this caller may read.
     *
     * @return object
     */
    private static function visibleEventTypeQuery()
    {
        return PermissionGate::scopeToReadableCalendars(CalendarSlot::query(), 'calendar_id');
    }

    /**
     * FluentBooking's nouns mapped to the ones an agent likely arrives with
     * (e.g. Cal.com's "event type"), stated once instead of in every tool.
     *
     * @return array
     */
    private static function terminology()
    {
        return [
            'event_type'   => __('A bookable meeting definition (duration, location, questions). Stored as a calendar slot.', 'fluent-booking'),
            'calendar'     => __('A host (type "simple"), a team (type "team"), or a one-off event calendar (type "event").', 'fluent-booking'),
            'booking'      => __('One scheduled appointment. Group bookings share a group_id.', 'fluent-booking'),
            'availability' => __('A named weekly schedule plus date overrides, reusable across event types.', 'fluent-booking'),
        ];
    }
}
