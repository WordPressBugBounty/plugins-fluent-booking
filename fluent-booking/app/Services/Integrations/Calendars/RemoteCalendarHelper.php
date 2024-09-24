<?php

namespace FluentBooking\App\Services\Integrations\Calendars;

use FluentBooking\App\App;
use FluentBooking\App\Models\Meta;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\Helper;
use FluentBooking\Framework\Support\Arr;

class RemoteCalendarHelper
{
    public static function getUserRemoteCreatableCalendarSettings($userId)
    {
        $exist = Meta::where('object_type', '_calendar_user_meta')
            ->where('object_id', $userId)
            ->where('key', 'create_remote_calendar')
            ->first();

        if ($exist) {
            $value = $exist->value;
            if (!is_array($value)) {
                return [];
            }
            return $value;
        }

        return [];
    }

    public static function updateUserRemoteCreatableCalendarSettings($userId, $settings)
    {
        if (!$settings) {
            $settings = [];
        }

        $exist = Meta::where('object_type', '_calendar_user_meta')
            ->where('object_id', $userId)
            ->where('key', 'create_remote_calendar')
            ->first();

        if ($exist) {
            $exist->value = $settings;
            $exist->save();
            return $exist;
        }

        return Meta::create([
            'object_type' => '_calendar_user_meta',
            'object_id'   => $userId,
            'key'         => 'create_remote_calendar',
            'value'       => $settings
        ]);
    }

    public static function getRemoteCalendarConfig($userId)
    {
        $settings = self::getUserRemoteCreatableCalendarSettings($userId);

        if (!$settings) {
            return null;
        }

        $idConfig = Arr::get($settings, 'id');
        $driver = Arr::get($settings, 'driver');

        if (!$idConfig || !$driver) {
            return null;
        }

        $idArr = explode('__||__', $idConfig);

        if (count($idArr) < 2) {
            return null;
        }

        $metaId = (int)array_shift($idArr);

        if (!$metaId) {
            return null;
        }

        $remoteCalendarId = implode('__||__', $idArr);

        return [
            'db_id'              => $metaId,
            'remote_calendar_id' => $remoteCalendarId,
            'driver'             => $driver
        ];
    }

    public static function showGeneralError($data = [])
    {
        $defaults = [
            'title'    => __('Unknown error', 'fluent-booking'),
            'body'     => __('Something went wrong. Please try again later.', 'fluent-booking'),
            'btn_url'  => Helper::getAppBaseUrl(),
            'btn_text' => __('Back to dashboard', 'fluent-booking')
        ];

        $data = array_merge($defaults, $data);

        $app = App::getInstance();

        header('Content-Type: text/html; charset=utf-8');
        $app->view->render('admin.general_error', $data);
        exit();
    }

    public static function getRruleDates($rules, $sampleRange, $minDate, $maxDate, $args = [], $timezone = 'UTC')
    {
        try {
            $durationSeconds = strtotime($sampleRange[1]) - strtotime($sampleRange[0]);
            $timezone = DateTimeHelper::getValidatedTimeZone($timezone);
            // Define the time range you're interested in
            $minDate = new \DateTime($minDate, new \DateTimeZone($timezone));
            $maxDate = new \DateTime($maxDate, new \DateTimeZone($timezone));
            $dtStart = new \DateTime($sampleRange[0], new \DateTimeZone($timezone));

            // Create a rule set
            $rset = new \FluentBooking\App\Services\Libs\RRule\RSet();

            foreach ($rules as $recurrence) {
                if (strpos($recurrence, 'RRULE') === 0) {
                    // Create the RRule object with the actual DTSTART
                    $rrule = new \FluentBooking\App\Services\Libs\RRule\RRule($recurrence, $dtStart);
                    $rset->addRRule($rrule);
                } elseif (strpos($recurrence, 'EXDATE') === 0) {
                    $exDates = \FluentBooking\App\Services\Libs\RRule\RfcParser::parseExDate($recurrence);
                    foreach ($exDates as $exDate) {
                        $rset->addExDate($exDate);
                    }
                }
            }

            $occurrences = $rset->getOccurrencesBetween($minDate, $maxDate);

            return array_map(function ($date) use ($durationSeconds, $args) {

                $start = $date->format('Y-m-d H:i:s');
                $endDateTime = gmdate('Y-m-d H:i:s', strtotime($start) + $durationSeconds); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

                if (!$args) {
                    return [
                        'start' => $start,
                        'end'   => $endDateTime
                    ];
                }

                return wp_parse_args([
                    'start' => $start,
                    'end'   => $endDateTime
                ], $args);
            }, $occurrences);

        } catch (\Exception $exception) {
            Helper::debugLog([
                'message' => $exception->getMessage(),
                'method'  => __METHOD__,
                'type'    => 'rrule_error'
            ]);
            return [];
        }
    }

    public static function convertToTimeZoneOffset($dateTime, $toTimeZone, $refernceDate = null)
    {
        if ($toTimeZone === 'UTC') {
            return gmdate('Y-m-d H:i:s', strtotime($dateTime));
        }

        if (!$refernceDate) {
            return DateTimeHelper::convertFromUtc($dateTime, $toTimeZone);
        }

        $dateTime = new \DateTime($dateTime, new \DateTimeZone('UTC'));
        $offset = self::getTimeOffset($refernceDate, $toTimeZone);
        if ($offset > 0) {
            $dateTime->add(new \DateInterval('PT' . $offset . 'S'));
        } else {
            $dateTime->sub(new \DateInterval('PT' . abs($offset) . 'S'));
        }

        return $dateTime->format('Y-m-d H:i:s');
    }

    public static function getTimeOffset($refDate, $timezone)
    {
        static $cache = [];

        $cacheKey = $refDate . '_' . $timezone;

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $timezone = DateTimeHelper::getValidatedTimeZone($timezone);

        $refDate = new \DateTime($refDate, new \DateTimeZone('UTC'));
        $refDate->setTimezone(new \DateTimeZone($timezone));

        $offset = $refDate->getOffset();

        $cache[$cacheKey] = $offset;

        return $offset;
    }

}
