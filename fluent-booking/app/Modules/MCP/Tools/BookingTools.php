<?php

namespace FluentBooking\App\Modules\MCP\Tools;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Modules\MCP\Support\BookingProjector;
use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Read-only booking tools. Attendees, hosts etc. are `include` values on
 * get-booking rather than separate tools, to save schema tokens.
 *
 * Scoping happens in the query, not the response, so totals and pagination
 * never leak rows the caller can't see.
 */
class BookingTools
{
    const DEFAULT_PER_PAGE = 20;

    // `include` values for get-booking. Each costs a query, so none are default.
    const INCLUDABLE = ['custom_fields', 'attendees', 'hosts', 'activities', 'notes'];

    public static function definitions()
    {
        return [
            'fluent-booking/list-bookings' => [
                'label'               => __('List bookings', 'fluent-booking'),
                'description'         => __('List bookings with filters. Returns a compact row per booking; attendee emails are masked unless include_pii is set. Use get-booking for one booking in full.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'period'      => [
                            'type'        => 'string',
                            'description' => __('Computed bucket. Defaults to upcoming.', 'fluent-booking'),
                            'enum'        => ContextTools::bookingPeriods(),
                        ],
                        'status'      => [
                            'type'        => 'array',
                            'description' => __('Filter by raw status values instead of a period bucket.', 'fluent-booking'),
                            'items'       => [
                                'type' => 'string',
                                'enum' => ContextTools::bookingStatuses(),
                            ],
                        ],
                        'calendar_id' => ['type' => 'integer'],
                        'event_id'    => ['type' => 'integer'],
                        'event_type'  => [
                            'type' => 'string',
                            'enum' => ContextTools::eventTypes(),
                        ],
                        'host_id'     => ['type' => 'integer'],
                        'email'       => [
                            'type'        => 'string',
                            'description' => __('Exact attendee email match.', 'fluent-booking'),
                        ],
                        'search'      => [
                            'type'        => 'string',
                            'description' => __('Free-text search across attendee name, email and phone.', 'fluent-booking'),
                        ],
                        'from'        => [
                            'type'        => 'string',
                            'description' => __('Start of the booking date range, Y-m-d.', 'fluent-booking'),
                        ],
                        'to'          => [
                            'type'        => 'string',
                            'description' => __('End of the booking date range, Y-m-d.', 'fluent-booking'),
                        ],
                        'timezone'    => [
                            'type'        => 'string',
                            'description' => __('IANA timezone for the *_local times in the response. Defaults to the site timezone.', 'fluent-booking'),
                        ],
                        'group_bookings' => [
                            'type'        => 'boolean',
                            'description' => __('Collapse group bookings to one row, matching the admin list. Default true.', 'fluent-booking'),
                        ],
                        'include_pii' => [
                            'type'        => 'boolean',
                            'description' => __('Return unmasked attendee emails. Requires read access to all bookings.', 'fluent-booking'),
                        ],
                        'page'        => ['type' => 'integer'],
                        'per_page'    => [
                            'type'        => 'integer',
                            'description' => sprintf(
                                /* translators: %1$d: default page size, %2$d: maximum page size */
                                __('Default %1$d, maximum %2$d.', 'fluent-booking'),
                                self::DEFAULT_PER_PAGE,
                                MCPHelper::MAX_PER_PAGE
                            ),
                        ],
                    ],
                ],
                'annotations'         => [
                    'title'    => __('List bookings', 'fluent-booking'),
                    'readonly' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'listBookings'],
            ],

