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
 * The highest-volume FluentBooking support question, answered in one call.
 *
 * Design constraint: this class does NOT re-derive availability. Slot maths
 * lives in TimeSlotService and a second implementation would eventually
 * disagree with the first, which for a diagnostic is worse than useless —
 * it would confidently explain an outcome that never happened. So the approach
 * is: take the real engine's output as ground truth, read the configuration
 * through the model's own accessors, and *attribute* each empty date to the
 * first rule that accounts for it, in the order the engine applies them.
 *
 * Attribution order matters and mirrors TimeSlotService::getDates():
 *   event active → bookable window → date override closes the day →
 *   weekday has no hours → frequency cap reached → every slot booked →
 *   minimum notice (today only)
 *
 * A date that survives all of those and still has no slots is reported as
 * `unexplained` rather than guessed at. An honest "I don't know" is worth more
 * to whoever is holding the support ticket than a plausible wrong answer.
 */
class AvailabilityDiagnostics
{
    /**
     * Statuses that occupy a slot. Mirrors TimeSlotService::getBookedSlots() —
     * a booking in any of these states blocks its time.
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

        // Stored hours are UTC. Every check below reports them under the
        // schedule's own timezone, so convert once here rather than labelling
        // raw UTC as local — the same call AvailabilityService makes when it
        // renders a schedule for the admin.
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

        // Two different questions, previously answered by one number:
        //   - "is the per-day cap reached" is per EVENT TYPE (booking_frequency
        //     is an event-type setting), and
        //   - "is the day full" is per HOST, because any booking on any event
        //     occupies the host's time.
        // Using the host-wide count for both reported `daily_cap_reached` on a
        // day where the cap was nowhere near, whenever the host happened to be
        // busy on some other event type.
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
     * The configuration audit: every rule that can remove slots, with the value
     * it is actually set to.
     *
     * `passed` answers "is this rule permitting anything at all", not "did it
     * remove something" — a buffer of 15 minutes passes even though it does
     * remove slots, because it is configured sanely. A failing check is one
     * that on its own explains an empty calendar.
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
            // Host-wide: anything on the host's calendar occupies their time.
            $booked  = isset($bookingsByDate[$date]) ? (int) $bookingsByDate[$date] : 0;
            // This event type only: booking_frequency is an event-type setting,
            // so it must be measured against this event's own bookings.
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
     * Bookings per date that occupy time on this event's hosts.
     *
     * Counted against the same host set and the same blocking statuses the slot
     * engine uses, so "3 bookings" here means the same three the engine removed
     * slots for.
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
            // The window is a LOCAL one — empty_dates walks dates in $timezone —
            // so the UTC column has to be bounded by the UTC instants those
            // local days start and end at, not by the bare date strings.
            ->where('start_time', '>=', MCPHelper::dayBoundaryToUtc($from, $timezone, false))
            ->where('start_time', '<=', MCPHelper::dayBoundaryToUtc($to, $timezone, true));

        if ($thisEventOnly) {
            $query->where('event_id', $event->id);
        }

        $counts = [];

        foreach ($query->get(['id', 'start_time']) as $booking) {
            // Bucketed by the LOCAL date, for the same reason.
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
     * Weekdays with hours, as day => "09:00-17:00, 18:00-20:00".
     *
     * Rendered as strings rather than nested arrays: this is read by a human
     * through an agent, and three keys per slot per day would triple the
     * payload for information nobody acts on programmatically.
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
     * Minutes as something a person reads without arithmetic.
     *
     * "43200 minutes" is technically the notice period and practically useless
     * to whoever is holding the support ticket; "30 days" is the same fact.
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
     * Date overrides inside the queried window, flagged by what they do.
     *
     * An override present in the day-block list with no replacement slots closes
     * the day; one with slots replaces that day's hours.
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
     * External busy time, asked of the engine rather than inferred.
     *
     * `fluent_booking/remote_booked_events` is the exact filter
     * TimeSlotService::getBookedSlots() applies to pull Google/Outlook/Apple/
     * CalDAV busy blocks into the slot calculation, so running it here reports
     * what the engine actually saw — not what a guess at where connections are
     * stored would suggest. This is the usual answer when every other check
     * passes and slots are still missing.
     *
     * Counts and providers only. Pulling the titles of a host's private calendar
     * events into an agent's context is not this tool's job.
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
