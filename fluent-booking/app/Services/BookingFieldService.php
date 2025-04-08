<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\Framework\Support\Arr;

class BookingFieldService
{
    public static function getCustomFieldsData($postedData, CalendarSlot $slot)
    {
        $customFields = self::getCustomFields($slot, true);

        $errors = [];

        $formattedValues = [];

        foreach ($customFields as $fieldKey => $customField) {
            $value = wp_unslash(Arr::get($postedData, $fieldKey));
            if (Arr::isTrue($customField, 'required')) {
                $isTerms = $customField['type'] === 'terms-and-conditions';
                $isCheckbox = $customField['type'] === 'checkbox';
                if (!$value || ($isCheckbox && $value !== 'Yes') || ($isTerms && $value !== 'Accepted')) {
                    $errors[$fieldKey . '.required'] = sprintf(__('%s is required', 'fluent-booking'), $customField['label']);
                    continue;
                }
            }

            if (is_array($value)) {
                if ($customField['type'] === 'multi-select') {
                    $value = array_map(function ($item) {
                        return sanitize_text_field(Arr::get($item, 'value'));
                    },$value);
                } else if ($customField['type'] === 'file') {
                    $maxField = Arr::get($customField, 'max_file_allow', 1);
                    $value = array_slice($value, 0, $maxField);
                    $value = array_map('sanitize_text_field', $value);
                } else {
                    $value = array_map('sanitize_text_field', $value);
                }
            } else if ($customField['type'] == 'textarea') {
                $value = sanitize_textarea_field($value);
            } else {
                $value = sanitize_text_field($value);
            }

            $formattedValues[$fieldKey] = $value;
        }

        if ($errors) {
            return new \WP_Error('required_field', __('Please fill up the required data', 'fluent-booking'), $errors);
        }

        return $formattedValues;
    }

