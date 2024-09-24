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
        $app = App::getInstance();
        $items['global_modules'] = [
            'title'          => __('Advanced Features & Addons', 'fluent-booking'),
            'disable'        => false,
            'icon_url'       => $app['url.assets'] . 'images/checklist.svg',
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
            'woo'        => [
                'logo'           => $assetUrl . 'images/woo.svg',
                'name'           => 'woo',
                'title'          => __('WooCommerce', 'fluent-booking'),
                'description'    => __('Accept payment on your booking appointment with WooCommerce Checkout', 'fluent-booking'),
                'is_unavailable' => !defined('WC_PLUGIN_FILE'),
                'is_system'      => 'no',
                'is_active'      => Arr::get($settings, 'woocommerce') == 'yes',
            ],
            'fluentcrm'  => [
                'logo'           => $assetUrl . 'images/fluentcrm.svg',
                'name'           => 'fluentcrm',
                'title'          => __('FluentCRM', 'fluent-booking'),
                'description'    => __('Segment your guests, send bulk emails, run automations using FluentCRM', 'fluent-booking'),
                'is_unavailable' => defined('FLUENTCRM'),
                'install_url'    => admin_url('plugin-install.php?s=FluentCRM&tab=search&type=term'),
                'is_system'      => 'yes',
                'is_active'      => defined('FLUENTCRM')
            ],
            'fluentform' => [
                'logo'           => $assetUrl . 'images/fluentform.png',
                'name'           => 'fluentform',
                'title'          => __('Fluent Forms', 'fluent-booking'),
                'description'    => __('Create beautiful booking forms using Fluent Forms with your booking field', 'fluent-booking'),
                'is_unavailable' => defined('FLUENTFORM'),
                'install_url'    => admin_url('plugin-install.php?s=Fluent%20Forms&tab=search&type=term'),
                'is_system'      => 'yes',
                'is_active'      => defined('FLUENTFORM')
            ],
            'fluentboards' => [
                'logo'           => $assetUrl . 'images/fluentboards.png',
                'name'           => 'fluentboards',
                'title'          => __('Fluent Boards', 'fluent-booking'),
                'description'    => __('Seamlessly create tasks in Fluent Boards using your booking field', 'fluent-booking'),
                'is_unavailable' => defined('FLUENT_BOARDS'),
                'install_url'    => admin_url('plugin-install.php?s=FluentBoards&tab=search&type=term'),
                'is_system'      => 'yes',
                'is_active'      => defined('FLUENT_BOARDS')
            ]
        ]);
    }
}
