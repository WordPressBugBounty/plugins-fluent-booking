<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\Framework\Http\Request\Request;

class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $settingsKey = sanitize_text_field($request->get('settings_key'));
            $settings = apply_filters('fluent_booking/get_client_settings_' . $settingsKey, []);
            $fieldSettings = apply_filters('fluent_booking/get_client_field_settings_' . $settingsKey, []);

            return [
                'status'         => !empty($fieldSettings),
                'settings'       => $settings,
                'field_settings' => $fieldSettings,
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function update(Request $request)
    {
        try {
            $settingsKey = sanitize_text_field($request->get('settings_key'));

            $settings = wp_unslash($request->get('settings'));

            do_action('fluent_booking/save_client_settings_' . $settingsKey, $settings);

            return [
                'message' => __('Settings has been successfully saved.', 'fluent-booking')
            ];

        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
