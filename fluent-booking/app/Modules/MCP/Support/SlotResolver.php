<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\App\Hooks\Handlers\TimeSlotServiceHandler;
use FluentBooking\App\Models\CalendarSlot;

defined('ABSPATH') || exit;

/**
 * Thin wrapper over the slot engine for the MCP tools.
 *
 * Deliberately thin. Availability is the one answer an agent must never get
 * differently from what a visitor sees on the booking page, so this class
 * computes nothing: it resolves the same service the public page resolves
 * (TimeSlotServiceHandler::initService, which returns the round-robin /
 * collective / one-off / multi variants for Pro event types), calls the same
 * method, and then only reshapes and trims the result.
 *
 * The reshaping is the point. getAvailableSpots() returns one array per slot,
 * keyed by full timestamp — a 30-day window on a 30-minute event is ~480 of
 * those, which is roughly 12k tokens of an agent's context for a single call.
 * Keyed by date with bare "HH:MM" strings, the same information is about a
 * tenth of that. See docs/mcp-server-spec.md §10.
 */
class SlotResolver
{
    /**
     * Hard ceiling on a slot query, in days. A caller asking for a year of
     * availability does not want a year of availability in one response; it
     * wants a smaller question it has not thought of yet.
     */
    const MAX_RANGE_DAYS = 62;

    /**
     * Hard ceiling on slots in one response, enforced at a whole-date boundary.
     *
     * A 62-day window on a busy event is ~1,400 slots ≈ 3,500 tokens, which
     * overruns the budget in docs/mcp-server-spec.md §10 by more than double.
     * Measured at ~8.6 bytes per slot, 600 keeps a full response near 1,500
     * tokens. The cap is never silent: the response says it truncated and names
     * the first date it left out, so the agent asks for the next window instead
     * of concluding the calendar ends there.
     */
    const MAX_SLOTS = 600;

