<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\App;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\Framework\Support\Arr;

class BookingService
{
    public static function createBooking($data = [], $calendarSlot = null, $customFieldsData = [])
    {
        if (empty($data['email']) || empty($data['start_time']) || empty($data['person_time_zone'])) {
            throw new \Exception(esc_html__('Email, Start Time and timezone are required to create a booking', 'fluent-booking'), 422);
        }

        if (!$calendarSlot) {
            $calendarSlot = CalendarSlot::findOrFail($data['event_id']);
        }

        $defaults = [
            'event_id'     => $calendarSlot->id,
            'calendar_id'  => $calendarSlot->calendar_id,
            'host_user_id' => $calendarSlot->user_id
        ];

        $data = self::prepareBookingData($data, $calendarSlot);

        $guests = Arr::get($data, 'additional_guests', []);

        $bookingData = Arr::only(wp_parse_args($data, $defaults), (new Booking())->getFillable());

        $bookingData['group_id'] = self::getGroupId($calendarSlot, $bookingData);

        $bookingData['event_type'] = $calendarSlot->event_type;

        $bookingData = apply_filters('fluent_booking/booking_data', $bookingData, $calendarSlot, $customFieldsData, $data);

        if (is_wp_error($bookingData)) {
            return $bookingData;
        }

        return self::createSingleOrMultiBooking($bookingData, $calendarSlot, $customFieldsData, $guests);
    }

    public static function createSingleOrMultiBooking($bookingData, $calendarSlot, $customFieldsData, $guests = [], $bookingIds = [])
    {
        if (is_array($bookingData['start_time'])) {
            return self::createMultiTimeBooking($bookingData, $calendarSlot, $customFieldsData, $guests);
        }

        if (is_array($bookingData['email'])) {
            return self::createMultiGuestBooking($bookingData, $calendarSlot, $customFieldsData);
        }

        do_action('fluent_booking/before_booking', $bookingData, $calendarSlot);

        $booking = Booking::create($bookingData);

        self::attachHosts($booking, $calendarSlot);
        self::updateParentInfo($booking, $bookingIds);
        self::updateMetas($booking, $bookingData, $guests, $customFieldsData, $calendarSlot);

        $booking->load('calendar');

        $bookingData = apply_filters('fluent_booking/after_booking_data', $bookingData, $booking, $calendarSlot, $customFieldsData);

        // this pre hook is for early actions that require for remote calendars and locations
        do_action('fluent_booking/pre_after_booking_' . $booking->status, $booking, $calendarSlot, $bookingData);

        // We are just renewing this as this may have been changed by the pre hook
        $booking = Booking::find($booking->id);
        do_action('fluent_booking/after_booking_' . $booking->status, $booking, $calendarSlot, $bookingData);

        return $booking;
    }

    public static function createMultiTimeBooking($data, $calendarSlot, $customFieldsData, $guests)
    {
        $booking = [];
        $bookingIds = [];
        $createdBookingIds = [];
        $lastBooking = end($data['start_time']);
        $totalBooking = count($data['start_time']);
        $bookingTimes = array_combine($data['start_time'], $data['end_time']);

        foreach ($bookingTimes as $startTime => $endTime) {
            $bookingData = $data;

            $bookingData['start_time'] = $startTime;
            $bookingData['end_time'] = $endTime;

            $isConfRequired = $calendarSlot->isConfirmationRequired($startTime);
            $bookingData['status'] = $isConfRequired ? 'pending' : $data['status'];

            if ($startTime == $lastBooking) {
                $createdBookingIds = $bookingIds;
            }

            if (Arr::get($data, 'payment_method')) {
                if ($startTime == $lastBooking) {
                    $bookingData['quantity'] = $totalBooking;
                } else {
                    $bookingData['payment_status'] = '';
                    $bookingData['payment_method'] = '';
                }
            }

            $booking = self::createSingleOrMultiBooking($bookingData, $calendarSlot, $customFieldsData, $guests, $createdBookingIds);

            $bookingIds[] = $booking->id;
        }

        return $booking;
    }

