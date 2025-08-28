<?php

namespace FluentBooking\App\Services\Integrations\Elementor\Widgets;

use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Hooks\Handlers\BlockEditorHandler;
use FluentBooking\App\Models\CalendarSlot;

class FcalCalendar extends \Elementor\Widget_Base
{

    /**
     * Get widget name.
     *
     * @return string Widget name.
     */
    public function get_name()
    {
        return 'fluentbooking-calendar';
    }

    /**
     * Get widget title.
     *
     * @return string Widget title.
     */
    public function get_title()
    {
        return esc_html__('Calendar', 'fluent-booking');
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
        return ['fluent', 'booking', 'calendar', 'team', 'event'];
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
            'title',
            [
                'label'       => esc_html__('Title', 'fluent-booking'),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'placeholder' => esc_html__('Enter title here', 'fluent-booking'),
            ]
        );
        $this->add_control(
            'description',
            [
                'label'       => esc_html__('Description', 'fluent-booking'),
                'type'        => \Elementor\Controls_Manager::TEXTAREA,
                'rows'        => 4,
                'placeholder' => esc_html__('Enter description here', 'fluent-booking'),
            ]
        );
        $this->add_control(
            'header_image',
            [
                'label'   => esc_html__('Choose Header Image', 'fluent-booking'),
                'type'    => \Elementor\Controls_Manager::MEDIA
            ]
        );
        $this->add_control(
            'show_host_info',
            [
                'label'        => esc_html__('Host Info', 'fluent-booking'),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Show', 'fluent-booking'),
                'label_off'    => esc_html__('Hide', 'fluent-booking'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        $this->add_control(
            'selected_cal_id',
            [
                'label'       => esc_html__('Select Calendar', 'fluent-booking'),
                'type'        => \Elementor\Controls_Manager::SELECT,
                'label_block' => true,
                'options'     => $this->getCalendars()
            ]
        );

        $this->add_control(
            'selected_event_ids',
            [
                'label'       => esc_html__('Select Events', 'fluent-booking'),
                'type'        => \Elementor\Controls_Manager::SELECT2,
                'label_block' => true,
                'multiple'    => true,
                'options'     => []
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
                    {{WRAPPER}} .fcal_calendar_inner .fcal_date_wrapper .fcal_date_event_details .fcal_date_event_details_header .fcal_back button.fcal_svg'  => 'border-color: {{VALUE}} !important',
                    '{{WRAPPER}} .fcal_slot' => 'border-bottom-color: {{VALUE}}',
                    '{{WRAPPER}} .fcal_slots,
                    {{WRAPPER}} .fcal_slot .book_now' => 'border-color: {{VALUE}}'
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
                    '{{WRAPPER}} .fcal_calendar_inner .fcal_side .fcal_author_avatar img,
                    {{WRAPPER}} .fcal_author_header img,
                    {{WRAPPER}} .fcal_calendar_wrapper .fcal_calendar_header .fcal_person_avatar img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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

    private function getCalendars()
    {
        $calendars = Calendar::with(['events' => function ($query) {
            $query->where('status', 'active');
        }])->where('status', 'active')->get();

        $formattedValue = [];

        if (empty($calendars)) {
            return $formattedValue;
        }

        foreach ($calendars as $calendar) {
            $formattedValue[$calendar->id] = $calendar->title;
        }

        return $formattedValue;
    }

    private function getCalendarEvents($selectedCalId, $eventIds)
    {
        if (empty($eventIds) || empty($selectedCalId)) {
            return [];
        }

        $events = CalendarSlot::where('calendar_id', $selectedCalId)
            ->whereIn('id', $eventIds)
            ->get();

        $calendar = Calendar::find($selectedCalId);

        if (!$calendar || !$events) {
            return [];
        }

        $formattedData = [
            'user_profile' => $calendar->getAuthorProfile(),
            'events'       => $this->formatEvents($events),
        ];

        $formattedData['user_profile']['description'] = $calendar->description;

        return $formattedData;
    }


    private function formatEvents($events)
    {
        $formattedEvents = [];
        foreach ($events as $event) {
            $event->description = $event->getDescription();
            $formattedEvents[] = [
                'title'             => $event->title,
                'color_schema'      => $event->color_schema,
                'short_description' => $event->short_description,
                'durations'         => $event->getAvailableDurations(),
            ];
        }

        return $formattedEvents;
    }


    /**
     * Render currency widget output on the frontend.
     *
     * @access protected
     */
    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $events   = $this->getCalendarEvents($settings['selected_cal_id'], $settings['selected_event_ids']);

        $blockEditor = new BlockEditorHandler();

        $hostInfo = true;
        if ($settings['show_host_info'] == 'yes') {
            $hostInfo = false;
        }

        $attributes = [
            'calendarId'  => $settings['selected_cal_id'],
            'eventIds'    => $settings['selected_event_ids'],
            'title'       => $settings['title'],
            'description' => $settings['description'],
            'headerImage' => $settings['header_image'],
            'hideInfo'    => $hostInfo
        ];

        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) :
            // Output for editor
            ?>
            <style>
                .fcal_calendar_wrapper .fcal_calendar_header {
                    text-align: center;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    margin-bottom: 30px;
                }
                .fcal_calendar_wrapper .fcal_calendar_header .fcal_person_avatar {
                    display: inline-block;
                }
                .fcal_calendar_wrapper .fcal_calendar_header .fcal_person_avatar img {
                    max-width: 74px;
                    max-height: 74px;
                    width: 100%;
                    object-fit: cover;
                    border-radius: 8px;
                }
                .fcal_calendar_wrapper .fcal_calendar_header .fcal_calendar_title {
                    font-size: 1.5rem;
                    margin: 6px 0 0.2rem 0;
                    line-height: 1.2;
                    color: #1b2533;
                    font-weight: 500;
                }
                .fcal_calendar_wrapper .fcal_calendar_header .fcal_calendar_description {
                    font-size: 14px;
                    line-height: 20px;
                    color: #6b7280;
                    font-weight: 400;
                }
                .fcal_slots_wrap {
                    margin-top: 1rem;
                }
                .fcal_slots {
                    display: grid;
                    grid-template-columns: 1fr;
                    border: 1px solid #d6dae1;
                    border-radius: 8px;
                    overflow: hidden;
                }
                .fcal_slots .fcal_slot:last-child {
                    border-bottom: none;
                }
                .fcal_slot {
                    border-bottom: 1px solid #d6dae1;
                    padding: 16px 24px;
                    transition: 0.3s;
                    background: #ffffff;
                }
                .fcal_slot > .fcal_card {
                    text-decoration: none;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                }
                .fcal_slot h2 {
                    font-size: 16px;
                    font-weight: 700;
                    line-height: 24px;
                    position: relative;
                    margin: 0;
                    color: #1B2533;
                    padding-left: 19px;
                }
                .fcal_slot h2 .fcal_slot_color_schema {
                    width: 10px;
                    height: 10px;
                    border-radius: 50%;
                    position: absolute;
                    left: 0;
                    top: 8px;
                }
                .fcal_slot .fcal_description {
                    font-size: 16px;
                    font-weight: 400;
                    line-height: 24px;
                    color: #6b7280;
                    margin: 0 0 7px;
                    padding-left: 19px;
                }
                .fcal_slot .fcal_slot_duration {
                    font-size: 12px;
                    font-weight: 500;
                    line-height: 18px;
                    margin: 0;
                    display: inline-flex;
                    align-items: center;
                    gap: 7px;
                    color: #445164;
                    padding-left: 19px;
                }
                .fcal_slot .book_now {
                    border: 1px solid #d6dae1;
                    color: #1b2533;
                    min-width: fit-content;
                    font-size: 14px;
                    font-weight: 500;
                    line-height: 20px;
                    display: inline-flex;
                    align-items: center;
                    border-radius: 8px;
                    background: transparent;
                    padding: 7px 16px 7px 16px;
                    transition: 0.3s;
                    gap: 8px;
                    position: relative;
                }
                .fcal_author_header {
                    text-align: center;
                }
                .fcal_author_header img {
                    width: 96px;
                    height: 96px;
                    object-fit: cover;
                    border-radius: 8px;
                    display: block;
                    margin: auto auto 10px auto;
                }
                .fcal_author_header h1 {
                    font-size: 20px;
                    font-weight: 700;
                    line-height: 28px;
                    margin: 0;
                    color: #1b2533;
                }
                .fcal_author_header p {
                    font-size: 16px;
                    line-height: 24px;
                    margin: 8px 0 0 0;
                    color: #6b7280;
                }
            </style>
            <div class="fcal_calendar_wrapper">
                <div class="fcal_cals_wrap">
                    <div class="fcal_calendar_header">
                        <?php if ($settings['header_image']) : ?>
                            <div class="fcal_person_avatar">
                                <img decoding="async" src="<?php echo esc_url($settings['header_image']['url']); ?>" alt="<?php echo esc_html($settings['title']); ?>">
                            </div>
                        <?php endif;
                        if ($settings['title']) : ?>
                            <h2 class="fcal_calendar_title"><?php echo esc_html($settings['title']); ?></h2>
                        <?php
                        endif;
                        if ($settings['description']) : ?>
                            <div class="fcal_calendar_description">
                                <?php echo esc_html($settings['description']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                if (isset($events['events']) && !empty($events['events'])) : ?>
                    <div class="fluent_booking_team_view">
                        <?php if (!$hostInfo) : ?>
                            <div class="fcal_author_header">
                                <img src="<?php echo esc_url($events['user_profile']['avatar']); ?>" alt="<?php echo esc_attr($events['user_profile']['name']); ?>">
                                <div class="author_info">
                                    <h1><?php echo esc_html($events['user_profile']['name']); ?></h1>
                                    <?php if ($events['user_profile']['description']) : ?>
                                        <p class="fcal_description"><?php echo esc_html($events['user_profile']['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="fcal_slots_wrap">
                            <div class="fcal_slots">
                                <?php foreach ($events['events'] as $event) : ?>
                                    <div class="fcal_slot">
                                        <div class="fcal_card fcal_event_card">
                                            <div class="fcal_slot_content">
                                                <h2>
                                                    <span class="fcal_slot_color_schema" style="background: <?php echo esc_attr($event['color_schema']); ?>"></span>
                                                    <?php echo esc_html($event['title']); ?>
                                                </h2>
                                                <p class="fcal_description">
                                                    <?php echo esc_html($event['short_description']); ?>
                                                </p>
                                                <?php foreach ($event['durations'] as $duration) : ?>
                                                    <span class="fcal_slot_duration">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                                             viewBox="0 0 14 14"
                                                             fill="none">
                                                            <path d="M12.8334 7C12.8334 10.22 10.22 12.8333 7.00002 12.8333C3.78002 12.8333 1.16669 10.22 1.16669 7C1.16669 3.78 3.78002 1.16666 7.00002 1.16666C10.22 1.16666 12.8334 3.78 12.8334 7Z"
                                                                  stroke="#445164" stroke-linecap="round"
                                                                  stroke-linejoin="round"></path>
                                                            <path d="M9.16418 8.855L7.35585 7.77584C7.04085 7.58917 6.78418 7.14 6.78418 6.7725V4.38084"
                                                                  stroke="#445164" stroke-linecap="round"
                                                                  stroke-linejoin="round"></path>
                                                        </svg>
                                                        <?php echo esc_html($duration); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <span class="book_now">
                                                Book Now
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="fcal_empty"><?php esc_html_e('Please Select the Event(s)', 'fluent-booking'); ?></div>
                <?php endif; ?>
            </div>
        <?php else :
            // Output for frontend
            echo $blockEditor->fcalRenderCalendarManagementBlock($attributes);
        endif;
        ?>
        <?php

    }

    public function content_template()
    {

    }
}
