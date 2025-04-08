<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\App;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\BookingService;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Hooks\Handlers\FrontEndHandler;
use FluentBooking\App\Hooks\Handlers\TimeSlotServiceHandler;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\App\Services\Helper;
use FluentBooking\Framework\Support\Arr;

class BookingController extends Controller
{
    public function getSlots(Request $request, $slotId)
    {
        $slot = CalendarSlot::findOrfail($slotId);

        if ($slot->status != 'active') {
            return $this->sendError([
                'message' => __('Sorry, the host is not accepting any new bookings at the moment.', 'fluent-booking')
            ]);
        }

        $calendar = $slot->calendar;
        $startDate = $request->get('start_date', gmdate('Y-m-d H:i:s'));
        $timeZone = $request->get('timezone', 'UTC');

        if (!$timeZone) {
            $timeZone = 'UTC';
        }

        $timeSlotService = TimeSlotServiceHandler::initService($calendar, $slot);

        if (is_wp_error($timeSlotService)) {
            return TimeSlotServiceHandler::sendError($timeSlotService, $slot, $timeZone);
        }

        $availableSpots = $timeSlotService->getAvailableSpots($startDate, $timeZone);

        if(is_wp_error($availableSpots)) {
            return [
                'available_slots' => [],
                'timezone'        => $timeZone,
                'invalid_dates'   => true,
                'max_lookup_date' => $slot->getMaxLookUpDate(),
            ];
        }

        return [
            'available_slots' => array_filter($availableSpots),
            'timezone'        => $timeZone,
            'max_lookup_date' => $slot->getMaxLookUpDate(),
        ];
    }