    public static function getBookingFields(CalendarSlot $calendarSlot, $cached = false)
    {
        static $bookingFields = null;

        if ($cached && $bookingFields) {
            return $bookingFields;
        }

        $requiredIndexes = ['name', 'email', 'message', 'cancellation_reason', 'rescheduling_reason'];

        $defaultFields = [
            'name' => [
                'index'          => 1,
                'type'           => 'text',
                'name'           => 'name',
                'label'          => __('Your Name', 'fluent-booking'),
                'required'       => true,
                'enabled'        => true,
                'system_defined' => true,
                'disable_alter'  => true,
                'is_visible'     => true,
                'placeholder'    => __('Your Name', 'fluent-booking'),
                'help_text'      => ''
            ],
            'email' => [
                'index'          => 2,
                'type'           => 'email',
                'name'           => 'email',
                'label'          => __('Your Email', 'fluent-booking'),
                'required'       => true,
                'enabled'        => true,
                'system_defined' => true,
                'disable_alter'  => true,
                'is_visible'     => true,
                'placeholder'    => __('Your Email', 'fluent-booking'),
                'help_text'      => ''
            ],
            'message' => [
                'index'          => 3,
                'type'           => 'textarea',
                'name'           => 'message',
                'label'          => __('What is this meeting about?', 'fluent-booking'),
                'required'       => false,
                'enabled'        => true,
                'system_defined' => true,
                'disable_alter'  => false,
                'help_text'      => ''
            ],
            'cancellation_reason' => [
                'index'          => 4,
                'type'           => 'textarea',
                'name'           => 'cancellation_reason',
                'label'          => __('Reason for cancellation', 'fluent-booking'),
                'placeholder'    => __('Why are you cancelling?', 'fluent-booking'),
                'required'       => true,
                'enabled'        => true,
                'system_defined' => true,
                'disable_alter'  => false,
                'help_text'      => ''
            ],
            'rescheduling_reason' => [
                'index'          => 5,
                'type'           => 'textarea',
                'name'           => 'rescheduling_reason',
                'label'          => __('Reason for reschedule', 'fluent-booking'),
                'placeholder'    => __('Let others know why you need to reschedule', 'fluent-booking'),
                'required'       => true,
                'enabled'        => true,
                'system_defined' => true,
                'disable_alter'  => false,
                'help_text'      => ''
            ],
        ];

        if ($calendarSlot->isGuestFieldRequired()) {
            $requiredIndexes[] = 'guests';
            $defaultFields['guests'] = [
                'index'          => 6,
                'type'           => 'multi-guests',
                'name'           => 'guests',
                'label'          => __('Additional Guests', 'fluent-booking'),
                'limit'          => 10,
                'required'       => false,
                'enabled'        => false,
                'system_defined' => true,
                'disable_alter'  => false
            ];
        }
        if ($calendarSlot->isLocationFieldRequired()) {
            $requiredIndexes[] = 'location';
            $defaultFields['location'] = [
                'index'          => 7,
                'type'           => 'radio',
                'name'           => 'location',
                'label'          => __('Location', 'fluent-booking'),
                'options'        => LocationService::getLocationOptions($calendarSlot),
                'required'       => true,
                'enabled'        => true,
                'system_defined' => true,
                'disable_alter'  => true,
                'placeholder'    => esc_attr__('Location', 'fluent-booking')
            ];
        } else if ($calendarSlot->isPhoneRequired()) {
            $requiredIndexes[] = 'phone_number';
            $defaultFields['phone_number'] = [
                'index'          => 8,
                'type'           => 'phone',
                'name'           => 'phone_number',
                'label'          => __('Your Phone Number', 'fluent-booking'),
                'required'       => true,
                'enabled'        => true,
                'system_defined' => true,
                'disable_alter'  => true,
                'is_sms_number'  => true,
                'help_text'      => ''
            ];
        } else if ($calendarSlot->isAddressRequired()) {
            $requiredIndexes[] = 'address';
            $defaultFields['address'] = [
                'index'          => 9,
                'type'           => 'text',
                'name'           => 'address',
                'label'          => __('Your Address', 'fluent-booking'),
                'required'       => true,
                'enabled'        => true,
                'system_defined' => true,
                'disable_alter'  => true,
                'placeholder'    => esc_attr__('Address', 'fluent-booking'),
                'help_text'      => ''
            ];
        }

        $dbFields = $calendarSlot->getMeta('booking_fields', []);

        if (!$dbFields) {
            $dbFields = array_values($defaultFields);
        }

        $existingFields = [];
        foreach ($dbFields as $dbField) {
            $name = $dbField['name'];
            $existingFields[$name] = $dbField;
        }

        if (empty($defaultFields['location'])) {
            unset($existingFields['location']);
        } else {
            if (empty($existingFields['location'])) {
                $existingFields['location'] = $defaultFields['location'];
            } else {
                $existingFields['location']['options'] = $defaultFields['location']['options'];
            }
        }

        if (empty($defaultFields['phone_number'])) {
            unset($existingFields['phone_number']);
        }

        if (empty($defaultFields['address'])) {
            unset($existingFields['address']);
        }

        foreach ($requiredIndexes as $index) {
            if (empty($existingFields[$index]) && !empty($defaultFields[$index])) {
                $existingFields[$index] = $defaultFields[$index];
            }
        }

        $paymentField = apply_filters('fluent_booking/payment_booking_field', [], $calendarSlot, $existingFields);

        if (!$paymentField) {
            unset($existingFields['payment_method']);
        } else {
            $existingFields['payment_method'] = $paymentField;
        }

        $existingFields['email']['disabled'] = false;

        $bookingFields = apply_filters('fluent_booking/booking_fields', array_values($existingFields), $calendarSlot);

        return $bookingFields;
    }

    public static function getBookingFieldLabels(CalendarSlot $calendarSlot, $enabledOnly = false)
    {
        $fields = self::getBookingFields($calendarSlot, true);

        $labels = [];
        foreach ($fields as $field) {
            if ($enabledOnly && !Arr::isTrue($field, 'enabled')) {
                continue;
            }

            $labels[$field['name']] = $field['label'];
        }

        return $labels;
    }

    public static function generateFieldName($calendarEvent, $fieldLabel)
    {
        $fieldLabel = str_replace(' ', '_', $fieldLabel);
        $fieldName = 'custom_' . strtolower($fieldLabel);
        $bookingFields = self::getBookingFields($calendarEvent);
        
        $matched = 0;
        foreach ($bookingFields as $field) {
            if (strpos($field['name'], $fieldName) !== false) {
                $matched++;
            }
        }

        if ($matched) {
            $fieldName .= '_' . $matched;
        }
        return $fieldName;
    }

