<?php

namespace FluentBooking\App\Services\Integrations\Elementor;


use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\App;

class ElementorIntegration
{
    public function register()
    {
        add_action('elementor/init', [$this, 'addElementorCategory']);

        add_action('elementor/widgets/widgets_registered', [$this, 'registerWidget']);
        add_action('elementor/controls/controls_registered', [$this, 'registerControl']);

        add_action('elementor/editor/before_enqueue_scripts', [$this, 'editorScripts']);
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'editorScripts']);

        add_action('wp_ajax_get_calendar_events', [$this, 'ajaxGetCalendarEvents']);
        add_action('wp_ajax_nopriv_get_calendar_events', [$this, 'ajaxGetCalendarEvents']);

        add_action('wp_ajax_get_event_hash', [$this, 'ajaxGetEventHash']);
        add_action('wp_ajax_nopriv_get_event_hash', [$this, 'ajaxGetEventHash']);
    }

    public function editorScripts()
    {
        wp_enqueue_script('fcal-custom-elementor', plugin_dir_url(__FILE__) . 'fcal-custom-elementor.js', ['jquery'], time(), true);

        wp_localize_script('fcal-custom-elementor', 'fcal_elementor_ajax_object', array(
            'nonce'   => wp_create_nonce('calendar_events_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'svgIcon' => App::getInstance('url.assets') . 'images/fluentbooking.svg'
        ));
    }


    private function getCalendarEvents($selectedCalId = '')
    {
        if (empty($selectedCalId)) {
            return [];
        }

        $calendar = Calendar::with(['events' => function ($query) {
            $query->where('status', 'active');
        }])->find($selectedCalId);

        if (!$calendar) {
            return [];
        }

        $formattedData = [];

        foreach ($calendar->events as $event) {
            $formattedData[$event->id] = $event->title;
        }

        return $formattedData;
    }

    public function addElementorCategory()
    {
        \Elementor\Plugin::instance()->elements_manager->add_category('fluentbooking', [
            'title' => __('FluentBooking', 'fluent-booking'),
        ], 1);
    }

    /**
     * @throws \Exception
     */
    public function registerWidget()
    {
        $this->includeWidgets();
        $this->registerWidgets();
    }

    /**
     * @throws \Exception
     */
    public function includeWidgets()
    {
        $this->loadFile('/Widgets/FcalCalendarEvent.php');
        $this->loadFile('/Widgets/FcalBookings.php');
        $this->loadFile('/Widgets/FcalCalendar.php');
    }

    /**
     * @throws \Exception
     */
    public function registerControl($controls_manager)
    {
        $this->loadFile('/Controls/FluentBookingCustomGroupSelect.php');
        $this->registerFluentBookingCustomGroupSelect($controls_manager);
    }

    public function ajaxGetCalendarEvents() {
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'calendar_events_nonce')) {
            wp_send_json_error(['message' => __('Nonce verification failed', 'fluent-booking')]);
            exit;
        }

        if (!isset($_POST['cal_id'])) {
            wp_send_json_error(['message' => __('No calendar ID provided', 'fluent-booking')]);
        }

        $calId = intval($_POST['cal_id']);
        $events = []; // Fetch events using your getCalendarEvents method or similar

        // Example of fetching events (you need to replace this with your actual method)
        $events = $this->getCalendarEvents($calId);

        wp_send_json_success($events);
    }

    public function ajaxGetEventHash() {
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'calendar_events_nonce')) {
            wp_send_json_error(['message' => __('Nonce verification failed', 'fluent-booking')]);
            exit;
        }

        $eventId = intval($_POST['event_id']);

        $event = CalendarSlot::find($eventId);

        if (!$event) {
            wp_send_json_error(['message' => __('Event not found', 'fluent-booking')]);
            exit;
        }

        $eventHash = $event->hash;

        wp_send_json_success(['hash' => $eventHash]);
    }

    public function registerWidgets()
    {
        \Elementor\Plugin::instance()->widgets_manager->register(new \FluentBooking\App\Services\Integrations\Elementor\Widgets\FcalCalendarEvent());
        \Elementor\Plugin::instance()->widgets_manager->register(new \FluentBooking\App\Services\Integrations\Elementor\Widgets\FcalBookings());
        \Elementor\Plugin::instance()->widgets_manager->register(new \FluentBooking\App\Services\Integrations\Elementor\Widgets\FcalCalendar());
    }

    /**
     * @throws \Exception
     */
    private function loadFile($relativePath)
    {
        $filePath = __DIR__ . $relativePath;
        if (file_exists($filePath)) {
            require_once($filePath);
        } else {
            throw new \Exception(sprintf(__("File not found: %s", "fluent-booking"), $filePath));
        }
    }

    private function registerFluentBookingCustomGroupSelect($controls_manager)
    {
        $control = new \FluentBookingCustomGroupSelect();
        \Elementor\Plugin::instance()->controls_manager->register($control, 'fcal_group_select');
    }

}