    public function createBooking(Request $request, $eventId)
    {
        $calendarEvent = CalendarSlot::findOrfail($eventId);

        if ($calendarEvent->status != 'active') {
            return $this->sendError([
                'message' => __('Sorry, the host is not accepting any new bookings at the moment.', 'fluent-booking')
            ]);
        }

        $postedData = $request->all();

        $rules = [
            'name'       => 'required',
            'email'      => 'required|email',
            'timezone'   => 'required',
            'event_time' => 'required'
        ];

        $messages = [
            'name.required'       => __('Please enter attendee\'s name', 'fluent-booking'),
            'email.required'      => __('Please enter attendee\'s email address', 'fluent-booking'),
            'email.email'         => __('Please provide a valid email address', 'fluent-booking'),
            'timezone.required'   => __('Please select the timezone', 'fluent-booking'),
            'event_time.required' => __('Please select a date and time', 'fluent-booking')
        ];

        $locationType = Arr::get($postedData, 'location_type');

        if ($calendarEvent->isPhoneRequired()) {
            $rules['location_description'] = 'required';
            $messages['location_description.required'] = __('Please provide attendee\'s phone number', 'fluent-booking');
        } else if ($calendarEvent->isAddressRequired()) {
            $rules['location_description'] = 'required';
            $messages['location_description.required'] = __('Please provide attendee\'s address', 'fluent-booking');
        }

        if ($additionalGuests = Arr::get($postedData, 'guests', [])) {
            if ($calendarEvent->isMultiGuestEvent()) {
                $additionalGuests = $this->sanitize_mapped_data($additionalGuests);
                $additionalGuests = array_values(array_filter($additionalGuests, function ($guest) {
                    return Arr::get($guest, 'name') && Arr::get($guest, 'email');
                }));
            } else {
                $additionalGuests = array_filter(array_map('sanitize_email', $additionalGuests));
            }
        }

        $postedData['guests'] = $additionalGuests;

        $requiredFields = array_filter($calendarEvent->getMeta('booking_fields', []), function ($field) {
            return Arr::isTrue($field, 'required') && Arr::isTrue($field, 'enabled') && (Arr::get($field, 'name') == 'message' || Arr::get($field, 'name') == 'guests');
        });

        foreach ($requiredFields as $field) {
            if (empty($rules[$field['name']])) {
                $rules[$field['name']] = 'required';
                $messages[$field['name'] . '.required'] = __('This field is required', 'fluent-booking');
            }
        }

        $validationConfig = apply_filters('fluent_booking/schedule_validation_rules_data', [
            'rules'    => $rules,
            'messages' => $messages
        ], $postedData, $calendarEvent);

        $app = App::getInstance();

        $validator = $app->validator->make($postedData, $validationConfig['rules'], $validationConfig['messages']);
        if ($validator->validate()->fails()) {
            wp_send_json([
                'message' => __('Please fill up the required data', 'fluent-booking'),
                'errors'  => $validator->errors()
            ], 422);
            return;
        }

        $customFieldsData = BookingFieldService::getCustomFieldsData(Arr::get($postedData, 'custom_fields', []), $calendarEvent);
        $customFieldsData = apply_filters('fluent_booking/schedule_custom_field_data', $customFieldsData, $calendarEvent);

        if (is_wp_error($customFieldsData)) {
            wp_send_json([
                'message' => $customFieldsData->get_error_message(),
                'errors'  => $customFieldsData->get_error_data()
            ], 422);
            return;
        }

        $duration = $calendarEvent->getDuration(Arr::get($postedData, 'duration', null));
        $timezone = Arr::get($postedData, 'timezone', 'UTC');

        $startDateTime = DateTimeHelper::convertToUtc($postedData['event_time'], $timezone);
        $endDateTime   = gmdate('Y-m-d H:i:s', strtotime($startDateTime) + ($duration * 60));

        $bookingData = [
            'person_time_zone' => sanitize_text_field($timezone),
            'start_time'       => $startDateTime,
            'name'             => sanitize_text_field($postedData['name']),
            'email'            => sanitize_email($postedData['email']),
            'message'          => sanitize_textarea_field(wp_unslash(Arr::get($postedData, 'message', ''))),
            'phone'            => sanitize_textarea_field(Arr::get($postedData, 'phone_number', '')),
            'address'          => sanitize_textarea_field(Arr::get($postedData, 'address', '')),
            'ip_address'       => Helper::getIp(),
            'status'           => sanitize_text_field($postedData['status']),
            'source'           => 'admin',
            'event_type'       => $calendarEvent->event_type,
            'slot_minutes'     => $duration
        ];

        $eventLocations = [];
        $locationSettings = $calendarEvent->location_settings;
        foreach ($locationSettings as $index => $location) {
            $eventLocations[$location['type']] = $location;
        }

        $locationDetails['type'] = $locationType;
        if ($locationType == 'phone_organizer') {
            $locationDetails['description'] = $eventLocations[$locationType]['host_phone_number'];
        } else if ($locationType == 'phone_guest') {
            $bookingData['phone'] = Arr::get($postedData, 'location_description', '');
        } else if ($locationType == 'in_person_guest') {
            $locationDetails['description'] = Arr::get($postedData, 'location_description', '');
        } else if (in_array($locationType, ['custom', 'in_person_organizer'])) {
            $locationDetails['description'] = $eventLocations[$locationType]['description'];
        } else if (in_array($locationType, ['google_meet', 'online_meeting', 'zoom_meeting', 'ms_teams'])) {
            $locationDetails['description'] = Arr::get($eventLocations[$locationType], 'meeting_link', '');
            $locationDetails['online_platform_link'] = $locationDetails['description'];
        }

        $bookingData['location_details'] = $locationDetails;

        if ($sourceUrl = Arr::get($postedData, 'source_url', '')) {
            $bookingData['source_url'] = sanitize_url($sourceUrl);
        }

        if ($hostUserId = Arr::get($postedData, 'host_user_id', null)) {
            $bookingData['host_user_id'] = (int)$hostUserId;
        }

        $hostIds = null;
        if ($calendarEvent->isRoundRobin() && !$hostUserId) {
            $hostIds = $calendarEvent->getHostIdsSortedByBookings($startDateTime);
            $bookingData['host_user_id'] = $hostIds[0];
        }

        $availableSpot = false;
        if (!Arr::isTrue($postedData, 'ignore_availability')) {
            $timeSlotService = TimeSlotServiceHandler::initService($calendarEvent->calendar, $calendarEvent);

            if (is_wp_error($timeSlotService)) {
                return TimeSlotServiceHandler::sendError($timeSlotService, $calendarEvent, $timezone);
            }

            $availableSpot = $timeSlotService->isSpotAvailable($startDateTime, $endDateTime, $duration, $hostUserId);

            if (!$availableSpot) {
                wp_send_json([
                    'message' => __('This selected time slot is not available. Maybe someone booked the spot just a few seconds ago.', 'fluent-booking')
                ], 422);
            }

            if ($calendarEvent->isRoundRobin() && !$hostUserId) {
                $bookingData['host_user_id'] = $timeSlotService->hostUserId;
            }
        }

        if ($additionalGuests) {
            $guestField = BookingFieldService::getBookingFieldByName($calendarEvent, 'guests');
            $guestLimit = Arr::get($guestField, 'limit', 10);
            if ($calendarEvent->isMultiGuestEvent() && $availableSpot) {
                $remaining = Arr::get($availableSpot, 'remaining', $calendarEvent->getMaxBookingPerSlot());
                $guestLimit = min($remaining, $guestLimit) - 1;
            }
            $bookingData['additional_guests'] = array_slice($additionalGuests, 0, $guestLimit);
        }

        do_action('fluent_booking/before_creating_schedule', $bookingData, $postedData, $calendarEvent);

        try {
            $booking = BookingService::createBooking($bookingData, $calendarEvent, $customFieldsData);

            do_action('fluent_booking/after_creating_schedule', $booking, $postedData, $calendarEvent);
        } catch (\Exception $e) {
            wp_send_json([
                'message' => $e->getMessage()
            ], $e->getCode());
        }

        return [
            'booking' => $booking,
            'message' => __('Booking has been created', 'fluent-booking'),
        ];
    }

