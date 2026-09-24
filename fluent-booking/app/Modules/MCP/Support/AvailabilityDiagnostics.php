<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\SanitizeService;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Why does this event show no slots?
 *
 * This does not recompute availability; a second implementation would drift
 * from TimeSlotService. It takes the engine's real output and attributes each
 * empty date to the first rule that explains it, in the engine's order
 * (TimeSlotService::getDates()):
 *   event active → bookable window → date override closes the day →
 *   weekday has no hours → frequency cap reached → every slot booked →
 *   minimum notice (today only)
 *
 * A date none of these explain is reported as `unexplained`, not guessed at.
 */
class AvailabilityDiagnostics
{
    /**
     * Statuses that block a slot. Mirrors TimeSlotService::getBookedSlots().
     */
    const BLOCKING_STATUSES = ['pending', 'reserved', 'approved', 'scheduled', 'completed'];

    /**
     * @param CalendarSlot $event
     * @param string       $from     'Y-m-d'
     * @param string       $to       'Y-m-d'
     * @param string       $timezone resolved IANA identifier
     * @param int|null     $hostId
     * @return array
     */
    public static function run(CalendarSlot $event, $from, $to, $timezone, $hostId = null)
    {
        $scheduleTimezone = $event->getScheduleTimezone($hostId);

        // Stored hours are UTC; report them in the schedule's timezone, as
        // AvailabilityService does for the admin.
        $weeklySlots = SanitizeService::weeklySchedules(
            (array) $event->getWeeklySlots($hostId),
            'UTC',
            $scheduleTimezone
        );

        $overrides = (array) $event->getDateOverrides($hostId);

        $overrideSlots = isset($overrides[0]) && is_array($overrides[0]) ? $overrides[0] : [];
        $overrideDays  = isset($overrides[1]) && is_array($overrides[1]) ? $overrides[1] : [];

        // Ground truth: what the booking page would actually offer.
        $actual = SlotResolver::getSlots($event, $from, $to, $timezone, null, $hostId);

        $slotsByDate = is_wp_error($actual) ? [] : (array) Arr::get($actual, 'slots', []);

        $counts = [];
        $totalSlots = 0;

        foreach ($slotsByDate as $date => $times) {
            $counts[$date] = count($times);
            $totalSlots += count($times);
        }

        // The per-day cap counts this event type only; "day is full" counts
        // everything on the host's calendar.
        $bookingsByDate     = self::bookingCountsByDate($event, $from, $to, $hostId, $timezone);
        $eventBookingsByDate = self::bookingCountsByDate($event, $from, $to, $hostId, $timezone, true);

        $window = self::bookableWindow($event, $from, $timezone);

        $frequencyCaps = self::capLimits(Arr::get($event->settings, 'booking_frequency', []));

        $checks = self::checks($event, $weeklySlots, $overrideDays, $overrideSlots, $window, $frequencyCaps, $scheduleTimezone, $from, $to, $hostId);

        $emptyDates = self::explainEmptyDates(
            $event,
            $from,
            $to,
            $counts,
            $weeklySlots,
            $overrideDays,
            $overrideSlots,
            $window,
            $frequencyCaps,
            $bookingsByDate,
            $eventBookingsByDate
        );

        return [
            'event'    => [
                'id'                => (int) $event->id,
                'title'             => $event->title,
                'status'            => $event->status,
                'event_type'        => $event->event_type,
                'duration'          => (int) $event->getDuration(),
                'schedule_timezone' => $scheduleTimezone,
            ],
            'window'   => [
                'from'     => $from,
                'to'       => $to,
                'timezone' => $timezone,
            ],
            'outcome'  => [
                'total_slots'    => $totalSlots,
                'dates_with_slots' => count($counts),
                'slots_per_date' => $counts,
            ],
            'checks'      => $checks,
            'empty_dates' => $emptyDates,
        ];
    }

