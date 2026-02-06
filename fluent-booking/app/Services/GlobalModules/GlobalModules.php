<?php

namespace FluentBooking\App\Services\GlobalModules;

use FluentBooking\App\App;
use FluentBooking\App\Services\Helper;
use FluentBooking\Framework\Support\Arr;

class GlobalModules
{
    public function register()
    {
        add_filter('fluent_booking/settings_menu_items', [$this, 'addMenuItem'], 20);
    }

    public function addMenuItem($items)
    {
        $items['global_modules'] = [
            'title'          => __('Advanced Features & Addons', 'fluent-booking'),
            'disable'        => false,
            'svg_icon'       => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" id="checklist"><path d="M61,18.34A3.43,3.43,0,0,0,59.76,16l-1.91-1.6a3.48,3.48,0,0,0-4.9.43l-2.4,2.85L47,21.9V11.83a3,3,0,0,0-.88-2.12L39.29,2.88A3,3,0,0,0,37.17,2H6A3,3,0,0,0,3,5V59a3,3,0,0,0,3,3H44a3,3,0,0,0,3-3V37a.93.93,0,0,0-.07-.33L60.19,20.88A3.48,3.48,0,0,0,61,18.34ZM45,59a1,1,0,0,1-1,1H6a1,1,0,0,1-1-1V5A1,1,0,0,1,6,4H37v7a1,1,0,0,0,1,1h7V24a1,1,0,0,0,0,.23L32.2,39.54a.93.93,0,0,0-.2.38s0,0,0,0l-1.93,8.1a1,1,0,0,0,.33,1,1,1,0,0,0,.64.24,1.14,1.14,0,0,0,.4-.08l7.64-3.32h0a1,1,0,0,0,.36-.27L45,39ZM38.55,43.56l-4.18-3.5L51.44,19.71l4.18,3.51Z"/><path d="M22 14H33a1 1 0 0 0 0-2H22a1 1 0 0 0 0 2zM21 26H39a1 1 0 0 0 0-2H21a1 1 0 0 0 0 2zM31 37a1 1 0 0 0-1-1H21a1 1 0 0 0 0 2h9A1 1 0 0 0 31 37zM17 33H11a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V34A1 1 0 0 0 17 33zm-1 6H12V35h4zM27 48H21a1 1 0 0 0 0 2h6a1 1 0 0 0 0-2zM17 45H11a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V46A1 1 0 0 0 17 45zm-1 6H12V47h4zM13.29 28.71A1 1 0 0 0 14 29h.16a1 1 0 0 0 .73-.54l3-6a1 1 0 1 0-1.78-.9l-2.38 4.76-2-2a1 1 0 0 0-1.42 1.42zM13.29 16.71A1 1 0 0 0 14 17h.16a1 1 0 0 0 .73-.54l3-6a1 1 0 1 0-1.78-.9l-2.38 4.76-2-2a1 1 0 0 0-1.42 1.42z"/></svg>',
            'component_type' => 'StandAloneComponent',
            'class'          => 'advanced_features_and_addons',
            'route'          => [
                'name' => 'globalModules'
            ]
        ];

        return $items;
    }

    public function getAllModules()
    {
        $assetUrl = App::getInstance('url.assets');
        $settings = Helper::getGlobalModuleSettings();
        return apply_filters('fluent_booking/global_modules', [
            'fluent-cart'      => [
                'logo'           => $assetUrl . 'images/fluent-cart.svg',
                'name'           => 'fluent-cart',
                'title'          => __('FluentCart', 'fluent-booking'),
                'description'    => __('Seamlessly integrate FluentCart to sell paid bookings and manage products directly from your appointments.', 'fluent-booking'),
                'is_unavailable' => !defined('FLUENTCART_VERSION'),
                'install_url'    => admin_url('plugin-install.php?s=FluentCart&tab=search&type=term'),            
                'is_system'      => 'yes',
                'is_active'      => defined('FLUENTCART_VERSION')
            ],
            'fluentcrm'  => [
                'logo'           => $assetUrl . 'images/fluentcrm.svg',
                'name'           => 'fluentcrm',
                'title'          => __('FluentCRM', 'fluent-booking'),
                'description'    => __('Segment your guests, send bulk emails, run automations using FluentCRM', 'fluent-booking'),
                'is_unavailable' => !defined('FLUENTCRM'),
                'install_url'    => admin_url('plugin-install.php?s=FluentCRM&tab=search&type=term'),
                'is_system'      => 'yes',
                'is_active'      => defined('FLUENTCRM')
            ],
            'fluentform' => [
                'logo'           => $assetUrl . 'images/fluentform.png',
                'name'           => 'fluentform',
                'title'          => __('Fluent Forms', 'fluent-booking'),
                'description'    => __('Create beautiful booking forms using Fluent Forms with your booking field', 'fluent-booking'),
                'is_unavailable' => !defined('FLUENTFORM'),
                'install_url'    => admin_url('plugin-install.php?s=Fluent%20Forms&tab=search&type=term'),
                'is_system'      => 'yes',
                'is_active'      => defined('FLUENTFORM')
            ],
            'fluentsmtp' => [
                'logo'           => $assetUrl . 'images/fluent-smtp.svg',
                'name'           => 'fluentsmtp',
                'title'          => __('FluentSMTP', 'fluent-booking'),
                'description'    => __('Send emails using FluentSMTP with your booking field', 'fluent-booking'),
                'is_unavailable' => !defined('FLUENTMAIL'),
                'install_url'    => admin_url('plugin-install.php?s=FluentSMTP&tab=search&type=term'),
                'is_system'      => 'yes',
                'is_active'      => defined('FLUENTMAIL'),
            ],
            'fluentboards' => [
                'logo'           => $assetUrl . 'images/fluentboards.png',
                'name'           => 'fluentboards',
                'title'          => __('Fluent Boards', 'fluent-booking'),
                'description'    => __('Seamlessly create tasks in Fluent Boards using your booking field', 'fluent-booking'),
                'is_unavailable' => !defined('FLUENT_BOARDS'),
                'install_url'    => admin_url('plugin-install.php?s=FluentBoards&tab=search&type=term'),
                'is_system'      => 'yes',
                'is_active'      => defined('FLUENT_BOARDS')
            ],
            'woo'        => [
                'logo'           => $assetUrl . 'images/woo.svg',
                'name'           => 'woo',
                'title'          => __('WooCommerce', 'fluent-booking'),
                'description'    => __('Accept payment on your booking appointment with WooCommerce Checkout', 'fluent-booking'),
                'is_unavailable' => !defined('WC_PLUGIN_FILE'),
                'is_system'      => 'no',
                'is_active'      => Arr::get($settings, 'woocommerce') == 'yes',
            ],
        ]);
    }
}