    public function getEvent(Request $request, $eventId)
    {   
        $calendarEvent = CalendarSlot::find($eventId);

        if (!$calendarEvent || $calendarEvent->status != 'active') {
            wp_send_json([
                'message' => __('Sorry, the host is not accepting any new bookings at the moment.', 'fluent-booking')
            ], 422);
        }

        $calendar = $calendarEvent->calendar;

        if (!$calendar) {
            return $this->sendError([
                'message' => __('Calendar not found', 'fluent-booking')
            ]);
        }

        $calendarEventVars = (new FrontEndHandler())->getCalendarEventVars($calendar, $calendarEvent);

        $startDate = $request->get('start_date');

        if (!$startDate) {
            $startDate = gmdate('Y-m-d H:i:s');
        }

        $timeZone = $request->get('timezone');

        if (!$timeZone) {
            $timeZone = wp_timezone_string();
        }

        if (!in_array($timeZone, \DateTimeZone::listIdentifiers())) {
            $timeZone = $calendar->author_timezone;
        }
        
        $duration = $calendarEvent->getDuration($request->get('duration'));

        $hostId = $request->get('host_id', null);
        
        $timeSlotService = TimeSlotServiceHandler::initService($calendar, $calendarEvent);

        if (is_wp_error($timeSlotService)) {
            return TimeSlotServiceHandler::sendError($timeSlotService, $calendarEvent, $timeZone);
        }

        $availableSpots = $timeSlotService->getAvailableSpots($startDate, $timeZone, $duration, $hostId);

        if (is_wp_error($availableSpots)) {
            wp_send_json([
                'available_slots' => [],
                'calendar_event'  => $calendarEventVars,
                'timezone'        => $timeZone,
                'invalid_dates'   => true
            ], 200);
        }

        $availableSpots = apply_filters('fluent_booking/available_slots_for_view', array_filter($availableSpots), $calendarEvent, $calendar, $timeZone, $duration);

        return [
            'calendar_event'  => $calendarEventVars,
            'available_slots' => $availableSpots
        ];
    }

    public function getBookings(Request $request)
    {
        $userData = get_userdata(get_current_user_id());
        
        $userEmail = $userData ? $userData->user_email : null;

        if (!$userEmail) {
            return $this->sendError([
                'message' => __('Please login to view your bookings', 'fluent-booking')
            ]);
        }

        $perPage = intval($request->get('per_page', 10));

        $bookingPeriod = sanitize_text_field($request->get('period', 'all'));

        $bookingQuery = Booking::query()->with('calendar_event')
            ->where('email', $userEmail)
            ->applyComputedStatus($bookingPeriod)
            ->applyBookingOrderByStatus($bookingPeriod);
        
        $calendarIds = $request->get('calendar_ids', []);

        if (!in_array('all', $calendarIds)) {
            $calendarIds = array_map('intval', $calendarIds);
            $bookingQuery->whereIn('calendar_id', $calendarIds);
        }

        do_action_ref_array('fluent_booking/bookings_query', [&$bookingQuery]);

        $totalBookings = $bookingQuery->count();

        $bookings = $bookingQuery->limit($perPage)->get();
        
        $formattedBookings = [];
        foreach ($bookings as $booking) {
            $formattedBookings[] = [
                'id'                => $booking->id,
                'person_time_zone'  => $booking->person_time_zone,
                'status'            => ucfirst($booking->status),
                'payment_status'    => $booking->payment_status,
                'booking_title'     => $booking->getBookingTitle(true),
                'author_name'       => $booking->getHostDetails(false)['name'],
                'booking_date'      => DateTimeHelper::formatToLocale($booking->getAttendeeStartTime(), 'date'),
                'booking_time'      => DateTimeHelper::formatToLocale($booking->getAttendeeEndTime(), 'time') . ' - ' . DateTimeHelper::formatToLocale($booking->getAttendeeEndTime(), 'time'),
            ];
        }

        return [
            'bookings' => $formattedBookings,
            'total'    => $totalBookings
        ];
    }

    private static function sanitize_mapped_data($settings)
    {
        $sanitizerMap = [
            'name'  => 'sanitize_text_field',
            'email' => 'sanitize_email',
        ];

        return Helper::fcal_backend_sanitizer($settings, $sanitizerMap);
    }
}
