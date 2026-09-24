<?php

namespace FluentBooking\App\Services\Integrations\FluentForms;

use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Services\LocationService;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\BookingService;
use FluentBooking\App\Hooks\Handlers\TimeSlotServiceHandler;
use FluentBooking\App\Hooks\Handlers\FrontEndHandler;
use FluentForm\App\Models\Submission;
use FluentForm\App\Helpers\Helper as FluentFormHelper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentBooking\App\Vite;


class FluentFormInit
{
    // Fluent Forms keys its Offline Payment method 'test'.
    const FF_OFFLINE_METHOD = 'test';

    protected $hostId;

    public function init()
    {
        $this->registerHooks();
        $this->registerIntegrations();
    }

    public function registerHooks()
    {
        add_action('fluentform/validate_input_item_fcal_booking', [$this, 'handleValidations'], 10, 3);
        add_action('fluentform/notify_on_form_submit', [$this, 'handleFormSubmitted'], 10, 3);
        add_action('fluentform/after_payment_status_change', [$this, 'handlePaymentStatusChanged'], 10, 2);
        add_action('fluentform/conversational_question', [$this, 'loadConversationalAsset'], 10, 3);

        add_filter('fluentform/conversational_field_types', function ($fieldTypes) {
            $fieldTypes['fcal_booking'] = 'FlowFormCustomType';
            return $fieldTypes;
        });

        add_filter('fluentform/conversational_accepted_field_elements', function ($elements) {
            $elements[] = 'fcal_booking';
            return $elements;
        });

        add_action('fluent_booking/booking_meta_info_main_meta_fluentform', [$this, 'pushFormDataToBooking'], 10, 2);
    }

    public function registerIntegrations()
    {
        add_action('init', function () {
            new BookingElement();
        });
    }

