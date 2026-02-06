<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\App\App;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\App\Services\TransStrings;

class AdminMenuHandler
{
    public function register()
    {
        add_action('admin_menu', [$this, 'add']);

        add_action('admin_enqueue_scripts', function () {
            if (!isset($_REQUEST['page']) || $_REQUEST['page'] != 'fluent-booking') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                return;
            }
            $this->enqueueAssets();
        }, 100);
    }

    public function add()
    {
        $capability = PermissionManager::getMenuPermission();
        $menuPriority = 26;

        if (defined('FLUENTCRM')) {
            $menuPriority = 4;
        }

        add_menu_page(
            __('Fluent Booking', 'fluent-booking'),
            __('Fluent Booking', 'fluent-booking'),
            $capability,
            'fluent-booking',
            [$this, 'render'],
            $this->getMenuIcon(),
            $menuPriority
        );

        add_submenu_page(
            'fluent-booking',
            __('Dashboard', 'fluent-booking'),
            __('Dashboard', 'fluent-booking'),
            $capability,
            'fluent-booking',
            [$this, 'render']
        );

        add_submenu_page(
            'fluent-booking',
            __('Calendars', 'fluent-booking'),
            __('Calendars', 'fluent-booking'),
            $capability,
            'fluent-booking#/calendars',
            [$this, 'render']
        );

        add_submenu_page(
            'fluent-booking',
            __('Bookings', 'fluent-booking'),
            __('Bookings', 'fluent-booking'),
            $capability,
            'fluent-booking#/scheduled-events',
            [$this, 'render']
        );

        add_submenu_page(
            'fluent-booking',
            __('Availability', 'fluent-booking'),
            __('Availability', 'fluent-booking'),
            $capability,
            'fluent-booking#/availability',
            [$this, 'render']
        );

        add_submenu_page(
            'fluent-booking',
            __('Settings', 'fluent-booking'),
            __('Settings', 'fluent-booking'),
            $capability,
            'fluent-booking#/settings/general-settings',
            [$this, 'render']
        );
    }

    public function render()
    {
        if (!as_has_scheduled_action('fluent_booking_five_minutes_tasks')) {
            as_schedule_recurring_action(time(), (60 * 5), 'fluent_booking_five_minutes_tasks', [], 'fluent-booking', true);
        }

        $this->changeFooter();

        $app = App::getInstance();

        $config = $app->config;

        $name = $config->get('app.name');

        $slug = $config->get('app.slug');

        $baseUrl = Helper::getAppBaseUrl();

        if (is_admin()) {
            $baseUrl = admin_url('admin.php?page=fluent-booking#/');
        }

        $isNew = $this->isNew();

        $menuItems = [
            [
                'key'       => 'dashboard',
                'label'     => $isNew ? __('Getting Started', 'fluent-booking') : __('Dashboard', 'fluent-booking'),
                'permalink' => $baseUrl
            ],
            [
                'key'       => 'calendars',
                'label'     => __('Calendars', 'fluent-booking'),
                'permalink' => $baseUrl . 'calendars'
            ],
            [
                'key'       => 'scheduled-events',
                'label'     => __('Bookings', 'fluent-booking'),
                'permalink' => $baseUrl . 'scheduled-events',
            ],
            [
                'key'       => 'availability',
                'label'     => __('Availability', 'fluent-booking'),
                'permalink' => $baseUrl . 'availability'
            ]
        ];

        $settingItems = [];
        if (PermissionManager::userCan('manage_all_data')) {
            $settingItems = [
                'key'       => 'settings',
                'label'     => __('Settings', 'fluent-booking'),
                'permalink' => $baseUrl . 'settings/general-settings'
            ];
        }

        $menuItems = apply_filters('fluent_booking/admin_menu_items', $menuItems);

        $assets = $app['url.assets'];

        $portalVars = apply_filters('fluent_booking/admin_portal_vars', [
            'name'      => $name,
            'slug'      => $slug,
            'menuItems' => $menuItems,
            'settings'  => $settingItems,
            'baseUrl'   => $baseUrl,
            'logo'      => $assets . 'images/logo.svg',
            'dark_logo' => $assets . 'images/logo_dark.svg',
        ]);

        do_action('fluent_booking/admin_app_rendering');

        $app->view->render('admin.menu', $portalVars);
    }

    public function changeFooter()
    {
        add_filter('admin_footer_text', function ($content) {
            $url = 'https://fluentbooking.com/';
            /* translators: %s: URL of the FluentBooking website */
            return sprintf(wp_kses(__('Thank you for using <a href="%s">FluentBooking</a>.', 'fluent-booking'), array('a' => array('href' => array()))), esc_url($url)) . '<span title="based on your WP timezone settings" style="margin-left: 10px;" data-timestamp="' . current_time('timestamp') . '" id="fcal_server_timestamp"></span>';
        });

        add_filter('update_footer', function ($text) {
            return FLUENT_BOOKING_VERSION;
        });
    }

    public function enqueueAssets()
    {
        $app = App::getInstance();

        $isRtl = Helper::fluentbooking_is_rtl();

        $assets = $app['url.assets'];

        $slug = $app->config->get('app.slug');

        $adminAppCss = 'admin/admin.css';
        if ($isRtl) {
            $adminAppCss = 'admin/admin-rtl.css';
            wp_enqueue_style('fluentbooking_admin_rtl', $assets . 'admin/fluentbooking_admin_rtl.css', [], FLUENT_BOOKING_ASSETS_VERSION);
        }

        add_action('wp_print_scripts', function () {

            $isSkip = apply_filters('fluent_booking/skip_no_conflict', false, 'scripts');

            if ($isSkip) {
                return;
            }

            global $wp_scripts;
            if (!$wp_scripts) {
                return;
            }

            $approvedSlugs = apply_filters('fluent_booking/asset_listed_slugs', [
                '\/fluent-crm\/'
            ]);

            $approvedSlugs[] = '\/fluent-booking\/';
            $approvedSlugs[] = '\/fluent-booking-pro\/';

            $approvedSlugs = array_unique($approvedSlugs);

            $approvedSlugs = implode('|', $approvedSlugs);

            $pluginUrl = plugins_url();

            $pluginUrl = str_replace(['http:', 'https:'], '', $pluginUrl);

            foreach ($wp_scripts->queue as $script) {
                if (empty($wp_scripts->registered[$script]) || empty($wp_scripts->registered[$script]->src)) {
                    continue;
                }

                $src = $wp_scripts->registered[$script]->src;
                $isMatched = (strpos($src, $pluginUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);
                if (!$isMatched) {
                    continue;
                }
                wp_dequeue_script($wp_scripts->registered[$script]->handle);
            }
        });

        add_action('wp_print_styles', function () {
            $isSkip = apply_filters('fluent_booking/skip_no_conflict', false, 'styles');

            if ($isSkip) {
                return;
            }

            global $wp_styles;
            if (!$wp_styles) {
                return;
            }

            $approvedSlugs = apply_filters('fluent_booking/asset_listed_slugs', [
                '\/fluent-crm\/'
            ]);

            $approvedSlugs[] = '\/fluent-booking\/';
            $approvedSlugs[] = '\/fluent-booking-pro\/';

            $approvedSlugs = array_unique($approvedSlugs);

            $approvedSlugs = implode('|', $approvedSlugs);

            $pluginUrl = plugins_url();

            $themeUrl = get_theme_root_uri();

            $pluginUrl = str_replace(['http:', 'https:'], '', $pluginUrl);
            $themeUrl = str_replace(['http:', 'https:'], '', $themeUrl);

            foreach ($wp_styles->queue as $script) {

                if (empty($wp_styles->registered[$script]) || empty($wp_styles->registered[$script]->src)) {
                    continue;
                }

                $src = $wp_styles->registered[$script]->src;
                $pluginMatched = (strpos($src, $pluginUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);
                $themeMatched = (strpos($src, $themeUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);

                if (!$pluginMatched && !$themeMatched) {
                    continue;
                }

                wp_dequeue_style($wp_styles->registered[$script]->handle);
            }
        }, 999999);

        wp_enqueue_style('fluent_booing_admin_app', $assets . $adminAppCss, [], FLUENT_BOOKING_ASSETS_VERSION, 'all');

        do_action($slug . '_loading_app'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

        wp_enqueue_script(
            $slug . '_admin_app',
            $assets . 'admin/app.js',
            array('jquery'),
            FLUENT_BOOKING_ASSETS_VERSION,
            true
        );

        wp_enqueue_script(
            $slug . '_global_admin',
            $assets . 'admin/global_admin.js',
            array(),
            FLUENT_BOOKING_ASSETS_VERSION,
            true
        );

        if (function_exists('wp_enqueue_editor')) {
            add_filter('user_can_richedit', '__return_true');
            wp_enqueue_editor();
            wp_enqueue_media();
        }

        wp_localize_script($slug . '_admin_app', 'fluentFrameworkAdmin', $this->getDashboardVars($app));
    }

    public function getDashboardVars($app)
    {
        $assets = $app['url.assets'];
        $currentUser = get_user_by('ID', get_current_user_id());

        $currentUsername = trim($currentUser->first_name . ' ' . $currentUser->last_name);
        if (!$currentUsername) {
            $currentUsername = $currentUser->display_name;
        }

        $isNew = $this->isNew();

        $requireSlug = false;

        if ($isNew) {
            $result = $this->maybeAutoCreateCalendar($currentUser);
            if (!$result) {
                $requireSlug = true;
            }
        }

        $calendarId = null;

        $firstCalendar = Calendar::where('user_id', $currentUser->ID)->where('type', 'simple')->first();

        if ($firstCalendar) {
            $calendarId = $firstCalendar->id;
        }

        $hasAllAccess = false;
        if (PermissionManager::hasAllCalendarAccess()) {
            $hasAllAccess = true;
        }

        $eventColors = Helper::getEventColors();
        $meetingDurations = Helper::getMeetingDurations();
        $multiDurations = Helper::getMeetingMultiDurations();
        $durationLookup = Helper::getDurationLookup();
        $multiDurationLookup = Helper::getDurationLookup(true);
        $scheduleSchema = Helper::getWeeklyScheduleSchema();
        $bufferTimes = Helper::getBufferTimes();
        $slotIntervals = Helper::getSlotIntervals();
        $customFieldTypes = Helper::getCustomFieldTypes();
        $weekSelectTimes = Helper::getWeekSelectTimes();
        $overrideSelectTimes = Helper::getOverrideSelectTimes();
        $statusChangingTimes = Helper::getBookingStatusChangingTimes();
        $defaultTermsAndConditions = Helper::getDefaultTermsAndConditions();
        $locationFields = (new CalendarSlot())->getLocationFields();

        return apply_filters('fluent_booking/admin_vars', [
            'slug'                   => $slug = $app->config->get('app.slug'),
            'nonce'                  => wp_create_nonce($slug),
            'rest'                   => $this->getRestInfo($app),
            'brand_logo'             => $this->getMenuIcon(),
            'asset_url'              => $assets,
            'event_colors'           => $eventColors,
            'meeting_durations'      => $meetingDurations,
            'multi_durations'        => $multiDurations,
            'buffer_times'           => $bufferTimes,
            'slot_intervals'         => $slotIntervals,
            'schedule_schema'        => $scheduleSchema,
            'location_fields'        => $locationFields,
            'custom_field_types'     => $customFieldTypes,
            'week_select_times'      => $weekSelectTimes,
            'duration_lookup'        => $durationLookup,
            'multi_duration_lookup'  => $multiDurationLookup,
            'override_select_times'  => $overrideSelectTimes,
            'status_changing_times'  => $statusChangingTimes,
            'default_terms'          => $defaultTermsAndConditions,
            'me'                     => [
                'id'          => $currentUser->ID,
                'calendar_id' => $calendarId,
                'full_name'   => $currentUsername,
                'email'       => $currentUser->user_email,
                'is_admin'    => $hasAllAccess,
                'permissions' => PermissionManager::getUserPermissions($currentUser, false),
            ],
            'all_hosts'              => Calendar::getAllHosts(),
            'is_new'                 => $isNew,
            'require_slug'           => $requireSlug,
            'site_url'               => site_url('/'),
            'upgrade_url'            => Helper::getUpgradeUrl(),
            'timezones'              => DateTimeHelper::getTimeZones(true),
            'features'               => Helper::getFeatures(),
            'supported_features'     => apply_filters('fluent_booking/supported_featured', [
                'multi_users' => true
            ]),
            'i18'                    => [
                'date_time_config' => DateTimeHelper::getI18nDateTimeConfig(),
            ],
            'has_pro'                 => defined('FLUENT_BOOKING_PRO_DIR_FILE'),
            'require_upgrade'         => defined('FLUENT_BOOKING_PRO_DIR_FILE') && !defined('FLUENT_BOOKING_LITE'),
            'dashboard_notices'       => apply_filters('fluent_booking/dashboard_notices', []),
            'payment_methods'         => apply_filters('fluent_booking/payment/get_all_methods', []),
            'trans'                   => TransStrings::getStrings(),
            'date_format'             => DateTimeHelper::getDateFormatter(true),
            'time_format'             => DateTimeHelper::getTimeFormatter(true),
            'date_time_formatter'     => DateTimeHelper::getDateFormatter(true) . ', ' . DateTimeHelper::getTimeFormatter(true),
            'available_date_formats'  => DateTimeHelper::getAvailableDateFormats(),
            'default_booking_filters' => Helper::getDefaultBookingFilters(),
            'default_paginations'     => Helper::getDefaultPaginations(),
            'pref_settings'           => Helper::getPrefSettings(),
            'settings_menu_items'     => static::settingsMenuItems(),
            'admin_url'               => admin_url(),
            'is_rtl'                  => Helper::fluentbooking_is_rtl(),
            'iframe_html'             => Helper::getIframeHtml()
        ]);
    }

    protected function getRestInfo($app)
    {
        $ns = $app->config->get('app.rest_namespace');
        $ver = $app->config->get('app.rest_version');

        return [
            'base_url'  => esc_url_raw(rest_url()),
            'url'       => rest_url($ns . '/' . $ver),
            'nonce'     => wp_create_nonce('wp_rest'),
            'namespace' => $ns,
            'version'   => $ver
        ];
    }

    protected function getMenuIcon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg width="96" height="101" viewBox="0 0 96 101" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="25.5746" width="6.39365" height="15.9841" rx="3.19683" fill="white"/><rect x="63.9365" width="6.39365" height="15.9841" rx="3.19683" fill="white"/><path fill-rule="evenodd" clip-rule="evenodd" d="M54.878 53.0655C54.544 55.6678 53.4646 58.035 51.8535 59.9427C50.1623 61.9572 47.886 63.4614 45.2863 64.1988L45.1741 64.2309L44.9203 64.2976L44.8989 64.303L24.7671 69.7V65.019C24.7671 64.9148 24.7671 64.8106 24.7778 64.7064C24.8953 62.748 26.127 61.0862 27.8476 60.3514C28.0427 60.2659 28.2431 60.1938 28.4515 60.1377L28.6412 60.0869L54.8753 53.0575V53.0655H54.878Z" fill="white"/><path fill-rule="evenodd" clip-rule="evenodd" d="M71.1411 35.8059C70.4571 41.1467 66.6178 45.5017 61.5494 46.9391L61.4372 46.9712L61.1861 47.038H61.1834L61.162 47.0433L24.7671 56.7953V52.1144C24.7671 50.0197 26.0362 48.2216 27.8476 47.4468L28.4515 47.233L28.6385 47.1823L71.1384 35.7952V35.8059H71.1411Z" fill="white"/><path fill-rule="evenodd" clip-rule="evenodd" d="M19.9802 11.1889H75.9246C83.4282 11.1889 89.5111 17.2718 89.5111 24.7754V70H95.9048V24.7754C95.9048 13.7406 86.9593 4.79523 75.9246 4.79523H19.9802C8.94542 4.79523 0 13.7406 0 24.7754V80.7198C0 91.7546 8.94542 100.7 19.9802 100.7L64.9524 100.7V94.3063H19.9802C12.4765 94.3063 6.39365 88.2234 6.39365 80.7198V24.7754C6.39365 17.2718 12.4765 11.1889 19.9802 11.1889Z" fill="white"/><path fill-rule="evenodd" clip-rule="evenodd" d="M95.9524 70.7477V69.7H64.9524V100.7H66.0001L95.9524 70.7477Z" fill="white"/></svg>');
    }

    protected function isNew()
    {
        return apply_filters('fluent_booking/is_new', !Calendar::first());
    }

    /**
     * @param $user \WP_User
     * @return bool | Calendar
     */
    protected function maybeAutoCreateCalendar($user)
    {
        if (!apply_filters('fluent_booking/auto_create_calendar', false, $user)) {
            return false;
        }

        $userName = $user->user_login;

        if (is_email($userName)) {
            $userName = explode('@', $userName);
            $userName = $userName[0];
        }

        if (!Helper::isCalendarSlugAvailable($userName, true)) {
            return false;
        }

        $personName = trim($user->first_name . ' ' . $user->last_name);

        if (!$personName) {
            $personName = $user->display_name;
        }

        $data = [
            'user_id' => $user->ID,
            'title'   => $personName,
            'slug'    => $userName
        ];

        return Calendar::create($data);
    }

    public static function settingsMenuItems()
    {
        $app = App::getInstance();
        $urlAssets = $app['url.assets'];

        return apply_filters('fluent_booking/settings_menu_items', [
            'general_settings'    => [
                'title'          => __('General', 'fluent-booking'),
                'disable'        => false,
                'el_icon'        => 'Operation',
                'component_type' => 'StandAloneComponent',
                'class'          => 'general_settings',
                'route'          => [
                    'name' => 'general_settings'
                ]
            ],
            'team_members'        => [
                'title'          => __('Team', 'fluent-booking'),
                'disable'        => true,
                'el_icon'        => 'TeamIcon',
                'component_type' => 'StandAloneComponent',
                'class'          => 'team_members',
                'route'          => [
                    'name' => 'team_members'
                ]
            ],
            'payment_settings'    => [
                'title'          => __('Payment', 'fluent-booking'),
                'disable'        => true,
                'el_icon'        => 'Money',
                'component_type' => 'PaymentSettingsComponent',
                'class'          => 'payment_settings',
                'route'          => [
                    'name' => 'global_payment_settings',
                ],
                'children'       => [
                    'payment_settings' => [
                        'title'   => __('Settings', 'fluent-booking'),
                        'disable' => true,
                        'route'   => [
                            'name' => 'global_payment_settings'
                        ]
                    ],
                    'payment_methods' => [
                        'title'   => __('Payment Methods', 'fluent-booking'),
                        'disable' => true,
                        'route'   => [
                            'name' => 'payment_methods',
                            'params' => [
                                'settings_key' => 'stripe'
                            ]
                        ]
                    ],
                    'payment_coupons' => [
                        'title'   => __('Coupons', 'fluent-booking'),
                        'disable' => true,
                        'route'   => [
                            'name' => 'payment_coupons'
                        ]
                    ]
                ],
            ],
            'google'              => [
                'title'          => __('Google Calendar / Meet', 'fluent-booking'),
                'disable'        => true,
                'icon_url'       => $urlAssets . 'images/gg-calendar.svg',
                'component_type' => 'StandAloneComponent',
                'class'          => 'configure_google_calendar',
                'route'          => [
                    'name' => 'configure-google'
                ]
            ],
            'outlook'             => [
                'title'          => __('Outlook Calendar / MS Teams', 'fluent-booking'),
                'disable'        => true,
                'icon_url'       => $urlAssets . 'images/ol-icon-color.svg',
                'component_type' => 'GlobalSettingsComponent',
                'class'          => 'configure_outlook_calendar',
                'route'          => [
                    'name'   => 'configure-integrations',
                    'params' => [
                        'settings_key' => 'outlook'
                    ]
                ]
            ],
            'apple_calendar'      => [
                'title'          => __('Apple Calendar', 'fluent-booking'),
                'disable'        => true,
                'icon_url'       => $urlAssets . 'images/a-cal.svg',
                'component_type' => 'GlobalSettingsComponent',
                'class'          => 'configure_apple_calendar',
                'route'          => [
                    'name'   => 'configure-integrations',
                    'params' => [
                        'settings_key' => 'apple_calendar'
                    ]
                ]
            ],
            'next_cloud_calendar' => [
                'title'          => __('Nextcloud Calendar', 'fluent-booking'),
                'disable'        => true,
                'svg_icon'       => '<svg xmlns="http://www.w3.org/2000/svg" height="800" width="1200" viewBox="-17.0838 -18.65685 148.0596 111.9411"><path d="M57.0328 0C45.2275 0 35.2216 8.0032 32.1204 18.8466c-2.6952-5.7515-8.5359-9.781-15.2633-9.781C7.6053 9.0657 0 16.671 0 25.9228c0 9.2519 7.6052 16.8606 16.857 16.8606 6.7275 0 12.5682-4.0319 15.2635-9.7844 3.1011 10.8442 13.107 18.85 24.9123 18.85 11.718 0 21.6729-7.885 24.8533-18.607 2.745 5.622 8.5135 9.5414 15.1454 9.5414 9.2518 0 16.8605-7.6087 16.8605-16.8606 0-9.2518-7.6087-16.857-16.8605-16.857-6.632 0-12.4003 3.917-15.1454 9.5378C78.7057 7.8825 68.7507 0 57.0328 0zm0 9.8955c8.9116 0 16.0307 7.1156 16.0307 16.0272s-7.119 16.0308-16.0307 16.0308c-8.9116 0-16.0272-7.1192-16.0272-16.0308S48.1212 9.8955 57.0328 9.8955zm-40.1757 9.0657c3.9044 0 6.965 3.0571 6.965 6.9615s-3.0606 6.965-6.965 6.965-6.9616-3.0606-6.9616-6.965 3.0572-6.9615 6.9616-6.9615zm80.1744 0c3.9044 0 6.965 3.0571 6.965 6.9615s-3.0606 6.965-6.965 6.965-6.9616-3.0606-6.9616-6.965 3.0572-6.9615 6.9616-6.9615z" color="currentColor" font-weight="400" font-family="sans-serif" overflow="visible" fill="currentColor"></path><path d="M29.1085 63.7615c2.7752 0 4.3275 1.9756 4.3275 4.939 0 .2822-.2352.5174-.5174.5174h-7.4792c.047 2.6342 1.8816 4.1394 3.9983 4.1394 1.3171 0 2.2579-.5644 2.7283-.9408.2822-.1881.5174-.141.6585.1412l.1411.2352c.1411.2351.094.4703-.1411.6585-.5645.4233-1.7875 1.129-3.4338 1.129-3.0575 0-5.4094-2.2109-5.4094-5.4095.047-3.3868 2.3048-5.4094 5.1272-5.4094zm2.8693 4.4216c-.094-2.1638-1.4112-3.2457-2.9164-3.2457-1.7404 0-3.2456 1.129-3.575 3.2457zm15.584-2.9164v-3.622c0-.3292.1882-.5174.5174-.5174h.3764c.3292 0 .4703.1882.4703.5174v2.446h2.1168c.3292 0 .5174.1882.5174.5174v.1412c0 .3292-.1882.4703-.5174.4703h-2.1168v5.1743c0 2.399 1.4582 2.6812 2.2579 2.7282.4233.047.5644.1411.5644.5174v.2823c0 .3292-.141.4704-.5644.4704-2.2579 0-3.622-1.3642-3.622-3.8102zm10.7718-1.5052c1.7875 0 2.9164.7526 3.4339 1.176.2351.188.2822.4233.047.7055l-.1411.2352c-.1882.2822-.4234.2822-.7056.094-.4704-.3292-1.3641-.9407-2.5871-.9407-2.2579 0-4.0453 1.6934-4.0453 4.1864 0 2.446 1.7874 4.1394 4.0453 4.1394 1.4582 0 2.446-.6585 2.9164-1.0819.2822-.1881.4704-.141.6585.1411l.1411.1882c.1411.2822.0941.4704-.141.7056-.5175.4233-1.7876 1.317-3.6691 1.317-3.0575 0-5.4094-2.2108-5.4094-5.4094.047-3.1986 2.399-5.4564 5.4564-5.4564zm6.2562-3.3398c0-.3292-.1882-.5174.141-.5174h.3764c.3293 0 .8467.1882.8467.5174V71.664c0 1.3171.6115 1.4582 1.0819 1.5053.2352 0 .4233.141.4233.4703v.3293c0 .3293-.1411.5174-.5174.5174-.8467 0-2.352-.2822-2.352-2.54zm9.6429 3.3398c3.0104 0 5.4564 2.3048 5.4564 5.3623 0 3.1046-2.446 5.4565-5.4564 5.4565-3.0105 0-5.4565-2.352-5.4565-5.4565 0-3.0575 2.446-5.3623 5.4565-5.3623zm0 9.5958c2.2108 0 3.9982-1.7875 3.9982-4.2335 0-2.3519-1.7874-4.0923-3.9982-4.0923s-4.0454 1.7875-4.0454 4.0923c.047 2.399 1.8346 4.2335 4.0454 4.2335zm23.4722-9.5958c2.493 0 3.3868 2.0697 3.3868 2.0697h.047s-.047-.3293-.047-.7997v-4.6568c0-.3293-.1412-.5174.1881-.5174h.3763c.3293 0 .8467.1881.8467.5174v13.406c0 .3292-.1411.5174-.4704.5174h-.3292c-.3293 0-.5175-.1411-.5175-.4704v-.7997c0-.3763.0941-.6585.0941-.6585h-.047s-.8938 2.1638-3.575 2.1638c-2.7752 0-4.5157-2.2108-4.5157-5.4095-.094-3.1986 1.8345-5.3623 4.5628-5.3623zm.047 9.5958c1.7404 0 3.3398-1.223 3.3398-4.1864 0-2.1167-1.082-4.1394-3.2927-4.1394-1.8345 0-3.3398 1.5052-3.3398 4.1394.047 2.54 1.3641 4.1864 3.2927 4.1864zm-85.8923.9878h.3763c.3293 0 .5174-.1881.5174-.5174V63.7274c0-1.5993 1.7404-2.7412 3.716-2.7412 1.9757 0 3.7161 1.142 3.7161 2.7412v10.1003c0 .3293.1882.5174.5174.5174h.3763c.3293 0 .4704-.1881.4704-.5174V63.6674c0-2.6812-2.6812-3.9983-5.1272-3.9983-2.3519 0-5.0331 1.317-5.0331 3.9983v10.1603c0 .3293.1411.5174.4704.5174zm78.5073-10.3485h-.3763c-.3293 0-.5175.1882-.5175.5175v5.6916c0 1.5993-1.0348 3.0575-3.0575 3.0575-1.9756 0-3.0575-1.4582-3.0575-3.0575v-5.6916c0-.3293-.1881-.5175-.5174-.5175h-.3763c-.3293 0-.4704.1882-.4704.5175v6.068c0 2.6811 1.9756 3.9982 4.4216 3.9982 2.446 0 4.4217-1.317 4.4217-3.9983v-6.068c.047-.3292-.1412-.5174-.4704-.5174zm-46.5643-.0771c-.1152.0184-.2258.0953-.3317.2214L41.5673 66.41l-1.4249 1.6978-2.1571-2.5715-1.1705-1.3955c-.1058-.1261-.2257-.195-.35-.2058-.1244-.0107-.2533.0366-.3795.1424l-.2884.2416c-.2523.2117-.2392.446-.0276.6982l1.9045 2.2693 1.5793 1.8815-2.3124 2.7553c-.0018.002-.0029.0043-.0046.0064l-1.1668 1.39c-.2117.2523-.188.5178.0643.7295l.2885.2407c.2522.2116.481.1585.6927-.0937l1.9036-2.2693 1.425-1.6977 2.157 2.5715c.0015.0016.0032.0029.0047.0046l1.1668 1.391c.2116.2521.4763.275.7285.0633l.2885-.2416c.2522-.2117.2392-.446.0275-.6983l-1.9045-2.2692-1.5792-1.8815 2.3124-2.7553c.0017-.002.0028-.0044.0046-.0064l1.1668-1.39c.2116-.2523.1879-.5179-.0643-.7295l-.2885-.2416c-.1261-.1058-.2459-.1452-.361-.1268z" fill="currentColor"></path></svg>',
                'component_type' => 'GlobalSettingsComponent',
                'class'          => 'configure_nextcloud_calendar',
                'route'          => [
                    'name'   => 'configure-integrations',
                    'params' => [
                        'settings_key' => 'next_cloud_calendar'
                    ]
                ]
            ],
            'zoom_meeting'        => [
                'title'          => __('Zoom', 'fluent-booking'),
                'disable'        => true,
                'icon_url'       => $urlAssets . 'images/zoom.svg',
                'component_type' => 'StandAloneComponent',
                'class'          => 'zoom_integrations',
                'route'          => [
                    'name' => 'zoom_integrations'
                ]
            ],
            'twilio'              => [
                'title'          => __('SMS by Twilio', 'fluent-booking'),
                'disable'        => true,
                'svg_icon'       => '<svg xmlns="http://www.w3.org/2000/svg" enable-background="new 0 0 24 24" viewBox="0 0 24 24" id="twilio"><path d="M0,12c0,6.64,5.359,12,12,12s12-5.36,12-12c0-6.641-5.36-12-12-12C5.381-0.008,0.008,5.352,0,11.971V12z M3.199,12c-0.014-4.846,3.904-8.786,8.75-8.801H12c4.847-0.014,8.786,3.904,8.801,8.75V12c0.015,4.847-3.904,8.786-8.75,8.801H12c-4.846,0.015-8.786-3.904-8.801-8.75V12z"></path><path d="M14.959 17.44c1.361 0 2.481-1.12 2.481-2.48 0-1.359-1.12-2.48-2.481-2.479-1.359 0-2.479 1.12-2.479 2.479C12.486 16.326 13.592 17.432 14.959 17.44zM14.959 11.52c1.361 0 2.481-1.12 2.481-2.479 0-1.361-1.12-2.481-2.481-2.481-1.359 0-2.479 1.12-2.479 2.481C12.487 10.407 13.593 11.513 14.959 11.52zM9.042 17.44c1.359 0 2.479-1.12 2.479-2.48 0-1.359-1.121-2.48-2.479-2.479-1.361 0-2.481 1.12-2.481 2.479C6.567 16.327 7.674 17.433 9.042 17.44zM9.042 11.52c1.359 0 2.479-1.12 2.479-2.479 0-1.361-1.121-2.481-2.479-2.481-1.361 0-2.481 1.12-2.481 2.481C6.567 10.408 7.675 11.513 9.042 11.52z"></path></svg>',
                'component_type' => 'GlobalSettingsComponent',
                'class'          => 'configure_twilio',
                'route'          => [
                    'name'   => 'configure-integrations',
                    'params' => [
                        'settings_key' => 'twilio'
                    ]
                ]
            ],
            'license'             => [
                'title'          => __('License', 'fluent-booking'),
                'disable'        => true,
                'el_icon'        => 'Lock',
                'component_type' => 'StandAloneComponent',
                'class'          => 'configure_license',
                'route'          => [
                    'name' => 'license'
                ]
            ]
        ]);
    }

    public static function getEventSettingsMenuItems($event)
    {
        return apply_filters('fluent_booking/calendar_event_setting_menu_items', [
            'event_details'         => [
                'type'    => 'route',
                'visible' => true,
                'disable' => false,
                'route'   => [
                    'name'   => 'event_details',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Event Details', 'fluent-booking'),
                'svgIcon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M8 2V5" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 2V5" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 13H15" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 17H12" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 3.5C19.33 3.68 21 4.95 21 9.65V15.83C21 19.95 20 22.01 15 22.01H9C4 22.01 3 19.95 3 15.83V9.65C3 4.95 4.67 3.69 8 3.5H16Z" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            ],
            'assignment'            => [
                'type'    => 'route',
                'visible' => false,
                'disable' => true,
                'route'   => [
                    'name'   => 'assignment',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Assignment', 'fluent-booking'),
                'svgIcon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2 h-[16px] w-[16px] stroke-[2px] ltr:mr-2 rtl:ml-2 md:mt-px" data-testid="icon-component"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>'
            ],
            'availability_settings' => [
                'type'    => 'route',
                'visible' => true,
                'disable' => false,
                'route'   => [
                    'name'   => 'availability_settings',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Availability', 'fluent-booking'),
                'svgIcon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M6.66666 1.66699V4.16699" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.3333 1.66699V4.16699" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M2.91666 7.5752H17.0833" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.5 7.08366V14.167C17.5 16.667 16.25 18.3337 13.3333 18.3337H6.66667C3.75 18.3337 2.5 16.667 2.5 14.167V7.08366C2.5 4.58366 3.75 2.91699 6.66667 2.91699H13.3333C16.25 2.91699 17.5 4.58366 17.5 7.08366Z" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.0789 11.4167H13.0864" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.0789 13.9167H13.0864" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.99623 11.4167H10.0037" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.99623 13.9167H10.0037" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.91194 11.4167H6.91942" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.91194 13.9167H6.91942" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            ],
            'limit_settings'        => [
                'type'    => 'route',
                'visible' => true,
                'disable' => false,
                'route'   => [
                    'name'   => 'limit_settings',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Limits', 'fluent-booking'),
                'elIcon'  => 'Clock'
            ],
            'question_settings'     => [
                'type'    => 'route',
                'visible' => true,
                'disable' => false,
                'route'   => [
                    'name'   => 'question_settings',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Question Settings', 'fluent-booking'),
                'svgIcon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2V5" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 2V5" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 13.8714V13.6441C12 12.908 12.5061 12.5182 13.0121 12.2043C13.5061 11.9012 14 11.5115 14 10.797C14 9.8011 13.1085 9 12 9C10.8915 9 10 9.8011 10 10.797" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M11.9945 16.4587H12.0053" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 3.5C19.33 3.67504 21 4.91005 21 9.48055V15.4903C21 19.4968 20 21.5 15 21.5H9C4 21.5 3 19.4968 3 15.4903V9.48055C3 4.91005 4.67 3.68476 8 3.5H16Z" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            ],
            'email_notification'    => [
                'type'    => 'route',
                'visible' => true,
                'disable' => false,
                'route'   => [
                    'name'   => 'email_notification',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Email Notification', 'fluent-booking'),
                'elIcon'  => 'Message'
            ],
            'sms_notification'      => [
                'type'    => 'route',
                'visible' => true,
                'disable' => true,
                'route'   => [
                    'name'   => 'sms_notification',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('SMS Notification', 'fluent-booking'),
                'elIcon'  => 'Notification'
            ],
            'recurring_settings'    => [
                'type'    => 'route',
                'visible' => ($event->isOneToOne() || $event->isGroup()) ? true : false,
                'disable' => true,
                'route'   => [
                    'name'   => 'recurring_settings',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Recurring Settings', 'fluent-booking'),
                'svgIcon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6 4H21C21.5523 4 22 4.44772 22 5V12H20V6H6V9L1 5L6 1V4ZM18 20H3C2.44772 20 2 19.5523 2 19V12H4V18H18V15L23 19L18 23V20Z"></path></svg>'
            ],
            'advanced_settings'     => [
                'type'    => 'route',
                'visible' => true,
                'disable' => true,
                'route'   => [
                    'name'   => 'advanced_settings',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Advanced Settings', 'fluent-booking'),
                'elIcon'  => 'Operation'
            ],
            'payment_settings'      => [
                'type'    => 'route',
                'visible' => true,
                'disable' => true,
                'route'   => [
                    'name'   => 'payment_settings',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Payment Settings', 'fluent-booking'),
                'elIcon'  => 'Money'
            ],
            'webhook_settings'      => [
                'type'    => 'route',
                'visible' => true,
                'disable' => true,
                'route'   => [
                    'name'   => 'webhook_settings',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Webhooks Feeds', 'fluent-booking'),
                'elIcon'  => 'Link'
            ],
            'integrations'          => [
                'type'    => 'route',
                'visible' => true,
                'disable' => false,
                'route'   => [
                    'name'   => 'integrations',
                    'params' => [
                        'calendar_id' => $event->calendar_id,
                        'event_id'    => $event->id
                    ]
                ],
                'label'   => __('Integrations', 'fluent-booking'),
                'elIcon'  => 'Connection'
            ]
        ], $event);
    }

    public static function getCalendarSettingsMenuItems($calendar)
    {
        return apply_filters('fluent_booking/calendar_setting_menu_items', [
            'calendar_settings' => [
                'type'    => 'route',
                'visible' => true,
                'disable' => false,
                'route'   => [
                    'name'   => 'calendar_settings',
                    'params' => [
                        'calendar_id' => $calendar->id
                    ]
                ],
                'label'   => __('Calendar Settings', 'fluent-booking'),
                'svgIcon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 15C13.6569 15 15 13.6569 15 12C15 10.3431 13.6569 9 12 9C10.3431 9 9 10.3431 9 12C9 13.6569 10.3431 15 12 15Z" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 12.8799V11.1199C2 10.0799 2.85 9.21994 3.9 9.21994C5.71 9.21994 6.45 7.93994 5.54 6.36994C5.02 5.46994 5.33 4.29994 6.24 3.77994L7.97 2.78994C8.76 2.31994 9.78 2.59994 10.25 3.38994L10.36 3.57994C11.26 5.14994 12.74 5.14994 13.65 3.57994L13.76 3.38994C14.23 2.59994 15.25 2.31994 16.04 2.78994L17.77 3.77994C18.68 4.29994 18.99 5.46994 18.47 6.36994C17.56 7.93994 18.3 9.21994 20.11 9.21994C21.15 9.21994 22.01 10.0699 22.01 11.1199V12.8799C22.01 13.9199 21.16 14.7799 20.11 14.7799C18.3 14.7799 17.56 16.0599 18.47 17.6299C18.99 18.5399 18.68 19.6999 17.77 20.2199L16.04 21.2099C15.25 21.6799 14.23 21.3999 13.76 20.6099L13.65 20.4199C12.75 18.8499 11.27 18.8499 10.36 20.4199L10.25 20.6099C9.78 21.3999 8.76 21.6799 7.97 21.2099L6.24 20.2199C5.33 19.6999 5.02 18.5299 5.54 17.6299C6.45 16.0599 5.71 14.7799 3.9 14.7799C2.85 14.7799 2 13.9199 2 12.8799Z" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            ],
            'remote_calendars'  => [
                'type'    => 'route',
                'visible' => true,
                'disable' => true,
                'route'   => [
                    'name'   => 'remote_calendars',
                    'params' => [
                        'calendar_id' => $calendar->id
                    ]
                ],
                'label'   => __('Remote Calendars', 'fluent-booking'),
                'svgIcon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2 h-[16px] w-[16px] stroke-[2px] ltr:mr-2 rtl:ml-2 md:mt-0" data-testid="icon-component"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"></rect><line x1="16" x2="16" y1="2" y2="6"></line><line x1="8" x2="8" y1="2" y2="6"></line><line x1="3" x2="21" y1="10" y2="10"></line></svg>'
            ],
            'zoom_meeting'      => [
                'type'    => 'route',
                'visible' => true,
                'disable' => true,
                'route'   => [
                    'name'   => 'user_zoom_integration',
                    'params' => [
                        'calendar_id' => $calendar->id
                    ]
                ],
                'label'   => __('Zoom Integration', 'fluent-booking'),
                'svgIcon' => '<svg xmlns="http://www.w3.org/2000/svg"  viewBox="0 0 48 48" width="48px" height="48px"><circle cx="24" cy="24" r="20" fill="#2196f3"/><path fill="#fff" d="M29,31H14c-1.657,0-3-1.343-3-3V17h15c1.657,0,3,1.343,3,3V31z"/><polygon fill="#fff" points="37,31 31,27 31,21 37,17"/></svg>'
            ]
        ], $calendar);
    }
}

