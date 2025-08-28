<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\App\App;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Services\BookingService;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\LandingPage\LandingPageHandler;
use FluentBooking\App\Hooks\Handlers\TimeSlotServiceHandler;
use FluentBooking\App\Services\CalendarEventService;
use FluentBooking\App\Services\LocationService;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\App\Services\CurrenciesHelper;
use FluentBooking\App\Services\SanitizeService;
use FluentBooking\Framework\Support\Arr;

class FrontEndHandler
{
    public function register()
    {
        add_shortcode('fluent_booking', [$this, 'handleBookingShortcode']);

        add_shortcode('fluent_booking_team', [$this, 'handleTeamShortcode']);

        add_shortcode('fluent_booking_calendar', [$this, 'handleCalendarShortcode']);

        add_shortcode('fluent_booking_lists', [$this, 'handleBookingListsShortcode']);

        add_shortcode('fluent_booking_receipt', [$this, 'handleReceiptShortcode']);

        add_action('wp_ajax_fluent_cal_schedule_meeting', [$this, 'ajaxScheduleMeeting']);
        add_action('wp_ajax_nopriv_fluent_cal_schedule_meeting', [$this, 'ajaxScheduleMeeting']);

        add_action('wp_ajax_fcal_cancel_meeting', [$this, 'ajaxHandleCancelMeeting']);
        add_action('wp_ajax_nopriv_fcal_cancel_meeting', [$this, 'ajaxHandleCancelMeeting']);

        add_action('wp_ajax_fluent_cal_get_available_dates', [$this, 'ajaxGetAvailableDates']);
        add_action('wp_ajax_nopriv_fluent_cal_get_available_dates', [$this, 'ajaxGetAvailableDates']);

        add_action('fluent_booking/starting_scheduling_ajax', [$this, 'handleRescheduling']);
    }

    public function handleBookingShortcode($atts, $content)
    {
        $atts = shortcode_atts([
            'id'             => 0,
            'theme'          => 'light',
            'disable_author' => 'no',
            'hash'           => ''
        ], $atts);

        if (!$atts['id'] && !$atts['hash']) {
            return '';
        }

        $calendarEvent = CalendarSlot::find($atts['id']);
        if (!$calendarEvent) {
            $calendarEvent = CalendarSlot::where('hash', $atts['hash'])->first();
            if (!$calendarEvent) {
                return __('Calendar event not found', 'fluent-booking');
            }
        }

        $calendar = $calendarEvent->calendar;
        if (!$calendar) {
            return __('Calendar not found', 'fluent-booking');
        }

        $assetUrl = App::getInstance('url.assets');

        $localizeData = $this->getCalendarEventVars($calendar, $calendarEvent);
        $localizeData['disable_author'] = $atts['disable_author'] == 'yes';
        $localizeData['theme'] = $atts['theme'];

        if (BookingFieldService::hasPhoneNumberField($localizeData['form_fields'])) {
            wp_enqueue_script('fluent-booking-phone-field', $assetUrl . 'public/js/phone-field.js', [], FLUENT_BOOKING_ASSETS_VERSION, true);
        }

        wp_enqueue_script('fluent-booking-public', $assetUrl . 'public/js/app.js', [], FLUENT_BOOKING_ASSETS_VERSION, true);

        $this->loadGlobalVars();
        wp_localize_script(
            'fluent-booking-public',
            'fcal_public_vars_' . $calendar->id . '_' . $calendarEvent->id,
            $localizeData,
        );

        return App::make('view')->make('public.calendar', [
            'calenderEvent' => $calendarEvent,
            'theme'         => $atts['theme']
        ]);
    }

    public function handleTeamShortcode($atts, $content)
    {
        $atts = shortcode_atts([
            'event_ids'   => '',
            'title'       => '',
            'description' => '',
            'logo_url'    => ''
        ], $atts);

        if (!$atts['event_ids']) {
            return '';
        }

        $eventIds = array_filter(array_map('intval', explode(',', $atts['event_ids'])));

        if (empty($eventIds)) {
            return '';
        }

        $events = CalendarSlot::query()->whereIn('id', $eventIds)
            ->where('status', 'active')
            ->get();

        $calendarIds = [];
        $calendarEvents = [];

        foreach ($events as $event) {
            $calendarIds[] = $event->calendar_id;
            if (!isset($calendarEvents[$event->calendar_id])) {
                $calendarEvents[$event->calendar_id] = [];
            }
            $event = CalendarEventService::processEvent($event);
            $calendarEvents[$event->calendar_id][] = $event;
        }

        $calendars = Calendar::query()->whereIn('id', $calendarIds)->get();

        foreach ($calendars as $calendar) {
            $calendar->activeEvents = $calendarEvents[$calendar->id] ?? [];
            $eventOrder = $calendar->getMeta('event_order');
            if (!empty($eventOrder)) {
                $eventsArray = $calendar->activeEvents;
                usort($eventsArray, function($a, $b) use ($eventOrder) {
                    $posA = array_search($a->id, $eventOrder);
                    $posB = array_search($b->id, $eventOrder);
                    return $posA - $posB;
                });
                $calendar->activeEvents = $eventsArray;
            }
        }

        return $this->renderTeamHosts($calendars, [
            'title'         => $atts['title'],
            'description'   => $atts['description'],
            'logo'          => $atts['logo_url'],
            'wrapper_class' => ''
        ]);
    }

