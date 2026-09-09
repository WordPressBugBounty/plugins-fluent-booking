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
 * Discovery — the agent's entry point into a FluentBooking site.
 *
 * `get-booking-context` is the documented "call this first" tool. One call tells
 * the agent who it is, what it may do, the site's time conventions, headline
 * counts, and every valid enum — so it never has to guess a status string or a
 * timezone. It takes no parameters at all: discovery should have zero friction,
 * and a no-argument schema is also the cheapest schema there is.
 *
 * It earns its ~350 tokens of resident context several times over. Without it,
 * every other tool's schema would have to restate the status and event-type
 * enums inline, and the agent would still guess wrong about week start and
 * timezone.
 *
 * Small reference lists (hosts, calendars, event types) are inlined only while
 * they stay small. Past the threshold the payload reports counts and points at
 * `list-reference-data` instead — a context tool that grows with the size of
 * the site is a context tool that eventually breaks the session it was meant to
 * bootstrap.
 */
class ContextTools
{
    const CACHE_TTL = 60;

    const CACHE_PREFIX = 'fluent_booking_mcp_context_';

    /**
     * Above this many rows a reference list is summarised rather than inlined.
     *
     * Derived from the response budget in docs/mcp-server-spec.md §10 (≤1,200
     * tokens for this tool), not picked by feel. Measured against a real site:
     * the payload without reference lists is ~1,770 bytes, a calendar row ~55
     * and an event-type row ~115. Twelve of each is 12 × 55 + 12 × 115 = 2,040
     * bytes, for a worst case of ~3,800 bytes ≈ 1,090 tokens. Raising this
     * without re-doing that arithmetic breaks the budget the whole design rests
     * on — this tool is called at the start of every session.
     */
    const INLINE_LIST_LIMIT = 12;

    /**
     * Booking statuses the `status` column genuinely holds that
     * Booking::getBookingStatus()'s label map does NOT list.
     *
     * An enum is wrong in two directions and only one of them is loud. Listing a
     * value the column can never hold gives the agent a filter that silently
     * returns zero rows. OMITTING a value the column does hold is worse: those
     * bookings become unreachable, and because the value is absent from the
     * input_schema enum the call is rejected outright, so the agent cannot even
     * discover that the rows exist.
     *
     * Both of these are written by real code paths:
     *  - reserved: written during checkout for payment-pending bookings and
     *              queried by SchedulesController::addCountsForFirstPage().
     *  - no_show:  settable through SchedulesController::patchBooking()'s status
     *              whitelist and counted by the same method.
     *  - approved: TimeSlotService::getBookedSlots() treats it as occupying a
     *              slot, so rows holding it are real enough to remove
     *              availability — and were previously unreachable by any filter.
     *
     * Keep this list in step with the writers, not with the label map.
     */
    const PERSISTED_ONLY_STATUSES = ['reserved', 'no_show', 'approved'];

    /**
     * Statuses Booking::getBookingStatus() has a label for. That method keeps
     * its map private, so the list is mirrored here rather than read out of it —
     * and the mirror is deliberate: adding a status there without adding it here
     * only costs the agent a filter, whereas reflecting into a private array
     * would break silently on any refactor.
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
     * The computed period buckets list-bookings accepts.
     *
     * Read from the canonical helper so a bucket added by a filter shows up
     * automatically, then unioned with the two that
     * Booking::scopeApplyComputedStatus() honours but the admin's filter
     * dropdown never renders. A period the scope supports but the enum omits is
     * a filter the agent cannot reach; one the enum lists but the scope ignores
     * silently returns the unfiltered set. Both directions matter.
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
        return ['single', 'group', 'round_robin', 'collective', 'single_event', 'group_event'];
    }

    /**
     * Every enum the agent is told to trust. Shared with the tool schemas, so a
     * value the agent is offered in an input_schema and a value this payload
     * advertises can never disagree.
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
     * The tool definitions this class owns.
     *
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
                    // stdClass, not [] — an empty PHP array serialises as a JSON
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
     * Build (or serve from cache) the context payload.
     *
     * Cached per user, never globally: the payload states the caller's
     * permission set and permission-scoped counts, so a shared cache entry would
     * hand one host another host's view of the site.
     *
     * @param array $params unused; the tool takes none
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
     * Invalidate every user's cached context.
     *
     * Bumping a shared version counter rather than deleting a key: the cache is
     * per-user, and these hooks fire as whoever made the edit, so deleting
     * "the" key only ever cleared the editor's own copy and left every other
     * operator reading stale reference lists until the TTL expired. The version
     * is part of the key, so one write retires all of them at once.
     */
    public static function invalidateCache()
    {
        $option = self::CACHE_PREFIX . 'version';

        // Not autoloaded. Only MCP requests read it, and autoloading meant
        // every calendar write flushed the site's alloptions cache.
        update_option($option, (int) get_option($option, 0) + 1, false);
    }

    /**
     * Per-user, per-site, per-version cache key. `get_current_blog_id()` is
     * included because an object-cache backend fronting transients is not
     * guaranteed to isolate keys per site on multisite.
     *
     * @return string
     */
    private static function cacheKey()
    {
        $version = (int) get_option(self::CACHE_PREFIX . 'version', 0);

        return self::CACHE_PREFIX . get_current_blog_id() . '_' . get_current_user_id() . '_' . $version;
    }

    /**
     * Who the agent is acting as, and what that account may do. The permission
     * keys are echoed verbatim so an agent that hits a permission_denied can
     * tell the user exactly which grant is missing.
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
     * Site conventions the agent would otherwise guess wrong: the zone, the
     * current instant in both UTC and local form, which day the week starts on,
     * and the clock format the operator reads.
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
     * Headline counts, scoped exactly the way the list tools scope their
     * queries. A count built on a wider query than the list it describes is a
     * disclosure bug, so both go through the same host filter.
     *
     * @return array
     */
    private static function counts()
    {
        $seesAll = PermissionGate::canSeeAllBookings();
        $userId  = get_current_user_id();

        $bookingQuery = Booking::query();

        if (!$seesAll) {
            // whereHostAccess(), matching BookingTools::buildQuery() and
            // BookingReportService::scoped(). A bare host_user_id filter is
            // NARROWER: it misses every booking the caller is a secondary host
            // on, which is most of a round-robin or collective host's work. The
            // context payload would report three upcoming bookings and
            // list-bookings would then return eleven.
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
     * Reference lists, inlined only while they are small enough to be free. Past
     * INLINE_LIST_LIMIT the entry becomes a count plus a pointer, so the context
     * payload stays flat as a site grows.
     *
     * @return array
     */
    private static function referenceLists()
    {
        return [
            'calendars'   => self::inlineList(
                self::visibleCalendarQuery(),
                // list-reference-data is in the `scheduling` toolset: on a
                // core-only site nothing lists calendars, so point at nothing.
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
                // NOT list-reference-data: it has no `event_types` kind, so
                // that pointer fails validation. get-event-types is in `core`.
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
     * Inline a list, or summarise it when it is too long to be free.
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
     * Calendars this caller may read, through the module's one visibility
     * helper — so the context payload, `get-event-types` and
     * `list-reference-data` cannot disagree about what exists.
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
     * FluentBooking's nouns against the ones an agent is most likely to arrive
     * with. An agent that has read Cal.com's docs will ask for an "event type"
     * and a "schedule"; telling it the mapping once here is cheaper than every
     * tool description explaining itself.
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
