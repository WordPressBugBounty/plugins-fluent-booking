<?php

namespace FluentBooking\App\Services\Integrations\Elementor\Widgets;

use FluentBooking\App\Models\Calendar;

class FcalBookings extends \Elementor\Widget_Base
{

    /**
     * Get widget name.
     *
     * @return string Widget name.
     */
    public function get_name()
    {
        return 'fluentbooking-bookings';
    }

    /**
     * Get widget title.
     *
     * @return string Widget title.
     */
    public function get_title()
    {
        return esc_html__('Bookings', 'fluent-booking');
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
            'selected_calendars',
            [
                'label'       => esc_html__('Select Calendars', 'fluent-booking'),
                'type'        => \Elementor\Controls_Manager::SELECT2,
                'label_block' => true,
                'multiple'    => true,
                'description' => esc_html__('Left empty to show the bookings for all calendars', 'fluent-booking'),
                'options'     => $this->getCalendars(),
            ]
        );
        $this->add_control(
            'booking_title',
            [
                'label'       => esc_html__('Title', 'fluent-booking'),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'label_block' => true,
                'default'     => esc_html__('My Bookings', 'fluent-booking'),
                'placeholder' => esc_html__('Type your title here', 'fluent-booking'),
            ]
        );
        $this->add_control(
            'no_bookings_title',
            [
                'label'       => esc_html__('No Booking Title', 'fluent-booking'),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'label_block' => true,
                'default'     => esc_html__('No bookings found', 'fluent-booking'),
                'placeholder' => esc_html__('Type your title here', 'fluent-booking'),
            ]
        );

