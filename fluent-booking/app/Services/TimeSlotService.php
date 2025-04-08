<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\Framework\Support\DateTime;

class TimeSlotService
{
    protected $calendarSlot;

    protected $calendar;

    protected $hostId = null;
    
    public function __construct(Calendar $calendar, CalendarSlot $calendarSlot)
    {
        $this->hostId = null;
        $this->calendar = $calendar;
        $this->calendarSlot = $calendarSlot;
    }

    public function getDates($fromDate = false, $toDate = false, $duration = null, $isDoingBooking = false, $timeZone = 'UTC')
    {
        $duration = $this->calendarSlot->getDuration($duration);

        $fromDate = $fromDate ?: gmdate('Y-m-d'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $toDate = $toDate ?: gmdate('Y-m-t 23:59:59', strtotime($fromDate)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $ranges = $this->getCurrentDateRange($fromDate, $toDate);

        $bookedSlots = $this->getBookedSlots([$fromDate, $toDate], 'UTC', $isDoingBooking);

        $ranges = $this->maybeBookingFrequencyLimitRanges($ranges);
        $ranges = $this->maybeBookingDurationLimitRanges($ranges, $duration);

        $cutOutTime = DateTimeHelper::getTimestamp() + $this->calendarSlot->getCutoutSeconds();

        $maxBookingTime = $this->getMaxBookingTimestamp($fromDate, $toDate, $timeZone);

        $timezoneInfo = $this->getTimezoneInfo();

        $rangedSlots = $this->getRangedValidSlots($ranges, $duration, $bookedSlots, $cutOutTime, $maxBookingTime, $timezoneInfo);

        $rangedSlots = $this->maybeBookingPerDayLimitSlots($rangedSlots, $bookedSlots, $duration);

        return $rangedSlots;
    }

    protected function getRangedValidSlots($ranges, $duration, $bookedSlots, $cutOutTime, $maxBookingTime, $timezoneInfo, $rangedSlots = [], $hostId = null)
    {
        $period = $duration * 60;

        $hostId = $hostId ?: $this->hostId;

        $bufferTime = $this->calendarSlot->getTotalBufferTime() * 60;

        $daySlots = $this->getWeekDaySlots($duration, $hostId);

        $dateOverrides = $this->calendarSlot->getDateOverrides($hostId);

        list($scheduleTimezone, $dstTime) = $timezoneInfo;

        $todayDate = gmdate('Y-m-d'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $lastDate = end($ranges);

        foreach ($ranges as $date) {
            $day = strtolower(gmdate('D', strtotime($date))); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

            $availableSlots = $daySlots[$day] ?? [];

            $availableSlots = $this->maybeDateOverrides($dateOverrides, $availableSlots, $date, $duration);

            if (!$availableSlots) {
                continue;
            }

            $isToday = $date === $todayDate;

            $isLastDay = $date === $lastDate;

            $validSlots = [];

            $validDstDateSlots = [];

            foreach ($availableSlots as $start) {
                $end = gmdate('H:i', strtotime($start) + $period); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                $endDate = $start < $end ? $date : gmdate('Y-m-d', strtotime($date) + 86400); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

                $slot = [
                    'start' => $date . ' ' . $start . ':00',
                    'end'   => $endDate . ' ' . $end . ':00'
                ];

                $slot = $this->maybeDayLightSavingSlot($slot, $dstTime, $scheduleTimezone);

                $slotDate = gmdate('Y-m-d', strtotime($slot['start'])); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

                $currentBookedSlots = $bookedSlots[$slotDate] ?? [];

                if ($isToday && strtotime($slot['start']) < $cutOutTime) {
                    continue;
                }

                if ($isLastDay && strtotime($slot['end']) > $maxBookingTime) {
                    continue;
                }

                $isSlotAvailable = $this->isSlotAvailable($slot, $currentBookedSlots, $bufferTime, $hostId);

                if ($isSlotAvailable) {
                    if ($slotDate != $date) {
                        $validDstDateSlots[] = $slot;
                    } else {
                        $validSlots[] = $slot;
                    }
                }
            }

            if ($validSlots) {
                $currentSlots = $rangedSlots[$date] ?? [];
                $rangedSlots[$date] = $this->mergeAndSortSlots($currentSlots, $validSlots);
            }

            if ($validDstDateSlots) {
                $slotDate = gmdate('Y-m-d', strtotime($validDstDateSlots[0]['start'])); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                $currentSlots = $rangedSlots[$slotDate] ?? [];
                $rangedSlots[$slotDate] = $this->mergeAndSortSlots($currentSlots, $validDstDateSlots);
            }
        }

        return $rangedSlots;
    }

    protected function isSlotAvailable(&$slot, $currentBookedSlots, $bufferTime, $hostId)
    {
        if (!$currentBookedSlots) {
            return true;
        }

        $startTimeStamp = strtotime($slot['start']);
        $endTimeStamp = strtotime($slot['end']);

        foreach ($currentBookedSlots as $bookedSlot) {
            $bookedStart = strtotime($bookedSlot['start']);
            $bookedEnd = strtotime($bookedSlot['end']);

            if (Arr::get($bookedSlot, 'source')) {
                $bookedStart = $bookedStart - $bufferTime;
                $bookedEnd = $bookedEnd + $bufferTime;
            }

            if (
                ($startTimeStamp >= $bookedStart && $startTimeStamp < $bookedEnd) ||
                ($endTimeStamp > $bookedStart && $endTimeStamp <= $bookedEnd) ||
                ($startTimeStamp <= $bookedStart && $endTimeStamp > $bookedStart) ||
                ($startTimeStamp < $bookedEnd && $endTimeStamp >= $bookedEnd)
            ) {
                if (!Arr::get($bookedSlot, 'remaining')) {
                    return false;
                }
                $slot['remaining'] = $bookedSlot['remaining'];
            }
        }

        return true;
    }

    public function isSpotAvailable($fromTime, $toTime, $duration = null, $hostId = null)
    {
        $this->hostId = $hostId;

        $fromTimeStamp = strtotime($fromTime);
        $toTimeStamp = strtotime($toTime);

        $duration = $this->calendarSlot->getDuration($duration);

        list($scheduleTimezone, $dstTime) = $this->getTimezoneInfo();

        $fromStartTime = $this->maybeDayLightSavingTime($fromTime, $dstTime, $scheduleTimezone);
        $toEndTime = $this->maybeDayLightSavingTime($toTime, $dstTime, $scheduleTimezone);

        $fromTime = gmdate('Y-m-d 00:00:00', strtotime($fromStartTime)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $toTime = gmdate('Y-m-d 23:59:59', strtotime($toEndTime)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $slots = $this->getDates($fromTime, $toTime, $duration, true);

        $fromDate = gmdate('Y-m-d', $fromTimeStamp); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $toDate = gmdate('Y-m-d', $toTimeStamp); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $availableSlots = $slots[$fromDate] ?? [];

        if ($fromDate != $toDate) {
            $availableSlots = array_merge($availableSlots, $slots[$toDate] ?? []);
        }

        return $this->isSlotExists($availableSlots, $fromTimeStamp, $toTimeStamp);
    }

    protected function isSlotExists($availableSlots, $fromTimeStamp, $toTimeStamp)
    {
        $left = 0;
        $right = count($availableSlots) - 1;

        while ($left <= $right) {
            $mid = $left + (($right - $left) >> 1);
            $slot = $availableSlots[$mid];

            $slotStartTime = strtotime($slot['start']);
            $slotEndTime = strtotime($slot['end']);

            if ($fromTimeStamp == $slotStartTime && $toTimeStamp == $slotEndTime) {
                return $slot;
            } elseif ($fromTimeStamp > $slotStartTime) {
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        return false;
    }

    protected function getCurrentDateRange($startDate = false, $endDate = false)
    {
        if (!$startDate) {
            $startDate = gmdate('Y-m-d'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        if (!$endDate) {
            $endDate = gmdate('Y-m-t 23:59:59', strtotime($startDate)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        $currentDate = strtotime($startDate);
        $endDate = strtotime($endDate);
        $oneDay = 24 * 60 * 60;

        $dateArray = [];

        while ($currentDate <= $endDate) {
            $dateArray[] = gmdate('Y-m-d', $currentDate); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            $currentDate += $oneDay;
        }

        return $dateArray;
    }

    protected function bookSlot($eventId, $start, $end, $remaining = 0, $source = null)
    {
        return [
            'event_id'  => $eventId,
            'start'     => $start,
            'end'       => $end,
            'remaining' => $remaining,
            'source'    => $source
        ];
    }

    protected function getBookedSlots($dateRange, $toTimeZone = 'UTC', $isDoingBooking = false)
    {
        if ($toTimeZone != 'UTC') {
            $dateRange[0] = DateTimeHelper::convertToUtc($dateRange[0], $toTimeZone);
            $dateRange[1] = DateTimeHelper::convertToUtc($dateRange[1], $toTimeZone);
        }

        $hostIds = $this->calendarSlot->getHostIds($this->hostId);
        $status = ['pending', 'reserved', 'approved', 'scheduled', 'completed'];

        $bookings = Booking::with(['calendar_event'])
            ->whereHas('hosts', function ($query) use ($hostIds) {
                $query->whereIn('user_id', $hostIds);
            })
            ->where(function ($query) use ($dateRange) {
                $query->whereBetween('start_time', $dateRange)
                      ->orWhereBetween('end_time', $dateRange);
            })
            ->orderBy('start_time', 'ASC')
            ->whereIn('status', $status)
            ->get()
            ->groupBy('group_id');

        $maxBooking = $this->calendarSlot->getMaxBookingPerSlot();

        $isGroupBooking = $maxBooking > 1;

        $books = $this->processBookings($bookings, $toTimeZone, $maxBooking, $isGroupBooking);

        $books = apply_filters('fluent_booking/local_booked_events', $books, $this->calendarSlot, $toTimeZone, $dateRange, $isDoingBooking);

        $remoteBookings = apply_filters('fluent_booking/remote_booked_events', [], $this->calendarSlot, $toTimeZone, $dateRange, $this->hostId, $isDoingBooking);

        $books = $this->processRemoteBookings($books, $remoteBookings);

        return apply_filters('fluent_booking/booked_events', $books, $this->calendarSlot, $toTimeZone, $dateRange, $isDoingBooking);
    }

    protected function processBookings($bookings, $toTimeZone, $maxBooking, $isGroupBooking)
    {
        $books = [];
        foreach ($bookings as $booking) {
            $booked = $booking->count();
            $booking = $booking[0];

            $remaining = 0;
            if ($this->calendarSlot->id == $booking->event_id) {
                if ($booking->status == 'reserved') {
                    continue;
                }
                $remaining = max(0, $maxBooking - $booked);
            }

            if ($toTimeZone != 'UTC') {
                $booking->start_time = DateTimeHelper::convertToTimeZone($booking->start_time, 'UTC', $toTimeZone);
                $booking->end_time = DateTimeHelper::convertToTimeZone($booking->end_time, 'UTC', $toTimeZone);
            }

            $date = gmdate('Y-m-d', strtotime($booking->start_time)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

            $books[$date] = $books[$date] ?? [];

            $bufferTime = $booking->calendar_event->getTotalBufferTime();
            if ($bufferTime) {
                $beforeBufferTime = gmdate('Y-m-d H:i:s', strtotime($booking->start_time . " -$bufferTime minutes")); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                $afterBufferTime = gmdate('Y-m-d H:i:s', strtotime($booking->end_time . " +$bufferTime minutes")); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                if ($remaining) {
                    if ($beforeBufferTime < $booking->start_time) {
                        $books[$date][] = $this->bookSlot(null, $beforeBufferTime, $booking->start_time);
                    }
                    if ($afterBufferTime > $booking->end_time) {
                        $books[$date][] = $this->bookSlot(null, $booking->end_time, $afterBufferTime);
                    }
                } else {
                    $booking->start_time = $beforeBufferTime;
                    $booking->end_time = $afterBufferTime;
                }
            }

            $rangedItems = $this->createDateRangeArrayFromSlotConfig([
                'event_id'  => $booking->event_id,
                'start'     => $booking->start_time,
                'end'       => $booking->end_time,
                'remaining' => $remaining
            ]);

            $eventIdAdded = false;
            foreach ($rangedItems as $date => $slot) {
                if ($isGroupBooking && $remaining && $this->calendarSlot->id == $booking->event_id) {
                    if ($eventIdAdded) {
                        $slot['event_id'] = null;
                    }
                    $eventIdAdded = true;
                }
                $books[$date] = $books[$date] ?? [];
                $books[$date][] = $slot;
            }
        }

        return $books;
    }

    protected function processRemoteBookings($books, $remoteBookings)
    {
        if (!$remoteBookings) {
            return $books;
        }

        foreach ($remoteBookings as $slot) {
            $rangedItems = $this->createDateRangeArrayFromSlotConfig([
                'start'   => $slot['start'],
                'end'     => $slot['end'],
                'source'  => $slot['source']
            ]);

            foreach ($rangedItems as $rangedDate => $rangedSlot) {
                $books[$rangedDate] = $books[$rangedDate] ?? [];
            
                if (!$this->isLocalBooking($books[$rangedDate], $rangedSlot)) {
                    $books[$rangedDate][] = $rangedSlot;
                }
            }
        }
        return $books;
    }

    protected function getWeekDaySlots($duration, $hostId = null)
    {
        $period = $duration * 60;

        $hostId = $hostId ?: $this->hostId;

        $interval = $this->calendarSlot->getSlotInterval($duration) * 60;

        $weeklySlots = $this->calendarSlot->getWeeklySlots($hostId);

        $items = $this->getEnabledSlots($weeklySlots);

        // create range of each day slots from $items array above with $period minutes interval
        $formattedSlots = [];
        $days = array_keys($items);
        foreach ($items as $day => &$slots) {
            $daySlots = [];
            foreach ($slots as $slot) {
                $slot['end'] = ($slot['end'] == '00:00') ? '24:00' : $slot['end'];
                $start = strtotime($slot['start']);
                $end = strtotime($slot['end']);

                while ($start + $period <= $end) {
                    $daySlots[] = gmdate('H:i', $start); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $start += $interval;
                }

                if ($slot['end'] == '24:00' && $start < $end) {
                    $daySlots = $this->handleNextDaySlot($daySlots, $items, $start, $end, $interval, $period, $day, $days);
                }
            }
            if ($daySlots) {
                $formattedSlots[$day] = $daySlots;
            }
        }

        return $formattedSlots;
    }

    private function getEnabledSlots($weeklySlots)
    {
        $items = [];

        foreach ($weeklySlots as $weekDay => $weeklySlot) {
            if ($weeklySlot['enabled'] || !empty($weeklySlot['slots'])) {
                $items[$weekDay] = $weeklySlot['slots'];
            }
        }

        return $items;
    }

    protected function handleNextDaySlot($daySlots, &$items, $start, $end, $interval, $period, $day, $days)
    {
        $nextDayIndex = (array_search($day, $days) + 1) % count($items);

        if (isset($days[$nextDayIndex])) {
            $nextDay = $items[$days[$nextDayIndex]];

            if ($nextDay && $nextDay[0]['start'] == '00:00') {
                $nextDayEndTime = strtotime($nextDay[0]['end']) - strtotime($nextDay[0]['start']);
                $reserveTime = $end - $start;

                while ($period - $reserveTime <= $nextDayEndTime) {
                    $startTime = gmdate('H:i', $start); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $nextDayStart = gmdate('H:i', $interval - $reserveTime); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $daySlots[] = $startTime;

                    if ($nextDayStart < $startTime) {
                        $items[$days[$nextDayIndex]][0]['start'] = $nextDayStart;
                        break;
                    }

                    $start += $interval;
                    $reserveTime = $end - $start;
                }
            }
        }

        return $daySlots;
    }

    protected function maybeDateOverrides($dateOverrides, $availableSlots, $date, $duration)
    {
        if (!$dateOverrides) {
            return $availableSlots;
        }

        list($overrideSlots, $overrideDays) = $dateOverrides;

        if ($overrideDays && isset($overrideDays[$date])) {
            $availableSlots = $this->removeOverrideSlots($availableSlots, $overrideDays[$date]);
        }
        
        if ($overrideSlots && isset($overrideSlots[$date])) {
            $flatOverrideSlots = $this->convertSlotSetsToFlat($overrideSlots, $date, $duration);
            $availableSlots = array_merge($availableSlots, $flatOverrideSlots);
            $availableSlots = $this->sortDaySlots($availableSlots);
        }

        return $availableSlots;
    }

    protected function convertSlotSetsToFlat(&$overrideSlots, $date, $duration = null)
    {
        $period = $this->calendarSlot->getDuration($duration) * 60;

        $interval = $this->calendarSlot->getSlotInterval($duration) * 60;

        $formattedSlots = [];

        $slotSets = $overrideSlots[$date];

        foreach ($slotSets as $slot) {
            $slot['end'] = ($slot['end'] == '00:00') ? '24:00' : $slot['end'];
            $start = strtotime($slot['start']);
            $end = strtotime($slot['end']);

            while ($start + $period <= $end) {
                $formattedSlots[] = gmdate('H:i', $start); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                $start += $interval;
            }

            if ($slot['end'] == '24:00' && $start < $end) {
                $formattedSlots = $this->handleNextDayOverrideSlot($formattedSlots, $start, $end, $interval, $period, $overrideSlots, $date);
            }
        }

        return $formattedSlots;
    }

    protected function handleNextDayOverrideSlot($formattedSlots, $start, $end, $interval, $period, &$overrideSlots, $date)
    {
        $nextDayIndex = gmdate('Y-m-d', strtotime($date) + 86400); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        if (isset($overrideSlots[$nextDayIndex])) {
            $nextDay = $overrideSlots[$nextDayIndex];

            if ($nextDay && $nextDay[0]['start'] == '00:00') {
                $nextDayEndTime = strtotime($nextDay[0]['end']) - strtotime($nextDay[0]['start']);
                $reserveTime = $end - $start;

                while ($period - $reserveTime <= $nextDayEndTime) {
                    $startTime = gmdate('H:i', $start); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $nextDayStart = gmdate('H:i', $interval - $reserveTime); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $formattedSlots[] = $startTime;

                    if ($startTime > $nextDayStart) {
                        $overrideSlots[$nextDayIndex][0]['start'] = $nextDayStart;
                        break;
                    }

                    $start += $interval;
                    $reserveTime = $end - $start;
                }
            }
        }

        return $formattedSlots;
    }

    protected function removeOverrideSlots($availableSlots, $overrideDay)
    {
        if (!$availableSlots || !$overrideDay) {
            return $availableSlots;
        }

        $startTime = strtotime($overrideDay['start']);
        $endTime   = strtotime($overrideDay['end']);

        $filteredSlots = array_filter($availableSlots, function ($slot) use ($startTime, $endTime) {
            return strtotime($slot) < $startTime || strtotime($slot) >= $endTime;
        });

        return $filteredSlots;
    }

    public function getAvailableSpots($startDate, $timeZone = 'UTC', $duration = null, $hostId = null)
    {
        $this->hostId = $hostId;

        $event    = $this->calendarSlot;
        $duration = $event->getDuration($duration);

        $startDate = $this->adjustStartDate($startDate, $timeZone);

        $isDisplaySpots = $event->is_display_spots;
        $isMultiGuest   = $event->isMultiGuestEvent();
        $isMultiBooking = $event->isAdditionalGuestEnabled();
        $endDate        = $event->getMaxBookableDateTime($startDate, $timeZone);
        $startDate      = $event->getMinBookableDateTime($startDate, $timeZone);

        $maxBooking = false;
        if ($isMultiGuest && ($isDisplaySpots || $isMultiBooking)) {
            $maxBooking = $event->getMaxBookingPerSlot();
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            return new \WP_Error('invalid_date_range', __('Invalid date range', 'fluent-booking'));
        }

        $slots = $this->getDates($startDate, $endDate, $duration, false, $timeZone);

        return $this->convertSpots($slots, $startDate, 'UTC', $timeZone, $maxBooking);
    }

    protected function adjustStartDate($startDate, $timeZone)
    {
        $requestedDate = $startDate;

        $startDate = DateTimeHelper::convertToUtc($startDate, $timeZone);
        $currentDateTime = gmdate('Y-m-d H:i:s'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        if (strtotime($startDate) < strtotime($currentDateTime)) {
            $startDate = $currentDateTime;
        }

        // Extract month and year from the timezone converted start date and requested date
        list($startDateMonth, $startDateYear) = $this->extractMonthAndYear($startDate);
        list($requestedDateMonth, $requestedDateYear) = $this->extractMonthAndYear($requestedDate);

        if ($startDateYear < $requestedDateYear || $startDateMonth < $requestedDateMonth) {
            $startDate = gmdate('Y-m-01 00:00:00', strtotime($requestedDate)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        return $startDate;
    }

    protected function convertSpots($slots, $startDate, $fromTimeZone = 'UTC', $toTimeZone = 'UTC', $maxBooking = false)
    {
        $minBookableTimestamp = strtotime($startDate);

        $convertedSpots = [];
        foreach ($slots as $spots) {
            foreach ($spots as $spot) {
                $start = $spot['start'];
                $end = $spot['end'];

                if (strtotime($start) < $minBookableTimestamp) {
                    continue;
                }

                if ($fromTimeZone != $toTimeZone) {
                    $start = DateTimeHelper::convertToTimeZone($spot['start'], 'UTC', $toTimeZone);
                    $end = DateTimeHelper::convertToTimeZone($spot['end'], 'UTC', $toTimeZone);
                }

                $spotStartDate = gmdate('Y-m-d', strtotime($start)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

                $convertedSpots[$spotStartDate] = $convertedSpots[$spotStartDate] ?? [];

                $remainingSlots = $maxBooking ? Arr::get($spot, 'remaining', $maxBooking) : false;

                $convertedSpots[$spotStartDate][$start] = [
                    'start'     => $start,
                    'end'       => $end,
                    'remaining' => $remainingSlots,
                ];
            }
        }

        $convertedSpots = array_map(function ($spots) {
            return array_values($spots);
        }, $convertedSpots);

        return $convertedSpots;
    }

    private function extractMonthAndYear($date)
    {
        $month = gmdate('m', strtotime($date)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $year = gmdate('Y', strtotime($date)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        return [$month, $year];
    }

    protected function createDateRangeArrayFromSlotConfig($slotConfig = [])
    {
        if (empty($slotConfig['start']) || empty($slotConfig['end'])) {
            return [];
        }

        $startTime = $slotConfig['start'];
        $endTime = $slotConfig['end'];
        if (gmdate('Ymd', strtotime($startTime)) == gmdate('Ymd', strtotime($endTime))) {
            return [
                gmdate('Y-m-d', strtotime($startTime)) => $this->bookSlot(Arr::get($slotConfig, 'event_id'), $startTime, $endTime, Arr::get($slotConfig, 'remaining'), Arr::get($slotConfig, 'source'))
            ];
        }

        $start = new \DateTime($startTime);
        $end = new \DateTime($endTime);

        // Set the end time to the end of the day if it's set to the beginning of a day
        if ($end->format('H:i:s') === '00:00:00') {
            $end->modify('-1 second'); // This will set the time to 23:59:59 of the previous day
        }

        $interval = new \DateInterval('P1D');
        $dateRange = new \DatePeriod($start, $interval, $end);

        $rangeArray = [];
        foreach ($dateRange as $date) {
            $dateKey = $date->format('Y-m-d');

            if ($date->format('Y-m-d') === $start->format('Y-m-d')) {
                $rangeArray[$dateKey] = $this->bookSlot(Arr::get($slotConfig, 'event_id'), $startTime, $date->format('Y-m-d 23:59:59'), Arr::get($slotConfig, 'remaining'), Arr::get($slotConfig, 'source'));
            } elseif ($date->format('Y-m-d') === $end->format('Y-m-d')) {
                $rangeArray[$dateKey] = $this->bookSlot(Arr::get($slotConfig, 'event_id'), $date->format('Y-m-d 00:00:00'), $endTime, Arr::get($slotConfig, 'remaining'), Arr::get($slotConfig, 'source'));
            } else {
                $rangeArray[$dateKey] = $this->bookSlot(Arr::get($slotConfig, 'event_id'), $date->format('Y-m-d 00:00:00'), $date->format('Y-m-d 23:59:59'), Arr::get($slotConfig, 'remaining'), Arr::get($slotConfig, 'source'));
            }
        }

        // Add the last day if it was not included in the loop
        if ($end->format('Y-m-d') !== $start->format('Y-m-d')) {
            $lastDayKey = $end->format('Y-m-d');
            $rangeArray[$lastDayKey] = $this->bookSlot(Arr::get($slotConfig, 'event_id'), $end->format('Y-m-d 00:00:00'), $endTime, Arr::get($slotConfig, 'remaining'), Arr::get($slotConfig, 'source'));
        }

        return $rangeArray;
    }

    private function maybeBookingFrequencyLimitRanges($ranges)
    {
        if (!$ranges) {
            return $ranges;
        }

        $isBookingFrequencyEnabled = !!Arr::get($this->calendarSlot->settings, 'booking_frequency.enabled');
        if (!$isBookingFrequencyEnabled) {
            return $ranges;
        }

        $keyedFrequenceyLimits = [];
        $frequenceyLimits = Arr::get($this->calendarSlot->settings, 'booking_frequency.limits', []);
        foreach ($frequenceyLimits as $limit) {
            if (!empty($limit['value'])) {
                $keyedFrequenceyLimits[$limit['unit']] = $limit['value'];
            }
        }

        // Per Month Booking Frequency Limit Hanlder
        if (!empty($keyedFrequenceyLimits['per_month'])) {
            $startDate = gmdate('Y-m-01 00:00:00', strtotime(min($ranges)));
            $endDate = gmdate('Y-m-t 23:59:59', strtotime(min($ranges)));

            $monthlyLimit = (int)$keyedFrequenceyLimits['per_month'];

            $monthlyCount = $this->getBookingsTotal($startDate, $endDate);

            if ($monthlyCount >= $monthlyLimit) {
                return [];
            }
        }

        // Per Week Booking Frequency Limit Hanlder
        if (!empty($keyedFrequenceyLimits['per_week'])) {

            if (!$ranges) {
                return [];
            }

            $weeklyLimit = (int)$keyedFrequenceyLimits['per_week'];
            $filledWeeks = $this->getFilledWeeks(min($ranges), max($ranges));
            foreach ($filledWeeks as $filledWeek) {

                $weeklyCount = $this->getBookingsTotal($filledWeek[0] . ' 00:00:00', $filledWeek[6] . ' 23:59:59');

                if ($weeklyCount >= $weeklyLimit) {
                    $ranges = array_filter($ranges, function ($rangeDate) use ($filledWeek) {
                        return !in_array($rangeDate, $filledWeek);
                    });

                    if (!$ranges) {
                        return [];
                    }
                }
            }
        }
        return $ranges;
    }

    private function maybeBookingDurationLimitRanges($ranges, $duration)
    {
        if (!$ranges) {
            return $ranges;
        }

        if (!Arr::get($this->calendarSlot->settings, 'booking_duration.enabled')) {
            return $ranges;
        }

        $limits = Arr::get($this->calendarSlot->settings, 'booking_duration.limits', []);

        $keyedLimits = [];
        foreach ($limits as $limit) {
            if (!empty($limit['value'])) {
                $keyedLimits[$limit['unit']] = (int)$limit['value'];
            }
        }

        // Per Month Booking Frequency Limit Hanlder
        if (!empty($keyedLimits['per_month'])) {
            $startDate = gmdate('Y-m-01 00:00:00', strtotime(min($ranges)));
            $endDate = gmdate('Y-m-t 23:59:59', strtotime(min($ranges)));

            $monthlyDuration = $this->getBookingDurationTotal($startDate, $endDate);

            if ($monthlyDuration + $duration > $keyedLimits['per_month']) {
                $ranges = [];
            }
        }

        // Per Week Booking Frequency Limit Hanlder
        if (!empty($keyedLimits['per_week'])) {
            $weeklyLimit = (int)$keyedLimits['per_week'];
            $filledWeeks = $this->getFilledWeeks(min($ranges), max($ranges));
            foreach ($filledWeeks as $filledWeek) {
                $weeklyDuration = $this->getBookingDurationTotal($filledWeek[0] . ' 00:00:00', $filledWeek[6] . ' 23:59:59');

                if ($weeklyDuration + $duration > $weeklyLimit) {
                    $ranges = array_filter($ranges, function ($rangeDate) use ($filledWeek) {
                        return !in_array($rangeDate, $filledWeek);
                    });

                    if (!$ranges) {
                        return [];
                    }
                }
            }
        }
        return $ranges;
    }

    protected function maybeBookingPerDayLimitSlots($rangesSlots, $bookedSlots, $duration)
    {
        $isDurationEnabled = !!Arr::get($this->calendarSlot->settings, 'booking_duration.enabled');
        
        $isFrequencyEnabled = !!Arr::get($this->calendarSlot->settings, 'booking_frequency.enabled');

        if (!$isDurationEnabled && !$isFrequencyEnabled) {
            return $rangesSlots;
        }

        $hostTimeZone = $this->calendarSlot->getScheduleTimezone($this->hostId);

        $convertedRangesSlots = $this->convertSlotsByTimezone($rangesSlots, 'UTC', $hostTimeZone);

        $convertedBookedSlots = $this->convertSlotsByTimezone($bookedSlots, 'UTC', $hostTimeZone);

        $convertedRangesSlots = $this->maybeBookingDurationDayLimit($convertedRangesSlots, $convertedBookedSlots, $duration, $isDurationEnabled);

        $convertedRangesSlots = $this->maybeBookingFrequencyDayLimit($convertedRangesSlots, $convertedBookedSlots, $isFrequencyEnabled);

        $rangesSlots = $this->convertSlotsByTimezone($convertedRangesSlots, $hostTimeZone, 'UTC');

        return $rangesSlots;
    }

    protected function maybeBookingDurationDayLimit($rangesSlots, $bookedSlots, $duration, $isEnabled)
    {
        if (!$isEnabled) {
            return $rangesSlots;
        }

        $limits = Arr::get($this->calendarSlot->settings, 'booking_duration.limits', []);

        $perDayLimit = null;
        foreach ($limits as $limit) {
            if (Arr::get($limit, 'unit') == 'per_day' && Arr::get($limit, 'value')) {
                $perDayLimit = (int)Arr::get($limit, 'value');
                break;
            }
        }

        if (!$perDayLimit) {
            return $rangesSlots;
        }

        $isMultiSlot = $this->calendarSlot->isMultiGuestEvent();

        foreach ($rangesSlots as $rangeDate => &$slots) {
            if (!isset($bookedSlots[$rangeDate])) {
                continue;
            }

            $dayDuration = array_reduce($bookedSlots[$rangeDate], function ($carry, $slot) {
                if (Arr::get($slot, 'event_id') == $this->calendarSlot->id) {
                    $carry += (int)((strtotime($slot['end']) - strtotime($slot['start'])) / 60);
                }
                return $carry;
            }, 0);

            if ($dayDuration + $duration > $perDayLimit) {
                if ($isMultiSlot) {
                    $slots = array_values(array_filter($slots, function ($slot) {
                        return !empty(Arr::get($slot, 'remaining'));
                    }));
                }
                if (!$isMultiSlot || !count($slots)) {
                    unset($rangesSlots[$rangeDate]);
                }
            }
        }

        return $rangesSlots;
    }

    protected function maybeBookingFrequencyDayLimit($rangesSlots, $bookedSlots, $isEnabled)
    {
        if (!$isEnabled) {
            return $rangesSlots;
        }

        $limits = Arr::get($this->calendarSlot->settings, 'booking_frequency.limits', []);

        $perDayLimit = null;
        foreach ($limits as $limit) {
            if (Arr::get($limit, 'unit') == 'per_day' && Arr::get($limit, 'value')) {
                $perDayLimit = (int)Arr::get($limit, 'value');
                break;
            }
        }

        if (!$perDayLimit) {
            return $rangesSlots;
        }

        $isMultiSlot = $this->calendarSlot->isMultiGuestEvent();

        foreach ($rangesSlots as $rangeDate => &$slots) {
            if (!isset($bookedSlots[$rangeDate])) {
                continue;
            }

            $dayBooked = array_filter($bookedSlots[$rangeDate], function ($slot) {
                return Arr::get($slot, 'event_id') == $this->calendarSlot->id;
            });

            if (count($dayBooked) >= $perDayLimit) {
                if ($isMultiSlot) {
                    $slots = array_values(array_filter($slots, function ($slot) {
                        return !empty(Arr::get($slot, 'remaining'));
                    }));
                }
                if (!$isMultiSlot || !count($slots)) {
                    unset($rangesSlots[$rangeDate]);
                }
            }
        }

        return $rangesSlots;
    }

    protected function convertSlotsByTimezone($slots, $fromTimeZone, $toTimeZone)
    {
        if ($fromTimeZone == $toTimeZone) {
            return $slots;
        }

        $convertedSlots = [];

        foreach ($slots as $spots) {
            foreach ($spots as $spot) {
                $spot['start'] = DateTimeHelper::convertToTimeZone($spot['start'], $fromTimeZone, $toTimeZone);
                $spot['end'] = DateTimeHelper::convertToTimeZone($spot['end'], $fromTimeZone, $toTimeZone);

                $spotDate = gmdate('Y-m-d', strtotime($spot['start']));

                $convertedSlots[$spotDate] = $convertedSlots[$spotDate] ?? [];

                $convertedSlots[$spotDate][] = $spot;
            }
        }

        return $convertedSlots;
    }

    public function getFilledWeeks($from, $to, $weekStart = '')
    {
        $weekStart = $weekStart ? $weekStart : Arr::get(Helper::getGlobalSettings(), 'administration.start_day', 'sun');

        $startDate = new DateTime($from);
        $endDate = new DateTime($to);

        if (strtolower($startDate->format('D')) != $weekStart) {
            $startDate->modify('last ' . $weekStart);
        }

        $weeks = [];

        while ($startDate <= $endDate) {
            // get all days in this week
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = $startDate->format('Y-m-d');
                $startDate->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $weeks;
    }

    protected function getBookingsTotal($start, $end)
    {
        if ($this->calendarSlot->event_type == 'group') {
            return Booking::query()
                ->where('event_id', $this->calendarSlot->id)
                ->whereBetween('start_time', [$start, $end])
                ->whereIn('status', ['scheduled', 'completed'])
                ->groupBy('group_id')
                ->count();
        }

        return Booking::query()
            ->where('event_id', $this->calendarSlot->id)
            ->whereBetween('start_time', [$start, $end])
            ->whereIn('status', ['scheduled', 'completed'])
            ->count();
    }

    protected function getBookingDurationTotal($start, $end)
    {
        if ($this->calendarSlot->event_type == 'group') {
            return Booking::query()
                ->select(['group_id', 'slot_minutes'])
                ->where('event_id', $this->calendarSlot->id)
                ->whereBetween('start_time', [$start, $end])
                ->whereIn('status', ['scheduled', 'completed'])
                ->groupBy('group_id')
                ->get()
                ->sum('slot_minutes');
        }

        return Booking::query()
            ->where('event_id', $this->calendarSlot->id)
            ->whereBetween('start_time', [$start, $end])
            ->whereIn('status', ['scheduled', 'completed'])
            ->sum('slot_minutes');
    }

    protected function getMaxBookingTimestamp($fromDate, $toDate, $timeZone)
    {
        $maxBookingTime = $this->calendarSlot->getMaxBookableDateTime($fromDate, $timeZone, 'Y-m-d H:i:s');

        return strtotime($maxBookingTime);
    }

    protected function getTimezoneInfo($hostId = null)
    {
        $hostId = $hostId ?: $this->hostId;

        $scheduleTimezone = $this->calendarSlot->getScheduleTimezone($hostId);

        $dstTime = DateTimeHelper::getDaylightSavingTime($scheduleTimezone);

        return [$scheduleTimezone, $dstTime];
    }

    protected function maybeDayLightSavingSlot($slot, $dstTime, $scheduleTimezone, $adjustSign = '-')
    {
        if (!$dstTime) {
            return $slot;
        }

        $slot['start'] = $this->maybeDayLightSavingTime($slot['start'], $dstTime, $scheduleTimezone, $adjustSign);
        $slot['end'] = $this->maybeDayLightSavingTime($slot['end'], $dstTime, $scheduleTimezone, $adjustSign);

        return $slot;
    }

    protected function maybeDayLightSavingTime($time, $dstTime, $timezone, $adjustSign = '+')
    {
        $scheduleTime = DateTimeHelper::convertToTimeZone($time, 'UTC', $timezone);
        if (DateTimeHelper::isDaylightSavingActive($scheduleTime, $timezone)) {
            $time = gmdate('Y-m-d H:i:s', strtotime($time . " $adjustSign $dstTime minutes")); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        return $time;
    }

    protected function isLocalBooking($bookings, $slot)
    {
        if (empty($bookings)) {
            return false;
        }

        foreach ($bookings as $book) {
            if ($book['start'] == $slot['start'] && $book['end'] == $slot['end']) {
                return true;
            }
        }
        
        return false;
    }

    protected function mergeAndSortSlots($currentSlots, $validSlots)
    {
        if (!$currentSlots) {
            return $validSlots;
        }

        $mergedSlots = array_merge($currentSlots, $validSlots);

        usort($mergedSlots, function ($a, $b) {
            return strtotime($a['start']) - strtotime($b['start']);
        });

        return $mergedSlots;
    }

    protected function sortDaySlots($daySlots)
    {
        usort($daySlots, function ($a, $b) {
            return strtotime($a) - strtotime($b);
        });

        return $daySlots;
    }
}
