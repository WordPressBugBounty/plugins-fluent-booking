<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Availability;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\Framework\Support\Arr;

class AvailabilityService
{
    public static function maybeCreateAvailability($calendar, $scheduleData)
    {
        $userId = $calendar->user_id;

        $availability = self::getDefaultSchedule($userId);

        if ($availability) {
            return $availability;
        }

        $timezone = $calendar->author_timezone;

        $defaultSchedule = self::createScheduleSchema($userId, 'Weekly Hours', true, $timezone, 'UTC', $scheduleData);

        return Availability::create($defaultSchedule);
    }

    public static function availabilitySchedules()
    {
        $permissions = ['read_and_use_other_availabilities', 'manage_other_availabilities', 'read_other_calendars', 'manage_other_calendars'];

        $availabilities = Availability::when(
            !PermissionManager::userCan($permissions),
            function ($query) {
                return $query->where('object_id', get_current_user_id());
            })->get()->toArray();

        $formattedSchedules = [];
        foreach ($availabilities as $availability) {
            $toTimezone = Arr::get($availability, 'value.timezone', 'UTC');
            $formattedSchedules[] = [
                'id'        => Arr::get($availability, 'id'),
                'object_id' => Arr::get($availability, 'object_id'),
                'title'     => Arr::get($availability, 'key'),
                'settings'  => [
                    'default'          => Arr::isTrue($availability, 'value.default'),
                    'timezone'         => $toTimezone,
                    'date_overrides'   => SanitizeService::slotDateOverrides(Arr::get($availability, 'value.date_overrides', []), 'UTC', $toTimezone),
                    'weekly_schedules' => SanitizeService::weeklySchedules(Arr::get($availability, 'value.weekly_schedules', []), 'UTC', $toTimezone),
                ]
            ];
        }
        return $formattedSchedules;
    }

    public static function getFormattedSchedule($schedule)
    {
        $timezone = Arr::get($schedule, 'value.timezone', 'UTC');

        $author = $schedule->getAuthor();

        return [
            'id'          => $schedule->id,
            'host_name'   => $author['name'],
            'host_avatar' => $author['avatar'],
            'title'       => $schedule->key,
            'created_at'  => $schedule->created_at->format('Y-m-d H:i:s'),
            'usage_count' => self::getAvailabilityUsageCount($schedule->id),
            'settings'    => [
                'default'          => Arr::isTrue($schedule, 'value.default'),
                'timezone'         => $timezone,
                'date_overrides'   => SanitizeService::slotDateOverrides(Arr::get($schedule, 'value.date_overrides', []), 'UTC', $timezone),
                'weekly_schedules' => SanitizeService::weeklySchedules(Arr::get($schedule, 'value.weekly_schedules'), 'UTC', $timezone)
            ],
        ];
    }

    public static function isTitleAlreadyExist($title, $userId, $currentTitle = '')
    {
        if ($title == $currentTitle) {
            return false;
        }

        $scheduleTitles = Availability::where('object_id', $userId)
            ->pluck('key')
            ->toArray();

        if (in_array($title, $scheduleTitles)) {
            return true;
        }

        return false;
    }

    public static function updateOtherDefaultStatus($schedule, $id)
    {
        $schedules = Availability::where('object_id', $schedule->object_id)
            ->where('id', '!=', $id)
            ->get();

        foreach ($schedules as $schedule) {
            $schedule = Availability::findOrFail($schedule->id);
            $updatedSettings = [
                'default'          => false,
                'timezone'         => Arr::get($schedule, 'value.timezone', 'UTC'),
                'date_overrides'   => Arr::get($schedule, 'value.data_overrides', []),
                'weekly_schedules' => Arr::get($schedule, 'value.weekly_schedules'),
            ];

            $schedule->value = $updatedSettings;
            $schedule->save();
        }
    }

