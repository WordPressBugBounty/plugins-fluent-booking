<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\App;
use FluentBooking\App\Services\GlobalModules\GlobalModules;
use FluentBooking\App\Hooks\Handlers\AdminMenuHandler;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\Libs\Countries;
use FluentBooking\App\Services\OnboardingService;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Support\Arr;

class SettingsController extends Controller
{
    public function getSettingsMenu()
    {
        return [
            'menu_items' => AdminMenuHandler::settingsMenuItems()
        ];
    }

    public function getGeneralSettings()
    {
        $settings = Helper::getGlobalSettings();

        $settings['emailingFields'] = [
            'from_name'                  => [
                'wrapper_class' => 'fc_item_half',
                'type'          => 'input-text',
                'placeholder'   => __('From Name for emails', 'fluent-booking'),
                'label'         => __('From Name', 'fluent-booking'),
                'help'          => __('Default Name that will be used to send email)', 'fluent-booking')
            ],
            'from_email'                 => [
                'wrapper_class' => 'fc_item_half',
                'type'          => 'input-or-select',
                'placeholder'   => 'name@domain.com',
                'data_type'     => 'email',
                'options'       => Helper::getVerifiedSenders(),
                'label'         => __('From Email Address', 'fluent-booking'),
                'help'          => __('Provide Valid Email Address that will be used to send emails', 'fluent-booking'),
                'inline_help'   => __('email as per your domain/SMTP settings', 'fluent-booking')
            ],
            'reply_to_name'              => [
                'wrapper_class' => 'fc_item_half',
                'type'          => 'input-text',
                'placeholder'   => __('Reply to Name', 'fluent-booking'),
                'label'         => __('Reply to Name (Optional)', 'fluent-booking'),
                'help'          => __('Default Reply to Name (Optional)', 'fluent-booking')
            ],
            'reply_to_email'             => [
                'wrapper_class' => 'fc_item_half',
                'type'          => 'input-text',
                'placeholder'   => 'name@domain.com',
                'data_type'     => 'email',
                'label'         => __('Reply to Email (Optional)', 'fluent-booking'),
                'help'          => __('Default Reply to Email (Optional)', 'fluent-booking')
            ],
            'use_host_name'              => [
                'wrapper_class'  => 'fc_full_width fc_mb_0',
                'type'           => 'inline-checkbox',
                'checkbox_label' => __('Use host name as From Name for booking emails to guests', 'fluent-booking'),
                'true_label'     => 'yes',
                'false_label'    => 'no',
            ],
            'use_host_email_on_reply'    => [
                'wrapper_class'  => 'fc_full_width fc_mb_0',
                'type'           => 'inline-checkbox',
                'checkbox_label' => __('Use host email for reply-to value for booking emails to guests', 'fluent-booking'),
                'true_label'     => 'yes',
                'false_label'    => 'no',
            ],
            'attach_ics_on_confirmation' => [
                'wrapper_class'  => 'fc_full_width fc_mb_0',
                'type'           => 'inline-checkbox',
                'checkbox_label' => __('Include ICS file attachment in booking confirmation emails', 'fluent-booking'),
                'true_label'     => 'yes',
                'false_label'    => 'no',
            ],
            'email_footer'               => [
                'wrapper_class' => 'fc_full_width fc_mb_0 fc_wp_editor',
                'type'          => 'wp-editor-field',
                'label'         => __('Email Footer for Booking related emails (Optional)', 'fluent-booking'),
                'inline_help'   => __('You may include your business name, address etc here, for example: <br />You have received this email because signed up for an event or made a booking on our website.', 'fluent-booking')
            ]
        ];

        $settings['all_countries'] = Countries::get();

        return apply_filters('fluent_booking/general_settings', $settings);
    }

    public function updateGeneralSettings(Request $request)
    {
        $settings = [
            'emailing'       => $request->get('emailing', []),
            'administration' => $request->get('administration', []),
        ];

        $formattedSettings = [];

        foreach ($settings as $settingKey => $setting) {
            $santizedSettings = array_map('sanitize_text_field', $setting);
            if ($settingKey == 'emailing') {
                $santizedSettings['email_footer'] = wp_kses_post($setting['email_footer']);
            }
            $formattedSettings[$settingKey] = $santizedSettings;
        }
        $formattedSettings['time_format'] = $request->get('timeFormat');

        update_option('_fluent_booking_settings', $formattedSettings, 'no');

        return [
            'message'  => __('Settings updated successfully', 'fluent-booking'),
            'settings' => $formattedSettings
        ];
    }

    public function updatePaymentSettings(Request $request)
    {
        $paymentSettings = $request->get('payments', []);

        $currency = Arr::get($paymentSettings, 'currency', 'USD');
        $isActive = Arr::get($paymentSettings, 'is_active', 'no');
        $numberFormat = Arr::get($paymentSettings, 'number_format', 'comma_separated');
        $currencyPosition = Arr::get($paymentSettings, 'currency_position', 'left');

        update_option('fluent_booking_global_payment_settings', [
            'currency'          => sanitize_text_field($currency),
            'is_active'         => $isActive == 'yes' ? 'yes' : 'no',
            'number_format'     => $numberFormat == 'comma_separated' ? 'comma_separated' : 'dot_separated',
            'currency_position' => sanitize_text_field($currencyPosition)
        ], 'no');

        return [
            'message' => __('Settings updated successfully', 'fluent-booking')
        ];
    }

