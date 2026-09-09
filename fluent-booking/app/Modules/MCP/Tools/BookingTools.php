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
 * Reading bookings — the surface an agent spends most of its calls on.
 *
 * Two tools rather than five. Cal.com ships separate tools for a booking's
 * attendees; here that is an `include` value on `get-booking`, because the
 * parameter shape is identical and a separate tool would cost another ~500
 * tokens of permanently-resident schema to save one round-trip nobody makes.
 *
 * Scoping is done in the query, never in the response. A host without
 * read-all-bookings permission gets a query that cannot see other hosts' rows
 * at all, so counts, pagination totals and results are all consistent with what
 * they are allowed to know. Filtering after the fact leaks the totals.
 */
class BookingTools
{
    const DEFAULT_PER_PAGE = 20;

    /**
     * `include` values get-booking accepts. Each one costs a query or an
     * unserialize, which is exactly why none of them are on by default.
     */
    const INCLUDABLE = ['custom_fields', 'attendees', 'hosts', 'activities'];

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
                'description'         => __('Full detail for one booking by id or hash, including attendee contact details, location, status history and cancellation reason. Use include to add form answers, guests, hosts or the activity timeline.', 'fluent-booking'),
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

        // The admin list collapses group bookings on group_id so a ten-attendee
        // group event reads as one booking rather than ten. Default to the same
        // thing: an agent that reports a different number than the operator's
        // screen is worse than useless.
        $grouped = (bool) Arr::get($params, 'group_bookings', true);

        // ...except when searching. GROUP BY keeps one arbitrary row per group,
        // so a term matching two attendees of the same group could drop the
        // exact match in favour of the weaker one beside it.
        $searchCollapsed = $grouped && trim((string) Arr::get($params, 'search', '')) !== '';

        if ($searchCollapsed) {
            $grouped = false;
        }

        $perPage = MCPHelper::perPage(Arr::get($params, 'per_page'), self::DEFAULT_PER_PAGE);
        $page    = max(1, absint(Arr::get($params, 'page', 1)));

        // Count BEFORE the groupBy is applied. COUNT() over a grouped query
        // returns the size of the first group, not the number of groups — which
        // reads as a plausible small number rather than an error, so an agent
        // would report "1 booking" over a page of seventeen and never know.
        // Mirrors SchedulesController::addCountsForFirstPage().
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

        // Unmasked emails are a read-all-bookings privilege. Asking for them
        // without that permission is not an error — the rows are still useful —
        // so the request is downgraded and the response says it was.
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

        return MCPHelper::success(
            BookingProjector::full($booking, $timezone, $include),
            [
                'timezone' => $timezone,
                'scope'    => PermissionGate::currentScope(),
            ]
        );
    }

    /**
     * True when the caller may read this specific booking.
     *
     * Deliberately the SAME test `list-bookings` scopes its query with —
     * `Booking::whereHostAccess()`, i.e. own the calendar or be a host on this
     * booking. It used to fall back to `PermissionManager::canReadCalendar()`,
     * which is a broader question than it sounds: `canReadCalendar()` treats a
     * calendar as readable if the caller is a team member on *any one* of its
     * event types, so on a shared team calendar it returned true for every
     * booking on every other event type too.
     *
     * The effect was a single-record read that was wider than the list beside
     * it, on sequential integer ids, while still stamping the response
     * `scope: own_calendars`. An agent that cannot see a booking in
     * `list-bookings` must not be able to open it by guessing its id.
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

        // Calendar ownership, matching whereHostAccess()'s first branch. Not
        // canReadCalendar(): that also admits shared calendars.
        return (bool) Calendar::where('id', $booking->calendar_id)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Compose the list query from the filters, scoped to what the caller may see.
     *
     * Every filter here delegates to an existing Booking scope, so MCP results
     * and the admin schedules list are produced by the same code — the two
     * cannot drift into disagreeing about what "upcoming" or "cancelled" means.
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

        // Applied before the status/period branch below, because that branch
        // returns early: a search silently dropped whenever `status` was also
        // passed produced a full unfiltered result set that reads exactly like
        // a successful search, which is the one failure mode this module is
        // least able to recover from.
        $search = sanitize_text_field((string) Arr::get($params, 'search', ''));

        if ($search) {
            $query->searchBy($search);
        }

        // A raw status filter and a period bucket answer different questions
        // ("rows whose column says cancelled" vs "rows the admin shows under
        // Cancelled"), so an explicit status list wins rather than being ANDed
        // into a contradiction that silently returns nothing.
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
     * Translate the caller's from/to dates into the UTC window they mean.
     *
     * `start_time` is stored in UTC, but "bookings on 2026-08-24" is a question
     * about a local calendar day. Matching the UTC column against a bare
     * '2026-08-24 00:00:00'–'23:59:59' answers a question up to fourteen hours
     * out of alignment with the one asked: in America/Los_Angeles it silently
     * drops everything from 5pm Monday onward and folds in Sunday evening
     * instead. The dates are therefore read in the same timezone the response
     * renders its *_local times in.
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

        // An open-ended bound is a usable question; fill the other end rather
        // than rejecting it.
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