    public function renderTeamHosts($calendars, $headerConfig = [])
    {
        $wrapperId = 'fcal_team_' . Helper::getNextIndex();
        wp_enqueue_script('fluent-booking-team', App::getInstance('url.assets') . 'public/js/team_app.js', [], FLUENT_BOOKING_ASSETS_VERSION, true);

        $vars = [];
        foreach ($calendars as $calendar) {
            $hostHtml = (string)(string)\FluentBooking\App\App::getInstance('view')->make('landing.author_html', [
                'author'   => $calendar->getAuthorProfile(),
                'calendar' => $calendar,
                'events'   => $calendar->activeEvents
            ]);

            $hostHtml .= '<div onclick="fcalBackToTeam(this)" class="fcal_back_btn_team"><svg height="20px" version="1.1" viewBox="0 0 512 512" width="512px" xml:space="preserve" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><polygon points="352,128.4 319.7,96 160,256 160,256 160,256 319.7,416 352,383.6 224.7,256 "></polygon></svg> <span>' . __('Back to team', 'fluent-booking') . '</span></div>';

            $eventCount = count($calendar->activeEvents);

            $vars['fcal_host_' . $calendar->id] = [
                'host_html'       => $hostHtml,
                'event_count'     => $eventCount,
                'target_event_id' => ($eventCount == 1) ? $calendar->activeEvents[0]->id : 0
            ];

            foreach ($calendar->activeEvents as $event) {
                $itemVars = $this->getCalendarEventVars($event->calendar, $event);
                $extraJs = (new LandingPageHandler())->getEventLandingExtraJsFiles($itemVars['form_fields'], $event);
                if ($extraJs) {
                    $itemVars['lazy_js_files'] = $extraJs;
                }
                wp_localize_script('fluent-booking-team', 'fcal_public_vars_' . $event->calendar_id . '_' . $event->id, $itemVars);
            }
        }

        wp_localize_script('fluent-booking-team', $wrapperId, $vars);

        $assetUrl = App::getInstance('url.assets');
        wp_enqueue_script('fluent-booking-public', $assetUrl . 'public/js/app.js', [], FLUENT_BOOKING_ASSETS_VERSION, true);
        $this->loadGlobalVars();

        return App::make('view')->make('public.team_page', [
            'hosts'         => $calendars,
            'wrapper_id'    => $wrapperId,
            'logo'          => Arr::get($headerConfig, 'logo', ''),
            'title'         => Arr::get($headerConfig, 'title', ''),
            'description'   => Arr::get($headerConfig, 'description', ''),
            'wrapper_class' => Arr::get($headerConfig, 'wrapper_class', '')
        ]);
    }

    public function handleCalendarShortcode($atts, $content)
    {
        $atts = shortcode_atts([
            'calendar_id' => '',
            'event_ids'   => '',
            'title'       => '',
            'description' => '',
            'logo'        => '',
            'hide_info'   => false
        ], $atts);

        $calendarId = intval($atts['calendar_id']);
        $title = sanitize_text_field($atts['title']);
        $description = sanitize_text_field($atts['description']);
        $logo = sanitize_text_field($atts['logo']);
        $hideInfo = $atts['hide_info'] ? true : false;
        $eventIds = array_filter(array_map('intval', explode(',', $atts['event_ids'])));

        if (!$calendarId) {
            return '';
        }

        $calendar = Calendar::find($calendarId);
        if (!$calendar) {
            return '';
        }

        $calendarEventQuery = CalendarSlot::where('calendar_id', $calendar->id)
            ->where('status', 'active');
        
        if ($eventIds && $eventIds != 'all') {
            $calendarEventQuery->whereIn('id', $eventIds);
        }

        $calendarEvents = $calendarEventQuery->get();

        if ($calendarEvents->isEmpty()) {
            return '';
        }

        $calendarEvents = CalendarEventService::processEvents($calendar, $calendarEvents);

        $calendar->activeEvents = $calendarEvents;

        return $this->renderCalendarBlock($calendar, [
            'title'         => $title,
            'description'   => $description,
            'logo'          => $logo,
            'hide_info'     => $hideInfo,
            'wrapper_class' => '',
        ]);
    }