    public static function createMultiGuestBooking($data, $calendarSlot, $customFieldsData)
    {
        $booking = [];
        $bookingIds = [];
        $createdBookingIds = [];
        $lastBooking = end($data['email']);
        $totalBooking = count($data['email']);
        $guests = array_combine($data['email'], $data['first_name']);

        foreach ($guests as $email => $name) {
            $bookingData = $data;

            $bookingData['email'] = $email;

            $nameArray = explode(' ', trim($name));
            $bookingData['first_name'] = array_shift($nameArray);
            $bookingData['last_name'] = implode(' ', $nameArray);

            if ($user = get_user_by('email', $email)) {
                $bookingData['person_user_id'] = $user->ID;
            }

            $bookingData['group_id'] = self::getGroupId($calendarSlot, $bookingData);

            if ($email == $lastBooking) {
                $createdBookingIds = $bookingIds;
            }

            if (Arr::get($data, 'payment_method')) {
                if ($email == $lastBooking) {
                    $bookingData['quantity'] = $totalBooking;
                } else {
                    $bookingData['payment_status'] = '';
                    $bookingData['payment_method'] = '';
                }
            }

            $booking = self::createSingleOrMultiBooking($bookingData, $calendarSlot, $customFieldsData, [], $createdBookingIds);

            $bookingIds[] = $booking->id;
        }

        return $booking;
    }

    private static function prepareBookingData($data, $calendarSlot)
    {
        if (empty($data['first_name']) && !empty($data['name'])) {
            $nameArray = explode(' ', trim($data['name']));
            $data['first_name'] = array_shift($nameArray);
            $data['last_name'] = implode(' ', $nameArray);
        }

        if (empty($data['slot_minutes'])) {
            $data['slot_minutes'] = $calendarSlot->duration;
        }

        if (empty($data['end_time'])) {
            $data['end_time'] = gmdate('Y-m-d H:i:s', strtotime($data['start_time']) + ($data['slot_minutes'] * 60));
        }

        if (!isset($data['person_user_id'])) {
            $user = get_user_by('email', $data['email']);
            if ($user) {
                $data['person_user_id'] = $user->ID;
                if (empty($data['first_name'])) {
                    $data['first_name'] = $user->first_name;
                    $data['last_name'] = $user->last_name;
                }
            }
        }

        if (empty($data['location_details'])) {
            $data['location_details'] = LocationService::getLocationDetails($calendarSlot, [], []);
        }

        if ($additionalGuests = Arr::get($data, 'additional_guests', [])) {
            if ($calendarSlot->isMultiGuestEvent()) {
                $guestEmails = array_map(function ($guest) {
                    return $guest['email'];
                }, $additionalGuests);
                $data['email'] = array_merge($guestEmails, (array) $data['email']);

                $guestNames = array_map(function ($guest) {
                    return $guest['name'];
                }, $additionalGuests);
                $data['first_name'] = array_merge($guestNames, (array) ($data['first_name'] . ' ' . $data['last_name']));

                $data['additional_guests'] = [];
            }
        }

        return $data;
    }

    private static function attachHosts($booking, $calendarSlot)
    {
        $hosts = [$booking->host_user_id];
        if ($calendarSlot->isMultiHostsEvent()) {
            $hosts = $calendarSlot->getHostIds();
        }

        $hostData = [];
        foreach ($hosts as $hostId) {
            $hostData[$hostId] = ['status' => 'confirmed'];
        }

        $booking->hosts()->attach($hostData);
    }

    protected static function getGroupId($calendarSlot, $bookingData)
    {
        if (!$calendarSlot->isMultiGuestEvent()) {
            return null;
        }

        $event = Booking::select('group_id')
            ->where('event_id', $calendarSlot->id)
            ->where('calendar_id', $calendarSlot->calendar_id)
            ->where('start_time', $bookingData['start_time'])
            ->first();

        return $event ? $event->group_id : null;
    }