    public static function maybeGenerateFieldName($calendarEvent, $fieldValue)
    {
        $bookingFields = self::getBookingFields($calendarEvent);

        foreach ($bookingFields as $field) {
            if ($field['name'] == $fieldValue['name'] && $field['index'] != $fieldValue['index']) {
                return self::generateFieldName($calendarEvent, $fieldValue['label']);
            }
        }

        return sanitize_text_field($fieldValue['name']);
    }

    public static function getFormattedCustomBookingData(Booking $booking, $htmlSupport = true, $isPublic = false)
    {
        $customFormData = $booking->getMeta('custom_fields_data', []);
        if (!$customFormData) {
            return [];
        }

        $labels = self::getBookingFieldLabels($booking->calendar_event);

        $formattedData = [];

        foreach ($customFormData as $dataKey => $value) {
            $label = $labels[$dataKey] ?? $dataKey;
    
            $formattedValue = is_array($value) ? implode(', ', $value) : $value;
            
            $field = self::getBookingFieldByName($booking->calendar_event, $dataKey);

            $fieldType = Arr::get($field, 'type');

            if ($fieldType == 'file' && is_array($value)) {
                $formattedValue = self::getUploadedFiles($value, $htmlSupport);
            }
        
            if ($fieldType == 'hidden') {
                if ($isPublic) continue;
                $formattedValue = EditorShortcodeParser::parse($formattedValue, $booking);
            }

            $formattedData[$dataKey] = [
                'label' => $label,
                'type'  => $fieldType,
                'value' => $formattedValue
            ];
        }

        return $formattedData;
    }

    public static function getCustomFields($calendarEvent, $withConfig = false)
    {
        $existingFields = $calendarEvent->getMeta('booking_fields', []);

        if (!$existingFields) {
            return [];
        }

        $customFields = [];

        foreach ($existingFields as $existingField) {
            if (Arr::get($existingField, 'system_defined') || !Arr::isTrue($existingField, 'enabled')) {
                continue;
            }
            if ($withConfig) {
                $customFields[$existingField['name']] = $existingField;
            } else {
                $customFields[$existingField['name']] = $existingField['label'];
            }
        }

        return $customFields;
    }

    public static function getBookingFieldByName($calendarEvent, $name)
    {
        $fields = self::getBookingFields($calendarEvent, true);

        foreach ($fields as $field) {
            if (Arr::get($field, 'name') == $name) {
                return $field;
            }
        }

        return [];
    }

    public static function hasPhoneNumberField($fields)
    {
        if(!$fields) {
            return false;
        }

        foreach ($fields as $field) {
            if ($field['type'] == 'phone') {
                return true;
            } else if ($field['name'] == 'location') {
                if (!empty($field['options'])) {
                    foreach ($field['options'] as $option) {
                        if (Arr::get($option, 'type') == 'phone_guest') {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    public static function getUploadedFiles($fieldValue, $htmlSupport = true)
    {
        if (empty($fieldValue)) {
            return '';
        }

        $files = array_map(function($file) use ($htmlSupport) {
            if ($htmlSupport) {
                return '<a href="' . esc_url($file) . '" target="_blank" download="' . esc_attr(basename($file)) . '">' . esc_html(basename($file)) . '</a>';
            }
            return $file;
        }, $fieldValue);
    
        $separator = $htmlSupport ? '<br>' : PHP_EOL;

        return implode($separator, $files);
    }

    public static function validateDateFields($customFieldsData, $calendarEvent)
    {
        foreach ($customFieldsData as $fieldKey => $fieldValue) {
            $field = self::getBookingFieldByName($calendarEvent, $fieldKey);
            if ($fieldValue && Arr::get($field, 'type') == 'date') {
                $minDate = Arr::get($field, 'min_date');
                $maxDate = Arr::get($field, 'max_date');

                $fieldValue = date('Y-m-d', strtotime($fieldValue));
                $minDate = date('Y-m-d', strtotime($minDate ?: '1900-01-01'));
                $maxDate = date('Y-m-d', strtotime($maxDate ?: date('Y-12-31')));

                if ($minDate && $fieldValue < $minDate) {
                    return new \WP_Error('invalid_date', sprintf(__('The date for %s cannot be earlier than %s.', 'fluent-booking'), $field['label'], $minDate));
                }
                if ($maxDate && $fieldValue > $maxDate) {
                    return new \WP_Error('invalid_date', sprintf(__('The date for %s cannot be later than %s.', 'fluent-booking'), $field['label'], $maxDate));
                }
            }
        }
        return true;
    }
}