    /**
     * Every rule that can remove slots, with its current value.
     *
     * A check fails only when it alone explains an empty calendar; a buffer
     * that removes some slots still passes.
     *
     * @return array
     */
    private static function checks(CalendarSlot $event, $weeklySlots, $overrideDays, $overrideSlots, $window, $frequencyCaps, $scheduleTimezone, $from, $to, $hostId)
    {
        $checks = [];

        $checks[] = [
            'check'  => 'event_active',
            'passed' => $event->status === 'active',
            'detail' => $event->status === 'active'
                ? __('The event type is active.', 'fluent-booking')
                : sprintf(
                    /* translators: %s: the event type's current status */
                    __('The event type status is "%s". Only active events offer slots.', 'fluent-booking'),
                    $event->status
                ),
        ];

        $enabledDays = self::enabledWeekdays($weeklySlots);

        $checks[] = [
            'check'  => 'weekly_schedule',
            'passed' => !empty($enabledDays),
            'detail' => $enabledDays
                ? sprintf(
                    /* translators: 1: comma-separated weekday names, 2: timezone identifier */
                    __('Available on %1$s (schedule timezone %2$s).', 'fluent-booking'),
                    implode(', ', array_keys($enabledDays)),
                    $scheduleTimezone
                )
                : __('No weekday has any available hours. Every slot is removed by this alone.', 'fluent-booking'),
            'hours'  => $enabledDays,
        ];

        $overridesInRange = self::overridesInRange($overrideDays, $overrideSlots, $from, $to);

        $checks[] = [
            'check'  => 'date_overrides',
            'passed' => true,
            'detail' => $overridesInRange
                ? sprintf(
                    /* translators: %d: number of date overrides falling inside the queried window */
                    __('%d date override(s) fall inside this window.', 'fluent-booking'),
                    count($overridesInRange)
                )
                : __('No date overrides fall inside this window.', 'fluent-booking'),
            'overrides' => $overridesInRange,
        ];

        $windowOverlaps = !($window['max'] && $window['max'] < $from) && !($window['min'] && $window['min'] > $to);

        $checks[] = [
            'check'  => 'bookable_window',
            'passed' => $windowOverlaps,
            'detail' => $windowOverlaps
                ? sprintf(
                    /* translators: 1: earliest bookable date, 2: latest bookable date or "no limit" */
                    __('Bookable from %1$s to %2$s.', 'fluent-booking'),
                    $window['min'],
                    $window['max'] ? $window['max'] : __('no limit', 'fluent-booking')
                )
                : __('The queried window falls entirely outside the event\'s bookable range.', 'fluent-booking'),
            'earliest' => $window['min'],
            'latest'   => $window['max'],
        ];

        $noticeMinutes = (int) round($event->getCutoutSeconds() / MINUTE_IN_SECONDS);

        $checks[] = [
            'check'  => 'minimum_notice',
            'passed' => true,
            'detail' => $noticeMinutes
                ? sprintf(
                    /* translators: %s: minimum notice, already humanised, e.g. "30 days" */
                    __('Bookings must be made at least %s ahead, which removes the soonest dates.', 'fluent-booking'),
                    self::humanizeMinutes($noticeMinutes)
                )
                : __('No minimum notice period is set.', 'fluent-booking'),
            'minutes' => $noticeMinutes,
        ];

        $bufferBefore = (int) Arr::get($event->settings, 'buffer_time_before', 0);
        $bufferAfter  = (int) Arr::get($event->settings, 'buffer_time_after', 0);

        $checks[] = [
            'check'  => 'buffers',
            'passed' => true,
            'detail' => ($bufferBefore || $bufferAfter)
                ? sprintf(
                    /* translators: 1: buffer before in minutes, 2: buffer after in minutes */
                    __('%1$d minutes before and %2$d after each booking are blocked.', 'fluent-booking'),
                    $bufferBefore,
                    $bufferAfter
                )
                : __('No buffer time is configured.', 'fluent-booking'),
            'before_minutes' => $bufferBefore,
            'after_minutes'  => $bufferAfter,
        ];

        $checks[] = [
            'check'  => 'booking_caps',
            'passed' => true,
            'detail' => $frequencyCaps
                ? sprintf(
                    /* translators: %s: comma-separated caps such as "per_day: 2" */
                    __('Booking frequency is capped (%s).', 'fluent-booking'),
                    self::describeCaps($frequencyCaps)
                )
                : __('No booking frequency cap is set.', 'fluent-booking'),
            'limits' => $frequencyCaps,
        ];

        $checks[] = self::remoteCalendarCheck($event, $from, $to, $hostId);

        return $checks;
    }