    /**
     * Available slots for an event, keyed by date, in the requested timezone.
     *
     * @param CalendarSlot $event
     * @param string       $from     'Y-m-d' in $timezone
     * @param string       $to       'Y-m-d' in $timezone
     * @param string       $timezone resolved IANA identifier
     * @param int|null     $duration minutes; the event default when null
     * @param int|null     $hostId   for team events, restrict to one host
     * @return array|\WP_Error
     */
    public static function getSlots(CalendarSlot $event, $from, $to, $timezone, $duration = null, $hostId = null)
    {
        if ($event->status !== 'active') {
            // The slot engine does not check event status — BookingController
            // does, before it ever calls the engine. Without mirroring that here
            // a draft event reports a full calendar of bookable times that the
            // public page would refuse, and an agent would try to book into it.
            return [
                'slots'  => [],
                'reason' => sprintf(
                    /* translators: %s: the event type's current status */
                    __('The event type is "%s", not active, so it accepts no bookings.', 'fluent-booking'),
                    $event->status
                ),
            ];
        }

        $service = TimeSlotServiceHandler::initService($event->calendar, $event);

        if (is_wp_error($service)) {
            return MCPHelper::error('slot_engine_unavailable', $service->get_error_message());
        }

        $slots          = [];
        $spotsRemaining = [];
        $lastError      = null;

        // The engine is month-bounded: getAvailableSpots() derives its end date
        // via getMaxBookableDateTime(), which clamps to the last day of the
        // START date's month, because the booking page renders one month at a
        // time. A single call for 23 Aug – 5 Sep therefore returns August only
        // and reports nothing for September — which an agent reads as "fully
        // booked" rather than "not asked". So walk the range a month at a time
        // and merge. MAX_RANGE_DAYS keeps this to at most three calls.
        $cursor = $from;

        while ($cursor <= $to) {
            $spots = $service->getAvailableSpots($cursor . ' 00:00:00', $timezone, $duration, $hostId);

            if (is_wp_error($spots)) {
                // One month being unusable (its window is past the event's
                // bookable range) says nothing about the others — keep going and
                // only surface the error if no month yields anything.
                $lastError = $spots;
            } else {
                self::mergeMonth((array) $spots, $from, $to, $slots, $spotsRemaining);
            }

            $next = gmdate('Y-m-01', strtotime(gmdate('Y-m-01', strtotime($cursor)) . ' +1 month')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

            // Guard against a non-advancing cursor: an infinite loop inside a
            // request is worse than a wrong answer.
            if ($next <= $cursor) {
                break;
            }

            $cursor = $next;
        }

        if (!$slots && $lastError) {
            return [
                'slots'  => [],
                'reason' => $lastError->get_error_message(),
            ];
        }

        ksort($slots);

        return self::truncate($slots, $spotsRemaining);
    }

    /**
     * Apply MAX_SLOTS at a whole-date boundary and say so when it bites.
     *
     * Whole dates rather than a flat slot count: half a Tuesday reads as a
     * Tuesday that is half booked, which is a different and wrong answer.
     *
     * @param array $slots
     * @param array $spotsRemaining
     * @return array
     */
    private static function truncate($slots, $spotsRemaining)
    {
        $kept  = [];
        $count = 0;
        $truncatedAt = '';

        foreach ($slots as $date => $times) {
            if ($count && ($count + count($times)) > self::MAX_SLOTS) {
                $truncatedAt = $date;
                break;
            }

            $kept[$date] = $times;
            $count += count($times);
        }

        $result = ['slots' => $kept];

        if ($spotsRemaining) {
            $result['spots_remaining'] = array_intersect_key($spotsRemaining, $kept);
        }

        if ($truncatedAt) {
            $result['truncated'] = true;
            $result['truncated_at'] = $truncatedAt;
            $result['truncation_note'] = sprintf(
                /* translators: 1: the slot ceiling, 2: the first date omitted from the response */
                __('Capped at %1$d slots. Dates from %2$s onward are not included — query again starting there.', 'fluent-booking'),
                self::MAX_SLOTS,
                $truncatedAt
            );
        }

        return $result;
    }

    /**
     * Fold one month's raw engine output into the accumulating result, trimmed
     * to the requested window.
     *
     * getAvailableSpots() can also snap its start back to the first of the month,
     * so the lower bound needs trimming as well as the upper.
     *
     * @param array  $spots           raw engine output
     * @param string $from
     * @param string $to
     * @param array  $slots           accumulator, by reference
     * @param array  $spotsRemaining  accumulator, by reference
     */
    private static function mergeMonth($spots, $from, $to, &$slots, &$spotsRemaining)
    {
        foreach ($spots as $date => $daySlots) {
            if ($date < $from || $date > $to || !$daySlots) {
                continue;
            }

            $times = isset($slots[$date]) ? $slots[$date] : [];

            foreach ($daySlots as $slot) {
                $start = isset($slot['start']) ? $slot['start'] : '';

                if (!$start) {
                    continue;
                }

                $time = gmdate('H:i', strtotime($start)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

                if (!in_array($time, $times, true)) {
                    $times[] = $time;
                }

                // `remaining` is false on every event that does not track spots,
                // which is most of them. Only build the parallel map when there
                // is something in it — an always-present map of nulls is pure
                // context cost.
                if (isset($slot['remaining']) && $slot['remaining'] !== false && $slot['remaining'] !== null) {
                    $spotsRemaining[$date][$time] = (int) $slot['remaining'];
                }
            }

            if ($times) {
                sort($times);
                $slots[$date] = $times;
            }
        }
    }

    /**
     * Is one specific slot bookable right now?
     *
     * Runs the same engine as getSlots() rather than scanning its output, so the
     * answer reflects the state at the moment of asking — this is the check a
     * write path relies on, and a cached list is exactly what it must not trust.
     *
     * @param CalendarSlot $event
     * @param string       $startUtc 'Y-m-d H:i:s' in UTC, already validated by
     *                               MCPHelper::toUtc()
     * @param string       $timezone resolved IANA identifier, for the response
     * @param int|null     $duration minutes
     * @param int|null     $hostId
     * @return array|\WP_Error
     */
    public static function checkSlot(CalendarSlot $event, $startUtc, $timezone, $duration = null, $hostId = null)
    {
        $service = TimeSlotServiceHandler::initService($event->calendar, $event);

        if (is_wp_error($service)) {
            return MCPHelper::error('slot_engine_unavailable', $service->get_error_message());
        }

        $duration = $event->getDuration($duration);

        if ($event->status !== 'active') {
            return array_merge(
                [
                    'available' => false,
                    'duration'  => (int) $duration,
                    'reason'    => sprintf(
                        /* translators: %s: the event type's current status */
                        __('The event type is "%s", not active, so it accepts no bookings.', 'fluent-booking'),
                        $event->status
                    ),
                ],
                MCPHelper::timePair($startUtc, $timezone, 'start')
            );
        }

        $endUtc = gmdate('Y-m-d H:i:s', strtotime($startUtc) + ($duration * 60)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $slot = $service->isSpotAvailable($startUtc, $endUtc, $duration, $hostId);

        return array_merge(
            [
                'available' => (bool) $slot,
                'duration'  => (int) $duration,
            ],
            MCPHelper::timePair($startUtc, $timezone, 'start'),
            MCPHelper::timePair($endUtc, $timezone, 'end')
        );
    }

    /**
     * @param CalendarSlot $event
     * @param mixed        $hostId
     * @return int|null|\WP_Error
     */
    public static function validateHostId(CalendarSlot $event, $hostId)
    {
        return MCPHelper::resolveEventHost($event, $hostId);
    }

    /**
     * Clamp a requested window to something answerable, defaulting to the next
     * 14 days when the caller gives no bounds.
     *
     * @param string $from 'Y-m-d' or empty
     * @param string $to   'Y-m-d' or empty
     * @return array|\WP_Error [$from, $to]
     */
    public static function resolveRange($from, $to)
    {
        // Absent and unparseable are different questions. Both used to
        // normalise to '', so `from: "next tuesday"` fell through to the
        // default window and came back as a confident answer about the wrong
        // fortnight. Matches BookingTools::dateRange().
        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            if (self::suppliedDate($value) && !self::normalizeDate($value)) {
                return MCPHelper::error(
                    'invalid_date',
                    __('from and to must be dates in Y-m-d form.', 'fluent-booking'),
                    ['parameter' => $key, 'received' => is_scalar($value) ? (string) $value : '']
                );
            }
        }

        $from = self::normalizeDate($from);
        $to   = self::normalizeDate($to);

        if (!$from) {
            $from = gmdate('Y-m-d'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        if (!$to) {
            $to = gmdate('Y-m-d', strtotime($from . ' +13 days')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        if ($to < $from) {
            return MCPHelper::error(
                'invalid_range',
                __('The end of the range is before its start.', 'fluent-booking')
            );
        }

        $days = (strtotime($to) - strtotime($from)) / DAY_IN_SECONDS;

        if ($days > self::MAX_RANGE_DAYS) {
            return MCPHelper::error(
                'range_too_large',
                sprintf(
                    /* translators: %d: maximum number of days allowed in one availability query */
                    __('Availability can be queried %d days at a time. Ask for a narrower window.', 'fluent-booking'),
                    self::MAX_RANGE_DAYS
                ),
                ['max_days' => self::MAX_RANGE_DAYS]
            );
        }

        return [$from, $to];
    }

    /**
     * @param mixed $date
     * @return bool whether the caller supplied anything at all
     */
    private static function suppliedDate($date)
    {
        return is_string($date) ? trim($date) !== '' : !empty($date);
    }

    /**
     * @param mixed $date
     * @return string 'Y-m-d', or '' when unparseable
     */
    private static function normalizeDate($date)
    {
        $date = is_string($date) ? trim($date) : '';

        if (!$date) {
            return '';
        }

        // Y-m-d only. strtotime() would also accept "next tuesday", "+1 year"
        // and "5", resolving them against the current instant and answering a
        // question nobody asked.
        return MCPHelper::isRealDate($date) ? $date : '';
    }
}