    public function handleValidations($error, $field, $formData)
    {
        if ($error) {
            return $error;
        }

        $name = Arr::get($field, 'name');

        if (!isset($formData[$name])) {
            return $error;
        }

        $isRequired = Arr::get($field, 'rules.required.value');

        $bookingData = Arr::get($formData, $name);

        if ($bookingData) {
            $bookingData = json_decode($bookingData, true);
        } else {
            $bookingData = [];
        }

        if ($isRequired) {
            if (empty($bookingData['start_time']) || empty($bookingData['timezone'])) {
                $error = Arr::get($field, 'rules.required.message');
                if (!$error) {
                    // translators: %s is the label of the required field
                    $error = sprintf(__('%s field is required', 'fluent-booking'), Arr::get($field, 'raw.settings.label'));
                }
                return $error;
            }
        } else if (empty($bookingData['start_time'])) {
            return $error;
        }

        $eventId = Arr::get($field, 'raw.settings.event_id');
        $calendarEvent = CalendarSlot::find($eventId);

        if (!$calendarEvent || $calendarEvent->status != 'active') {
            return __('Sorry, the host is not accepting any new bookings at the moment.', 'fluent-booking');
        }

        $duration = $calendarEvent->getDuration(Arr::get($bookingData, 'duration', null));

        $startTime = Arr::get($bookingData, 'start_time');
        $timeZone = $bookingData['timezone'];

        $startDateTime = DateTimeHelper::convertToUtc($startTime, $timeZone);
        $endDateTime = gmdate('Y-m-d H:i:s', strtotime($startDateTime) + ($duration * 60));

        $timeSlotService = TimeSlotServiceHandler::initService($calendarEvent->calendar, $calendarEvent);

        if (is_wp_error($timeSlotService)) {
            return TimeSlotServiceHandler::sendError($timeSlotService, $calendarEvent, $timeZone);
        }

        $isSlotLocked = Helper::lockRoundRobinSlot($calendarEvent, $startDateTime, $endDateTime);

        $availableSpot = $isSlotLocked ? $timeSlotService->isSpotAvailable($startDateTime, $endDateTime, $duration) : false;

        if (!$availableSpot) {
            $message = __('This selected time slot is not available. Maybe someone booked the spot just a few seconds ago.', 'fluent-booking');
            wp_send_json(['errors' => [$message]], 422);
        }

        if ($calendarEvent->isRoundRobin()) {
            $this->hostId = $timeSlotService->hostUserId;
        }

        if (!is_user_logged_in()) {
            $fieldError = '';
            // Now check if the email field is given or not
            $emailFieldKey = Arr::get($field, 'raw.settings.cal_guest_fields.email_field');
            if (!$emailFieldKey) {
                $fieldError = __('Email is required for this appointment. Looks like this field does not have email field selected.', 'fluent-booking');
            } else {
                $email = Arr::get($formData, $emailFieldKey);
                if (!$email || !is_email($email)) {
                    $fieldError = __('Email is required for this appointment. Please provide a valid email', 'fluent-booking');
                }
            }

            if ($fieldError) {
                return $fieldError;
            }
        }

        $locationFieldKey = $this->getLocationFieldKey($calendarEvent);

        if ($locationFieldKey) {
            $requiredKeys = [];
            if ($locationFieldKey == 'location') {
                $locationFieldKey = 'location_config';
            }

            $userInputData = Arr::get($bookingData, 'form.' . $locationFieldKey);

            if (in_array($locationFieldKey, ['phone_number', 'address'])) {
                $requiredKeys[] = $locationFieldKey;
            } else if ($locationFieldKey == 'location_config') {
                $requiredKeys[] = 'location_config.driver';
                $selectedLocation = LocationService::getLocationDetails($calendarEvent, $userInputData, $bookingData['form']);
                $selectedLocationDriver = Arr::get($selectedLocation, 'type');
                if (in_array($selectedLocationDriver, ['in_person_guest', 'phone_guest'])) {
                    $requiredKeys[] = 'location_config.user_location_input';
                }
            }

            foreach ($requiredKeys as $requiredKey) {
                if (!Arr::get($bookingData['form'], $requiredKey)) {
                    return __('Please provide a valid location for this meeting', 'fluent-booking');
                }
            }
        }

        /*
         * We are decoding the data with valid array
         */
        add_filter('fluentform/insert_response_data', function ($data) use ($name, $calendarEvent) {

            if (isset($data[$name]) && is_string($data[$name])) {
                $bookingArr = json_decode($data[$name], true);
                $validData = array_filter(Arr::only($bookingArr, ['start_time', 'timezone', 'duration', 'form.location_config', 'form.phone_number', 'form.address']));
                $extendedData = array_filter(Arr::only(Arr::get($bookingArr, 'form', []), ['location_config', 'phone_number', 'address']));

                if ($extendedData) {
                    $validData = array_merge($validData, $extendedData);
                }

                if ($validData) {
                    $validData['duration'] = $calendarEvent->getDuration(Arr::get($validData, 'duration', null));
                    $validData['end_time'] = gmdate('Y-m-d H:i:s', strtotime($bookingArr['start_time']) + ($validData['duration'] * 60));
                }

                $data[$name] = (array)$validData;
            }

            return $data;
        });


        return '';
    }

    /**
     * Read the Fluent Forms values mapped onto the event's custom booking fields.
     * The form's own field rules decide what is required; values are only sanitized here.
     *
     * @param array $field parsed fcal_booking field
     * @param array $formData submitted form values keyed by field name
     * @param CalendarSlot $event
     *
     * @return array
     */
    private function getMappedFieldsData($field, $formData, CalendarSlot $event)
    {
        $fieldMap = array_filter((array) Arr::get($field, 'raw.settings.cal_guest_fields.field_map', []));

        if (!$fieldMap) {
            return [];
        }

        // Form values arrive unslashed; getCustomFieldsData() unslashes, so slash to keep backslashes.
        $postedData = [];
        foreach ($fieldMap as $bookingFieldKey => $formFieldName) {
            $postedData[$bookingFieldKey] = wp_slash(Arr::get($formData, $formFieldName));
        }

        return BookingFieldService::getCustomFieldsData($postedData, $event, array_keys($fieldMap));
    }

