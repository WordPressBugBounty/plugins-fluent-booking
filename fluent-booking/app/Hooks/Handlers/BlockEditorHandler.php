<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\App\App;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\CalendarEventService;
use FluentBooking\App\Services\Helper;
use FluentBooking\Framework\Support\Arr;

class BlockEditorHandler
{
    public function init()
    {
        add_action('enqueue_block_editor_assets', function () {
            $app = App::getInstance();
            $assets = $app['url.assets'];

            wp_enqueue_script(
                'fluent-booking/calendar',
                $assets . 'admin/fluent-booking-index.js',
                array('wp-blocks', 'wp-components', 'wp-block-editor', 'wp-element'),
                FLUENT_BOOKING_ASSETS_VERSION,
                true
            );

            wp_localize_script('fluent-booking/calendar', 'fluentCalendarGutenbergVars', [
                'ajaxurl' => admin_url('admin-ajax.php'),
            ]);

            wp_enqueue_script(
                'fluent-booking/team-management',
                $assets . 'admin/fluent-booking-team-management-index.js',
                array('wp-blocks', 'wp-components', 'wp-block-editor', 'wp-element'),
                FLUENT_BOOKING_ASSETS_VERSION,
                true
            );

            wp_enqueue_script(
                'fluent-booking/calendar-management',
                $assets . 'admin/fluent-booking-calendar-management-index.js',
                array('wp-blocks', 'wp-components', 'wp-block-editor', 'wp-element'),
                FLUENT_BOOKING_ASSETS_VERSION,
                true
            );

            wp_enqueue_script(
                'fluent-booking/booking-management',
                $assets . 'admin/fluent-booking-booking-management-index.js',
                array('wp-blocks', 'wp-components', 'wp-block-editor', 'wp-element'),
                FLUENT_BOOKING_ASSETS_VERSION,
                true
            );

            $calendars = Calendar::with(['events' => function ($query) {
                $query->where('status', 'active');
            }])->where('status', 'active')->get();

            $formattedCalendars = [];

            foreach ($calendars as $calendar) {
                if ($calendar->events->isEmpty()) {
                    continue;
                }

                $formattedEvents = [];
                foreach ($calendar->events as $event) {
                    $formattedEvents[] = [
                        'id'           => (string)$event->id,
                        'title'        => $event->title,
                        'color_schema' => $event->color_schema,
                        'durations'    => $event->getAvailableDurations(),
                        'locations'    => $event->defaultLocationHtml(),
                        'payment_html' => $event->getPaymentHtml(),
                        'loc_settings' => $event->location_settings,
                        'description'  => $event->short_description
                    ];
                }

                $formattedCalendars[$calendar->id] = [
                    'id'          => (string)$calendar->id,
                    'title'       => $calendar->title,
                    'description' => wpautop(Helper::excerpt($calendar->description, 200)),
                    'author'      => $calendar->getAuthorProfile(),
                    'event_order' => $calendar->getMeta('event_order'),
                    'events'      => $formattedEvents
                ];
            }

            wp_localize_script('fluent-booking/calendar', 'fluent_booking_block', [
                'assets_url' => $assets,
                'hosts'      => $formattedCalendars
            ]);
        });

        register_block_type('fluent-booking/calendar', array(
            'editor_script'   => 'fluent-booking/calendar',
            'render_callback' => array($this, 'fcalRenderBlock'),
            'attributes'      => [
                    'slotId'      => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                    'calendarId'     => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                    'eventHash'     => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                    'avatar_rounded' => [
                        'type'    => 'boolean',
                        'default' => false
                    ],
                    'primary_color'  => [
                        'type'    => 'string',
                        'default' => '#4587EC'
                    ],
                    'date_round'     => [
                        'type'    => 'string',
                        'default' => '4px'
                    ],
                    'avatarStyle'    => [
                        'type'    => 'string',
                        'default' => '8px'
                    ],
                    'hideHostInfo'   => [
                        'type'    => 'string',
                        'default' => 'no'
                    ],
                    'theme'   => [
                        'type'    => 'string',
                        'default' => 'light'
                    ]
            ]
        ));

        register_block_type('fluent-booking/team-management', array(
            'editor_script'   => 'fluent-booking/team-management',
            'render_callback' => array($this, 'fcalRenderTeamManagementBlock'),
            'attributes'      => [
                'title'       => [
                    'type'    => 'string',
                    'default' => ''
                ],
                'description' => [
                    'type'    => 'string',
                    'default' => ''
                ],
                'headerImage' => [
                    'type'    => 'object',
                    'default' => ''
                ],
                'hosts'       => [
                    'type'    => 'object',
                    'default' => ''
                ]
            ]
        ));

        register_block_type('fluent-booking/calendar-management', array(
            'editor_script'   => 'fluent-booking/calendar-management',
            'render_callback' => array($this, 'fcalRenderCalendarManagementBlock'),
            'attributes'      => array(
                'title'       => array(
                    'type'    => 'string',
                    'default' => ''
                ),
                'description' => array(
                    'type'    => 'string',
                    'default' => ''
                ),
                'headerImage' => array(
                    'type'    => 'object',
                    'default' => ''
                ),
                'calendarId'  => array(
                    'type'    => 'string',
                    'default' => ''
                ),
                'eventIds'    => array(
                    'type'    => 'array',
                    'default' => []
                ),
                'hideInfo'    => array(
                    'type'    => 'boolean',
                    'default' => false
                )
            )
        ));

        register_block_type('fluent-booking/booking-management', array(
            'editor_script'   => 'fluent-booking/booking-management',
            'render_callback' => array($this, 'fcalRenderBookingManagementBlock'),
            'attributes'      => [
                'title'             => [
                    'type'    => 'string',
                    'default' => __('My Bookings', 'fluent-booking')
                ],
                'showFilter'        => [
                    'type'    => 'boolean',
                    'default' => true
                ],
                'showPagination'    => [
                    'type'    => 'boolean',
                    'default' => true
                ],
                'period'            => [
                    'type'    => 'string',
                    'default' => 'all'
                ],
                'perPage'           => [
                    'type'    => 'number',
                    'default' => 5
                ],
                'noBookingsMessage' => [
                    'type'    => 'string',
                    'default' => __('No bookings found', 'fluent-booking')
                ],
                'calendarIds'       => [
                    'type'    => 'array',
                    'default' => ['all']
                ]
            ]
        ));
    }

