<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\App;
use FluentBooking\App\Services\GlobalModules\GlobalModules;
use FluentBooking\App\Hooks\Handlers\AdminMenuHandler;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\Libs\Countries;
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

        $currency = Arr::get($paymentSettings, 'currency');
        $isActive = Arr::get($paymentSettings, 'is_active', 'no');

        update_option('fluent_booking_global_payment_settings', [
            'currency'  => sanitize_text_field($currency),
            'is_active' => ($isActive == 'yes') ? 'yes' : 'no'
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

        $featuresPrefs = Helper::getPrefSettins(false);

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
                'title' => $page->post_title ? $page->post_title : __('(no title)', 'fluent-boards')
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

        $prefSettings = Helper::getPrefSettins(false);

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
        ];

        if (!isset($allowedPlugins[$pluginId])) {
            return $this->sendError([
                'message' => __('This action is not allowed', 'fluent-booking')
            ]);
        }

        $this->backgroundInstaller($allowedPlugins[$pluginId], $pluginId);

        return $this->sendSuccess([
            'message' => __('Plugin is being installed in the background', 'fluent-booking')
        ]);
    }

    private function backgroundInstaller($plugin_to_install, $plugin_id)
    {
        if (!empty($plugin_to_install['repo-slug'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            WP_Filesystem();

            $skin = new \Automatic_Upgrader_Skin();
            $upgrader = new \WP_Upgrader($skin);
            $installed_plugins = array_reduce(array_keys(\get_plugins()), array($this, 'associate_plugin_file'), array());
            $plugin_slug = $plugin_to_install['repo-slug'];
            $plugin_file = isset($plugin_to_install['file']) ? $plugin_to_install['file'] : $plugin_slug . '.php';
            $installed = false;
            $activate = false;

            // See if the plugin is installed already.
            if (isset($installed_plugins[$plugin_file])) {
                $installed = true;
                $activate = !is_plugin_active($installed_plugins[$plugin_file]);
            }

            // Install this thing!
            if (!$installed) {
                // Suppress feedback.
                ob_start();

                try {
                    $plugin_information = plugins_api(
                        'plugin_information',
                        array(
                            'slug'   => $plugin_slug,
                            'fields' => array(
                                'short_description' => false,
                                'sections'          => false,
                                'requires'          => false,
                                'rating'            => false,
                                'ratings'           => false,
                                'downloaded'        => false,
                                'last_updated'      => false,
                                'added'             => false,
                                'tags'              => false,
                                'homepage'          => false,
                                'donate_link'       => false,
                                'author_profile'    => false,
                                'author'            => false,
                            ),
                        )
                    );

                    if (is_wp_error($plugin_information)) {
                        throw new \Exception($plugin_information->get_error_message());
                    }

                    $package = $plugin_information->download_link;
                    $download = $upgrader->download_package($package);

                    if (is_wp_error($download)) {
                        throw new \Exception($download->get_error_message());
                    }

                    $working_dir = $upgrader->unpack_package($download, true);

                    if (is_wp_error($working_dir)) {
                        throw new \Exception($working_dir->get_error_message());
                    }

                    $result = $upgrader->install_package(
                        array(
                            'source'                      => $working_dir,
                            'destination'                 => WP_PLUGIN_DIR,
                            'clear_destination'           => false,
                            'abort_if_destination_exists' => false,
                            'clear_working'               => true,
                            'hook_extra'                  => array(
                                'type'   => 'plugin',
                                'action' => 'install',
                            ),
                        )
                    );

                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }

                    $activate = true;

                } catch (\Exception $e) {
                }

                // Discard feedback.
                ob_end_clean();
            }

            wp_clean_plugins_cache();

            // Activate this thing.
            if ($activate) {
                try {
                    $result = activate_plugin($installed ? $installed_plugins[$plugin_file] : $plugin_slug . '/' . $plugin_file);

                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }
                } catch (\Exception $e) {
                }
            }
        }
    }

    private function associate_plugin_file($plugins, $key)
    {
        $path = explode('/', $key);
        $filename = end($path);
        $plugins[$filename] = $key;
        return $plugins;
    }
}
