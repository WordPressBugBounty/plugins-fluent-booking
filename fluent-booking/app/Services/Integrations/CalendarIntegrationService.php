<?php

namespace FluentBooking\App\Services\Integrations;

use FluentBooking\App\Models\Meta;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\Framework\Validator\ValidationException;

class CalendarIntegrationService
{
    public function find($attr)
    {
        $slotId = intval(Arr::get($attr, 'slot_id'));
        $integrationId = intval(Arr::get($attr, 'integration_id'));
        $integrationName = sanitize_text_field(Arr::get($attr, 'integration_name'));

        $settings = [
            'conditionals' => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all',
            ],
            'enabled'      => true,
            'list_id'      => '',
            'list_name'    => '',
            'name'         => '',
            'merge_fields' => [],
        ];

        $mergeFields = false;
        if ($integrationId) {
            $feed = Meta::where(['object_id' => $slotId, 'id' => $integrationId])->first();

            if ($feed->value) {
                $settings = $feed->value;
                $settings = apply_filters('fluent_booking/get_integration_values_' . $integrationName, $settings, $feed, $slotId);
                if (!empty($settings['list_id'])) {
                    $mergeFields = apply_filters('fluent_booking/get_integration_merge_fields_' . $integrationName, false, $settings['list_id'], $slotId);
                }
            }
        } else {
            $settings = apply_filters('fluent_booking/get_integration_defaults_' . $integrationName, false, $slotId);
        }

        if ('true' == $settings['enabled']) {
            $settings['enabled'] = true;
        } elseif ('false' == $settings['enabled'] || $settings['enabled']) {
            $settings['enabled'] = false;
        }

        $settingsFields = apply_filters('fluent_booking/get_integration_settings_fields_' . $integrationName, $settings, $slotId, $settings);

        return [
            'settings'        => $settings,
            'settings_fields' => $settingsFields,
            'merge_fields'    => $mergeFields,
        ];
    }

    public function update($attr)
    {
        $slotId = intval(Arr::get($attr, 'slot_id'));
        $integrationId = intval(Arr::get($attr, 'integration_id'));
        $integrationName = sanitize_text_field(Arr::get($attr, 'integration_name'));
        $dataType = sanitize_text_field(Arr::get($attr, 'data_type'));
        $status = Arr::get($attr, 'status', true);
        $metaValue = Arr::get($attr, 'integration');

        $errors = [];

        if ('stringify' == $dataType) {
            $metaValue = \json_decode($metaValue, true);
        } else {
            $metaValue = wp_unslash($metaValue);
        }

        $isUpdatingStatus = empty($metaValue);

        if ($isUpdatingStatus) {
            $integrationData = Meta::findOrFail($integrationId);
            $metaValue = $integrationData->value;
            $metaValue['enabled'] = $status;
            $metaKey = $integrationData->key;
        } else {
            if (empty($metaValue['name'])) {
                $errors['name'] = [__('Feed name is required', 'fluent-booking')];
                throw new ValidationException(__('Validation Failed! Feed name is required', 'fluent-booking'), 422, null, $errors); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $metaValue = apply_filters('fluent_booking/save_integration_value_' . $integrationName, $metaValue, $integrationId, $slotId);
            $metaKey = $integrationName . '_feeds';
        }

        // Validate the meta values
        if ($metaValue['enabled']) {
            // Required fields

            if(empty($metaValue['email'])) {
                $errors['email'] = [__('Email is required', 'fluent-booking')];
            }

            if(empty($metaValue['event_trigger'])) {
                $errors['event_trigger'] = [__('Event trigger is required', 'fluent-booking')];
            }

            if($errors) {
                throw new ValidationException(__('Validation Failed! Please fill up required fields', 'fluent-booking'), 422, null, $errors); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }

        $data = [
            'object_id'   => $slotId,
            'object_type' => 'integration',
            'key'         => $metaKey,
            'value'       => $metaValue,
        ];

        $data = apply_filters('fluent_booking/save_integration_settings_' . $integrationName, $data, $integrationId);
        $created = false;

        if ($integrationId) {
            $integration = Meta::where('object_id', $slotId)
                ->where('object_type', 'integration')
                ->findOrFail($integrationId);
            $integration->value = $data['value'];
            $integration->save();
        } else {
            $integration = Meta::create($data);
            $integrationId = $integration->id;
            $created = true;
        }

        return [
            'message'          => __('Integration successfully saved', 'fluent-booking'),
            'integration_id'   => $integrationId,
            'integration_name' => $integrationName,
            'created'          => $created,
        ];
    }

    public function get($slotId)
    {
        $notificationKeys = apply_filters('fluent_booking/global_notification_types', [], $slotId);

        $feeds = [];
        if ($notificationKeys) {
            $feeds = Meta::whereIn('key', $notificationKeys)->where('object_id', $slotId)->get();
        }
        $formattedFeeds = [];

        if (!empty($feeds)) {
            foreach ($feeds as $feed) {
                $data = $feed->value;
                $enabled = Arr::get($data, 'enabled');
                if ($enabled == 'true') {
                    $enabled = true;
                } else {
                    $enabled = false;
                }

                $feedData = [
                    'id'       => $feed->id,
                    'name'     => Arr::get($data, 'name'),
                    'enabled'  => $enabled,
                    'provider' => $feed->key,
                    'feed'     => $data,
                ];

                $feedData = apply_filters('fluent_booking/global_notification_feed_' . $feed->key, $feedData, $slotId);

                $formattedFeeds[] = $feedData;
            }
        }

        $availableIntegrations = apply_filters('fluent_booking/get_available_form_integrations', [], $slotId);

        return [
            'feeds'                  => $formattedFeeds,
            'available_integrations' => $availableIntegrations,
            // 'all_module_config_url'  => admin_url('admin.php?page=fluent_forms_add_ons'),
        ];
    }

    public function delete($id)
    {
        Meta::where('id', $id)->delete();
    }
}
