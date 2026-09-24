<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\App\Hooks\Handlers\TimeSlotServiceHandler;
use FluentBooking\App\Models\CalendarSlot;

defined('ABSPATH') || exit;

/**
 * Thin wrapper over the slot engine for the MCP tools.
 *
 * An agent must see the same availability as the booking page, so this
 * computes nothing. It calls the same service the public page uses
 * (TimeSlotServiceHandler::initService) and only reshapes and trims the
 * result: slots keyed by date as "HH:MM" strings cost about a tenth of the
 * engine's per-slot arrays in tokens. See docs/mcp-server-spec.md §10.
 */
class SlotResolver
{
    // Hard ceiling on a slot query, in days.
    const MAX_RANGE_DAYS = 62;

    /**
     * Hard ceiling on slots in one response, applied at a whole-date boundary.
     * At ~8.6 bytes per slot, 600 keeps a response near 1,500 tokens (the
     * budget in docs/mcp-server-spec.md §10). Truncation is always reported.
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
            // The slot engine doesn't check status (BookingController does), so
            // a draft event would otherwise show slots the public page refuses.
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

        // getAvailableSpots() only returns the start date's month (the booking
        // page renders one month at a time), so walk the range month by month
        // and merge. MAX_RANGE_DAYS keeps this to at most three calls.
        $cursor = $from;

        while ($cursor <= $to) {
            $spots = $service->getAvailableSpots($cursor . ' 00:00:00', $timezone, $duration, $hostId);

            if (is_wp_error($spots)) {
                // A month past the bookable range says nothing about the others.
                // Only surface the error if no month yields anything.
                $lastError = $spots;
            } else {
                self::mergeMonth((array) $spots, $from, $to, $slots, $spotsRemaining);
            }

            $next = gmdate('Y-m-01', strtotime(gmdate('Y-m-01', strtotime($cursor)) . ' +1 month')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

            // Guard against a non-advancing cursor.
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
     * Apply MAX_SLOTS at a whole-date boundary, since half a day would read
     * as a half-booked day.
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
     * Fold one month's engine output into the result, trimmed to the window.
     * The engine can snap its start back to the 1st, so both bounds are trimmed.
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

                // `remaining` is false on events that don't track spots (most),
                // so only build the map when there is something to put in it.
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
     * Asks the engine directly rather than scanning getSlots() output, because
     * write paths rely on this and need the current state.
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
        // An unparseable date is an error, not a request for the default
        // window. Matches BookingTools::dateRange().
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

        // Y-m-d only. strtotime() would accept "next tuesday" or "5".
        return MCPHelper::isRealDate($date) ? $date : '';
    }
}
