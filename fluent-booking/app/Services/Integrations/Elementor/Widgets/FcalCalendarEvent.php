<?php

namespace FluentBooking\App\Services\Integrations\Elementor\Widgets;

use FluentBooking\App\Services\CalendarService;
use FluentBooking\App\Models\CalendarSlot;

class FcalCalendarEvent extends \Elementor\Widget_Base
{
    /**
     * Get widget name.
     *
     * @return string Widget name.
     */
    public function get_name()
    {
        return 'fluentbooking-calendar-event';
    }

    /**
     * Get widget title.
     *
     * @return string Widget title.
     */
    public function get_title()
    {
        return esc_html__('Calendar Event', 'fluent-booking');
    }

    /**
     * Get widget icon.
     *
     * @return string Widget icon.
     */
    public function get_icon()
    {
        return 'fluent-booking-icon';
    }

    /**
     * Get widget categories.
     *
     * @return array Widget categories.
     */
    public function get_categories()
    {
        return ['fluentbooking'];
    }

    /**
     * Get widget keywords.
     *
     * @return array Widget keywords.
     */
    public function get_keywords()
    {
        return ['fluent', 'booking', 'calendar', 'event'];
    }

    /**
     * Register FluentBooking widget controls.
     *
     * @access protected
     */
    protected function register_controls()
    {

        $this->start_controls_section(
            'content_section',
            [
                'label' => esc_html__('Content', 'fluent-booking'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'selected_event',
            [
                'label'   => __('Select Event', 'fluent-booking'),
                'type'    => 'fcal_group_select',
                'options' => $this->getCalendarEvents()
            ]
        );

        $this->add_control(
            'event_hash',
            [
                'type' => \Elementor\Controls_Manager::HIDDEN,
                'default' => '',
            ]
        );

        $this->add_control(
            'show_host_info',
            [
                'label'        => __('Show Host Info', 'fluent-booking'),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __('Show', 'fluent-booking'),
                'label_off'    => __('Hide', 'fluent-booking'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        $this->add_control(
            'select_theme',
            [
                'label'   => __('Color Schema', 'fluent-booking'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'light',
                'options' => [
                    'light'          => __('Light', 'fluent-booking'),
                    'dark'           => __('dark', 'fluent-booking'),
                    'system-default' => __('System Default', 'fluent-booking'),
                ],
            ]
        );


        $this->end_controls_section();

        $this->styleSettings();
    }

    private function styleSettings()
    {
        $this->start_controls_section(
            'content_style',
            [
                'label' => esc_html__('Style', 'fluent-booking'),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );
        $this->add_control('fcal_primary_color',
            [
                'label'     => esc_html__('Primary Color', 'fluent-booking'),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .calendar .day .is-today,
                    {{WRAPPER}} .fcal_slot_picker .fcal_spot_lists .fcal_spot.fcal_spot_selected .fcal_spot_name'           => 'color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .calendar .day .is-today:before'                       => 'background: {{VALUE}}',
                    '{{WRAPPER}} .fcal_slot_picker .fcal_spot_lists .fcal_spot .fcal_spot_confirm'                              => 'background: {{VALUE}}',
                    '{{WRAPPER}} .calendar_nav .fcal_nav_active svg'                                                            => 'color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_timezone_select .svelte-select.focused'                                                  => 'border-color: {{VALUE}} !important',
                    '{{WRAPPER}} .fcal_timezone_select .svelte-select .svelte-select-list .item.active'                         => 'border-color: {{VALUE}} !important',
                    '{{WRAPPER}} .fcal_slot_picker .fcal_spot_lists .fcal_spot.fcal_spot_selected'                              => 'border-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_slot_picker .fcal_spot_lists .fcal_spot:hover'                                           => 'border-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_timezone_select .svelte-select .svelte-select-list .item.hover'                          => 'background: {{VALUE}} !important',
                    '{{WRAPPER}} .fcal_slot_picker .fcal_spot_lists .fcal_spot:before'                                          => 'background: {{VALUE}} !important',
                    '{{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content input:focus'    => 'border-color: {{VALUE}} !important',
                    '{{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content select:focus'   => 'border-color: {{VALUE}} !important',
                    '{{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content textarea:focus' => 'border-color: {{VALUE}} !important',
                    '{{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item button'                             => 'border-color: {{VALUE}}; background: {{VALUE}};',
                    '{{WRAPPER}} span.fcal_host_badge'                                                                          => 'color: {{VALUE}}',
                    '{{WRAPPER}} span.fcal_host_badge:before'                                                                   => 'background: {{VALUE}}',
                    '{{WRAPPER}} .fcal_normal_booking_footer a'                                                                 => 'color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_timezone_select .svelte-select.list-open:before'                                         => 'border-bottom-color: {{VALUE}}; border-left-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_loading_dates_inner .wrapper .cube'                                                      => 'background-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_icon_item .fcal_multi_duration .fcal_duration.is_selected'          => 'background-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .calendar .day.day-enabled:hover span'          => 'background-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content .fcal_radio_group .fcal_radio_icon::before,
                    {{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .fcal_date_event_details .fcal_date_event_details_header .fcal_back button.fcal_svg:hover'          => 'background: {{VALUE}}',
                    '{{WRAPPER}} .fcal_no_availability button' => 'background-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_slot button svg path' => 'stroke: {{VALUE}}',
                    '{{WRAPPER}} .fcal_slot_wrapper .fcal_back .fcal_back_btn:hover,
                    {{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content .fcal_radio_group input:checked ~ .fcal_radio_icon,
                    {{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content .fcal_custom_checkbox input:checked ~ .checkbox_mark,
                    {{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content .fcal_custom_checkbox input:focus ~ .checkbox_mark' => 'border-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content .fcal_custom_checkbox input:checked ~ .checkbox_mark' => 'background: {{VALUE}}'
                ],
            ]
        );
        $this->add_control('fcal_border_color',
            [
                'label'     => esc_html__('Border Color', 'fluent-booking'),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_side'  => 'border-right-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .fcal_date_event_details .fcal_date_event_details_header'  => 'border-bottom-color: {{VALUE}}',
                    '{{WRAPPER}} {{WRAPPER}} .fcal_payment_items table tbody td'  => 'border-bottom-color: {{VALUE}} !important',
                    '{{WRAPPER}} .fcal_wrap .fcal_calendar_inner, 
                    {{WRAPPER}} .fcal_slot_picker .fcal_slot_picker_header .fcal_slot_picker_header_action, {{WRAPPER}} .fcal_slot_picker .fcal_spot_lists .fcal_spot, 
                    {{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content select, 
                    {{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content textarea,
                    {{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content input,
                    {{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content .fcal_radio_group .fcal_radio_icon,
                    {{WRAPPER}} .fcal_booking_form_wrap .fcal_booking_form .fcal_form_item .fcal_input_content .fcal_custom_checkbox .checkbox_mark,
                    {{WRAPPER}} .fcal_payment_items table'  => 'border-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_timezone_select .svelte-select,
                    {{WRAPPER}} .fcal_slot_picker .fcal_slot_picker_header .fcal_back .fcal_svg,
                    {{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .fcal_date_event_details .fcal_date_event_details_header .fcal_back button.fcal_svg'  => 'border-color: {{VALUE}} !important'
                ],
            ]
        );
        $this->add_control(
            'avatar-radius',
            [
                'label'      => esc_html__('Avatar Radius', 'fluent-booking'),
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'default'    => [
                    'top'      => 8,
                    'right'    => 8,
                    'bottom'   => 8,
                    'left'     => 8,
                    'unit'     => 'px',
                    'isLinked' => true,
                ],
                'selectors'  => [
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_side .fcal_author_avatar img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'condition' => [
                    'show_host_info' => 'yes',
                ],
            ]
        );

        $this->add_control(
            'date-radius',
            [
                'label'      => esc_html__('Date Radius', 'fluent-booking'),
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'default'    => [
                    'top'      => 4,
                    'right'    => 4,
                    'bottom'   => 4,
                    'left'     => 4,
                    'unit'     => 'px',
                    'isLinked' => true,
                ],
                'selectors'  => [
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .calendar .day.day-enabled span' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ],
            ]
        );

        $this->add_control('enabled_date_background',
            [
                'label'     => esc_html__('Enabled Date Background', 'fluent-booking'),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .fcal_payment_items table thead th' => 'background-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .calendar .day.day-enabled span' => 'background-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_icon_item .fcal_multi_duration .fcal_duration:not(.is_selected)' => 'background-color: {{VALUE}}',
                ],
            ]
        );
        $this->add_control('enabled_date_color',
            [
                'label'     => esc_html__('Enabled Date Color', 'fluent-booking'),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .calendar .day.day-enabled span' => 'color: {{VALUE}}',
                ],
            ]
        );
        $this->add_control('disabled_date_color',
            [
                'label'     => esc_html__('Disabled Date Color', 'fluent-booking'),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .calendar .day.day-disabled' => 'color: {{VALUE}}',
                ],
            ]
        );

        $this->end_controls_section();
    }

    private function getCalendarEvents()
    {
        $getCalendars = CalendarService::getCalendarOptionsByHost();

        $formattedData = [];

        if (empty($getCalendars)) {
            return $formattedData;
        }

        foreach ($getCalendars as $calendar) {
            $label   = $calendar['label'];
            $options = $calendar['options'];

            $formattedData[$label] = [];
            foreach ($options as $option) {
                $formattedData[$label][$option['value']] = $option['label'];
            }
        }

        return $formattedData;
    }

    private function getCalendarEvent($eventId, $eventHash)
    {
        $event = CalendarSlot::find($eventId);
        if (!$event) {
            $event = CalendarSlot::where('hash', $eventHash)->first();
        }

        if (!$event || !$event->calendar) {
            return [];
        }

        $data = [
            'id'                 => $event->id,
            'title'              => $event->title,
            'description'        => $event->description,
            'user_profile'       => $event->calendar->getAuthorProfile(),
            'location_icon_html' => $event->defaultLocationHtml(),
            'durations'          => $event->getAvailableDurations()
        ];
        return $data;
    }

    /**
     * Render currency widget output on the frontend.
     *
     * @access protected
     */
    protected function render()
    {
        $settings = $this->get_settings_for_display();

        if (empty($settings['selected_event']) && empty($settings['event_hash'])) {
            echo __('Please select an event', 'fluent-booking');
            return;
        }

        $selectedEventId = $settings['selected_event'] ?? null;

        $eventHash = $settings['event_hash'] ?? '';

        $selectedEvent = $this->getCalendarEvent($selectedEventId, $eventHash);

        if (empty($selectedEvent['id'])) {
            echo __('Calendar event not found', 'fluent-booking');
            return;
        }

        $hideHost = ($settings['show_host_info'] == 'yes') ? 'no' : 'yes';

        $shortcode = sprintf(
            '[fluent_booking id="%s" theme="%s" disable_author="%s" hash="%s"]',
            $selectedEvent['id'],
            $settings['select_theme'],
            $hideHost,
            $eventHash
        );

        $shortcode_output = $shortcode;

        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) : ?>
            <style>
                .fcal_calendar_inner .fcal_side {
                    width: 300px;
                    min-width: 300px;
                }
                .fcal_calendar_inner .fcal_date_wrapper .calendar-container > img {
                    max-width: 370px;
                    width: 100%;
                    display: block;
                }
            </style>
            <div class="fcal_wrap">
                <div class="fcal_calendar_inner">
                    <?php if ($hideHost == 'no') : ?>
                        <div class="fcal_side">
                            <div class="fcal_slot_wrapper">
                                <div class="fcal_author">
                                    <div class="fcal_author_avatar">
                                        <img src="<?php echo esc_url($selectedEvent['user_profile']['avatar']); ?>" alt="<?php echo esc_attr($selectedEvent['user_profile']['name']) ?>">
                                    </div>
                                    <div class="fcal_author_name"><?php echo esc_html($selectedEvent['user_profile']['name']); ?></div>
                                </div>
                                <div class="fcal_slot_info">
                                    <h1 class="fcal_slot_heading"><?php echo esc_html($selectedEvent['title']); ?></h1>
                                    <div class="slot_timing fcal_icon_item">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M16.5 9C16.5 13.14 13.14 16.5 9 16.5C4.86 16.5 1.5 13.14 1.5 9C1.5 4.86 4.86 1.5 9 1.5C13.14 1.5 16.5 4.86 16.5 9Z" stroke="#445164" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.7825 11.3849L9.45753 9.99745C9.05253 9.75745 8.72253 9.17995 8.72253 8.70745V5.63245" stroke="#445164" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                                        <div class="fcal_multi_duration">
                                            <?php foreach ($selectedEvent['durations'] as $duration) { ?>
                                                <span class="fcal_duration"><?php echo esc_html($duration); ?> </span>
                                            <?php } ?>
                                        </div>
                                    </div>
                                    <?php echo wp_kses_post($selectedEvent['location_icon_html']); ?>
                                </div>
                                <div class="fcal_slot_description"><p><?php echo wp_kses_post($selectedEvent['description']); ?></p></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="fcal_date_wrapper">
                        <div class="fcal_day_picker_wrap">
                            <div class="calendar-container">
                                <img src="<?php echo FLUENT_BOOKING_URL .'/assets/images/fcal-calendar.png' ?>" alt="calendar">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else :
            echo $shortcode_output;
        endif;

    }

    public function content_template()
    {

    }

}
