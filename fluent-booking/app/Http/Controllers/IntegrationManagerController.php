<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Services\Integrations\IntegrationManagerHelper;

abstract class IntegrationManagerController extends IntegrationManagerHelper
{
    protected $app = null;

    protected $subscriber = null;

    protected $title = '';

    protected $description = '';

    protected $integrationKey = '';

    protected $optionKey = '';

    protected $settingsKey = '';

    protected $priority = 11;

    public $logo = '';

    public $hasGlobalMenu = false;

    public $category = 'crm';

    public $disableGlobalSettings = 'yes';

    public function __construct($title, $integrationKey, $optionKey, $settingsKey, $priority = 11)
    {
        $this->title = $title;
        $this->integrationKey = $integrationKey;
        $this->optionKey = $optionKey;
        $this->settingsKey = $settingsKey;
        $this->priority = $priority;

        parent::__construct(
            $this->settingsKey, false, true
        );
    }

    public function registerAdminHooks()
    {
        $isEnabled = $this->isEnabled();
        add_filter('fluent_booking/global_addons', function ($addons) use ($isEnabled) {
            $addons[$this->integrationKey] = [
                'title'                   => $this->title,
                'category'                => $this->category,
                'disable_global_settings' => $this->disableGlobalSettings,
                'description'             => $this->description,
                'config_url'              => ('yes' != $this->disableGlobalSettings) ? admin_url('admin.php?page=fluent-booking#/settings/general-settings') : '',
                'logo'                    => $this->logo,
                'enabled'                 => ($isEnabled) ? 'yes' : 'no',
            ];
            return $addons;
        }, $this->priority, 1);

        if (!$isEnabled) {
            return;
        }

        $this->registerNotificationHooks();

        // Global Settings Here

        if ($this->hasGlobalMenu) {
            add_filter('fluent_booking/global_settings_components', [$this, 'addGlobalMenu']);
            add_filter('fluent_booking/global_integration_settings_' . $this->integrationKey, [$this, 'getGlobalSettings'],
                $this->priority, 1);
            add_filter('fluent_booking/global_integration_fields_' . $this->integrationKey, [$this, 'getGlobalFields'],
                $this->priority, 1);
            add_action('fluent_booking/save_global_integration_settings_' . $this->integrationKey,
                [$this, 'saveGlobalSettings'], $this->priority, 1);
        }

        add_filter('fluent_booking/global_notification_types', [$this, 'addNotificationType'], $this->priority);

        add_filter('fluent_booking/get_available_form_integrations', [$this, 'pushIntegration'], $this->priority, 2);

        add_filter('fluent_booking/global_notification_feed_' . $this->settingsKey, [$this, 'setFeedAttributes'], 10, 2);

        add_filter('fluent_booking/get_integration_defaults_' . $this->integrationKey, [$this, 'getIntegrationDefaults'], 10, 2);
        add_filter('fluent_booking/get_integration_settings_fields_' . $this->integrationKey, [$this, 'getSettingsFields'], 10, 2);
        add_filter('fluent_booking/get_integration_merge_fields_' . $this->integrationKey, [$this, 'getMergeFields'], 10, 3);
        add_filter('fluent_booking/get_integration_config_field_options_' . $this->integrationKey, [$this, 'getConfigFieldOptions'], 10, 2);
        add_filter('fluent_booking/get_integration_values_' . $this->integrationKey, [$this, 'prepareIntegrationFeed'], 10, 3);

        add_filter('fluent_booking/save_integration_settings_' . $this->integrationKey, [$this, 'setMetaKey'], 10, 2);
    }

    public function registerNotificationHooks()
    {
        if ($this->isConfigured()) {
            add_filter('fluent_booking/global_notification_active_types', [$this, 'addActiveNotificationType'], $this->priority);
            add_action('fluent_booking/integration_notify_' . $this->settingsKey, [$this, 'notify'], $this->priority, 3);
        }
    }

    public function notify($feed, $booking, $calendarEvent)
    {
        // Do something here in your integration class
    }

    public function addGlobalMenu($setting)
    {
        $setting[$this->integrationKey] = [
            'hash'         => 'general-' . $this->integrationKey . '-settings',
            'component'    => 'general-integration-settings',
            'settings_key' => $this->integrationKey,
            'title'        => $this->title,
        ];

        return $setting;
    }

    public function addNotificationType($types)
    {
        $types[] = $this->settingsKey;

        return $types;
    }

    public function addActiveNotificationType($types)
    {
        $types[$this->settingsKey] = $this->integrationKey;

        return $types;
    }

    public function getGlobalSettings($settings)
    {
        return $settings;
    }

    public function saveGlobalSettings($settings)
    {
        return $settings;
    }

    public function getGlobalFields($fields)
    {
        return $fields;
    }

    public function setMetaKey($data)
    {
        // $data['meta_key'] = $this->settingsKey;
        return $data;
    }

    public function prepareIntegrationFeed($setting, $feed, $calendarEventId)
    {
        $defaults = $this->getIntegrationDefaults([], $calendarEventId);

        foreach ($setting as $settingKey => $settingValue) {
            if ('true' == $settingValue) {
                $setting[$settingKey] = true;
            } elseif ('false' == $settingValue) {
                $setting[$settingKey] = false;
            } elseif ('conditionals' == $settingKey) {
                if ('true' == $settingValue['status']) {
                    $settingValue['status'] = true;
                } elseif ('false' == $settingValue['status']) {
                    $settingValue['status'] = false;
                }
                $setting['conditionals'] = $settingValue;
            }
        }

        if (!empty($setting['list_id'])) {
            $setting['list_id'] = (string)$setting['list_id'];
        }

        return wp_parse_args($setting, $defaults);
    }

    abstract public function getIntegrationDefaults($settings, $calendarEventId);

    abstract public function pushIntegration($integrations, $calendarEventId);

    abstract public function getSettingsFields($settings, $calendarEventId);

    abstract public function getMergeFields($list, $listId, $calendarEventId);

    public function getConfigFieldOptions($settings, $calendarEventId)
    {
        return [];
    }

    public function setFeedAttributes($feed, $calendarEventId)
    {
        $feed['provider'] = $this->integrationKey;
        $feed['provider_logo'] = $this->logo;

        return $feed;
    }

    public function isConfigured()
    {
        $globalStatus = $this->getApiSettings();
        return $globalStatus && $globalStatus['status'];
    }

    public function isEnabled()
    {
        return true;
    }

    public function getApiSettings()
    {
        $settings = get_option($this->optionKey);
        if (!$settings || empty($settings['status'])) {
            $settings = [
                'apiKey' => '',
                'status' => true,
            ];
        }

        return $settings;
    }


    protected function addLog($title, $description, $bookingId, $status = 'info', $type = 'log')
    {
        do_action('fluent_booking/log_booking_note', [
            'title'       => $title,
            'description' => $description,
            'booking_id'  => $bookingId,
            'status'      => $status,
            'type'        => $type,
        ]);
    }
}