    private static function updateMetas($booking, $bookingData, $guests, $customFieldsData, $calendarSlot)
    {
        if ($customFieldsData) {
            Helper::updateBookingMeta($booking->id, 'custom_fields_data', $customFieldsData);
        }

        if ($guests) {
            Helper::updateBookingMeta($booking->id, 'additional_guests', $guests);
        }

        if ($quantity = Arr::get($bookingData, 'quantity')) {
            Helper::updateBookingMeta($booking->id, 'quantity', $quantity);
        }

        do_action('fluent_booking/after_booking_meta_update', $booking, $bookingData, $customFieldsData, $calendarSlot);
    }

    private static function updateParentInfo($booking, $bookingIds)
    {
        if (!$bookingIds) {
            return;
        }

        Booking::whereIn('id', $bookingIds)->update(['parent_id' => $booking->id]);
    }

    public static function getBookingConfirmationHtml(Booking $booking, $actionType = 'confirmation')
    {
        $validActions = [
            'confirmation',
            'cancel',
            'reschedule'
        ];

        if (!in_array($actionType, $validActions)) {
            $actionType = 'confirmation';
        }

        $calendarSlot = $booking->calendar_event;

        $author = $booking->getHostDetails(false);

        $bookingTitle = $booking->getBookingTitle();

        $sections = [
            'what'  => [
                'title'   => __('What', 'fluent-booking'),
                'content' => $bookingTitle
            ],
            'when'  => [
                'title'   => __('When', 'fluent-booking'),
                'content' => $booking->getFullBookingDateTimeText($booking->person_time_zone, true) . ' (' . $booking->person_time_zone . ')'
            ],
            'who'   => [
                'title'   => __('Who', 'fluent-booking'),
                'content' => $booking->getHostAndGuestDetailsHtml()
            ],
            'where' => [
                'title'   => __('Where', 'fluent-booking'),
                'content' => $booking->getLocationDetailsHtml()
            ]
        ];

        if ($guests = $booking->getAdditionalGuests(true)) {
            $sections['guests'] = [
                'title'   => __('Additional Guests', 'fluent-booking'),
                'content' => $guests
            ];
        }

        if ($booking->status == 'cancelled') {
            // add cancellation reason at the beginning
            $sections = array_merge([
                'cancellation_reason' => [
                    'title'   => __('Cancellation Reason', 'fluent-booking'),
                    'content' => $booking->getCancelReason(false, true)
                ]
            ], $sections);
        }

        if ($booking->status == 'rejected') {
            // add rejection reason at the beginning
            $sections = array_merge([
                'cancellation_reason' => [
                    'title'   => __('Rejection Reason', 'fluent-booking'),
                    'content' => $booking->getRejectReason(false, true)
                ]
            ], $sections);
        }

        if ($booking->message) {
            $sections['note'] = [
                'title'   => __('Additional Note', 'fluent-booking'),
                'content' => wpautop($booking->message)
            ];
        }

        $customFieldsData = $booking->getCustomFormData(true, true);

        foreach ($customFieldsData as $dataKey => $data) {
            if (!empty($data['value'])) {
                $sections[$dataKey] = [
                    'title'   => $data['label'],
                    'content' => $data['value']
                ];
            }
        }

        $bookingStatus = $booking->getBookingStatus();

        $subHeading = '';
        if ($booking->status == 'scheduled') {
            // translators: %s is the name of the person scheduled
            $subHeading = sprintf(__('You are scheduled with %s', 'fluent-booking'), $author['name']);

            if ($actionType == 'confirmation' && $calendarSlot->allowMultiBooking()) {
                $bookingTime = (array) $sections['when']['content'];
                $sections['when']['content'] = array_merge($bookingTime, $booking->getOtherBookingTimes());
            }
        }

        // translators: %s is the status of the meeting
        $title = sprintf(__('Your meeting has been %s', 'fluent-booking'), $bookingStatus);
        if ($booking->status == 'pending' && $booking->payment_status != 'pending') {
            $title = __('Your booking has been submitted', 'fluent-booking');
            $subHeading = __('Please wait for the host to confirm your booking', 'fluent-booking');
        }

        $assetsUrl = App::getInstance('url.assets');

        $confirmationData = [
            'author'       => $author,
            'title'        => $title,
            'sub_heading'  => $subHeading,
            'sections'     => $sections,
            'slot'         => $calendarSlot,
            'booking'      => $booking,
            'message'      => __('A confirmation has been sent to your email address along with meeting location details.', 'fluent-booking'),
            'action_type'  => $actionType,
            'can_cancel'   => $booking->canCancel(),
            'bookmarks'    => [],
            'confirm_icon' => '',
            'action_url'   => '',
            'extra_html'   => ''
        ];

        if ($booking->payment_status) {
            $confirmationData['extra_html'] = EditorShortCodeParser::parse('{{payment.receipt_html}}', $booking);
        }

        if ($actionType == 'cancel') {
            $confirmationData['title'] = __('Booking Cancellation', 'fluent-booking');
            $confirmationData['sub_heading'] = __('Confirm and cancel the scheduled booking', 'fluent-booking');
            $confirmationData['cancel_field'] = BookingFieldService::getBookingFieldByName($calendarSlot, 'cancellation_reason');
            $confirmationData['action_url'] = add_query_arg([
                'action'       => 'fcal_cancel_meeting',
                'meeting_hash' => $booking->hash,
                'scope'        => Arr::get($_REQUEST, 'scope') // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ], admin_url('admin-ajax.php'));
        }

        if ($booking->status == 'scheduled' && $actionType == 'confirmation') {
            $confirmationData['confirm_icon'] = $assetsUrl . '/images/check-mark.png';
            $confirmationData['bookmarks']    = $booking->getMeetingBookmarks($assetsUrl);
        }

        if ($booking->status == 'cancelled' || $booking->status == 'rejected') {
            $confirmationData['confirm_icon'] = $assetsUrl . '/images/cancel-mark.png';
        }

        $confirmationData = apply_filters('fluent_booking/schedule_receipt_data', $confirmationData, $booking);

        return (string)App::make('view')->make('public.booking_confirmation', $confirmationData);
    }

    public static function generateBookingICS(Booking $booking)
    {
        $author = $booking->getHostDetails(false);

        // Initialize the ICS content
        $icsContent = "BEGIN:VCALENDAR\r\n";
        $icsContent .= "VERSION:2.0\r\n";
        $icsContent .= "PRODID:-//Google Inc//Fluent Booking//EN\r\n";
        $icsContent .= "METHOD:REQUEST\r\n";
        $icsContent .= "STATUS:CONFIRMED\r\n";

        $icsContent .= "BEGIN:VEVENT\r\n";
        $icsContent .= "UID:" . md5($booking->hash) . "\r\n"; // Unique ID for the event

        // Event details
        $icsContent .= "SUMMARY:" . $booking->getBookingTitle() . "\r\n";
        $icsContent .= "DESCRIPTION:" . $booking->getIcsBookingDescription() . "\r\n";

        // Date and time formatting (assuming eventStart and eventEnd are DateTime objects)
        $icsContent .= "DTSTART:" . gmdate('Ymd\THis\Z', strtotime($booking->start_time)) . "\r\n";
        $icsContent .= "DTEND:" . gmdate('Ymd\THis\Z', strtotime($booking->end_time)) . "\r\n";

        $icsContent .= "LOCATION:" . $booking->getLocationAsText() . "\r\n";

        $icsContent .= "ORGANIZER;CN=\"" . $author['name'] . "\":mailto:" . $author['email'] . "\r\n";

        $icsContent .= "ATTENDEE;CN=\"" . $booking->email . "\";ROLE=REQ-PARTICIPANT;RSVP=TRUE;PARTSTAT=ACCEPTED:mailto:" . $booking->email . "\r\n";

        $icsContent .= "END:VEVENT\r\n";

        // Close the VCALENDAR component
        $icsContent .= "END:VCALENDAR\r\n";

        return $icsContent;
    }

}
