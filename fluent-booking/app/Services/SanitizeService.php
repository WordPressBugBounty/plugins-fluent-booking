<?php

namespace FluentBooking\App\Services;

use FluentBooking\Framework\Support\Arr;

class SanitizeService
{
    public static function weeklySchedules($schedules, $fromTimeZone = '', $toTimeZone = false, $fromUser = true)
    {
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

            foreach ($schedule['slots'] as $index => $slot) {
                if ($fromUser) {
                    $slot['start'] = sanitize_text_field($slot['start']);
                    $slot['end'] = sanitize_text_field($slot['end']);
                }

                if (!$slot['start'] || !$slot['end']) {
                    unset($schedule['slots'][$index]);
                    continue;
                }

                if ($toTimeZone && $fromTimeZone) {
                    $slot['start'] = DateTimeHelper::convertToTimeZone($slot['start'], $fromTimeZone, $toTimeZone, 'H:i', $dateWithoutDST);
                    $slot['end'] = DateTimeHelper::convertToTimeZone($slot['end'], $fromTimeZone, $toTimeZone, 'H:i', $dateWithoutDST);
                }

                $schedule['slots'][$index] = $slot;
            }

            $schedule['slots'] = array_values($schedule['slots']);
        }

        return $schedules;
    }

    public static function slotDateOverrides($overrides, $fromTimeZone = '', $toTimeZone = false, $event = false)
    {
        $todayTimeStamp = strtotime(gmdate('Y-m-d')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $validOverrides = [];
        $updatedOverRides = [];

        $isSkipped = false;

        $dstTimeZone = $fromTimeZone == 'UTC' ? $toTimeZone : $fromTimeZone;

        $dateWithoutDST = DateTimeHelper::getDateWithoutDST($dstTimeZone);

        foreach ($overrides as $date => $slots) {
            if (strtotime($date) < $todayTimeStamp) {
                $isSkipped = true;
                continue;
            }

            $utcSlots = [];
            foreach ($slots as $index => $slot) {
                $slot['start'] = sanitize_text_field($slot['start']);
                $slot['end'] = sanitize_text_field($slot['end']);

                if (empty($slot['start']) || empty($slot['end'])) {
                    unset($slots[$index]);
                    continue;
                }

                $utcSlots[] = $slot;
                if ($toTimeZone && $fromTimeZone && $toTimeZone != $fromTimeZone) {
                    $slot['start'] = DateTimeHelper::convertToTimeZone($slot['start'], $fromTimeZone, $toTimeZone, 'H:i', $dateWithoutDST);
                    $slot['end'] = DateTimeHelper::convertToTimeZone($slot['end'], $fromTimeZone, $toTimeZone, 'H:i', $dateWithoutDST);
                }

                $slots[$index] = $slot;
            }

            if ($utcSlots) {
                $updatedOverRides[$date] = $utcSlots;
            }

            if ($slots) {
                $validOverrides[$date] = array_values($slots);
            }
        }

        if ($isSkipped && $fromTimeZone == 'UTC' && $event) {
            $event->settings = ['date_overrides' => $updatedOverRides];
            $event->save();
        }

        return $validOverrides;
    }


    public static function rangeDateBetween($range)
    {
        $range = array_filter($range);
        if (!$range || count($range) != 2) {
            return ['', ''];
        }

        $range = array_values($range);

        $range[0] = gmdate('Y-m-d H:i:s', strtotime(sanitize_text_field($range[0]))); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $range[1] = gmdate('Y-m-d H:i:s', strtotime(sanitize_text_field($range[1]))); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        return $range;
    }

    public static function scheduleConditions($conditions)
    {
        if (!$conditions) {
            return [
                'value' => 4,
                'unit'  => 'hours'
            ];
        }

        return [
            'value' => (int)Arr::get($conditions, 'value', 4),
            'unit'  => sanitize_text_field(Arr::get($conditions, 'unit', 'hours'))
        ];
    }

    public static function checkCollection($value, $collection, $default = '')
    {
        if (in_array($value, $collection, true)) {
            return $value;
        }
        return $default;
    }

    public static function locationSettings($locations)
    {
        $sanitizedLocations = [];

        foreach ($locations as $locationIndex => $location) {
            $locationType = $location['type'];
            $locationTitle = sanitize_text_field(Arr::get($location, 'title'));

            if (empty($locationTitle) && $locationType == 'ms_teams') {
                $locationTitle = 'MS Teams';
            }

            $sanitizedLocation = [
                'type'               => sanitize_text_field($location['type']),
                'title'              => $locationTitle,
                'display_on_booking' => sanitize_text_field(Arr::get($location, 'display_on_booking'))
            ];

            if ($locationType == 'online_meeting') {
                $sanitizedLocation['meeting_link'] = sanitize_url(Arr::get($location, 'meeting_link'));
            } elseif ($locationType == 'custom' || $locationType == 'in_person_organizer') {
                $sanitizedLocation['description'] = sanitize_textarea_field(Arr::get($location, 'description'));
            } elseif ($locationType == 'phone_organizer') {
                $sanitizedLocation['host_phone_number'] = sanitize_text_field(Arr::get($location, 'host_phone_number'));
            }

            $sanitizedLocations[] = $sanitizedLocation;
        }

        return $sanitizedLocations;
    }
}
