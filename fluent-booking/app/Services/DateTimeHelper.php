<?php

namespace FluentBooking\App\Services;

class DateTimeHelper
{
    public static function getTimeZones($grouped = false)
    {
        $tz_identifiers = timezone_identifiers_list();

        if (!$grouped) {
            return $tz_identifiers;
        }

        $lists = [];

        $topLevels = [];
        foreach ($tz_identifiers as $tz) {
            $parts = explode('/', $tz);
            if (count($parts) > 1) {
                $lists[$parts[0]][] = $tz;
            } else {
                $topLevels[$tz][] = $tz;
            }
        }

        return array_merge($topLevels, $lists);
    }

    public static function getFlatGroupedTimeZones()
    {
        $tz_identifiers = timezone_identifiers_list();

        $lists = [];
        $topLevels = [];
        foreach ($tz_identifiers as $tz) {
            $parts = explode('/', $tz);
            if (count($parts) > 1) {
                $lists[] = [
                    'label' => $tz,
                    'value' => $tz,
                    'group' => $parts[0]
                ];
            } else {
                $topLevels[] = [
                    'label' => $tz,
                    'value' => $tz,
                    'group' => $tz
                ];
            }
        }

        return $topLevels + $lists;
    }

    public static function getTimeZone()
    {
        $timeZone = wp_timezone_string();
        if (!$timeZone || !in_array($timeZone, \DateTimeZone::listIdentifiers())) {
            $timeZone = 'UTC';
        }

        return $timeZone;
    }

    public static function guessTimeZone()
    {
        $timeZone = self::getTimeZone();

        if (isset($_COOKIE['fluent_booking_user_timezone'])) {
            $timeZone = $_COOKIE['fluent_booking_user_timezone'];
        }

        return $timeZone;
    }

    public static function getValidatedTimeZone($requestedTimeZone)
    {
        static $cached = [];
        if (isset($cached[$requestedTimeZone])) {
            return $cached[$requestedTimeZone];
        }
        
        if (!in_array($requestedTimeZone, \DateTimeZone::listIdentifiers())) {
            $requestedTimeZone = apply_filters('fluent_booking/fallback_timezone', self::getTimeZone(), $requestedTimeZone);
        }

        $cached[$requestedTimeZone] = $requestedTimeZone;
        return $requestedTimeZone;
    }

    public static function convertToUtc($dateTime, $timezone, $format = 'Y-m-d H:i:s')
    {
        $timezone = self::getValidatedTimeZone($timezone);

        $dateTime = new \DateTime($dateTime, new \DateTimeZone($timezone));
        $dateTime->setTimezone(new \DateTimeZone('UTC'));
        return $dateTime->format($format);
    }

    public static function convertFromUtc($dateTime, $timezone, $format = 'Y-m-d H:i:s')
    {
        $dateTime = new \DateTime($dateTime, new \DateTimeZone('UTC'));

        if ($timezone != 'UTC') {
            $timezone = self::getValidatedTimeZone($timezone);
            $dateTime->setTimezone(new \DateTimeZone($timezone));
        }

        return $dateTime->format($format);
    }