    public function updateThemeSettings(Request $request)
    {
        $themeSettings = sanitize_text_field($request->get('theme'));

        $bookingOption = get_option('_fluent_booking_settings', []);

        $bookingOption['theme'] = $themeSettings;

        update_option('_fluent_booking_settings', $bookingOption, 'no');

        return [
            'message' => __('Settings updated successfully', 'fluent-booking')
        ];
    }

    public function getGlobalModules(Request $request)
    {
        $settings = Helper::getGlobalModuleSettings();


        if (!$settings) {
            $settings = (object)[];
        }

        $featuresPrefs = Helper::getPrefSettings(false);

        if (empty($featuresPrefs['frontend']['render_type'])) {
            $featuresPrefs['frontend']['render_type'] = 'standalone';
        }

        $featuresPrefs['panel_url'] = Helper::getAppBaseUrl();

        return [
            'settings'       => $settings,
            'modules'        => (new GlobalModules())->getAllModules(),
            'featureModules' => $featuresPrefs
        ];
    }

    public function updateGlobalModules(Request $request)
    {
        $settings = $request->get('settings', []);

        $modules = (new GlobalModules())->getAllModules();

        $formattedModules = [];

        foreach ($settings as $settingKey => $value) {
            if (!isset($modules[$settingKey])) {
                continue;
            }
            $formattedModules[$settingKey] = $value == 'yes' ? 'yes' : 'no';
        }

        Helper::updateGlobalModuleSettings($formattedModules);

        return [
            'message' => __('Settings updated successfully', 'fluent-booking'),
        ];
    }

    public function getPages(Request $request)
    {
        $db = App::getInstance('db');

        $allPages = $db->table('posts')->where('post_type', 'page')
            ->where('post_status', 'publish')
            ->select(['ID', 'post_title', 'post_name'])
            ->orderBy('post_title', 'ASC')
            ->get();

        $pages = [];
        foreach ($allPages as $page) {
            $pages[] = [
                'id'    => $page->ID,
                'name'  => $page->post_name,
                'title' => $page->post_title ? $page->post_title : __('(no title)', 'fluent-booking')
            ];
        }

        return [
            'pages' => $pages
        ];
    }

    public function saveAddonsSettings(Request $request)
    {
        if (!defined('FLUENT_BOOKING_PRO_DIR_FILE')) {
            return $this->sendError([
                'message' => __('This feature is only available in FluentBooking Pro', 'fluent-booking')
            ]);
        }

        $settings = $request->get('settings', []);

        $prefSettings = Helper::getPrefSettings(false);

        $settings = wp_parse_args($settings, $prefSettings);

        $settings = Arr::only($settings, array_keys($prefSettings));
        $settings['frontend']['slug'] = sanitize_title($settings['frontend']['slug']);

        if (empty($settings['frontend']['slug'])) {
            $settings['frontend']['slug'] = 'projects';
        }

        if (defined('FLUENT_BOOKING_ADMIN_SLUG') && FLUENT_BOOKING_FRONT_SLUG) {
            $settings['frontend']['slug'] = FLUENT_BOOKING_FRONT_SLUG;
        }

        do_action('fluent_booking/saving_addons', $settings, $prefSettings);

        update_option('fluent_booking_modules', $settings, 'yes');

        return $this->sendSuccess([
            'message'        => __('Settings are saved', 'fluent-booking'),
            'featureModules' => $settings
        ]);
    }

    public function installPlugin(Request $request)
    {
        if (!current_user_can('install_plugins')) {
            return $this->sendError([
                'message' => __('You do not have permission to install plugins', 'fluent-booking')
            ]);
        }

        $pluginId = $request->get('plugin_id');

        $allowedPlugins = [
            'fluent-smtp'    => [
                'name'      => 'FluentSMTP',
                'repo-slug' => 'fluent-smtp',
                'file'      => 'fluent-smtp.php',
            ],
            'fluent-booking' => [
                'name'      => 'FluentBooking',
                'repo-slug' => 'fluent-booking',
                'file'      => 'fluent-booking.php',
            ],
            'fluentform'     => [
                'name'      => 'FluentForm',
                'repo-slug' => 'fluentform',
                'file'      => 'fluentform.php',
            ],
            'fluent-crm'     => [
                'name'      => 'FluentCRM',
                'repo-slug' => 'fluent-crm',
                'file'      => 'fluent-crm.php',
            ],
            'fluent-boards'  => [
                'name'      => 'FluentBoards',
                'repo-slug' => 'fluent-boards',
                'file'      => 'fluent-boards.php',
            ],
            'fluent-cart' => [
                'name'      => 'FluentCart',
                'repo-slug' => 'fluent-cart',
                'file'      => 'fluent-cart.php',
            ]
        ];

        if (!isset($allowedPlugins[$pluginId])) {
            return $this->sendError([
                'message' => __('This action is not allowed', 'fluent-booking')
            ]);
        }

        OnboardingService::backgroundInstaller($allowedPlugins[$pluginId]);

        return $this->sendSuccess([
            'message' => __('Plugin has been installed Successfully', 'fluent-booking')
        ]);
    }
}