        $this->add_control(
            'default_period',
            [
                'label'   => esc_html__('Default Period', 'fluent-booking'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'all',
                'options' => [
                    'all'       => esc_html__('All', 'fluent-booking'),
                    'upcoming'  => esc_html__('Upcoming', 'fluent-booking'),
                    'completed' => esc_html__('Completed', 'fluent-booking'),
                    'cancelled' => esc_html__('Cancelled', 'fluent-booking'),
                    'pending'   => esc_html__('Pending', 'fluent-booking')
                ],
            ]
        );
        $this->add_control(
            'show_filter',
            [
                'label'        => esc_html__('Show Filter', 'fluent-booking'),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Show', 'fluent-booking'),
                'label_off'    => esc_html__('Hide', 'fluent-booking'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );
        $this->add_control(
            'show_pagination',
            [
                'label'        => esc_html__('Show Pagination', 'fluent-booking'),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Show', 'fluent-booking'),
                'label_off'    => esc_html__('Hide', 'fluent-booking'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );
        $this->add_control(
            'per_page',
            [
                'label'   => esc_html__('Per Page', 'fluent-booking'),
                'type'    => \Elementor\Controls_Manager::SLIDER,
                'range'   => [
                    'px' => [
                        'min'  => 1,
                        'max'  => 100,
                        'step' => 1,
                    ]
                ],
                'default' => [
                    'size' => 10,
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


    /**
     * Render currency widget output on the frontend.
     *
     * @access protected
     */
    protected function render()
    {
        $settings = $this->get_settings_for_display();

        $filter     = ($settings['show_filter'] == 'yes') ? 'show' : 'hide';
        $pagination = ($settings['show_pagination'] == 'yes') ? 'show' : 'hide';

        $calendarIds = (!empty($settings['selected_calendars'])) ? implode(',', $settings['selected_calendars']) : 'all';


        $calendar_ids = esc_attr($calendarIds);
        $period       = esc_attr($settings['default_period']);
        $filter       = esc_attr($filter);
        $pagination   = esc_attr($pagination);
        $per_page     = esc_attr($settings['per_page']['size']);
        $no_bookings  = esc_attr($settings['no_bookings_title']);
        $title        = esc_attr($settings['booking_title']);

        $shortcode = sprintf(
            '[fluent_booking_lists calendar_ids="%s" period="%s" filter="%s" pagination="%s" per_page="%s" no_bookings="%s" title="%s"]',
            $calendar_ids,
            $period,
            $filter,
            $pagination,
            $per_page,
            $no_bookings,
            $title
        );

        $shortcode_output = do_shortcode($shortcode);

        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) :
            // Output for editor
            ?>
            <style>
                .fcal_container {
                    pointer-events: none;
                }
                .fcal_container .fcal_booking_header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .fcal_container .fcal_booking_header .fcal_booking_header_actions form {
                    display: inline-flex;
                    gap: 0.5rem;
                    align-items: center;
                    background: #fff;
                    border: 1px solid #e5e7eb;
                    border-radius: 5px;
                    padding: 5px;
                }
                .fcal_container .fcal_booking_header .fcal_booking_header_actions form .fcal_radio_btn {
                    position: relative;
                    overflow: hidden;
                    cursor: pointer;
                }
                .fcal_container .fcal_booking_header .fcal_booking_header_actions form .fcal_radio_btn input {
                    position: absolute;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    z-index: 1;
                    cursor: pointer;
                    opacity: 0;
                }
                .fcal_container .fcal_booking_header .fcal_booking_header_actions form .fcal_radio_btn label {
                    display: block;
                    border-radius: 8px;
                    padding: 7px 10px;
                    color: #606266;
                    font-size: 0.875rem;
                    font-weight: 500;
                }
                .fcal_container .fcal_booking_header .fcal_booking_header_actions form .fcal_radio_btn input:checked ~ label {
                    background: #e5e7eb;
                    border-radius: 5px !important;
                    box-shadow: none;
                    color: #374151;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper {
                    background: #fff;
                    border: 1px solid #eaecf0;
                    border-radius: 8px;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking:last-child {
                    border-bottom: none;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking {
                    border-bottom: 1px solid #eaecf0;
                    position: relative;
                    cursor: pointer;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line {
                    align-items: center;
                    -moz-column-gap: 15px;
                    column-gap: 15px;
                    display: flex;
                    justify-content: space-between;
                    padding: 15px 25px;
                    position: relative;
                    transition: 0.1s;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_timing {
                    align-items: center;
                    display: flex;
                    flex-wrap: wrap;
                    color: #777;
                    font-weight: 500;
                    font-size: 14px;
                    line-height: 20px;
                    gap: 2px;
                    max-width: 180px;
                    min-width: 180px;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_timing .fcal_booking_date {
                    color: #111827;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_timing p {
                    margin: 0;
                    font-size: 14px;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_desc {
                    align-items: flex-start;
                    display: flex;
                    flex-direction: column;
                    margin-right: auto;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_desc .fcal_spot_title {
                    color: #445164;
                    font-size: 15px;
                    font-weight: 400;
                    line-height: 20px;
                    margin: 0;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_desc .fcal_spot_desc_sub_info {
                    align-items: center;
                    display: flex;
                    flex-wrap: wrap;
                    gap: 6px;
                    margin-top: 8px;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_desc .fcal_spot_desc_sub_info .fcal_spot_period_status,
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_desc .fcal_spot_desc_sub_info .fcal_spot_payment_status{
                    background: rgba(38, 83, 199, 0.1);
                    border-radius: 4px;
                    color: #306ae0;
                    display: inline-block;
                    font-size: 12px;
                    font-weight: 500;
                    margin: 0;
                    padding: 2px 10px;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_desc .fcal_spot_desc_sub_info .fcal_spot_payment_status.paid {
                    background: #e8f5f1;
                    color: #16896b;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_desc .fcal_spot_desc_sub_info .fcal_spot_payment_status.pending, .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_desc .fcal_spot_desc_sub_info .fcal_spot_payment_status.refunded, .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_desc .fcal_spot_desc_sub_info .fcal_spot_payment_status.partially-refunded {
                    background: #FDE8CD;
                    color: #F58E07;
                }
                .fcal_container .fcal_all_bookings .fcal_bookings .fcal_booking_wrapper .fcal_booking .fcal_spot_line .fcal_spot_actions {
                    display: flex;
                    gap: 0.5rem;
                    align-self: center;
                }
                .fcal_container .fcal_plain_btn {
                    border: 1px solid #d6dae1;
                    border-radius: 8px;
                    color: #1b2533;
                    font-size: 14px;
                    height: auto;
                    line-height: 1;
                    padding: 8px 16px;
                    background: transparent;
                    transition: 0.2s;
                }
                .fcal_container .fcal_pagination {
                    justify-content: flex-end;
                    text-align: right;
                    align-items: center;
                    color: #303133;
                    display: flex;
                    font-size: 0.875rem;
                    font-weight: 400;
                    white-space: nowrap;
                    margin-top: 0.5rem;
                    padding: 0;
                }
                .fcal_container .fcal_pagination form select {
                    display: flex;
                    align-items: center;
                    margin-left: 1rem;
                    border: 1px solid #d6dae1;
                    border-radius: 8px;
                    color: #1b2533;
                    height: 2rem;
                    line-height: 1;
                    padding: 0.25rem;
                    transition: 0.2s;
                    cursor: pointer;
                }
                .fcal_container .fcal_pagination .fcal_btn {
                    color: #303133;
                    text-decoration: none;
                    background-color: transparent;
                    margin-left: 1rem;
                }
                .fcal_container .fcal_pagination .fcal_btn.prev {
                    padding: 0 1rem;
                }
                .fcal_container .fcal_pagination .fcal_btn.disabled {
                    color: #c0c4cc;
                    cursor: not-allowed;
                }
                .fcal_container .fcal_pagination .fcal_pager {
                    align-items: center;
                    display: flex;
                    font-size: 0;
                    list-style: none;
                    margin: 0;
                    padding: 0;
                }
                .fcal_container .fcal_pagination .fcal_pager li {
                    border-radius: 8px;
                    height: 2rem;
                    transition: 0.2s;
                    background: #fff;
                    border: none;
                    box-sizing: border-box;
                    color: #303133;
                    font-size: 0.875rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    text-align: center;
                    line-height: 2rem;
                    min-width: 2rem;
                    padding: 0 4px;
                    cursor: pointer;
                }
                .fcal_container .fcal_pagination .fcal_pager li.active {
                    background: #306ae0;
                    color: #fff;
                }
            </style>

            <?php
        endif;
        echo $shortcode_output;
    }

    public function content_template()
    {

    }
}
