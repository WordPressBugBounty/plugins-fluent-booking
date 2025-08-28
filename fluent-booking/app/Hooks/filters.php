<?php

use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Services\CurrenciesHelper;
use FluentBooking\App\Services\DateTimeHelper;

/**
 * All registered filter's handlers should be in app\Hooks\Handlers,
 * addFilter is similar to add_filter and addCustomFlter is just a
 * wrapper over add_filter which will add a prefix to the hook name
 * using the plugin slug to make it unique in all wordpress plugins,
 * ex: $app->addCustomFilter('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_filter('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 * @var $app FluentBooking\Framework\Foundation\Application
 */

 $app->addFilter('fluent_booking/calendar_event_setting_menu_items', function ($items, $event) {
    if ($event->calendar->type != 'simple') {
        $items['assignment']['visible'] = true;
    }
    return $items;
}, 10, 2);

$app->addFilter('fluent_booking/calendar_setting_menu_items', function ($items, $calendar) {
    if ($calendar->type != 'simple') {
        $teamLabel = __('Team Settings', 'fluent-booking');
        $eventLabel = __('Calendar Settings', 'fluent-booking');

        $label = $calendar->type == 'event' ? $eventLabel : $teamLabel;
        $items['calendar_settings']['label'] = $label;

        $items['remote_calendars']['visible'] = false;
        $items['zoom_meeting']['visible'] = false;
    }
    return $items;
}, 20, 2);

 $app->addFilter('fluent_booking/get_calendar_event_settings', function($settings) {
    if (!isset($settings['buffer_time_before'], $settings['buffer_time_after'])) {
        $settings['buffer_time_before'] = '0';
        $settings['buffer_time_after'] = '0';
    }

    if (!isset($settings['slot_interval'])) {
        $settings['slot_interval'] = '';
    }

    if (!isset($settings['booking_frequency'], $settings['booking_duration'])) {
        $settings['booking_frequency'] = [
            'enabled' => false,
            'limits'  => [
                ['unit'  => 'per_day', 'value' => 5]
            ]
        ];
        $settings['booking_duration'] = [
            'enabled' => false,
            'limits'  => [
                ['unit'  => 'per_day', 'value' => 120]
            ]
        ];
    }

    if (!isset($settings['booking_title'])) {
        $settings['booking_title'] = '';
    }

    if (!isset($settings['submit_button_text'])) {
        $settings['submit_button_text'] = '';
    }

    if (!isset($settings['multiple_booking'])) {
        $settings['multiple_booking'] = [
            'enabled' => false,
            'limit'   => 5
        ];
    }

    if (!isset($settings['can_not_cancel'])) {
        $enabled = Arr::get($settings, 'can_cancel') == 'no' ? true : false;
        $settings['can_not_cancel'] = [
            'enabled'   => $enabled,
            'message'   => 'Sorry! you can not cancel this',
            'type'      => 'always',
            'condition' => [
                'unit'  => 'minutes',
                'value' => 30
            ]
        ];
    }

    if (!isset($settings['can_not_reschedule'])) {
        $enabled = Arr::get($settings, 'can_reschedule') == 'no' ? true : false;
        $settings['can_not_reschedule'] = [
            'enabled'   => $enabled,
            'message'   => 'Sorry! you can not reschedule this',
            'type'      => 'always',
            'condition' => [
                'unit'  => 'minutes',
                'value' => 30
            ]
        ];
    }

    if (!isset($settings['custom_redirect'])) {
        $settings['custom_redirect'] = [
            'enabled'         => false,
            'redirect_url'    => '',
            'is_query_string' => 'no',
            'query_string'    => ''
        ];
    }

    if (!isset($settings['multi_duration'])) {
        $settings['multi_duration'] = [
            'enabled'             => false,
            'default_duration'    => '',
            'available_durations' => []
        ];
    }

    if (!isset($settings['lock_timezone'])) {
        $settings['lock_timezone'] = [
            'enabled'  => false,
            'timezone' => ''
        ];
    }

    if (!isset($settings['requires_confirmation'])) {
        $settings['requires_confirmation'] = [
            'enabled'   => false,
            'type'      => 'always',
            'condition' => [
                'unit'  => 'minutes',
                'value' => 30
            ]
        ];
    }
    return $settings;
}, 10, 1);

$app->addFilter('fluent_booking/admin_vars', function ($vars) {
    $vars['currency_settings'] = CurrenciesHelper::getGlobalCurrencySettings();
    $vars['currency_sign'] = Arr::get($vars['currency_settings'], 'currency_sign');
    return $vars;
});

$app->addFilter('fluent_booking/general_settings', function ($settings) {
    $settings['all_currencies'] = CurrenciesHelper::getFormattedCurrencies();
    return $settings;
});

$app->addFilter('fluent_booking/public_event_vars', function($eventVars) {
    foreach ($eventVars['form_fields'] as &$field) {
        if ($field['type'] === 'date' && !empty($field['date_format'])) {
            $field['date_format'] = DateTimeHelper::convertPhpDateToDayJSFormay($field['date_format']);
        }
    }
    return $eventVars;
}, 10, 1);

$app->addFilter('fluent_booking/create_calendar_event_data', function ($slotData, $calendar) {
    if ($calendar->type != 'simple') {
        $slotData['user_id'] = reset($slotData['settings']['team_members']);
    }
    return $slotData;
}, 10, 2);

if (!defined('FLUENT_BOOKING_PRO_DIR_FILE')) {
    $app->addFilter('fluent_booking/payment/get_all_methods', function () {
        $methods['stripe'] = [
            'title' => __('Stripe', 'fluent-booking'),
            'image' => '',
            'status' => false
        ];
        $methods['paypal'] = [
            'title' => __('Paypal', 'fluent-booking'),
            'image' => '',
            'status' => false
        ];
        $methods['offline'] = [
            'title' => __('Offline Payment', 'fluent-booking'),
            'image' => '',
            'status' => false
        ];
        return $methods;
    });
}