    public static function convertToTimeZone($dateTime, $fromTimeZone, $toTimeZone, $format = 'Y-m-d H:i:s', $date = null)
    {
        if ($fromTimeZone == $toTimeZone) {
            return gmdate($format, strtotime($dateTime)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        if ($date) {
            $dateTime = gmdate('Y-m-d H:i', strtotime($date . ' ' . gmdate('H:i', strtotime($dateTime)))); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        $fromTimeZone = self::getValidatedTimeZone($fromTimeZone);
        $toTimeZone = self::getValidatedTimeZone($toTimeZone);

        $dateTime = new \DateTime($dateTime, new \DateTimeZone($fromTimeZone));
        $dateTime->setTimezone(new \DateTimeZone($toTimeZone));
        return $dateTime->format($format);
    }

    public static function getDateWithoutDST($timezone)
    {
        if (!self::isDaylightSavingActive('2024-01-03', $timezone)) {
            return '2024-01-03';
        }
        return '2024-06-03';
    }

    public static function convertToIso($dateTime, $fromTimeZone = 'UTC')
    {
        $fromTimeZone = self::getValidatedTimeZone($fromTimeZone);

        $dateTime = new \DateTime($dateTime, new \DateTimeZone($fromTimeZone));
        return $dateTime->format('Y-m-d\TH:i:s\Z');
    }

    public static function convertFromIso($dateTime, $toTimeZone = 'UTC')
    {
        $toTimeZone = self::getValidatedTimeZone($toTimeZone);
        $dateTime = new \DateTime($dateTime, new \DateTimeZone('UTC'));
        $dateTime->setTimezone(new \DateTimeZone($toTimeZone));
        return $dateTime->format('Y-m-d H:i:s');
    }

    public static function getIsoDurationInMinutes($duration)
    {
        if (!$duration) {
            return 0;
        }

        $duration = new \DateInterval($duration);

        return ($duration->d * 24 * 60) + ($duration->h * 60) + ($duration->i) + ($duration->s / 60);
    }

    public static function getTimestamp($timezone = 'UTC')
    {
        $timezone = self::getValidatedTimeZone($timezone);
        $dateTime = new \DateTime(gmdate('Y-m-d H:i:s'), new \DateTimeZone('UTC')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $dateTime->setTimezone(new \DateTimeZone($timezone));
        $date = $dateTime->format('Y-m-d H:i:s');
        return strtotime($date);
    }

    public static function formatToLocale($dateTime, $for = 'date')
    {
        // $for can be date | date_time | time
        $dateFormat = get_option('date_format');

        if ($for == 'date_time') {
            $dateFormat .= ' ' . get_option('time_format');
        } elseif ($for == 'time') {
            $dateFormat = get_option('time_format');
        }

        return date_i18n($dateFormat, strtotime($dateTime)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    }

    public static function getAvailableDateFormats()
    {
        $dateFormats = apply_filters('fluent_booking/available_date_formats', [
            'm/d/Y'  => 'm/d/Y - (Ex: 05/20/2024)', // USA
            'd/m/Y'  => 'd/m/Y - (Ex: 20/05/2024)', // Canada, UK
            'd.m.Y'  => 'd.m.Y - (Ex: 20.05.2024)', // Germany
            'n/j/y'  => 'n/j/y - (Ex: 5/20/24)',
            'm/d/y'  => 'm/d/y - (Ex: 05/20/24)',
            'M/d/Y'  => 'M/d/Y - (Ex: May/20/2024)',
            'y/m/d'  => 'y/m/d - (Ex: 24/05/20)',
            'Y-m-d'  => 'Y-m-d - (Ex: 2024-05-20)',
            'd-M-y'  => 'd-M-y - (Ex: 20-May-24)',
            'F j, Y' => 'F j, Y - (Ex: May 20, 2024)'
        ]);

        $formatted = [];
        foreach ($dateFormats as $format => $label) {
            $formatted[] = [
                'label' => $label,
                'value' => $format,
            ];
        }
        return $formatted;
    }

    public static function getFormattedEventTime($eventTime)
    {
        $timeZone = self::guessTimeZone();

        $convertedTime = self::convertToTimeZone($eventTime, 'UTC', $timeZone);

        $eventTime = self::formatToLocale($convertedTime, 'time');

        $eventDate = self::formatToLocale($convertedTime, 'date');

        return $eventTime . ', ' . $eventDate . ' (' . $timeZone . ')';
    }

    public static function convertPhpDateToDayJSFormay($phpFormat)
    {
        // Mapping PHP date format characters to Day.js format characters
        $replacements = [
            // Day
            'd' => 'DD', // Day of the month, 2 digits with leading zeros
            'D' => 'ddd', // A textual representation of a day, three letters
            'j' => 'D',   // Day of the month without leading zeros
            'l' => 'dddd', // A full textual representation of the day of the week
            'N' => 'E',   // ISO-8601 numeric representation of the day of the week
            'S' => 'o',   // English ordinal suffix for the day of the month, 2 characters
            'w' => 'd',   // Numeric representation of the day of the week
            'z' => 'DDD', // The day of the year (starting from 0)

            // Week
            'W' => 'W',   // ISO-8601 week number of year, weeks starting on Monday

            // Month
            'F' => 'MMMM', // A full textual representation of a month
            'm' => 'MM',   // Numeric representation of a month, with leading zeros
            'M' => 'MMM',  // A short textual representation of a month, three letters
            'n' => 'M',    // Numeric representation of a month, without leading zeros
            't' => '',     // Not supported in Day.js (Number of days in the given month)

            // Year
            'L' => '',     // Not supported in Day.js (Whether it's a leap year)
            'o' => 'GGGG', // ISO-8601 week-numbering year
            'Y' => 'YYYY', // A full numeric representation of a year, 4 digits
            'y' => 'YY',   // A two digit representation of a year

            // Time
            'a' => 'a',    // Lowercase Ante meridiem and Post meridiem
            'A' => 'A',    // Uppercase Ante meridiem and Post meridiem
            'B' => '',     // Not supported in Day.js (Swatch Internet time)
            'g' => 'h',    // 12-hour format of an hour without leading zeros
            'G' => 'H',    // 24-hour format of an hour without leading zeros
            'h' => 'hh',   // 12-hour format of an hour with leading zeros
            'H' => 'HH',   // 24-hour format of an hour with leading zeros
            'i' => 'mm',   // Minutes with leading zeros
            's' => 'ss',   // Seconds with leading zeros
            'u' => 'SSS',  // Milliseconds (Day.js uses SSS for fractional seconds)
            'v' => 'SSS',  // Milliseconds (Day.js uses SSS for fractional seconds)

            // Timezone
            'e' => '',     // Not supported in Day.js (Timezone identifier)
            'I' => '',     // Not supported in Day.js (Whether or not the date is in daylight saving time)
            'O' => 'ZZ',   // Difference to Greenwich time (GMT) in hours
            'P' => 'Z',    // Difference to Greenwich time (GMT) with colon between hours and minutes
            'T' => '',     // Not supported in Day.js (Timezone abbreviation)
            'Z' => '',     // Not supported in Day.js (Timezone offset in seconds)

            // Full Date/Time
            'c' => 'YYYY-MM-DDTHH:mm:ssZ', // ISO 8601 date
            'r' => 'ddd, DD MMM YYYY HH:mm:ss ZZ', // RFC 2822 formatted date
            'U' => 'X',   // Seconds since the Unix Epoch (January 1 1970 00:00:00 GMT)
        ];

        // Replace each PHP date format character with Day.js equivalent
        $dayjsFormat = "";

        for ($i = 0; $i < strlen($phpFormat); $i++) {
            $char = $phpFormat[$i];

            // Special handling for G\hi pattern
            if ($char === 'G' && $i + 2 < strlen($phpFormat) && 
                $phpFormat[$i + 1] === '\\' && $phpFormat[$i + 2] === 'h') {
                $dayjsFormat .= 'H[h]';
                $i += 2;
                continue;
            }

            // Check if the character is escaped
            if ($char === "\\") {
                // Add the next character to the result as is, without mapping
                $i++;
                if ($i < strlen($phpFormat)) {
                    $dayjsFormat .= "\\" . $phpFormat[$i];
                }
                continue;
            }

            // Add the mapped character or the character itself if not found in the mapping
            $dayjsFormat .= $replacements[$char] ?? $char;
        }

        return $dayjsFormat;
    }

    public static function getDateFormatter($isDayJs = false)
    {
        $format = get_option('date_format');
        if ($isDayJs) {
            return self::convertPhpDateToDayJSFormay($format);
        }

        return $format;
    }

    public static function getTimeFormatter($isDayJs = false)
    {
        $format = get_option('time_format');
        if ($isDayJs) {
            return self::convertPhpDateToDayJSFormay($format);
        }

        return $format;
    }

    public static function getTodayDate($timezone = 'UTC', $format = 'Y-m-d')
    {
        $timezone = self::getValidatedTimeZone($timezone);
        $dateTime = new \DateTime('now', new \DateTimeZone($timezone));
        return $dateTime->format($format);
    }

    public static function getDayDifference($dateTime, $fromTimeZone, $toTimeZone, $refDate = 'now')
    {
        $fromTimeZone = self::getValidatedTimeZone($fromTimeZone);
        $toTimeZone = self::getValidatedTimeZone($toTimeZone);

        if ($refDate != 'now') {
            $dateTime = gmdate('Y-m-d H:i', strtotime($refDate . ' ' . gmdate('H:i', strtotime($dateTime)))); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        $currentDate = new \DateTime($refDate, new \DateTimeZone($fromTimeZone));

        $originalDateTime = new \DateTime($dateTime, new \DateTimeZone($fromTimeZone));

        $originalDateTime->setTimezone(new \DateTimeZone($toTimeZone));

        return $originalDateTime->format('z') - $currentDate->format('z');
    }

    public static function getDaylightSavingTime($timezone)
    {
        $timezone = self::getValidatedTimeZone($timezone);
        $timezone = new \DateTimeZone($timezone);
        $dateTime = new \DateTime('2024-01-01', $timezone);

        $currentOffset = $timezone->getOffset($dateTime);

        $sixMonthsAgo = clone $dateTime;
        $sixMonthsAgo->modify('+6 month');
        $offsetBefore = $timezone->getOffset($sixMonthsAgo);

        $dstDifference = $currentOffset - $offsetBefore;

        return abs($dstDifference) / 60;
    }

    public static function isDaylightSavingActive($dateTime, $timezone)
    {
        if (!$dateTime || !$timezone) {
            return false;
        }

        $timezone = self::getValidatedTimeZone($timezone);

        $dateTimeObject = new \DateTime($dateTime, new \DateTimeZone($timezone));

        $isDstActive = $dateTimeObject->format('I');

        if ($timezone == 'Europe/Dublin') {
            return !$isDstActive;
        }

        return $isDstActive;
    }

    public static function getI18nDateTimeConfig()
    {
        return apply_filters('fluent_booking/i18n_date_time_config', [
            'weekdays'      => array(
                'sunday'    => _x('Sunday', 'calendar day full', 'fluent-booking'),
                'monday'    => _x('Monday', 'calendar day full', 'fluent-booking'),
                'tuesday'   => _x('Tuesday', 'calendar day full', 'fluent-booking'),
                'wednesday' => _x('Wednesday', 'calendar day full', 'fluent-booking'),
                'thursday'  => _x('Thursday', 'calendar day full', 'fluent-booking'),
                'friday'    => _x('Friday', 'calendar day full', 'fluent-booking'),
                'saturday'  => _x('Saturday', 'calendar day full', 'fluent-booking'),
            ),
            'months'        => array(
                'January'   => _x('January', 'calendar month name full', 'fluent-booking'),
                'February'  => _x('February', 'calendar month name full', 'fluent-booking'),
                'March'     => _x('March', 'calendar month name full', 'fluent-booking'),
                'April'     => _x('April', 'calendar month name full', 'fluent-booking'),
                'May'       => _x('May', 'calendar month name full', 'fluent-booking'),
                'June'      => _x('June', 'calendar month name full', 'fluent-booking'),
                'July'      => _x('July', 'calendar month name full', 'fluent-booking'),
                'August'    => _x('August', 'calendar month name full', 'fluent-booking'),
                'September' => _x('September', 'calendar month name full', 'fluent-booking'),
                'October'   => _x('October', 'calendar month name full', 'fluent-booking'),
                'November'  => _x('November', 'calendar month name full', 'fluent-booking'),
                'December'  => _x('December', 'calendar month name full', 'fluent-booking')
            ),
            'weekdaysShort' => array(
                'sun' => _x('Sun', 'calendar day short', 'fluent-booking'),
                'mon' => _x('Mon', 'calendar day short', 'fluent-booking'),
                'tue' => _x('Tue', 'calendar day short', 'fluent-booking'),
                'wed' => _x('Wed', 'calendar day short', 'fluent-booking'),
                'thu' => _x('Thu', 'calendar day short', 'fluent-booking'),
                'fri' => _x('Fri', 'calendar day short', 'fluent-booking'),
                'sat' => _x('Sat', 'calendar day short', 'fluent-booking')
            ),
            'monthsShort'   => array(
                'jan' => _x('Jan', 'calendar month name short', 'fluent-booking'),
                'feb' => _x('Feb', 'calendar month name short', 'fluent-booking'),
                'mar' => _x('Mar', 'calendar month name short', 'fluent-booking'),
                'apr' => _x('Apr', 'calendar month name short', 'fluent-booking'),
                'may' => _x('May', 'calendar month name short', 'fluent-booking'),
                'jun' => _x('Jun', 'calendar month name short', 'fluent-booking'),
                'jul' => _x('Jul', 'calendar month name short', 'fluent-booking'),
                'aug' => _x('Aug', 'calendar month name short', 'fluent-booking'),
                'sep' => _x('Sep', 'calendar month name short', 'fluent-booking'),
                'oct' => _x('Oct', 'calendar month name short', 'fluent-booking'),
                'nov' => _x('Nov', 'calendar month name short', 'fluent-booking'),
                'dec' => _x('Dec', 'calendar month name short', 'fluent-booking')
            ),
            'numericSystem' => _x('0_1_2_3_4_5_6_7_8_9', 'calendar numeric system - Sequence must need to maintained', 'fluent-booking'),
        ]);
    }
}