    public function handleFormSubmitted($entryId, $formDataX, $form)
    {
        $fields = FormFieldsParser::getInputs($form, ['rules', 'raw', 'name']);

        $bookingFields = array_filter($fields, function ($field) {
            return $field['element'] == 'fcal_booking';
        });

        if (!$bookingFields) {
            return;
        }

        if (FluentFormHelper::getSubmissionMeta($entryId, 'fluent_booking_id')) {
            return; // Already processed
        }

        $entry = wpFluent()->table('fluentform_submissions')
            ->where('id', $entryId)
            ->first();

        if (!$entry) {
            return;
        }

        $formData = json_decode($entry->response, true);

        foreach ($bookingFields as $bookingField) {
            $fieldName = Arr::get($bookingField, 'raw.attributes.name');
            $ffFieldData = Arr::get($formData, $fieldName);

            if (!$ffFieldData) {
                continue;
            }

            if (is_string($ffFieldData)) {
                $ffFieldData = json_decode($ffFieldData, true);
            }

            if (empty($ffFieldData['timezone']) || empty($ffFieldData['start_time']) || empty($ffFieldData['duration'])) {
                continue;
            }

            $eventId = Arr::get($bookingField, 'raw.settings.event_id');
            $event = CalendarSlot::find($eventId);

            if (!$event || $event->status != 'active') {
                continue;
            }

            $submittedData = json_decode($entry->response, true);

            $emailFieldKey = Arr::get($bookingField, 'raw.settings.cal_guest_fields.email_field');
            $guestEmail = '';
            $guestName = '';
            if ($emailFieldKey) {
                $guestEmail = Arr::get($submittedData, $emailFieldKey);
                $nameFieldKey = Arr::get($bookingField, 'raw.settings.cal_guest_fields.name_field');

                $guestName = Arr::get($submittedData, $nameFieldKey);
                if (is_array($guestName)) {
                    $guestName = implode(' ', $guestName);
                }
            }

            if (!$guestEmail) {
                $guestEmail = Arr::get($submittedData, 'email');
                $guestName = Arr::get($submittedData, 'names');
                if (is_array($guestName)) {
                    $guestName = implode(' ', $guestName);
                }
            }

            if (!$guestEmail && $entry->user_id) {
                $user = get_user_by('id', $entry->user_id);
                if ($user) {
                    $guestEmail = $user->user_email;
                    $guestName = trim($user->first_name . ' ' . $user->last_name);
                    if (!$guestName) {
                        $guestName = $user->display_name;
                    }
                }
            }

            if (!$guestEmail || !is_email($guestEmail)) {
                do_action('fluentform/log_data', [
                    'parent_source_id' => $form->id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $entry->id,
                    'component'        => 'FluentBooking',
                    'status'           => 'error',
                    'title'            => __('Appointment could not be created', 'fluent-booking'),
                    'description'      => __('Appointment could not be created because email is not given or invalid', 'fluent-booking'),
                ]);
                continue;
            }

            $startTime = $ffFieldData['start_time'];
            $timeZone  = $ffFieldData['timezone'];
            $duration  = $ffFieldData['duration'];

            $startDateTime = DateTimeHelper::convertToUtc($startTime, $timeZone);

            $bookingData = [
                'start_time'       => $startDateTime,
                'name'             => $guestName,
                'email'            => $guestEmail,
                'person_time_zone' => sanitize_text_field($ffFieldData['timezone']),
                'source'           => 'fluentform',
                'source_id'        => $entry->id,
                'status'           => 'scheduled',
                'source_url'       => $entry->source_url,
                'ip_address'       => $entry->ip,
                'event_type'       => $event->event_type,
                'slot_minutes'     => $duration
            ];

            if ($event->isConfirmationRequired($startDateTime)) {
                $bookingData['status'] = 'pending';
            }

            $bookingData = $this->maybeAddPaymentData($bookingData, $entry);

            if ($entry->user_id) {
                $bookingData['person_user_id'] = $entry->user_id;
            }

            if ($this->hostId) {
                $bookingData['host_user_id'] = $this->hostId;
            }

            $selectedLocation = LocationService::getLocationDetails($event, Arr::get($ffFieldData, 'location_config', []), $ffFieldData);
            if ($selectedLocation['type'] == 'phone_guest') {
                $bookingData['phone'] = sanitize_textarea_field($selectedLocation['description']);
            } else if (!empty($ffFieldData['address'])) {
                $bookingData['address'] = sanitize_textarea_field($ffFieldData['address']);
            }
            $bookingData['location_details'] = $selectedLocation;

            try {
                $customFieldsData = $this->getMappedFieldsData($bookingField, $submittedData, $event);

                $booking = BookingService::createBooking($bookingData, $event, $customFieldsData);

                // Must persist, or the guard above misses a payment retry on the same submission.
                FluentFormHelper::setSubmissionMeta($entry->id, 'fluent_booking_id', $booking->id, $form->id);

                $fieldData = $submittedData[$fieldName];
                $fieldData['booking_id'] = $booking->id;

                $submittedData[$fieldName] = (array)$fieldData;

                wpFluent()->table('fluentform_submissions')
                    ->where('id', $entryId)
                    ->update([
                        'response' => wp_json_encode($submittedData, JSON_UNESCAPED_UNICODE)
                    ]);

                do_action('fluentform/log_data', [
                    'parent_source_id' => $form->id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $entry->id,
                    'component'        => 'FluentBooking',
                    'status'           => 'info',
                    'title'            => __('Booking has been created on FluentBooking', 'fluent-booking'),
                    /* translators: %1$s is the opening anchor tag, %2$s is the closing anchor tag. */
                    'description'      => sprintf(__('A new appointment has been created on FluentBooking. %1$sView Booking Details%2$s', 'fluent-booking'), '<a rel="noopener" href="' . $booking->getAdminViewUrl() . '" target="_blank">', '</a>'),
                ]);

            } catch (\Exception $exception) {
                do_action('fluentform/log_data', [
                    'parent_source_id' => $form->id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $entry->id,
                    'component'        => 'FluentBooking',
                    'status'           => 'error',
                    'title'            => __('Failed to create booking', 'fluent-booking'),
                    'description'      => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Fluent Forms fires notify_on_form_submit before the gateway runs, so the row is
     * written to hold the slot but waits for the payment, as the cart integration does.
     * Offline is honoured up front, like the native offline method.
     *
     * @param array $bookingData
     * @param object $entry fluentform_submissions row
     *
     * @return array
     */
    private function maybeAddPaymentData($bookingData, $entry)
    {
        $submissionStatus = isset($entry->payment_status) ? $entry->payment_status : '';

        if (!$submissionStatus) {
            return $bookingData; // Not a payment submission
        }

        $paymentStatus = $this->mapPaymentStatus($submissionStatus);
        $isOffline = isset($entry->payment_method) && $entry->payment_method === self::FF_OFFLINE_METHOD;

        // 'offline' is the only method FiveMinuteScheduler::maybeAutoCancelBooking exempts.
        $bookingData['payment_method'] = $isOffline ? 'offline' : 'fluentform';
        $bookingData['payment_status'] = $paymentStatus;

        if ($paymentStatus != 'paid' && !$isOffline) {
            $bookingData['status'] = 'pending';
        }

        return $bookingData;
    }

    /**
     * @param string $submissionStatus Fluent Forms payment status
     *
     * @return string FluentBooking payment status
     */
    private function mapPaymentStatus($submissionStatus)
    {
        $statusMap = [
            'paid'               => 'paid',
            'refunded'           => 'refunded',
            'partially-refunded' => 'partially-refunded',
            'failed'             => 'failed',
            'cancelled'          => 'failed'
        ];

        // pending / processing / requires_review all mean "not settled yet"
        return Arr::get($statusMap, $submissionStatus, 'pending');
    }

    /**
     * @param string $newStatus Fluent Forms payment status
     * @param object $submission Submission model or fluentform_submissions row
     */
    public function handlePaymentStatusChanged($newStatus, $submission)
    {
        $submissionId = $this->readSubmissionId($submission);

        if (!$submissionId) {
            return;
        }

        // Fires for every payment on the site; Fluent Forms' indexed meta is the cheap check.
        if (!FluentFormHelper::getSubmissionMeta($submissionId, 'fluent_booking_id')) {
            return;
        }

        // A form can carry several booking fields, so a submission can own several bookings.
        $bookings = Booking::where('source', 'fluentform')
            ->where('source_id', $submissionId)
            ->get();

        if ($bookings->isEmpty()) {
            return;
        }

        $paymentStatus = $this->mapPaymentStatus($newStatus);

        foreach ($bookings as $booking) {
            if ($paymentStatus == 'paid') {
                $this->confirmPaidBooking($booking);
                continue;
            }

            if ($booking->payment_status == $paymentStatus) {
                continue;
            }

            if ($this->undoesSettledOutcome($paymentStatus, $booking->payment_status)) {
                continue;
            }

            // Failure and refund leave the booking status alone, as the native gateways do.
            $this->settlePaymentStatus($booking, $paymentStatus);
        }
    }

    /**
     * One payment module passes a Submission model, whose columns live behind __get,
     * the other a plain row. Property access reads both; an array cast reads only the row.
     *
     * @param object $submission
     *
     * @return int
     */
    private function readSubmissionId($submission)
    {
        if (!is_object($submission)) {
            return 0;
        }

        return isset($submission->id) ? (int)$submission->id : 0;
    }

    /**
     * Statuses that already record an outcome. An unsettled event landing on one of
     * these is stale delivery, not a state change.
     */
    const SETTLED_PAYMENT_STATUSES = ['paid', 'refunded', 'partially-refunded'];

    /**
     * @param string $paymentStatus
     *
     * @return bool
     */
    private function isSettled($paymentStatus)
    {
        return in_array($paymentStatus, self::SETTLED_PAYMENT_STATUSES, true);
    }

    /**
     * Redelivered events arrive out of order, and one carrying no outcome must not
     * overwrite a recorded one - flattening a refund would hide it from the paid path.
     *
     * @param string $paymentStatus incoming
     * @param string $currentPaymentStatus stored on the booking
     *
     * @return bool
     */
    private function undoesSettledOutcome($paymentStatus, $currentPaymentStatus)
    {
        return !$this->isSettled($paymentStatus) && $this->isSettled($currentPaymentStatus);
    }

    /**
     * @param string $paymentStatus
     *
     * @return bool
     */
    private function isRefunded($paymentStatus)
    {
        return in_array($paymentStatus, ['refunded', 'partially-refunded'], true);
    }

    /**
     * The precedence between two events landing together is settled in SQL, not against
     * the row as it was read: a refund is the final outcome and lands whatever arrived
     * first, anything else only fills in a booking with no outcome recorded yet.
     *
     * @param \FluentBooking\App\Models\Booking $booking
     * @param string $paymentStatus
     */
    private function settlePaymentStatus($booking, $paymentStatus)
    {
        $query = Booking::where('id', $booking->id);

        if (!$this->isRefunded($paymentStatus)) {
            $query->whereNotIn('payment_status', self::SETTLED_PAYMENT_STATUSES);
        }

        $query->update([
            'payment_status' => $paymentStatus,
            'updated_at'     => gmdate('Y-m-d H:i:s') // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        ]);
    }

    /**
     * @param \FluentBooking\App\Models\Booking $booking
     */
    private function confirmPaidBooking($booking)
    {
        if ($booking->payment_status == 'paid') {
            return;
        }

        $calendarEvent = $booking->calendar_event;

        if (!$calendarEvent) {
            return;
        }

        // Webhooks arrive late and out of order, and a refund is the last word on an
        // order - a stale 'paid' must not settle it again, let alone put it back on
        // the calendar. Logged rather than dropped, so the mismatch is visible.
        if ($this->isRefunded($booking->payment_status)) {
            do_action('fluent_booking/log_booking_activity', [
                'booking_id'  => $booking->id,
                'status'      => 'closed',
                'type'        => 'error',
                'title'       => __('Fluent Forms: Payment status could not be changed', 'fluent-booking'),
                /* translators: %s is the current payment status of the booking */
                'description' => sprintf(__('A paid notification arrived after the order was %s, so the booking was left unchanged.', 'fluent-booking'), $booking->getPaymentStatus())
            ]);
            return;
        }

        if ($booking->status != 'pending') {
            // Honoured up front, or already moved on - only settle the payment.
            $this->settlePaymentStatus($booking, 'paid');
            return;
        }

        $isRequireConfirmation = $calendarEvent->isConfirmationRequired($booking->start_time, $booking->created_at);

        // Awaiting approval stays pending; paying only releases the held-back notifications.
        $newStatus = $isRequireConfirmation ? 'pending' : 'scheduled';

        // Gateways redeliver, so only the request that settles the row dispatches the hooks.
        $flagged = Booking::where('id', $booking->id)
            ->where('status', 'pending')
            ->whereNotIn('payment_status', self::SETTLED_PAYMENT_STATUSES)
            ->update([
                'status'         => $newStatus,
                'payment_status' => 'paid',
                'updated_at'     => gmdate('Y-m-d H:i:s') // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ]);

        if (!$flagged) {
            return;
        }

        $booking = Booking::with(['calendar_event', 'calendar'])->find($booking->id);

        do_action('fluent_booking/log_booking_activity', [
            'booking_id'  => $booking->id,
            'status'      => 'closed',
            'type'        => 'success',
            'title'       => __('Payment completed on Fluent Forms', 'fluent-booking'),
            /* translators: %s is the booking status after the payment has been completed */
            'description' => sprintf(__('The form payment has been paid and the appointment is now in %s status.', 'fluent-booking'), $booking->getBookingStatus())
        ]);

        $bookingData = [
            'name'  => $booking->first_name . ' ' . $booking->last_name,
            'email' => $booking->email,
            'phone' => $booking->phone
        ];

        // this pre hook is for early actions that require for remote calendars and locations
        do_action('fluent_booking/pre_after_booking_' . $newStatus, $booking, $calendarEvent, $bookingData);

        $booking = Booking::with(['calendar_event', 'calendar'])->find($booking->id);

        do_action('fluent_booking/after_booking_' . $newStatus, $booking, $calendarEvent, $bookingData);
    }

    public function loadConversationalAsset($question, $field, $form)
    {
        if ('fcal_booking' === $field['element']) {

            $calendarEventId = Arr::get($field, 'settings.event_id');
            $calendarEvent = CalendarSlot::find($calendarEventId);

            if (!$calendarEvent || !$calendarEvent->calendar) {
                return;
            }

            [$localizeData, $elementId] = $this->getLocalizedData($calendarEvent, $field, $form);

            Vite::enqueueScript('fluent_booking', 'ff_conversational', [], FLUENT_BOOKING_ASSETS_VERSION);

            if (BookingFieldService::hasPhoneNumberField($localizeData['form_fields'])) {
                Vite::enqueueScript('fluent-booking-phone-field', 'phone_field', [], FLUENT_BOOKING_ASSETS_VERSION);
                $inlineStyle = '.fcal_phone_wrapper .flag { background: url(' . esc_url(FLUENT_BOOKING_URL . 'assets/images/flags_responsive.png') . ') no-repeat;background-size: 100%;}';
                wp_add_inline_style('fluent-booking-phone-field', $inlineStyle);
            }

            wp_localize_script('fluent_booking', 'fcal_public_vars_' . $question['id'], $localizeData);
            wp_localize_script('fluent_booking', 'fluentCalendarPublicVars', (new FrontEndHandler())->getGlobalVars());
        }
    }

    public function pushFormDataToBooking($meta, $booking)
    {
        if (!$booking->source_id) {
            return $meta;
        }

        try {
            $submission = Submission::find($booking->source_id);

            if (!$submission) {
                return $meta;
            }

            $response = json_decode($submission->response);

            $smartCode = '{all_data}';

            if ($submission->payment_total) {
                $smartCode .= '<h3>' . __('Related Payments', 'fluent-booking') . '</h3>{payment.receipt}';
            }

            $entryHtmlData = ShortCodeParser::parse(
                $smartCode,
                $submission->id,
                $response,
                $submission->form,
                false,
                true
            );

            $entryHtmlData .= '<p><a target="_blank" rel="noopener" href="' . admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . $submission->form_id . '#/entries/' . $submission->id) . '">' . __('View Form Submission', 'fluent-booking') . '</a></p>';

            $meta[] = [
                'id'      => 'fluentform',
                'title'   => __('Related Form Data', 'fluent-booking'),
                'content' => $entryHtmlData
            ];
        } catch (\Exception $e) {

        }

        return $meta;
    }

    public function getLocalizedData($calendarEvent, $data, $form)
    {
        $element_id = $this->makeElementId($data, $form);

        $calendar = $calendarEvent->calendar;

        $settings = Arr::get($data, 'settings');

        $name = Arr::get($data, 'attributes.name');

        $localizeData = (new FrontEndHandler())->getCalendarEventVars($calendar, $calendarEvent);

        $localizeData['name'] = $name;
        $localizeData['settings'] = $settings;

        $isHostEnabled = Arr::get($localizeData['settings']['cal_guest_fields'], 'host_info', 'hide') == 'show';

        $showHostInfo = $isHostEnabled || Arr::isTrue($calendarEvent->settings, 'multi_duration.enabled');

        if ($showHostInfo) {
            $localizeData['disable_author'] = false;
        } else {
            $localizeData['disable_author'] = true;
        }

        if(!empty($form->instance_css_class)) {
            $localizeData['form_instance'] = $form->instance_css_class;
        }

        $locationFieldKey = $this->getLocationFieldKey($calendarEvent);
        if ($locationFieldKey) {
            $formFields = $localizeData['form_fields'];
            $formFields = array_filter($formFields, function ($field) use ($locationFieldKey) {
                return $field['name'] == $locationFieldKey;
            });

            $localizeData['form_fields'] = array_values($formFields);
        } else {
            $localizeData['form_fields'] = [];
        }

        return [$localizeData, $element_id];
    }

    private function getLocationFieldKey($calendarEvent)
    {
        $locationFieldKey = '';
        if ($calendarEvent->isPhoneRequired()) {
            $locationFieldKey = 'phone_number';
        } else if ($calendarEvent->isAddressRequired()) {
            $locationFieldKey = 'address';
        } else if ($calendarEvent->isLocationFieldRequired()) {
            $locationFieldKey = 'location';
        }
        return $locationFieldKey;
    }

    /**
     * Build unique ID concatenating form id and name attribute
     *
     * @param array $data $form
     *
     * @return string for id value
     */
    protected function makeElementId($data, $form)
    {
        if (isset($data['attributes']['name'])) {
            $formInstance = FluentFormHelper::$formInstance;
            if (!empty($data['attributes']['id'])) {
                return $data['attributes']['id'];
            }
            $elementName = $data['attributes']['name'];
            $elementName = str_replace(['[', ']', ' '], '_', $elementName);

            $suffix = esc_attr($form->id);
            if ($formInstance > 1) {
                $suffix = $suffix . '_' . $formInstance;
            }

            $suffix .= '_' . $elementName;

            return 'ff_' . esc_attr($suffix);
        }
    }
}
