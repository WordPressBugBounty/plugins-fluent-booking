<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\Framework\Support\Arr;

class EditorShortCodeParser
{
    protected static $requireHtml = true;

    protected static $store = [
        'booking'             => null,
        'calendar_booking'    => null,
        'calendar'            => null,
        'host'                => null,
        'user'                => null,
        'custom_booking_data' => null,
        'payment_order'       => null
    ];

    public static function parse($parsable, $booking, $requireHtml = true)
    {
        try {
            static::$requireHtml = $requireHtml;
            static::setData($booking);
            return static::parseShortCodes($parsable);
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log($e->getTraceAsString());
            }
            return '';
        }
    }

    protected static function setData($booking)
    {
        static::$store['booking'] = $booking;
        static::$store['booking_event'] = $booking->calendar_event;
        static::$store['calendar'] = $booking->calendar;
        static::$store['host'] = $booking->getHostDetails(false);
        static::$store['team_members'] = $booking->getHostsDetails(false, $booking->host_user_id);
        static::$store['custom_booking_data'] = null;
        static::$store['payment_order'] = null;
        static::$store['meeting_bookmarks'] = null;
    }

    protected static function getBookingData($key)
    {
        $bookingEvent = static::$store['booking_event'];
        $calendar = static::$store['calendar'];
        $booking = static::$store['booking'];

        if (!$bookingEvent || !$calendar || !$booking) {
            return '';
        }

        if ($key == 'event_name') {
            return $bookingEvent->title;
        }

        if ($key == 'description') {
            return $bookingEvent->description;
        }

        if ($key == 'booking_title') {
            return $booking->getBookingTitle();
        }

        if ($key == 'additional_guests') {
            return $booking->getAdditionalGuests(true);
        }

        if ($key == 'full_start_end_guest_timezone') {
            return $booking->getShortBookingDateTime($booking->person_time_zone) . ' (' . $booking->person_time_zone . ')';
        }

        if ($key == 'full_start_end_host_timezone') {
            return $booking->getShortBookingDateTime($booking->getHostTimezone()) . ' (' . $booking->getHostTimezone() . ')';
        }

        if ($key == 'full_start_and_end_guest_timezone') {
            return $booking->getFullBookingDateTimeText($booking->person_time_zone) . ' (' . $booking->person_time_zone . ')';
        }

        if ($key == 'full_start_and_end_host_timezone') {
            return $booking->getFullBookingDateTimeText($booking->getHostTimezone()) . ' (' . $booking->getHostTimezone() . ')';
        }

        if ($key == 'start_date_time') {
            return $booking->start_time;
        }

        if (str_starts_with($key, 'start_date_time_for_attendee')) {
            $format = preg_match('/format\.([a-zA-Z\-: ]+)/', $key, $matches) ? $matches[1] : 'Y-m-d H:i:s';
            $dateTime = DateTimeHelper::convertFromUtc($booking->start_time, $booking->person_time_zone, 'Y-m-d H:i:s');
            return date_i18n($format, strtotime($dateTime));
        }

        if (str_starts_with($key, 'start_date_time_for_host')) {
            $format = preg_match('/format\.([a-zA-Z\-: ]+)/', $key, $matches) ? $matches[1] : 'Y-m-d H:i:s';
            $dateTime = DateTimeHelper::convertFromUtc($booking->start_time, $booking->getHostTimezone(), 'Y-m-d H:i:s');
            return date_i18n($format, strtotime($dateTime));
        }

        if ($key == 'cancel_reason') {
            return $booking->getCancelReason(false, true);
        }

        if ($key == 'reject_reason') {
            return $booking->getRejectReason(false, true);
        }

        if ($key == 'reschedule_reason') {
            return $booking->getRescheduleReason(true);
        }

        if ($key == 'previous_meeting_time') {
            return $booking->getPreviousMeetingTime($booking->getHostTimezone()) . ' (' . $booking->getHostTimezone() . ')';
        }

        if ($key == 'previous_meeting_time_guest_timezone') {
            return $booking->getPreviousMeetingTime($booking->person_time_zone) . ' (' . $booking->person_time_zone . ')';
        }

        if ($key == 'previous_meeting_date_time_host_timezone') {
            return $booking->getPreviousMeetingDateTimeText($booking->getHostTimezone()) . ' (' . $booking->getHostTimezone() . ')';
        }

        if ($key == 'previous_meeting_date_time_guest_timezone') {
            return $booking->getPreviousMeetingDateTimeText($booking->person_time_zone) . ' (' . $booking->person_time_zone . ')';
        }

        if ($key == 'start_time_human_format') {
            if (time() > strtotime($booking->start_time)) {
                $suffix = __(' ago', 'fluent-booking');
            } else {
                $suffix = __(' from now', 'fluent-booking');
            }

            return human_time_diff(time(), strtotime($booking->start_time)) . ' ' . $suffix;
        }

        if ($key == 'cancelation_url') {
            return $booking->getCancelUrl();
        }

        if ($key == 'reschedule_url') {
            return $booking->getRescheduleUrl();
        }

        if ($key == 'admin_booking_url') {
            return Helper::getAdminBookingUrl($booking->id) . '&period=upcoming';
        }

        if ($key == 'booking_confirm_url') {
            return Helper::getAdminBookingUrl($booking->id) . '&period=pending&confirm_booking=true';
        }

        if ($key == 'booking_reject_url') {
            return Helper::getAdminBookingUrl($booking->id) . '&period=pending&reject_booking=true';
        }

        if ($key == 'location_details_html') {
            return $booking->getLocationDetailsHtml();
        }

        if ($key == 'location_details_text') {
            return $booking->getLocationAsText();
        }

        if ($key == 'booking_hash') {
            return $booking->hash;
        }

        $fillables = (new Booking())->getFillable();
        $fillables[] = 'id';
        $fillables[] = 'created_at';
        $fillables[] = 'updated_at';

        if (in_array($key, $fillables)) {
            return $booking->{$key};
        }

        return '';
    }

    protected static function getBookingCustomData($key)
    {
        $booking = static::$store['booking'];
        if (!$booking) {
            return '';
        }

        if (self::$store['custom_booking_data'] === null) {
            self::$store['custom_booking_data'] = $booking->getMeta('custom_fields_data', []);
        }

        if (self::$store['custom_booking_data']) {
            if (preg_match('/format\.([a-zA-Z\-: ]+)/', $key, $matches)) {
                $fieldKey = preg_split('/\.format\./', $key)[0];
                $value = Arr::get(self::$store['custom_booking_data'], $fieldKey);
                $customField = BookingFieldService::getBookingFieldByName($booking->calendar_event, $fieldKey);
                $formattedDate = DateTimeHelper::getFormattedDate($value, Arr::get($customField, 'date_format'));
                return $formattedDate ? date_i18n($matches[1], strtotime($formattedDate)) : '';
            }

            $customField = BookingFieldService::getBookingFieldByName($booking->calendar_event, $key);

            if (Arr::get($customField, 'type') == 'file') {
                return self::getUploadedFileUrl(Arr::get(self::$store['custom_booking_data'], $key));
            }

            if (Arr::get($customField, 'type') == 'hidden') {
                return self::parseShortCodes(Arr::get(self::$store['custom_booking_data'], $key));
            }

            return Arr::get(self::$store['custom_booking_data'], $key);
        }

        return '';
    }

    protected static function getHostData($key)
    {
        $host = static::$store['host'];

        if (is_null($host)) {
            return '';
        }

        if ($key == 'timezone') {
            $booking = static::$store['booking'];
            return $booking ? $booking->getHostTimezone() : null;
        }

        return Arr::get($host, $key, '');
    }

    protected static function getTeamMembersData($key)
    {
        $teamMembers = static::$store['team_members'];

        if (empty($teamMembers)) {
            return '';
        }

        list($key, $value) = explode('.', $key);

        return Arr::get($teamMembers, $key - 1 . '.' . $value, '');
    }

    protected static function getGuestData($key)
    {
        $guest = static::$store['booking'];
        if (is_null($guest)) {
            return '';
        }

        if ('full_name' == $key) {
            return $guest['first_name'] . ' ' . $guest['last_name'];
        }
        if ('timezone' == $key) {
            return $guest->person_time_zone;
        }
        if ('note' == $key) {
            return $guest->getMessage();
        }

        if ($key == 'total_guest') {
            return $guest->getTotalGuestCount();
        }

        if ($key == 'form_data_html') {
            return __('will be available soon', 'fluent-booking');
        }

        return Arr::get($guest, $key, '');
    }

    protected static function getBookingEventData($key)
    {
        $bookingEvent = static::$store['booking_event'];

        if (is_null($bookingEvent)) {
            return '';
        }

        $fillables = (new CalendarSlot())->getFillable();

        if (in_array($key, $fillables) || isset($bookingEvent->{$key})) {
            return $bookingEvent->{$key};
        }

        return '';
    }

    protected static function getCalendarData($key)
    {
        $calendar = static::$store['calendar'];

        if (is_null($calendar)) {
            return '';
        }

        $fillables = (new Calendar())->getFillable();

        if (in_array($key, $fillables) || isset($calendar->{$key})) {
            return $calendar->{$key};
        }

        return '';
    }

    protected static function getPaymentData($key)
    {
        $booking = static::$store['booking'];

        if (is_null($booking)) {
            return '';
        }

        if (!$booking->payment_status) {
            return '';
        }

        if (is_null(static::$store['payment_order'])) {
            static::$store['payment_order'] = $booking->payment_order;
        }

        if (!static::$store['payment_order']) {
            return '';
        }

        $order = static::$store['payment_order'];

        if ($key == 'payment_total' && $order) {
            $isZeroDecimal = CurrenciesHelper::isZeroDecimal($order->currency);
            if ($isZeroDecimal) {
                return $order->total_amount;
            } else {
                return $order->total_amount / 100;
            }
        }

        if ($key == 'receipt_html') {
            return apply_filters('fluent_booking/payment_receipt_html', '', $booking->hash);
        }

        if ($key == 'payment_status') {
            return $order ? $order->status : '';
        }

        if ($key == 'payment_currency') {
            return $order ? $order->currency : '';
        }

        if ($key == 'payment_date') {
            return $order ? $order->created_at : '';
        }

        $fillables = (new \FluentBookingPro\App\Models\Order())->getFillable();

        if (in_array($key, $fillables)) {
            return $order ? $order->{$key} : '';
        }

        return '';
    }

    protected static function getUserData($key)
    {
        $user = static::$store['user'];
        if (is_null($user)) {
            $user = wp_get_current_user();
        }
        return $user ? $user->{$key} : '';
    }

    protected static function getWPData($key)
    {
        if ('site_url' == $key) {
            return site_url();
        }
        if ('admin_email' == $key) {
            return get_option('admin_email');
        }
        if ('site_title' == $key) {
            return get_option('blogname');
        }
        return $key;
    }

    protected static function getOtherData($key)
    {
        $meetingBookmarks = self::$store['meeting_bookmarks'];
        if (!$meetingBookmarks) {
            $booking = static::$store['booking'];
            $meetingBookmarks = $booking ? $booking->getMeetingBookmarks() : null;
            self::$store['meeting_bookmarks'] = $meetingBookmarks;
        }

        if (0 === strpos($key, 'date.')) {
            $format = str_replace('date.', '', $key);
            return gmdate($format, strtotime(current_time('mysql'))); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        } elseif ('add_booking_to_calendar' === $key) {
            return static::parseShortCodes(Helper::getAddToCalendarHtml());
        } elseif ('add_to_g_calendar_url' === $key) {
            return Arr::get($meetingBookmarks, 'google.url');
        } elseif ('add_to_ol_calendar_url' === $key) {
            return Arr::get($meetingBookmarks, 'outlook.url');
        } elseif ('add_to_ms_calendar_url' === $key) {
            return Arr::get($meetingBookmarks, 'msoffice.url');
        } elseif ('add_to_ics_calendar_url' === $key) {
            return Arr::get($meetingBookmarks, 'other.url');
        }

        return $key;
    }

    protected static function getUploadedFileUrl($fieldValue)
    {
        if (empty($fieldValue)) {
            return '';
        }

        $files = array_map(function($file) {
            return '<a href="' . esc_url($file) . '" style="color:#222;font-size:15px;" target="_blank" download="' . esc_attr(basename($file)) . '">' . esc_html(basename($file)) . '</a>';
        }, $fieldValue);
    
        return '<ul><li>' . implode('</li><li>', $files) . '</li></ul>';
    }

    protected static function parseShortCodes($parsable)
    {
        if (is_array($parsable)) {
            return static::parseFromArray($parsable);
        }

        return static::parseFromString($parsable);
    }

    protected static function parseFromArray($parsable)
    {
        foreach ($parsable as $key => $value) {
            if (is_array($value)) {
                $parsable[$key] = static::parseFromArray($value);
            } else {
                $parsable[$key] = static::parseFromString($value);
            }
        }

        return $parsable;
    }

    protected static function parseFromString($parsable)
    {
        if (!$parsable) {
            return '';
        }

        return preg_replace_callback('/({{|##)+(.*?)(}}|##)/', function ($matches) {
            $value = '';

            if (empty($matches[2])) {
                return '';
            }

            $match = $matches[2];

            if (false !== strpos($match, 'guest.')) {
                $guestProperty = substr($match, strlen('guest.'));
                $value = static::getGuestData($guestProperty);
            } elseif (false !== strpos($match, 'booking.custom.')) {
                $customBookingProp = substr($match, strlen('booking.custom.'));
                $value = static::getBookingCustomData($customBookingProp);
            } elseif (false !== strpos($match, 'booking.')) {
                $bookingProperty = substr($match, strlen('booking.'));
                $value = static::getBookingData($bookingProperty);
            } elseif (false !== strpos($match, 'host.')) {
                $hostProperty = substr($match, strlen('host.'));
                $value = static::getHostData($hostProperty);
            } elseif (false !== strpos($match, 'team_member.')) {
                $teamMemberProperty = substr($match, strlen('team_member.'));
                $value = static::getTeamMembersData($teamMemberProperty);
            } elseif (false !== strpos($match, 'event.')) {
                $eventProperty = substr($match, strlen('event.'));
                $value = static::getBookingEventData($eventProperty);
            } elseif (false !== strpos($match, 'calendar.')) {
                $calendarProperty = substr($match, strlen('calendar.'));
                $value = static::getCalendarData($calendarProperty);
            } elseif (false !== strpos($match, 'payment.')) {
                $paymentProperty = substr($match, strlen('payment.'));
                $value = static::getPaymentData($paymentProperty);
            } else {
                $value = static::getOtherData($match);
            }

            if (static::$requireHtml && is_array($value)) {
                $value = Helper::fcalImplodeRecursive(', ', $value);
            }

            return $value;
        }, $parsable);
    }
}