            'fluent-booking/get-booking' => [
                'label'               => __('Get booking', 'fluent-booking'),
                'description'         => __('Full detail for one booking by id or hash, including attendee contact details, location, status history and cancellation reason. Use include to add form answers, guests, hosts or the activity timeline. include notes is a Pro section: host notes, oldest first.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'booking_id' => ['type' => 'integer'],
                        'hash'       => [
                            'type'        => 'string',
                            'description' => __('The booking hash, as an alternative to booking_id.', 'fluent-booking'),
                        ],
                        'include'    => [
                            'type'        => 'array',
                            'description' => __('Extra sections to load. Each one costs an additional query.', 'fluent-booking'),
                            'items'       => [
                                'type' => 'string',
                                'enum' => self::INCLUDABLE,
                            ],
                        ],
                        'timezone'   => [
                            'type'        => 'string',
                            'description' => __('IANA timezone for the *_local times in the response.', 'fluent-booking'),
                        ],
                    ],
                ],
                'annotations'         => [
                    'title'    => __('Get booking', 'fluent-booking'),
                    'readonly' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'getBooking'],
            ],
        ];
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function listBookings($params = [])
    {
        $timezone = MCPHelper::resolveTimezone(Arr::get($params, 'timezone', ''));

        $seesAll = PermissionGate::canSeeAllBookings();

        $query = self::buildQuery($params, $seesAll, $timezone);

        if (is_wp_error($query)) {
            return $query;
        }

        // Collapse group bookings by default, like the admin list, so counts match.
        $grouped = (bool) Arr::get($params, 'group_bookings', true);

        // Not when searching: GROUP BY keeps an arbitrary row per group and
        // could hide the attendee that actually matched.
        $searchCollapsed = $grouped && trim((string) Arr::get($params, 'search', '')) !== '';

        if ($searchCollapsed) {
            $grouped = false;
        }

        $perPage = MCPHelper::perPage(Arr::get($params, 'per_page'), self::DEFAULT_PER_PAGE);
        $page    = max(1, absint(Arr::get($params, 'page', 1)));

        // Count before groupBy: COUNT() on a grouped query returns the first
        // group's size. Mirrors SchedulesController::addCountsForFirstPage().
        $total = $grouped
            ? (clone $query)->withoutEagerLoads()->distinct('group_id')->count('group_id')
            : (clone $query)->withoutEagerLoads()->count();

        if ($grouped) {
            $query->groupBy('group_id');
        }

        $bookings = $query->with(BookingProjector::rowRelations())
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // Unmasked emails need read-all-bookings. Without it, downgrade and
        // flag pii_masked rather than erroring.
        $wantsPii    = (bool) Arr::get($params, 'include_pii', false);
        $includePii  = $wantsPii && $seesAll;

        $rows = [];

        foreach ($bookings as $booking) {
            $rows[] = BookingProjector::row($booking, $timezone, $includePii);
        }

        $meta = array_merge(
            MCPHelper::paginationMeta($total, $page, $perPage),
            [
                'timezone' => $timezone,
                'scope'    => PermissionGate::currentScope(),
            ]
        );

        if ($wantsPii && !$includePii) {
            $meta['pii_masked'] = true;
        }

        if ($searchCollapsed) {
            $meta['group_bookings'] = false;
            $meta['group_bookings_note'] = __('Group bookings are listed per attendee here rather than collapsed, so a search cannot hide an attendee behind a group-mate. Counts will be higher than the admin list for group events.', 'fluent-booking');
        }

        return MCPHelper::success($rows, $meta);
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function getBooking($params = [])
    {
        $timezone = MCPHelper::resolveTimezone(Arr::get($params, 'timezone', ''));

        $bookingId = absint(Arr::get($params, 'booking_id'));
        $hash      = sanitize_text_field((string) Arr::get($params, 'hash', ''));

        if (!$bookingId && !$hash) {
            return MCPHelper::error(
                'missing_identifier',
                __('Pass either booking_id or hash.', 'fluent-booking')
            );
        }

        $query = Booking::query();

        if ($bookingId) {
            $query->where('id', $bookingId);
        } else {
            $query->where('hash', $hash);
        }

        $booking = $query->with(BookingProjector::rowRelations())->first();

        if (!$booking) {
            return MCPHelper::error(
                'booking_not_found',
                __('No booking matched that identifier.', 'fluent-booking')
            );
        }

        // Ownership, not just capability: a host with only their own access may
        // hold a valid booking id belonging to somebody else.
        if (!self::canReadBooking($booking)) {
            return MCPHelper::error(
                'permission_denied',
                __('You do not have access to this booking.', 'fluent-booking')
            );
        }

        $include = Arr::get($params, 'include', []);
        $include = is_array($include) ? array_intersect($include, self::INCLUDABLE) : [];

        $bookingData = apply_filters(
            'fluent_booking/mcp_booking_full',
            BookingProjector::full($booking, $timezone, $include),
            $booking,
            $timezone,
            $include
        );

        return MCPHelper::success(
            $bookingData,
            [
                'timezone' => $timezone,
                'scope'    => PermissionGate::currentScope(),
            ]
        );
    }

    /**
     * Whether the caller may read this booking. Must match the scope
     * list-bookings uses (Booking::whereHostAccess()), so a booking hidden from
     * the list can't be opened by guessing its id.
     *
     * @param Booking $booking
     * @return bool
     */
    private static function canReadBooking(Booking $booking)
    {
        if (PermissionGate::canSeeAllBookings()) {
            return true;
        }

        $userId = (int) get_current_user_id();

        if ((int) $booking->host_user_id === $userId) {
            return true;
        }

        if (in_array($userId, array_map('intval', (array) $booking->getHostIds()), true)) {
            return true;
        }

        // Calendar ownership, as in whereHostAccess(). Not canReadCalendar():
        // it admits any team member on a shared calendar.
        return (bool) Calendar::where('id', $booking->calendar_id)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Build the list query, scoped to what the caller may see. Filters reuse
     * the Booking scopes the admin list uses, so the two agree.
     *
     * @param array  $params
     * @param bool   $seesAll
     * @param string $timezone resolved IANA identifier the from/to dates are read in
     * @return object|\WP_Error
     */
    private static function buildQuery($params, $seesAll, $timezone)
    {
        $query = Booking::query();

        if (!$seesAll) {
            $query->whereHostAccess(get_current_user_id());
        }

        $hostId = absint(Arr::get($params, 'host_id'));

        if ($hostId) {
            $query->where('host_user_id', $hostId);
        }

        $calendarId = absint(Arr::get($params, 'calendar_id'));

        if ($calendarId) {
            $query->where('calendar_id', $calendarId);
        }

        $eventId = absint(Arr::get($params, 'event_id'));

        if ($eventId) {
            $query->where('event_id', $eventId);
        }

        $eventType = sanitize_text_field((string) Arr::get($params, 'event_type', ''));

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        $email = sanitize_email((string) Arr::get($params, 'email', ''));

        if ($email && is_email($email)) {
            $query->where('email', $email);
        }

        $range = self::dateRange($params, $timezone);

        if (is_wp_error($range)) {
            return $range;
        }

        if ($range) {
            $query->applyDateRangeFilter($range);
        }

        // Before the status branch below, which returns early.
        $search = sanitize_text_field((string) Arr::get($params, 'search', ''));

        if ($search) {
            $query->searchBy($search);
        }

        // An explicit status list replaces the period bucket. ANDing the two
        // can contradict and silently return nothing.
        $statuses = Arr::get($params, 'status', []);
        $statuses = is_array($statuses) ? array_filter(array_map('sanitize_text_field', $statuses)) : [];

        if ($statuses) {
            $query->whereIn('status', $statuses);
            $query->orderBy('start_time', 'DESC');

            return $query;
        }

        $period = sanitize_text_field((string) Arr::get($params, 'period', 'upcoming'));

        if (!in_array($period, ContextTools::bookingPeriods(), true)) {
            $period = 'upcoming';
        }

        $query->applyComputedStatus($period);
        $query->applyBookingOrderByStatus($period);

        return $query;
    }

    /**
     * Convert from/to dates into a UTC window. The dates are local calendar
     * days in $timezone, while `start_time` is stored in UTC.
     *
     * @param array  $params
     * @param string $timezone resolved IANA identifier
     * @return array|\WP_Error [] when unbounded
     */
    private static function dateRange($params, $timezone)
    {
        $from = sanitize_text_field((string) Arr::get($params, 'from', ''));
        $to   = sanitize_text_field((string) Arr::get($params, 'to', ''));

        if (!$from && !$to) {
            return [];
        }

        foreach ([$from, $to] as $date) {
            if ($date && !MCPHelper::isRealDate($date)) {
                return MCPHelper::error(
                    'invalid_date',
                    __('from and to must be real dates in Y-m-d form.', 'fluent-booking'),
                    ['received' => $date]
                );
            }
        }

        // Open-ended ranges are allowed.
        $start = $from ? MCPHelper::dayBoundaryToUtc($from, $timezone, false) : '1970-01-01 00:00:00';
        $end   = $to ? MCPHelper::dayBoundaryToUtc($to, $timezone, true) : '2999-12-31 23:59:59';

        if ($end < $start) {
            return MCPHelper::error(
                'invalid_range',
                __('The end of the range is before its start.', 'fluent-booking')
            );
        }

        return [
            'start_date' => $start,
            'end_date'   => $end,
        ];
    }
}
