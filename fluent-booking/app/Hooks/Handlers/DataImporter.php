<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\App\App;
use FluentBooking\App\Services\CalendarService;
use FluentBooking\App\Services\PermissionManager;

class DataImporter
{
    public function importCalendar()
    {
        if (!PermissionManager::userCan(['invite_team_members', 'manage_all_data', 'manage_other_calendars'])) {
            wp_send_json_error([
                'message' => __('You are not authorized to import calendar', 'fluent-booking'),
            ]);
        }

        $app = App::getInstance();

        $data = $app->request->all();

        if (empty($data['type'] || empty($data['user_id'] || empty($data['author_timezone'])))) {
            wp_send_json_error([
                'message' => __('Please provide all required data', 'fluent-booking'),
            ]);
        }

        $file = $app->request->file('file');

        if (empty($file) || $file->getClientOriginalExtension() != 'json') {
            wp_send_json_error([
                'message' => __('Invalid file. Please provide a valid JSON file', 'fluent-booking'),
            ]);
        }

        $fileContent = $file->getContents();

        $calendarData = wp_parse_args($data, json_decode($fileContent, true));

        if (empty($calendarData)) {
            wp_send_json_error([
                'message' => __('Invalid file. Please provide a valid JSON file', 'fluent-booking'),
            ]);
        }

        $calendar = CalendarService::createCalendar($calendarData, false, true);

        if (is_wp_error($calendar)) {
            wp_send_json_error([
                'message' => $calendar->get_error_message(),
            ]);
        }

        wp_send_json([
            'success'  => true,
            'message'  => __('Calendar imported successfully', 'fluent-booking'),
        ]);
    }
}
