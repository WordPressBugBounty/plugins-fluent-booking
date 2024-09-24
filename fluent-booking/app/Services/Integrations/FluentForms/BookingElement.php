<?php

namespace FluentBooking\App\Services\Integrations\FluentForms;


use FluentBooking\App\App;
use FluentBooking\App\Services\CalendarService;
use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\App\Hooks\Handlers\FrontEndHandler;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;

class BookingElement extends BaseFieldManager
{
    /**
     * Wrapper class for repeat element
     * @var string
     */
    protected $wrapperClass = 'fcal_booking_elem';

    public function __construct()
    {
        parent::__construct(
            'fcal_booking',
            'Calendar Booking',
            ['booking', 'calendar'],
            'advanced'
        );

        add_filter('fluentform/response_render_fcal_booking', array($this, 'renderResponse'), 10, 3);
        add_filter('fluentform/select_group_component_ajax_options', array($this, 'getCalendarOptions'));

        add_action('fluentform/loading_editor_assets', function () {
            wp_enqueue_script('fluentcal_ff_editor_extended', FLUENT_BOOKING_URL . 'assets/admin/fluentform.js', [], '1.0.0', true);
        });
    }

    function getComponent()
    {
        return [
            'index'          => 20,
            'element'        => 'fcal_booking',
            'attributes'     => array(
                'name'      => 'fcal_booking',
                'data-type' => 'fcal_booking'
            ),
            'settings'       => array(
                'label'              => __('Select Appointment Date & Time', 'fluent-booking'),
                'admin_field_label'  => '',
                'event_id'           => '',
                'booking_calendar'   => '',
                'conditional_logics' => array(),
                'container_class'    => '',
                'cal_guest_fields'   => [
                    'email_field' => '',
                    'name_field'  => '',
                    'host_info'   => 'show'
                ],
                'validation_rules'   => array(
                    'required' => [
                        'value'   => false,
                        'message' => __('Appointment Date & Time is required', 'fluent-booking'),
                    ],
                ),
            ),
            'editor_options' => array(
                'title'      => __('FluentBooking Field', 'fluent-booking'),
                'icon_class' => 'el-icon-date',
                'template'   => 'inputCalendar'
            ),
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'booking_calendar',
            'label',
            'label_placement',
            'admin_field_label',
            'event_id',
            'cal_guest_fields',
            'validation_rules',
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [
            'container_class',
            'name',
            'conditional_logics'
        ];
    }

    public function getEditorCustomizationSettings()
    {
        return [
            'event_id'         => [
                'template' => 'selectGroup',
                'label'    => __('Select Calendar', 'fluent-booking'),
            ],
            'cal_guest_fields' => [
                'template'      => 'CustomSettingsField',
                'label'         => __('Guest Fields', 'fluent-booking'),
                'componentName' => 'FluentCalNameEmailChoiceComponent'
            ],
        ];
    }

    /**
     * Compile and echo the html element
     * @param array $data [element data]
     * @param object $form [Form Object]
     * @return void
     */
    public function render($data, $form)
    {
        $elementName = $data['element'];

        $data['attributes']['class'] = @trim('ff-el-form-control ' . Arr::get($data, 'attributes.class'));
        $data['attributes']['id'] = $this->makeElementId($data, $form);
        if ($tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex()) {
            $data['attributes']['tabindex'] = $tabIndex;
        }

        $ariaRequired = 'false';
        if (Arr::get($data, 'settings.validation_rules.required.value')) {
            $ariaRequired = 'true';
        }
        
        $slot_id = (int)Arr::get($data, 'settings.event_id');
        
        $calendarEvent = CalendarSlot::find($slot_id);
        
        if (!$calendarEvent || !$calendarEvent->calendar) {
            esc_html_e('Selected Calendar could not be found', 'fluent-booking');
            return;
        }
        
        $isHostEnabled = Arr::get($data, 'settings.cal_guest_fields.host_info', 'hide') == 'show';

        $showHostInfo = $isHostEnabled || Arr::isTrue($calendarEvent->settings, 'multi_duration.enabled');

        [$localizeData, $element_id] = (new FluentFormInit())->getLocalizedData($calendarEvent, $data, $form);

        $localizeData['time_format'] = (Helper::getGlobalSettings())['time_format'];

        $assetUrl = App::getInstance('url.assets');

        wp_enqueue_script(
            'fluentform-calendar-public',
            $assetUrl . 'public/js/fluentform.js', [],
            FLUENT_BOOKING_ASSETS_VERSION, true
        );

        if (BookingFieldService::hasPhoneNumberField($localizeData['form_fields'])) {
            wp_enqueue_script('fluent-booking-phone-field', $assetUrl . 'public/js/phone-field.js', [], FLUENT_BOOKING_ASSETS_VERSION, true);
            ?>
            <style>
                .fcal_phone_wrapper .flag {
                    background: url(<?php echo esc_url($assetUrl.'images/flags_responsive.png'); ?>) no-repeat;
                    background-size: 100%;
                }
            </style>
            <?php
        }

        wp_localize_script('fluentform-calendar-public', 'fcal_public_vars_' . $element_id, $localizeData);

        wp_localize_script('fluentform-calendar-public', 'fluentCalendarPublicVars',
            (new FrontEndHandler())->getGlobalVars()
        );

        $calClass = 'fluentform_calendar_app';

        if ($showHostInfo) {
            $calClass .= ' fcal_showing_host';
        } else {
            $calClass .= ' fcal_not_showing_host';
        }

        $elMarkup = '<div class="fcal_cal_wrap"><div class="' . esc_attr($calClass) . '" data-element_id="' . esc_attr($element_id) . '"></div></div>';
        $html = $this->buildElementMarkup($elMarkup, $data, $form);
        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is escaped before being passed in.
    }

    public function renderResponse($data, $field, $form_id)
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (!$data) {
            return '';
        }

        $data = (array)$data;

        $text = Arr::get($data, 'start_time') . ' ( ' . Arr::get($data, 'timezone') . ' )';

        if (defined('FLUENTFORM_RENDERING_ENTRY')) {

            $booking = Booking::find(Arr::get($data, 'booking_id'));

            if ($booking && $booking->calendar) {
                $calendar = $booking->calendar;
                $html = '<div class="ff_entry_table_wrapper"><table class="ff_entry_table_field ff-table">';
                $html .= '<tr>';
                $html .= '<th>' . __('Booking ID', 'fluent-booking') . '</th>';
                $html .= '<td>' . $booking->id . ' <a href="' . Helper::getAppBaseUrl('scheduled-events?period=upcoming&booking_id=' . $booking->id) . '" target="_blank">View Booking</a></td>';
                $html .= '</tr>';
                $html .= '<tr>';
                $html .= '<th>' . __('Booking Status', 'fluent-booking') . '</th>';
                $html .= '<td>' . $booking->status . '</td>';
                $html .= '</tr>';
                $html .= '<tr>';
                $html .= '<th>' . __('Date & Time', 'fluent-booking') . '</th>';
                $html .= '<td>' . $booking->getFullBookingDateTimeText($calendar->author_timezone, true) . ' (' . $calendar->author_timezone . ')</td>';
                $html .= '</tr>';
                $html .= '<tr>';
                $html .= '<th>' . __('Meeting Duration', 'fluent-booking') . '</th>';
                $html .= '<td>' . $booking->slot_minutes . ' Minutes</td>';
                $html .= '</tr>';
                $html .= '<tr>';
                $html .= '<th>' . __('Meeting Host', 'fluent-booking') . '</th>';
                $html .= '<td>' . $calendar->title . '</td>';
                $html .= '</tr>';
                $html .= '</html></div>';
                return $html;
            }
        }

        return $text;
    }

    protected function getResponseHtml($response, $fields, $columns)
    {
        return __('HTML Response', 'fluent-booking');
    }

    protected function getResponseAsText($response, $fields, $columns)
    {
        return __('Text Response', 'fluent-booking');
    }

    public function getCalendarOptions()
    {
        return apply_filters('fluent_booking/ff_editor_calendar_options', CalendarService::getCalendarOptionsByHost());
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
