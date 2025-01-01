<?php

namespace FluentBooking\App\Services\Integrations\FluentForms;

use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Services\LocationService;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\BookingService;
use FluentBooking\App\Services\TimeSlotService;
use FluentBooking\App\Hooks\Handlers\TimeSlotServiceHandler;
use FluentBooking\App\Hooks\Handlers\FrontEndHandler;
use FluentForm\App\Models\Submission;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;


class FluentFormInit
{
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

        $availableSpot = $timeSlotService->isSpotAvailable($startDateTime, $endDateTime, $duration);

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

    public function handleFormSubmitted($entryId, $formDataX, $form)
    {
        $fields = FormFieldsParser::getInputs($form, ['rules', 'raw', 'name']);

        $bookingFields = array_filter($fields, function ($field) {
            return $field['element'] == 'fcal_booking';
        });

        if (!$bookingFields) {
            return;
        }

        if (\FluentForm\App\Helpers\Helper::getSubmissionMeta($entryId, 'fluent_booking_id')) {
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
                $booking = BookingService::createBooking($bookingData, $event);

                \FluentForm\App\Helpers\Helper::getSubmissionMeta($entry->id, 'fluent_booking_id', $booking->id);

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

    public function loadConversationalAsset($question, $field, $form)
    {
        if ('fcal_booking' === $field['element']) {

            $calendarEventId = Arr::get($field, 'settings.event_id');
            $calendarEvent = CalendarSlot::find($calendarEventId);

            if (!$calendarEvent || !$calendarEvent->calendar) {
                return;
            }

            [$localizeData, $elementId] = $this->getLocalizedData($calendarEvent, $field, $form);

            wp_enqueue_script(
                'fluent_booking',
                FLUENT_BOOKING_URL . 'assets/public/js/fluentform-conversational.js',
                [],
                FLUENT_BOOKING_ASSETS_VERSION,
                true
            );

            if (BookingFieldService::hasPhoneNumberField($localizeData['form_fields'])) {
                wp_enqueue_script('fluent-booking-phone-field', FLUENT_BOOKING_URL . 'assets/public/js/phone-field.js', [], FLUENT_BOOKING_ASSETS_VERSION, true);
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
            $formInstance = \FluentForm\App\Helpers\Helper::$formInstance;
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
