<?php

namespace FluentBooking\App\Services\Integrations;

use FluentBooking\App\Models\Meta;

class IntegrationManagerHelper
{
    protected $settingsKey;
    protected $formId;
    protected $isMultiple;

    protected $integrationService;

    public function __construct($settingsKey = '', $form_id = false, $isMultiple = false)
    {
        $this->settingsKey = $settingsKey;
        $this->isMultiple = $isMultiple;
        $this->integrationService = new CalendarIntegrationService();
    }

    public function get($settingsId)
    {
        $settings = Meta::where('form_id', $this->formId)
            ->where('meta_key', $this->settingsKey)
            ->find($settingsId);
        $settings->formattedValue = $this->getFormattedValue($settings);
        return $settings;
    }

    public function save($settings)
    {
        //
    }

    public function update($settingsId, $settings)
    {
        //
    }

    public function delete($settingsId)
    {
        Meta::where('id', $settingsId)
            ->delete();
    }

    public function getAll()
    {
        return [];
    }


    protected function getApiResponseMessage($response, $status)
    {
        if (is_array($response) && isset($response['message'])) {
            return $response['message'];
        }

        return $status;
    }

    public function getFormattedValue($setting)
    {
        return $setting->value;
    }
}
