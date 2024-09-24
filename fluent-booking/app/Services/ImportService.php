<?php

namespace FluentBooking\App\Services;

use FluentBooking\Framework\Support\Arr;

class ImportService
{
    /*
     * Import host data from JSON
     * @param array|string $data JSON data or array
     * @param bool $useCurrentUser If true, the current user will be used as the host
     * @return \FluentBooking\App\Models\Calendar|\WP_Error
     */
    public function importHostJson($data, $useCurrentUser = true)
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (!$data || !is_array($data) || empty($data['data_type']) || $data['data_type'] !== 'host') {
            return new \WP_Error('invalid_data', 'Invalid data provided');
        }

        if (empty($data['title']) || empty($data['slug'])) {
            return new \WP_Error('invalid_data', 'Invalid data provided');
        }

        $createdCalendar = CalendarService::createCalendar($data, $useCurrentUser);

        if (is_wp_error($createdCalendar)) {
            return new \WP_Error($createdCalendar->get_error_code(), $createdCalendar->get_error_message());
        }

        return $createdCalendar;
    }

    /*
     * Import host data from JSON URL
     * @param string $jsonUrl JSON URL
     * @param bool $useCurrentUser If true, the current user will be used as the host
     * @return \FluentBooking\App\Models\Calendar|\WP_Error
     */
    public function importHostByJSONUrl($jsonUrl, $useCurrentUser = true)
    {
        $response = wp_safe_remote_get($jsonUrl, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        // check if the status code is not 200
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('invalid_response', 'Invalid response from the server');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $this->importHostJson($body, $useCurrentUser);
    }
}