    public static function getScheduleOptions()
    {
        $permissions = ['read_and_use_other_availabilities', 'manage_other_availabilities', 'read_other_calendars', 'manage_other_calendars'];

        $availabilities = Availability::when(
            !PermissionManager::userCan($permissions),
            function ($query) {
                return $query->where('object_id', get_current_user_id());
            })->get();

        $scheduleOptions = [];
        foreach ($availabilities as $availability) {
            $calendar = Calendar::with(['user'])
                ->where('type', 'simple')
                ->where('user_id', $availability->object_id)
                ->first();

            if ($calendar) {
                $hostName = $calendar->user->full_name;
                if ($calendar->user_id == get_current_user_id()) {
                    $hostName = __('My Schedules', 'fluent-booking');
                }
            } else {
                $hostName = __('Deleted User', 'fluent-booking');
            }
            
            $scheduleOptions[$hostName] = $scheduleOptions[$hostName] ?? [];

            $scheduleOptions[$hostName][] = [
                'label'   => Arr::get($availability, 'key'),
                'value'   => Arr::get($availability, 'id'),
                'default' => Arr::isTrue($availability, 'value.default')
            ];
        }

        return apply_filters('fluent_booking/availability_schedule_options', $scheduleOptions);
    }

    public static function getDefaultSchedule($userId)
    {
        return Availability::where('object_id', $userId)
            ->get()
            ->first(function ($schedule) {
                return Arr::isTrue($schedule, 'value.default');
            });
    }

    public static function createScheduleSchema($userId, $title, $default, $fromTimezone, $toTimezone = 'UTC', $weeklySchedule = [], $dateOverrides = [])
    {
        $weeklySchedule = $weeklySchedule ? $weeklySchedule : Helper::getWeeklyScheduleSchema();

        $dateOverrides = $dateOverrides ? SanitizeService::slotDateOverrides($dateOverrides, $fromTimezone, $toTimezone) : [];

        $defaultSchedule = [
            'object_id' => $userId,
            'key'       => sanitize_text_field($title),
            'value'     => [
                'default'          => (bool)$default,
                'timezone'         => sanitize_text_field($fromTimezone),
                'date_overrides'   => $dateOverrides,
                'weekly_schedules' => SanitizeService::weeklySchedules($weeklySchedule, $fromTimezone, $toTimezone),
            ]
        ];
        return $defaultSchedule;
    }

    public static function getAvailabilityUsageCount($scheduleId)
    {
        return CalendarSlot::where('availability_type', 'existing_schedule')
            ->where('availability_id', $scheduleId)
            ->count();
    }