    public function renderCalendarBlock($calendar, $headerConfig = [])
    {
        $wrapperId = 'fcal_calendar_' . Helper::getNextIndex();
        wp_enqueue_script('fluent-booking-calendar', App::getInstance('url.assets') . 'public/js/calendar_app.js', [], FLUENT_BOOKING_ASSETS_VERSION, true);

        $calendarHtml = (string)(string)\FluentBooking\App\App::getInstance('view')->make('landing.author_html', [
            'author'   => $calendar->getAuthorProfile(),
            'calendar' => $calendar,
            'events'   => $calendar->activeEvents,
            'hideInfo' => Arr::isTrue($headerConfig, 'hide_info'),
            'block'    => true
        ]);

        $eventCount = count($calendar->activeEvents);

        $vars['fcal_host_calendar' ] = [
            'calendar_html'   => $calendarHtml,
            'event_count'     => $eventCount,
            'target_event_id' => ($eventCount == 1) ? $calendar->activeEvents[0]->id : 0
        ];

        foreach ($calendar->activeEvents as $event) {
            $itemVars = $this->getCalendarEventVars($event->calendar, $event);
            $extraJs = (new LandingPageHandler())->getEventLandingExtraJsFiles($itemVars['form_fields'], $event);
            if ($extraJs) {
                $itemVars['lazy_js_files'] = $extraJs;
            }
            wp_localize_script('fluent-booking-calendar', 'fcal_public_vars_' . $event->calendar_id . '_' . $event->id, $itemVars);
        }

        wp_localize_script('fluent-booking-calendar', $wrapperId, $vars);

        $assetUrl = App::getInstance('url.assets');
        wp_enqueue_script('fluent-booking-public', $assetUrl . 'public/js/app.js', [], FLUENT_BOOKING_ASSETS_VERSION, true);
        $this->loadGlobalVars();

        return App::make('view')->make('public.calendar_page', [
            'calendar'      => $calendar,
            'wrapper_id'    => $wrapperId,
            'logo'          => Arr::get($headerConfig, 'logo', ''),
            'title'         => Arr::get($headerConfig, 'title', ''),
            'description'   => Arr::get($headerConfig, 'description', ''),
            'wrapper_class' => Arr::get($headerConfig, 'wrapper_class', ''),
            'hide_info'     => Arr::isTrue($headerConfig, 'hide_info')
        ]);
    }

    public function handleBookingListsShortcode($atts, $content)
    {
        $atts = shortcode_atts([
            'title'        => __('My Bookings', 'fluent-booking'),
            'filter'       => 'show',
            'pagination'   => 'show',
            'period'       => 'all',
            'calendar_ids' => 'all',
            'no_bookings'  => __('No bookings found', 'fluent-booking'),
            'per_page'     => 10
        ], $atts);
        
        $atts['title'] = sanitize_text_field($atts['title']);
        $atts['filter'] = sanitize_text_field($atts['filter']);
        $atts['pagination'] = sanitize_text_field($atts['pagination']);
        $atts['no_bookings'] = sanitize_text_field($atts['no_bookings']);

        $userData = get_userdata(get_current_user_id());
        
        $userEmail = $userData ? $userData->user_email : null;
        
        if (!$userEmail) {
            return __('Please login to view your bookings', 'fluent-booking');
        }
        
        $data = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $perPage       = intval(Arr::get($data, 'booking_per_page', $atts['per_page']));
        $currentPage   = intval(Arr::get($data, 'booking_page', 1));
        $bookingPeriod = sanitize_text_field(Arr::get($data, 'booking_period', $atts['period']));

        $bookingQuery = Booking::query()->with('calendar_event')
            ->where('email', $userEmail)
            ->applyComputedStatus($bookingPeriod)
            ->applyBookingOrderByStatus($bookingPeriod);

        if ($atts['calendar_ids'] != 'all') {
            $atts['calendar_ids'] = array_map('intval', explode(',', $atts['calendar_ids']));
            $bookingQuery->whereIn('calendar_id', $atts['calendar_ids']);
        }

        $bookings = $bookingQuery->paginate($perPage, ['*'], 'booking_page', $currentPage)
            ->appends(['booking_page' => $currentPage])
            ->withQueryString();
        
        foreach ($bookings as &$booking) {
            $booking->author_name         = $booking->getHostDetails(false)['name'];
            $booking->happening_status    = $booking->getOngoingStatus();
            $booking->booking_status_text = $booking->getBookingStatus();
            $booking->payment_status_text = $booking->getPaymentStatus();

            $booking->booking_date = DateTimeHelper::formatToLocale($booking->getAttendeeStartTime(), 'date');
            $booking->booking_time = DateTimeHelper::formatToLocale($booking->getAttendeeStartTime(), 'time') . ' - ' . DateTimeHelper::formatToLocale($booking->getAttendeeEndTime(), 'time');
        }

        $currentPage = $bookings->currentPage();
        $lastPage    = $bookings->lastPage();
        $startPage   = max(1, $currentPage - 2);
        $endPage     = min($lastPage, $currentPage + 2);

        // Adjust if near the beginning or the end
        if ($currentPage < 3) {
            $endPage = min($lastPage, 5);
        }
        if ($currentPage > $lastPage - 2) {
            $startPage = max(1, $lastPage - 4);
        }

        $periodOptions = Helper::getBookingPeriodOptions();

        $pageOptions = apply_filters('fluent_booking/booking_per_page_options', [5, 10, 15, 20, 50, 100]);

        wp_enqueue_script('fluent-booking-list', App::getInstance('url.assets') . 'public/js/bookings.js', [], FLUENT_BOOKING_ASSETS_VERSION, true);

        return App::make('view')->make('public.bookings', [
            'bookings'       => $bookings,
            'attributes'     => $atts,
            'per_page'       => $perPage,
            'start_page'     => $startPage,
            'end_page'       => $endPage,
            'booking_period' => $bookingPeriod,
            'page_options'   => $pageOptions,
            'period_options' => $periodOptions
        ]);
    }