    public function fcalRenderTeamManagementBlock($attributes)
    {
        $hosts = Arr::get($attributes, 'calendarHosts', []);

        $wrapperClassName = Arr::get($attributes, 'className');

        if (!$hosts) {
            return '';
        }

        $hostItems = [];

        foreach ($hosts as $config) {
            $calendar = Calendar::find($config['id']);
            if (!$calendar) {
                continue;
            }

            $eventIds = Arr::get($config, 'events', []);
            if (!$eventIds) {
                continue;
            }

            $eventsQuery = CalendarSlot::where('calendar_id', $calendar->id)
                ->where('status', 'active');

            if (!in_array('all', $eventIds)) {
                $eventsQuery->whereIn('id', $eventIds);
            }

            $events = $eventsQuery->get();

            if ($events->isEmpty()) {
                continue;
            }

            $events = CalendarEventService::processEvents($calendar, $events);

            $calendar->activeEvents = $events;

            $hostItems[$calendar->id] = $calendar;
        }

        return (new FrontEndHandler())->renderTeamHosts($hostItems, [
            'title'         => Arr::get($attributes, 'title'),
            'description'   => Arr::get($attributes, 'description'),
            'logo'          => Arr::get($attributes, 'headerImage.url'),
            'wrapper_class' => $wrapperClassName
        ]);
    }

    public function fcalRenderCalendarManagementBlock($attributes)
    {
        $calendarId = Arr::get($attributes, 'calendarId');

        $eventIds = Arr::get($attributes, 'eventIds', []);

        if (!$calendarId || !$eventIds) {
            return '';
        }

        $calendar = Calendar::find($calendarId);
        if (!$calendar) {
            return '';
        }

        $eventsQuery = CalendarSlot::where('calendar_id', $calendar->id)
            ->where('status', 'active');

        if (!in_array('all', $eventIds)) {
            $eventsQuery->whereIn('id', $eventIds);
        }

        $events = $eventsQuery->get();

        if ($events->isEmpty()) {
            return '';
        }

        $events = CalendarEventService::processEvents($calendar, $events);

        $calendar->activeEvents = $events;

        return (new FrontEndHandler())->renderCalendarBlock($calendar, [
            'title'         => Arr::get($attributes, 'title'),
            'description'   => Arr::get($attributes, 'description'),
            'wrapper_class' => Arr::get($attributes, 'className'),
            'logo'          => Arr::get($attributes, 'headerImage.url'),
            'hide_info'     => Arr::isTrue($attributes, 'hideInfo')
        ]);
    }

    public function fcalRenderBookingManagementBlock($attributes)
    {
        $calendarIds = Arr::get($attributes, 'calendarIds', []);

        $title = sanitize_text_field(Arr::get($attributes, 'title'));

        $period = sanitize_text_field(Arr::get($attributes, 'period', 'all'));

        $perPage = intval(Arr::get($attributes, 'perPage', 10));

        $showFilter = Arr::isTrue($attributes, 'showFilter', true) ? 'show' : 'hide';

        $showPagination = Arr::isTrue($attributes, 'showPagination', true) ? 'show' : 'hide';

        $noBookingsMessage = sanitize_text_field(Arr::get($attributes, 'noBookingsMessage'));

        return do_shortcode("[fluent_booking_lists title=\"$title\" period=$period per_page=$perPage filter=$showFilter pagination=$showPagination no_bookings=\"$noBookingsMessage\" calendar_ids=" . implode(',', $calendarIds) . "]");
    }

    public function fcalRenderBlock($attributes)
    {
        $output = '<style>
            :root {
                --fcal_primary_color: ' . esc_attr($attributes['primary_color']) . ' !important;
                --fcal_date_radius: ' . esc_attr($attributes['date_round']) . ' !important;
                --fcal_avatar_radius: ' . esc_attr($attributes['avatarStyle']) . ' !important;
            }
        </style>';

        $slotId = (int) $attributes['slotId'];
        $disableHost = $attributes['hideHostInfo'];
        $theme = Arr::get($attributes, 'theme', 'light');
        $eventHash = Arr::get($attributes, 'eventHash');

        $slot = CalendarSlot::find($slotId);

        if (!$slot) {
            $slot = CalendarSlot::where('hash', $eventHash)->first();
            if (!$slot) {
                return '';
            }
            $slotId = $slot->id;
        }

        $output .= '<div class="fluent-booking-calendar-block align' . Arr::get($attributes, 'align') . '">';

        $output .= do_shortcode("[fluent_booking id=$slotId disable_author=$disableHost theme=$theme hash=$eventHash]");

        $output .= '</div>';
        return $output;
    }
}