    public static function getUtcWeeklySchedules($schedules, $fromTimeZone = false, $toTimeZone = 'UTC')
    {
        if (!$schedules) {
            return [];
        }

        if (!$fromTimeZone || !$toTimeZone || $fromTimeZone == $toTimeZone) {
            return $schedules;
        }

        $weekDays = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        $dstTimeZone = $fromTimeZone == 'UTC' ? $toTimeZone : $fromTimeZone;

        $dateWithoutDST = DateTimeHelper::getDateWithoutDST($dstTimeZone);

        foreach ($schedules as $day => &$schedule) {
            $schedule['enabled'] = Arr::isTrue($schedule, 'enabled');
            if (!$schedule['enabled'] || empty($schedule['slots'])) {
                $schedule['slots'] = [];
                $schedule['enabled'] = false;
                continue;
            }
            
            $schedule['enabled'] = true;

            $nextDay = null;
            $nextDayIndex = 0;
            $dayIndex = array_search($day, $weekDays);
            foreach ($schedule['slots'] as $index => $slot) {
                if (!$slot['start'] || !$slot['end']) {
                    unset($schedule['slots'][$index]);
                    continue;
                }

                if (!empty(Arr::get($slot, 'type', ''))) {
                    continue;
                }
                
                $dayDiff = DateTimeHelper::getDayDifference($slot['start'], $fromTimeZone, $toTimeZone, $dateWithoutDST);
                $slot['start'] = DateTimeHelper::convertToTimeZone($slot['start'], $fromTimeZone, $toTimeZone, 'H:i', $dateWithoutDST);
                $slot['end'] = DateTimeHelper::convertToTimeZone($slot['end'], $fromTimeZone, $toTimeZone, 'H:i', $dateWithoutDST);

                if ($nextDayIndex) {
                    array_splice($schedules[$nextDay]['slots'], $nextDayIndex, 0, [[
                        'start' => $slot['start'],
                        'end'   => $slot['end'],
                        'type'  => 'next_day'
                    ]]);
                    unset($schedule['slots'][$index]);
                    $nextDayIndex++;
                    continue;
                }

                if ($dayDiff > 0) {
                    $nextDayIndex = 1;
                    $nextDay = $weekDays[($dayIndex + $dayDiff) % 7];
                    $schedules[$nextDay]['enabled'] = true;
                    if (strtotime($slot['start']) < strtotime($slot['end'])) {
                        array_unshift($schedules[$nextDay]['slots'], [
                            'start' => $slot['start'],
                            'end'   => $slot['end'],
                            'type'  => 'next_day'
                        ]);
                        unset($schedule['slots'][$index]);
                        continue;
                    }
                    if ($slot['end'] != '00:00') {
                        array_unshift($schedules[$nextDay]['slots'], [
                            'start' => '00:00',
                            'end'   => $slot['end'],
                            'type'  => 'next_day'
                        ]);
                        $slot['end'] = '00:00';
                    }
                } else if ($dayDiff < 0) {
                    $prevDay = $weekDays[($dayIndex + $dayDiff + 7) % 7];
                    $schedules[$prevDay]['enabled'] = true;
                    if (strtotime($slot['start']) < strtotime($slot['end'])) {
                        array_push($schedules[$prevDay]['slots'], [
                            'start' => $slot['start'],
                            'end'   => $slot['end'],
                            'type'  => 'prev_day'
                        ]);
                        unset($schedule['slots'][$index]);
                        continue;
                    }
                    if ($slot['start'] != '00:00') {
                        array_push($schedules[$prevDay]['slots'], [
                            'start' => $slot['start'],
                            'end'   => '00:00',
                            'type'  => 'prev_day'
                        ]);
                        $slot['start'] = '00:00';
                    }
                } else {
                    if (strtotime($slot['start']) > strtotime($slot['end'])) {
                        if ($slot['end'] != '00:00') {
                            $nextDayIndex = 1;
                            $nextDay = $weekDays[($dayIndex + 1) % 7];
                            $schedules[$nextDay]['enabled'] = true;
                            array_unshift($schedules[$nextDay]['slots'], [
                                'start' => '00:00',
                                'end'   => $slot['end'],
                                'type'  => 'next_day'
                            ]);
                            $slot['end'] = '00:00';
                        }
                    }
                }

                if ($slot['start'] == '00:00' && $slot['end'] == '00:00') {
                    unset($schedule['slots'][$index]);
                    continue;
                }

                $schedule['slots'][$index] = $slot;
            }

            $schedule['slots'] = array_values($schedule['slots']);
        }

        return $schedules;
    }