    public function handleReceiptShortcode($atts, $content)
    {
        if (!isset($_REQUEST['hash'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return __('Booking hash is missing!', 'fluent-booking');
        }

        $hash = sanitize_text_field($_REQUEST['hash']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return apply_filters('fluent_booking/payment_receipt_html', '', $hash);
    }

    public function handleRescheduling($data)
    {
        if (empty($data['rescheduling_hash'])) {
            return;
        }

        add_filter('fluent_booking/schedule_custom_field_data', function ($array) {
            return [];
        });

        add_filter('fluent_booking/schedule_validation_rules_data', function ($data, $postedData, $calendarEvent)
        {
            $rules = $messages = [];
            $rescheduleField = BookingFieldService::getBookingFieldByName($calendarEvent, 'rescheduling_reason');

            if (Arr::isTrue($rescheduleField, 'required')) {
                $rules['rescheduling_reason'] = 'required';
                $messages['rescheduling_reason.required'] = __('Please provide a rescheduling reason', 'fluent-booking');
            }

            return [
                'rules'    => $rules,
                'messages' => $messages
            ];
        }, 10, 3);

        add_action('fluent_booking/before_creating_schedule', function ($bookingData, $postedData, $calendarEvent) {
            $existingHash = Arr::get($postedData, 'rescheduling_hash');
            $existingBooking = Booking::where('hash', $existingHash)->first();

            if (!$existingBooking) {
                wp_send_json([
                    'message' => __('Invalid rescheduling request', 'fluent-booking')
                ], 422);
            }

            $rescheduleBy = 'guest';
            $hostIds = $existingBooking->getHostIds();
            if (in_array(get_current_user_id(), $hostIds) || PermissionManager::userCan('manage_all_bookings')) {
                $rescheduleBy = 'host';
            }

            $existingBooking->updateMeta('rescheduled_by_type', $rescheduleBy);

            if ($rescheduleBy == 'guest' && !$existingBooking->canReschedule()) {
                wp_send_json([
                    'message' => $existingBooking->getRescheduleMessage()
                ], 422);
            }

            if ($bookingData['start_time'] == $existingBooking->start_time) {
                wp_send_json([
                    'message' => __('Sorry! you can not reschedule to the same time.', 'fluent-booking')
                ], 422);
            }

            $endDateTime = gmdate('Y-m-d H:i:s', strtotime($bookingData['start_time']) + ($existingBooking->slot_minutes * 60)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

            $previousBooking = clone $existingBooking;

            if ($existingBooking->isMultiGuestBooking()) {
                // Need to handle group booking type here
                // check for existing group
                $parent = Booking::where('status', 'scheduled')
                    ->where('event_id', $existingBooking->event_id)
                    ->where('start_time', $bookingData['start_time'])
                    ->orderBy('id', 'ASC')
                    ->first();

                if ($parent) {
                    $existingBooking->group_id = $parent->group_id;
                } else {
                    $existingBooking->group_id = Helper::getNextBookingGroup();
                }
            }

            if ($existingBooking->isRoundRobinBooking()) {
                $hostId = $bookingData['host_user_id'];
                $existingBooking->host_user_id = $hostId;
                $existingBooking->hosts()->sync([$hostId]);
            }

            $existingBooking->start_time = $bookingData['start_time'];
            $existingBooking->person_time_zone = $bookingData['person_time_zone'];
            $existingBooking->end_time = $endDateTime;
            $existingBooking->save();

            $existingBooking->updateMeta('previous_meeting_time', $previousBooking->start_time);

            $reschedulingMessage = sanitize_textarea_field(Arr::get($postedData, 'rescheduling_reason'));
            if ($reschedulingMessage) {
                $existingBooking->updateMeta('reschedule_reason', $reschedulingMessage);
            }

            do_action('fluent_booking/log_booking_activity', [
                'booking_id'  => $existingBooking->id,
                'type'        => 'info',
                'status'      => 'closed',
                'title'       => __('Meeting Rescheduled', 'fluent-booking'),
                /* translators: %1$s is the user who rescheduled the meeting, %2$s is the previous date and time in UTC. */
                'description' => sprintf(__('Meeting has been rescheduled by %1$s from Web UI. Previous date time: %2$s (UTC)', 'fluent-booking'), $rescheduleBy, $previousBooking->start_time)
            ]);

            do_action('fluent_booking/after_booking_rescheduled', $existingBooking, $previousBooking, $calendarEvent);

            add_filter('fluent_booking/schedule_receipt_data', function ($data) {
                $data['title'] = __('Your meeting has been rescheduled', 'fluent-booking');
                return $data;
            });

            $redirectUrl = $existingBooking->getRedirectUrlWithQuery();

            $html = BookingService::getBookingConfirmationHtml($existingBooking);

            wp_send_json(apply_filters('fluent_booking/booking_rescheduled_response', [
                'message'       => __('Booking has been rescheduled', 'fluent-booking'),
                'redirect_url'  => $redirectUrl,
                'response_html' => $html,
                'booking_hash'  => $existingBooking->hash
            ], $existingBooking), 200);

        }, 10, 3);
    }

    private function loadGlobalVars()
    {
        static $loaded;

        if ($loaded) {
            return;
        }

        $loaded = true;

        wp_localize_script('fluent-booking-public', 'fluentCalendarPublicVars', $this->getGlobalVars());
    }

    public function getGlobalVars()
    {
        $currentPerson = [
            'name'  => '',
            'email' => ''
        ];

        if (is_user_logged_in()) {
            $currentUser = wp_get_current_user();
            $name = trim($currentUser->first_name . ' ' . $currentUser->last_name);

            if (!$name) {
                $name = $currentUser->display_name;
            }

            $currentPerson = [
                'name'    => $name,
                'email'   => $currentUser->user_email,
                'user_id' => $currentUser->ID
            ];
        } else {
            $request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            // Check for url params
            if ($name = sanitize_text_field(Arr::get($request, 'invitee_name'))) {
                $currentPerson['name'] = $name;
            }

            if ($email = sanitize_email(Arr::get($request, 'invitee_email'))) {
                if (is_email($email)) {
                    $currentPerson['email'] = $email;
                }
            }
        }

        if (empty($currentPerson['email'])) {
            // Let's try to get from FluentCRM is exists
            if (defined('FLUENTCRM')) {
                $contactApi = FluentCrmApi('contacts');
                $contact = $contactApi->getCurrentContact();
                if ($contact) {
                    $currentPerson['email'] = $contact->email;
                    $currentPerson['name'] = $contact->full_name;
                }
            }
        }

        $globalSettings = Helper::getGlobalSettings();
        $startDay = Arr::get($globalSettings, 'administration.start_day', 'mon');

        $data = [
            'ajaxurl'           => admin_url('admin-ajax.php'),
            'timezones'         => DateTimeHelper::getFlatGroupedTimeZones(),
            'current_person'    => $currentPerson,
            'start_day'         => $startDay,
            'i18'               => [
                'Timezone'                      => __('Timezone', 'fluent-booking'),
                'Day'                           => __('Day', 'fluent-booking'),
                'Days'                          => __('Days', 'fluent-booking'),
                'Hour'                          => __('Hour', 'fluent-booking'),
                'Hours'                         => __('Hours', 'fluent-booking'),
                'Minute'                        => __('Minute', 'fluent-booking'),
                'Minutes'                       => __('Minutes', 'fluent-booking'),
                'Enter Details'                 => __('Enter Details', 'fluent-booking'),
                'Summary'                       => __('Summary', 'fluent-booking'),
                'Payment Details'               => __('Payment Details', 'fluent-booking'),
                'Total Payment'                 => __('Total Payment', 'fluent-booking'),
                'Payment Method'                => __('Payment Method', 'fluent-booking'),
                'Pay Now'                       => __('Pay Now', 'fluent-booking'),
                'processing'                    => __('Processing', 'fluent-booking'),
                'date_time_config'              => DateTimeHelper::getI18nDateTimeConfig(),
                'Country'                              => __('Country', 'fluent-booking'),
                '12h'                                  => _x('12h', 'date time format switch', 'fluent-booking'),
                '24h'                                  => _x('24h', 'date time format switch', 'fluent-booking'),
                'spots left'                           => _x('spots left', 'for how many spots left for available booking', 'fluent-booking'),
                'spots remaining'                      => _x('spots remaining', 'for how many spots remaining for available booking', 'fluent-booking'),
                'Next'                                 => _x('Next', 'Booking form spot selection', 'fluent-booking'),
                'Select on the Next Step'              => __('Select on the Next Step', 'fluent-booking'),
                'location options'                     => __('location options', 'fluent-booking'),
                'Your address'                         => __('Your address', 'fluent-booking'),
                'Organizer Phone Number'               => __('Organizer Phone Number', 'fluent-booking'),
                'In Person (Attendee Address)'         => __('In Person (Attendee Address)', 'fluent-booking'),
                'In Person (Organizer Address)'        => __('In Person (Organizer Address)', 'fluent-booking'),
                'Attendee Phone Number'                => __('Attendee Phone Number', 'fluent-booking'),
                'Google Meet'                          => __('Google Meet', 'fluent-booking'),
                'Zoom Meeting'                         => __('Zoom Meeting', 'fluent-booking'),
                'Online Meeting'                       => __('Online Meeting', 'fluent-booking'),
                'Phone Call'                           => __('Phone Call', 'fluent-booking'),
                'Processing...'                        => __('Processing...', 'fluent-booking'),
                'Loading Payment Processor...'         => __('Loading Payment Processor...', 'fluent-booking'),
                'PM'                                   => __('PM', 'fluent-booking'),
                'AM'                                   => __('AM', 'fluent-booking'),
                'Name'                                 => __('Name', 'fluent-booking'),
                'Email'                                => __('Email', 'fluent-booking'),
                'Date'                                 => __('Date', 'fluent-booking'),
                'Time'                                 => __('Time', 'fluent-booking'),
                'per guest'                            => __('per guest', 'fluent-booking'),
                'Add guest'                            => __('Add guest', 'fluent-booking'),
                'Add guests'                           => __('Add guests', 'fluent-booking'),
                'Add another'                          => __('Add another', 'fluent-booking'),
                'Choose File'                          => __('Choose File', 'fluent-booking'),
                'This field is required.'              => __('This field is required.', 'fluent-booking'),
                'No availability in'                   => __('No availability in', 'fluent-booking'),
                'View next month'                      => __('View next month', 'fluent-booking'),
                'View previous month'                  => __('View previous month', 'fluent-booking'),
                'No_payment_method_description'        => __('No activated payment method found. If you are an admin please check the event payment settings', 'fluent-booking'),
                'Please fill up the required data'     => __('Please fill up the required data', 'fluent-booking'),
                'Please select a valid payment method' => __('Please select a valid payment method', 'fluent-booking'),
                'Please Select'                        => __('Please Select', 'fluent-booking'),
                'Something is wrong!'                  => __('Something is wrong!', 'fluent-booking'),
                'Requires Confirmation'                => __('Requires Confirmation', 'fluent-booking'),
            ],
            'theme'             => Arr::get(get_option('_fluent_booking_settings'), 'theme', 'system-default'),
            'currency_settings' => CurrenciesHelper::getGlobalCurrencySettings()
        ];

        if (isset($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $data['user_country'] = sanitize_text_field($_SERVER['HTTP_CF_IPCOUNTRY']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        } else {
            $data['user_country'] = Arr::get($globalSettings, 'administration.default_country', '');
        }

        return apply_filters('fluent_calendar/global_booking_vars', $data);
    }

    public function ajaxScheduleMeeting()
    {
        $app = App::getInstance();

        $postedData = $_REQUEST;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $eventId = (int)$postedData['event_id'];

        $isRescheduling = Arr::get($postedData, 'rescheduling_hash', '');

        $calendarEvent = CalendarSlot::find($eventId);

        if (!$calendarEvent || ($calendarEvent->status != 'active' && !$isRescheduling)) {
            wp_send_json([
                'message' => __('Sorry, the host is not accepting any new bookings at the moment.', 'fluent-booking')
            ], 422);
        }

        do_action('fluent_booking/starting_scheduling_ajax', $postedData);

        $rules = [
            'name'       => 'required',
            'email'      => 'required|email',
            'timezone'   => 'required',
            'start_date' => 'required'
        ];

        $messages = [
            'name.required'       => __('Please enter your name', 'fluent-booking'),
            'email.required'      => __('Please enter your email address', 'fluent-booking'),
            'email.email'         => __('Please enter provide a valid email address', 'fluent-booking'),
            'timezone.required'   => __('Please select timezone first', 'fluent-booking'),
            'start_date.required' => __('Please select a date and time', 'fluent-booking')
        ];

        if ($calendarEvent->isPhoneRequired()) {
            $rules['phone_number'] = 'required';
            $messages['phone_number.required'] = __('Please provide your phone number', 'fluent-booking');
        } else if ($calendarEvent->isAddressRequired()) {
            $rules['address'] = 'required';
            $messages['address.required'] = __('Please provide your Address', 'fluent-booking');
        } else if ($calendarEvent->isLocationFieldRequired()) {
            $rules['location_config.driver'] = 'required';
            $messages['location_config.driver'] = __('Please select location', 'fluent-booking');

            $selectedLocation = LocationService::getLocationDetails($calendarEvent, Arr::get($postedData, 'location_config', []), $postedData);
            $selectedLocationDriver = Arr::get($selectedLocation, 'type');
            // is user input required
            if (in_array($selectedLocationDriver, ['in_person_guest', 'phone_guest'])) {
                $rules['location_config.user_location_input'] = 'required';
                if ($selectedLocationDriver == 'in_person_guest') {
                    $messages['location_config.user_location_input.required'] = __('Please provide your address', 'fluent-booking');
                } else {
                    $messages['location_config.user_location_input.required'] = __('Please provide your phone number', 'fluent-booking');
                }
            }
        }

        $duration = (int)$calendarEvent->getDuration(Arr::get($postedData, 'duration', null));

        if ($calendarEvent->isPaymentEnabled($duration)) {
            $rules['payment_method'] = 'required';
            $messages['payment_method.required'] = __('Please select a valid payment method', 'fluent-booking');
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

        $validator = $app->validator->make($postedData, $validationConfig['rules'], $validationConfig['messages']);
        if ($validator->validate()->fails()) {
            wp_send_json([
                'message' => __('Please fill up the required data', 'fluent-booking'),
                'errors'  => $validator->errors()
            ], 422);
            return;
        }

        $customFieldsData = BookingFieldService::getCustomFieldsData($postedData, $calendarEvent);
        $customFieldsData = apply_filters('fluent_booking/schedule_custom_field_data', $customFieldsData, $customFieldsData, $calendarEvent);

        if (is_wp_error($customFieldsData)) {
            wp_send_json([
                'message' => $customFieldsData->get_error_message(),
                'errors'  => $customFieldsData->get_error_data()
            ], 422);
            return;
        }

        $validateDateFields = BookingFieldService::validateDateFields($customFieldsData, $calendarEvent);

        if (is_wp_error($validateDateFields)) {
            wp_send_json([
                'message' => $validateDateFields->get_error_message(),
            ], 422);
            return;
        }

        $startDate = Arr::get($postedData, 'start_date');
        $timezone = sanitize_text_field(Arr::get($postedData, 'timezone', 'UTC'));

        if (is_array($startDate)) {
            $startDateTime = array_slice(
                array_map(function($date) use ($timezone) {
                    return DateTimeHelper::convertToUtc(sanitize_text_field($date), $timezone);
                }, $startDate), 0, $calendarEvent->multiBookingLimit()
            );
            $endDateTime = array_map(function($date) use ($duration) {
                return gmdate('Y-m-d H:i:s', strtotime($date) + ($duration * 60)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            }, $startDateTime);
        }

        if (is_string($startDate)) {
            $startDate = sanitize_text_field($startDate);
            $startDateTime = DateTimeHelper::convertToUtc($startDate, $timezone);
            $endDateTime = gmdate('Y-m-d H:i:s', strtotime($startDateTime) + ($duration * 60)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        $bookingData = [
            'person_time_zone' => sanitize_text_field($timezone),
            'start_time'       => $startDateTime,
            'end_time'         => $endDateTime,
            'name'             => sanitize_text_field($postedData['name']),
            'email'            => sanitize_email($postedData['email']),
            'message'          => sanitize_textarea_field(wp_unslash(Arr::get($postedData, 'message', ''))),
            'phone'            => sanitize_textarea_field(Arr::get($postedData, 'phone_number', '')),
            'address'          => sanitize_textarea_field(Arr::get($postedData, 'address', '')),
            'ip_address'       => Helper::getIp(),
            'status'           => 'scheduled',
            'source'           => 'web',
            'event_type'       => $calendarEvent->event_type,
            'slot_minutes'     => $duration,
            'utm_source'       => SanitizeService::sanitizeUtmData(Arr::get($postedData, 'utm_source', '')),
            'utm_medium'       => SanitizeService::sanitizeUtmData(Arr::get($postedData, 'utm_medium', '')),
            'utm_campaign'     => SanitizeService::sanitizeUtmData(Arr::get($postedData, 'utm_campaign', '')),
            'utm_term'         => SanitizeService::sanitizeUtmData(Arr::get($postedData, 'utm_term', ''))
        ];

        if ($calendarEvent->isConfirmationRequired($startDateTime)) {
            $bookingData['status'] = 'pending';
        }

        $locationConfig = Arr::get($postedData, 'location_config', []);
        $selectedLocation = LocationService::getLocationDetails($calendarEvent, $locationConfig, $postedData);
        if ($selectedLocation['type'] == 'phone_guest') {
            $bookingData['phone'] = $selectedLocation['description'];
        }

        $bookingData['location_details'] = $selectedLocation;

        if ($sourceUrl = Arr::get($postedData, 'source_url', '')) {
            $bookingData['source_url'] = sanitize_url($sourceUrl);
        }

        if (!empty($postedData['coupon_codes'])) {
            $bookingData['coupon_codes'] = array_map('sanitize_text_field', array_unique($postedData['coupon_codes']));
        }

        if (!empty($postedData['payment_method'])) {
            $customFieldsData['payment_method'] = sanitize_text_field($postedData['payment_method']);
        }

        $timeSlotService = TimeSlotServiceHandler::initService($calendarEvent->calendar, $calendarEvent);

        if (is_wp_error($timeSlotService)) {
            return TimeSlotServiceHandler::sendError($timeSlotService, $calendarEvent, $timezone);
        }

        $availableSpot = $timeSlotService->isSpotAvailable($startDateTime, $endDateTime, $duration);

        if (!$availableSpot) {
            wp_send_json([
                'message' => __('This selected time slot is not available. Maybe someone booked the spot just a few seconds ago.', 'fluent-booking')
            ], 422);
        }

        if ($additionalGuests) {
            $guestField = BookingFieldService::getBookingFieldByName($calendarEvent, 'guests');
            $guestLimit = Arr::get($guestField, 'limit', 10);
            if ($calendarEvent->isMultiGuestEvent()) {
                $remaining = Arr::get($availableSpot, 'remaining', $calendarEvent->getMaxBookingPerSlot());
                $guestLimit = min($remaining, $guestLimit) - 1;
            }
            $bookingData['additional_guests'] = array_slice($additionalGuests, 0, $guestLimit);
        }

        if ($calendarEvent->isRoundRobin()) {
            $bookingData['host_user_id'] = $timeSlotService->hostUserId;
        }

        do_action('fluent_booking/before_creating_schedule', $bookingData, $postedData, $calendarEvent);

        try {
            $booking = BookingService::createBooking($bookingData, $calendarEvent, $customFieldsData);

            if (is_wp_error($booking)) {
                throw new \Exception(wp_kses_post($booking->get_error_message()), 422);
            }

        } catch (\Exception $e) {
            wp_send_json([
                'message' => $e->getMessage()
            ], 422);
            return;
        }

        $redirectUrl = $booking->getRedirectUrlWithQuery();

        $html = BookingService::getBookingConfirmationHtml($booking);

        wp_send_json(apply_filters('fluent_booking/booking_confirmation_response', [
            'message'       => __('Booking has been confirmed', 'fluent-booking'),
            'redirect_url'  => $redirectUrl,
            'response_html' => $html,
            'booking_hash'  => $booking->hash
        ], $booking), 200);
    }

    public function ajaxGetAvailableDates()
    {
        $startBenchmark = microtime(true);

        $request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $eventId = (int)$request['event_id'];

        $rescheduling = Arr::get($request, 'rescheduling', 'no');

        $calendarEvent = CalendarSlot::findOrfail($eventId);

        if (!$calendarEvent || ($calendarEvent->status != 'active' && $rescheduling == 'no')) {
            wp_send_json([
                'message' => __('Sorry, the host is not accepting any new bookings at the moment.', 'fluent-booking')
            ], 422);
        }

        $calendar = $calendarEvent->calendar;
        $startDate = sanitize_text_field(Arr::get($request, 'start_date')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$startDate) {
            $startDate = gmdate('Y-m-d H:i:s'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        $timeZone = sanitize_text_field(Arr::get($request, 'timezone')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$timeZone) {
            $timeZone = wp_timezone_string();
        }

        if (!in_array($timeZone, \DateTimeZone::listIdentifiers())) {
            $timeZone = $calendar->author_timezone;
        }

        $duration = (int)$calendarEvent->getDuration(Arr::get($request, 'duration', null));

        $timeSlotService = TimeSlotServiceHandler::initService($calendar, $calendarEvent);
        
        if (is_wp_error($timeSlotService)) {
            return TimeSlotServiceHandler::sendError($timeSlotService, $calendarEvent, $timeZone);
        }

        $availableSpots = $timeSlotService->getAvailableSpots($startDate, $timeZone, $duration);

        if (is_wp_error($availableSpots)) {
            return TimeSlotServiceHandler::sendError($availableSpots, $calendarEvent, $timeZone);
        }

        $availableSpots = array_filter((array)$availableSpots);
        $availableSpots = apply_filters('fluent_booking/available_slots_for_view', $availableSpots, $calendarEvent, $calendar, $timeZone, $duration);

        wp_send_json([
            'available_slots' => $availableSpots,
            'timezone'        => $timeZone,
            'max_lookup_date' => $calendarEvent->getMaxLookUpDate(),
            'execution_time'  => microtime(true) - $startBenchmark
        ], 200);
    }

    public function getCalendarEventVars(Calendar $calendar, CalendarSlot $calendarEvent)
    {
        $calendarEvent->description = wpautop($calendarEvent->description);
        $calendarEvent->location_icon_html = $calendarEvent->defaultLocationHtml();
        $formFields = BookingFieldService::getBookingFields($calendarEvent);

        $eventData = [
            'id'                 => $calendarEvent->id,
            'max_lookup_date'    => $calendarEvent->getMaxLookUpDate(),
            'min_lookup_date'    => $calendarEvent->getMinLookUpDate(),
            'min_bookable_date'  => $calendarEvent->getMinBookableDateTime(),
            'is_display_spots'   => $calendarEvent->isDisplaySpots(),
            'duration'           => $calendarEvent->getDefaultDuration(),
            'title'              => $calendarEvent->title,
            'location_settings'  => $calendarEvent->location_settings,
            'location_icon_html' => $calendarEvent->location_icon_html,
            'description'        => $calendarEvent->description,
            'pre_selects'        => null,
            'settings'           => $calendarEvent->settings,
            'type'               => $calendarEvent->type,
            'event_type'         => $calendarEvent->event_type,
            'time_format'        => Arr::get(get_option('_fluent_booking_settings'), 'time_format', '12'),
        ];

        $author = $calendar->getAuthorProfile(true);
        $author['name'] = $calendar->title;

        $eventVars = [
            'slot'            => $eventData,
            'author_profile'  => $author,
            'form_fields'     => $formFields,
            'i18n'            => [
                'Schedule_Meeting'     => __('Schedule Meeting', 'fluent-booking'),
                'Continue_to_Payments' => __('Continue to Payments', 'fluent-booking'),
                'Confirm_Payment'      => __('Confirm Payment', 'fluent-booking'),
            ],
            'date_formatter'  => DateTimeHelper::getDateFormatter(true),
            'isRtl'           => Helper::fluentbooking_is_rtl(),
            'duration_lookup' => Helper::getDurationLookup(),
            'multi_duration_lookup' => Helper::getDurationLookup(true)
        ];

        $eventVars['form_fields'] = array_values($eventVars['form_fields']);

        if (!$calendar->isHostCalendar()) {
            $eventVars['team_member_profiles'] = $calendarEvent->getAuthorProfiles(true);
        }

        return apply_filters('fluent_booking/public_event_vars', $eventVars, $calendarEvent);
    }

    public function ajaxHandleCancelMeeting()
    {
        $data = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $meetingHash = Arr::get($data, 'meeting_hash');

        $meeting = Booking::where('hash', $meetingHash)->first();

        if (!$meeting) {
            wp_send_json([
                'message' => __('Sorry! meeting could not be found', 'fluent-booking')
            ], 422);
        }

        if (!$meeting->canCancel()) {
            wp_send_json([
                'message' => $meeting->getCancellationMessage()
            ], 422);
        }

        $message = sanitize_textarea_field(Arr::get($data, 'cancellation_reason', ''));

        $cancelField = BookingFieldService::getBookingFieldByName($meeting->calendar_event, 'cancellation_reason');

        if (!$message && Arr::isTrue($cancelField, 'required')) {
            wp_send_json([
                'message' => __('Please provide a reason for cancellation', 'fluent-booking')
            ], 422);
        }

        $result = $meeting->cancelMeeting($message, 'guest', get_current_user_id());

        if (is_wp_error($result)) {
            if (!wp_doing_ajax()) {
                wp_redirect($meeting->getConfirmationUrl());
                exit();
            }

            wp_send_json([
                'message' => $result->get_error_message()
            ], 422);
        }

        if (wp_doing_ajax()) {
            wp_send_json([
                'message' => __('Meeting has been cancelled', 'fluent-booking')
            ], 200);
        }

        wp_redirect($meeting->getConfirmationUrl());
        exit;
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