    /**
     * Attribute each empty date to the first rule that accounts for it.
     *
     * @return array
     */
    private static function explainEmptyDates(CalendarSlot $event, $from, $to, $counts, $weeklySlots, $overrideDays, $overrideSlots, $window, $frequencyCaps, $bookingsByDate, $eventBookingsByDate = [])
    {
        $explained = [];

        $isActive = $event->status === 'active';
        $today    = gmdate('Y-m-d'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $perDayCap = isset($frequencyCaps['per_day']) ? (int) $frequencyCaps['per_day'] : 0;

        $cursor = strtotime($from);
        $end    = strtotime($to);

        while ($cursor <= $end) {
            $date = gmdate('Y-m-d', $cursor); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            $cursor += DAY_IN_SECONDS;

            if (!empty($counts[$date])) {
                continue;
            }

            $day     = strtolower(gmdate('D', strtotime($date))); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            // Host-wide count, and this event type's count for its own cap.
            $booked  = isset($bookingsByDate[$date]) ? (int) $bookingsByDate[$date] : 0;
            $onEvent = isset($eventBookingsByDate[$date]) ? (int) $eventBookingsByDate[$date] : 0;

            $reason = 'unexplained';
            $detail = __('No rule in this report accounts for this date being empty. Check host-level schedules and connected calendars.', 'fluent-booking');

            if (!$isActive) {
                $reason = 'event_inactive';
                $detail = __('The event type is not active.', 'fluent-booking');
            } elseif ($window['min'] && $date < $window['min']) {
                $reason = 'before_bookable_window';
                $detail = sprintf(
                    /* translators: %s: earliest bookable date */
                    __('Earlier than the first bookable date (%s).', 'fluent-booking'),
                    $window['min']
                );
            } elseif ($window['max'] && $date > $window['max']) {
                $reason = 'after_bookable_window';
                $detail = sprintf(
                    /* translators: %s: latest bookable date */
                    __('Later than the last bookable date (%s).', 'fluent-booking'),
                    $window['max']
                );
            } elseif (isset($overrideDays[$date]) && empty($overrideSlots[$date])) {
                $reason = 'date_override_closed';
                $detail = __('A date override marks this day unavailable.', 'fluent-booking');
            } elseif (!self::weekdayHasHours($weeklySlots, $day) && empty($overrideSlots[$date])) {
                $reason = 'no_weekly_hours';
                $detail = sprintf(
                    /* translators: %s: three-letter weekday, e.g. "tue" */
                    __('No hours are configured for %s in the weekly schedule.', 'fluent-booking'),
                    $day
                );
            } elseif ($perDayCap && $onEvent >= $perDayCap) {
                $reason = 'daily_cap_reached';
                $detail = sprintf(
                    /* translators: 1: bookings already on the date for this event type, 2: the configured per-day cap */
                    __('%1$d booking(s) on this event type already, at its per-day cap of %2$d.', 'fluent-booking'),
                    $onEvent,
                    $perDayCap
                );
            } elseif ($booked > 0) {
                $reason = 'fully_booked';
                $detail = sprintf(
                    /* translators: %d: number of bookings already on the date across the host's calendar */
                    __('%d existing booking(s) on this host\'s calendar cover the available hours, once buffers are applied.', 'fluent-booking'),
                    $booked
                );
            } elseif ($date === $today) {
                $reason = 'minimum_notice';
                $detail = __('Today\'s remaining hours fall inside the minimum notice period.', 'fluent-booking');
            }

            $explained[] = [
                'date'    => $date,
                'weekday' => $day,
                'reason'  => $reason,
                'detail'  => $detail,
            ];
        }

        return $explained;
    }

    /**
     * Bookings per local date on this event's hosts, using the engine's host
     * set and blocking statuses.
     *
     * @return array date => count
     */
    private static function bookingCountsByDate(CalendarSlot $event, $from, $to, $hostId, $timezone, $thisEventOnly = false)
    {
        $hostIds = (array) $event->getHostIds($hostId);

        if (!$hostIds) {
            return [];
        }

        $query = Booking::whereHas('hosts', function ($query) use ($hostIds) {
                $query->whereIn('user_id', $hostIds);
            })
            ->whereIn('status', self::BLOCKING_STATUSES)
            // The dates are local to $timezone, so bound the UTC column by
            // those days' UTC start and end.
            ->where('start_time', '>=', MCPHelper::dayBoundaryToUtc($from, $timezone, false))
            ->where('start_time', '<=', MCPHelper::dayBoundaryToUtc($to, $timezone, true));

        if ($thisEventOnly) {
            $query->where('event_id', $event->id);
        }

        $counts = [];

        foreach ($query->get(['id', 'start_time']) as $booking) {
            // Bucket by local date too.
            $date = DateTimeHelper::convertFromUtc($booking->start_time, $timezone, 'Y-m-d');

            $counts[$date] = isset($counts[$date]) ? $counts[$date] + 1 : 1;
        }

        return $counts;
    }

    /**
     * The event's bookable date window, as dates.
     *
     * @return array {min: string, max: string|null}
     */
    private static function bookableWindow(CalendarSlot $event, $from, $timezone)
    {
        $min = $event->getMinBookableDateTime($from . ' 00:00:00', $timezone);
        $max = $event->getMaxLookUpDate();

        return [
            'min' => $min ? gmdate('Y-m-d', strtotime($min)) : '', // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            'max' => $max ? gmdate('Y-m-d', strtotime($max)) : null, // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        ];
    }

    /**
     * Weekdays with hours, as day => "09:00-17:00, 18:00-20:00". Strings keep
     * the payload small; nobody parses these.
     *
     * @return array
     */
    private static function enabledWeekdays($weeklySlots)
    {
        $days = [];

        foreach ($weeklySlots as $day => $schedule) {
            $slots = Arr::get($schedule, 'slots', []);

            if (!Arr::isTrue($schedule, 'enabled') || !$slots) {
                continue;
            }

            $ranges = [];

            foreach ($slots as $slot) {
                $start = Arr::get($slot, 'start');
                $endAt = Arr::get($slot, 'end');

                if ($start && $endAt) {
                    $ranges[] = $start . '-' . $endAt;
                }
            }

            if ($ranges) {
                $days[$day] = implode(', ', $ranges);
            }
        }

        return $days;
    }

    /**
     * Minutes in the largest whole unit, e.g. 43200 → "30 days".
     *
     * @param int $minutes
     * @return string
     */
    private static function humanizeMinutes($minutes)
    {
        $minutes = (int) $minutes;

        $minutesPerDay  = DAY_IN_SECONDS / MINUTE_IN_SECONDS;
        $minutesPerHour = HOUR_IN_SECONDS / MINUTE_IN_SECONDS;

        if ($minutes >= $minutesPerDay && $minutes % $minutesPerDay === 0) {
            $days = $minutes / $minutesPerDay;

            /* translators: %d: number of days */
            return sprintf(_n('%d day', '%d days', $days, 'fluent-booking'), $days);
        }

        if ($minutes >= $minutesPerHour && $minutes % $minutesPerHour === 0) {
            $hours = $minutes / $minutesPerHour;

            /* translators: %d: number of hours */
            return sprintf(_n('%d hour', '%d hours', $hours, 'fluent-booking'), $hours);
        }

        /* translators: %d: number of minutes */
        return sprintf(_n('%d minute', '%d minutes', $minutes, 'fluent-booking'), $minutes);
    }

    /**
     * @return bool
     */
    private static function weekdayHasHours($weeklySlots, $day)
    {
        $schedule = isset($weeklySlots[$day]) ? $weeklySlots[$day] : [];

        return Arr::isTrue($schedule, 'enabled') && !empty(Arr::get($schedule, 'slots', []));
    }

    /**
     * Date overrides inside the window. One without slots closes the day; one
     * with slots replaces that day's hours.
     *
     * @return array
     */
    private static function overridesInRange($overrideDays, $overrideSlots, $from, $to)
    {
        $dates = array_unique(array_merge(array_keys((array) $overrideDays), array_keys((array) $overrideSlots)));

        sort($dates);

        $out = [];

        foreach ($dates as $date) {
            if ($date < $from || $date > $to) {
                continue;
            }

            $out[] = [
                'date'   => $date,
                'effect' => empty($overrideSlots[$date]) ? 'closed' : 'custom_hours',
            ];
        }

        return $out;
    }

    /**
     * @return array unit => value
     */
    private static function capLimits($config)
    {
        if (!is_array($config) || !Arr::isTrue($config, 'enabled')) {
            return [];
        }

        $limits = [];

        foreach ((array) Arr::get($config, 'limits', []) as $limit) {
            $unit  = sanitize_text_field((string) Arr::get($limit, 'unit', ''));
            $value = (int) Arr::get($limit, 'value', 0);

            if ($unit && $value) {
                $limits[$unit] = $value;
            }
        }

        return $limits;
    }

    /**
     * @return string
     */
    private static function describeCaps($caps)
    {
        $parts = [];

        foreach ($caps as $unit => $value) {
            $parts[] = $unit . ': ' . $value;
        }

        return implode(', ', $parts);
    }

    /**
     * External calendar busy time, via the same `fluent_booking/remote_booked_events`
     * filter TimeSlotService::getBookedSlots() uses, so it reports what the engine saw.
     * Counts and providers only; private event titles stay out of the agent's context.
     *
     * @param CalendarSlot $event
     * @param string       $from
     * @param string       $to
     * @param int|null     $hostId
     * @return array
     */
    private static function remoteCalendarCheck(CalendarSlot $event, $from, $to, $hostId)
    {
        if (!defined('FLUENT_BOOKING_PRO_DIR_FILE')) {
            return [
                'check'  => 'remote_calendar_conflicts',
                'passed' => true,
                'detail' => __('External calendar conflict checking requires FluentBooking Pro, so no external calendar is blocking time here.', 'fluent-booking'),
            ];
        }

        $dateRange = [$from . ' 00:00:00', $to . ' 23:59:59'];

        $remote = apply_filters('fluent_booking/remote_booked_events', [], $event, 'UTC', $dateRange, $hostId, false);

        $remote = is_array($remote) ? $remote : [];

        $providers = [];

        foreach ($remote as $slot) {
            $source = Arr::get($slot, 'source');

            if ($source && is_scalar($source)) {
                $providers[(string) $source] = true;
            }
        }

        return [
            'check'  => 'remote_calendar_conflicts',
            'passed' => true,
            'detail' => $remote
                ? sprintf(
                    /* translators: 1: number of busy blocks found on connected calendars, 2: comma-separated provider names */
                    __('%1$d busy block(s) on connected external calendars (%2$s) remove slots in this window. These are invisible in FluentBooking\'s own bookings.', 'fluent-booking'),
                    count($remote),
                    $providers ? implode(', ', array_keys($providers)) : __('unknown provider', 'fluent-booking')
                )
                : __('No external calendar busy time falls inside this window.', 'fluent-booking'),
            'busy_blocks' => count($remote),
            'providers'   => array_keys($providers),
        ];
    }
}