    public static function getUtcDateOverrides($overrides, $fromTimeZone = false, $toTimeZone = 'UTC')
    {
        if (!$overrides) {
            return [];
        }

        if (!$fromTimeZone || !$toTimeZone) {
            return $overrides;
        }

        $dstTimeZone = $fromTimeZone == 'UTC' ? $toTimeZone : $fromTimeZone;

        $dateWithoutDST = DateTimeHelper::getDateWithoutDST($dstTimeZone);

        $todayTimeStamp = strtotime(gmdate('Y-m-d')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $validOverrides = [];
        foreach ($overrides as $date => $slots) {
            $dateTimestamp = strtotime($date);
            if ($dateTimestamp < $todayTimeStamp) {
                continue;
            }

            $nextDayIndex = 0;
            foreach ($slots as $index => $slot) {
                if (empty($slot['start']) || empty($slot['end']) || $slot['start'] == $slot['end']) {
                    unset($slots[$index]);
                    continue;
                }

                $dayDiff = DateTimeHelper::getDayDifference($slot['start'], $fromTimeZone, $toTimeZone, $dateWithoutDST);
                $slot['start'] = DateTimeHelper::convertToTimeZone($slot['start'], $fromTimeZone, $toTimeZone, 'H:i', $dateWithoutDST);
                $slot['end'] = DateTimeHelper::convertToTimeZone($slot['end'], $fromTimeZone, $toTimeZone, 'H:i', $dateWithoutDST);

                if ($nextDayIndex) {
                    $nextDay = gmdate('Y-m-d', ($dateTimestamp + 86400 * $dayDiff)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    array_splice($validOverrides[$nextDay], $nextDayIndex, 0, [[
                        'start' => $slot['start'],
                        'end'   => $slot['end']
                    ]]);
                    unset($slots[$index]);
                    $nextDayIndex++;
                    continue;
                }

                if ($dayDiff > 0) {
                    $nextDayIndex = 1;
                    $nextDay = gmdate('Y-m-d', ($dateTimestamp + 86400 * $dayDiff)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $validOverrides[$nextDay] = $validOverrides[$nextDay] ?? [];
                    if (strtotime($slot['start']) < strtotime($slot['end'])) {
                        array_unshift($validOverrides[$nextDay], [
                            'start' => $slot['start'],
                            'end'   => $slot['end']
                        ]);
                        unset($slots[$index]);
                        continue;
                    }
                    if ($slot['end'] != '00:00') {
                        array_unshift($validOverrides[$nextDay], [
                            'start' => '00:00',
                            'end'   => $slot['end']
                        ]);
                        $slot['end'] = '00:00';
                    }
                } else if ($dayDiff < 0) {
                    $prevDay = gmdate('Y-m-d', ($dateTimestamp - 86400 * abs($dayDiff))); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $validOverrides[$prevDay] = $validOverrides[$prevDay] ?? [];
                    if (strtotime($slot['start']) < strtotime($slot['end'])) {
                        array_push($validOverrides[$prevDay], [
                            'start' => $slot['start'],
                            'end'   => $slot['end']
                        ]);
                        unset($slots[$index]);
                        continue;
                    }
                    if ($slot['start'] != '00:00') {
                        array_push($validOverrides[$prevDay], [
                            'start' => $slot['start'],
                            'end'   => '00:00'
                        ]);
                        $slot['start'] = '00:00';
                    }
                } else {
                    if (strtotime($slot['start']) > strtotime($slot['end'])) {
                        if ($slot['end'] != '00:00') {
                            $nextDayIndex = 1;
                            $nextDay = gmdate('Y-m-d', ($dateTimestamp + 86400)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                            $validOverrides[$nextDay] = $validOverrides[$nextDay] ?? [];
                            array_unshift($validOverrides[$nextDay], [
                                'start' => '00:00',
                                'end'   => $slot['end']
                            ]);
                            $slot['end'] = '00:00';
                        }
                    }
                }

                if ($slot['start'] == '00:00' && $slot['end'] == '00:00') {
                    unset($slots[$index]);
                    continue;
                }
                $slots[$index] = $slot;
            }

            if ($slots) {
                $validOverrides[$date] = $validOverrides[$date] ?? [];
                $validOverrides[$date] = array_merge($validOverrides[$date], $slots);
            }
        }

        return $validOverrides;
    }

    public static function getDateOverrideDays($overrides, $fromTimeZone, $toTimeZone = 'UTC')
    {
        if (!$overrides || !$fromTimeZone || !$toTimeZone) {
            return [];
        }

        $overrideDays = [];
        foreach ($overrides as $date => $slots) {
            $dayStart = gmdate('Y-m-d 00:00:00', strtotime($date));
            $dayEnd   = gmdate('Y-m-d 24:00:00', strtotime($date));

            $convertedStart = DateTimeHelper::convertToTimeZone($dayStart, $fromTimeZone, $toTimeZone);
            $convertedEnd   = DateTimeHelper::convertToTimeZone($dayEnd, $fromTimeZone, $toTimeZone);

            $startDate = gmdate('Y-m-d', strtotime($convertedStart));
            $endDate   = gmdate('Y-m-d', strtotime($convertedEnd));

            if (strtotime($dayStart) == strtotime($convertedStart)) {
                $overrideDays[$startDate] = [
                    'start' => '00:00',
                    'end'   => '24:00'
                ];
                continue;
            }

            if (isset($overrideDays[$startDate])) {
                $overrideDays[$startDate] = [
                    'start' =>'00:00',
                    'end'   =>'24:00'
                ];
            } else {
                $overrideDays[$startDate] = [
                    'start' => gmdate('H:i', strtotime($convertedStart)),
                    'end'   => '24:00'
                ];
            }

            if (isset($overrideDays[$endDate])) {
                $overrideDays[$endDate] = [
                    'start' => '00:00',
                    'end'   => '24:00'
                ];
            } else {
                $overrideDays[$endDate] = [
                    'start' => '00:00',
                    'end'   => gmdate('H:i', strtotime($convertedEnd))
                ];
            }
        }
        return $overrideDays;
    }
}
