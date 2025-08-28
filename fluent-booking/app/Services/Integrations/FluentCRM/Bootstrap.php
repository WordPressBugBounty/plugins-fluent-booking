<?php

namespace FluentBooking\App\Services\Integrations\FluentCRM;

use FluentCrm\App\Models\Tag;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Subscriber;
use FluentBooking\Framework\Support\Arr;
use FluentCrm\App\Models\CustomContactField;
use FluentBooking\App\Http\Controllers\IntegrationManagerController;

class Bootstrap extends IntegrationManagerController
{
    public $hasGlobalMenu = false;

    public $disableGlobalSettings = 'yes';

    public function __construct()
    {
        parent::__construct(
            __('FluentCRM', 'fluent-booking'),
            'fluentcrm',
            '_fluent_booking_fluentcrm_settings',
            'fluentcrm_feeds',
            10
        );

        $this->logo = FLUENTCRM_PLUGIN_URL . 'assets/images/fluentcrm-logo.svg';

        $this->description = __('Connect FluentCRM with Fluent Booking and subscribe a contact when a booking is created.', 'fluent-booking');

        $this->registerAdminHooks();

        add_filter('fluent_booking/notifying_async_fluentcrm', '__return_false');
    }

    public function pushIntegration($integrations, $calendarEventId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' ' . __('Integration', 'fluent-booking'),
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluent-booking'),
            'global_configure_url'  => '#',
            'configure_message'     => __('FluentCRM is not configured yet! Please configure your FluentCRM api first', 'fluent-booking'),
            'configure_button_text' => __('Set FluentCRM', 'fluent-booking'),
        ];

        return $integrations;
    }

    public function getIntegrationDefaults($settings, $calendarEventId)
    {
        return [
            'name'              => '',
            'first_name'        => '{{guest.first_name}}',
            'last_name'         => '{{guest.last_name}}',
            'email'             => '{{guest.email}}',
            'other_fields'      => [
                [
                    'item_value' => '',
                    'label'      => '',
                ],
            ],
            'list_ids'          => '',
            'tag_ids'           => [],
            'skip_if_exists'    => false,
            'double_opt_in'     => false,
            'force_subscribe'   => false,
            'skip_primary_data' => false,
            'conditionals'      => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all',
            ],
            'run_events_only'   => [],
            'remove_tags'       => [],
            'enabled'           => true,
        ];
    }

    public function getSettingsFields($settings, $slotId)
    {
        $fieldOptions = [];

        foreach (Subscriber::mappables() as $key => $column) {
            $fieldOptions[$key] = $column;
        }

        foreach ((new CustomContactField)->getGlobalFields()['fields'] as $field) {
            $fieldOptions[$field['slug']] = $field['label'];
        }

        $fieldOptions['avatar'] = __('Profile Photo', 'fluent-booking');

        unset($fieldOptions['email']);
        unset($fieldOptions['first_name']);
        unset($fieldOptions['last_name']);
        unset($fieldOptions['prefix']);
        unset($fieldOptions['full_name']);
        unset($fieldOptions['company_id']);

        $fields = [
            [
                'key'         => 'name',
                'label'       => __('Feed Name', 'fluent-booking'),
                'required'    => true,
                'placeholder' => __('Your Feed Name', 'fluent-booking'),
                'component'   => 'text',
            ],
            [
                'key'                => 'CustomFields',
                'require_list'       => false,
                'label'              => __('Map Primary Fields', 'fluent-booking'),
                'tips'               => __('Associate your FluentCRM merge tags to the appropriate Fluent Form fields by selecting the appropriate form field from the list.', 'fluent-booking'),
                'component'          => 'map_fields',
                'field_label_remote' => __('FluentCRM Field', 'fluent-booking'),
                'field_label_local'  => __('Booking Field', 'fluent-booking'),
                'primary_fields'     => [
                    [
                        'key'           => 'email',
                        'label'         => __('Email Address', 'fluent-booking'),
                        'required'      => true,
                        'input_options' => 'emails',
                    ],
                    [
                        'key'   => 'first_name',
                        'label' => __('First Name', 'fluent-booking'),
                    ],
                    [
                        'key'   => 'last_name',
                        'label' => __('Last Name', 'fluent-booking'),
                    ]
                ],
            ],
            [
                'key'                => 'other_fields',
                'require_list'       => false,
                'label'              => __('Other Fields', 'fluent-booking'),
                'tips'               => __('Select which Fluent Form fields pair with their<br /> respective FlunentCRM fields.', 'fluent-booking'),
                'component'          => 'dropdown_many_fields',
                'field_label_remote' => __('FluentCRM Field', 'fluent-booking'),
                'field_label_local'  => __('Form Field', 'fluent-booking'),
                'options'            => $fieldOptions,
            ],
            [
                'key'         => 'list_ids',
                'label'       => __('FluentCRM Lists', 'fluent-booking'),
                'placeholder' => __('Select FluentCRM Lists', 'fluent-booking'),
                'tips'        => __('Select the FluentCRM Lists you would like to add your contacts to.', 'fluent-booking'),
                'component'   => 'select',
                'is_multiple' => true,
                'required'    => false,
                'options'     => $this->getLists(),
            ],
            [
                'key'          => 'tag_ids',
                'require_list' => false,
                'label'        => __('Contact Tags', 'fluent-booking'),
                'placeholder'  => __('Select Tags', 'fluent-booking'),
                'component'    => 'select',
                'is_multiple'  => true,
                'options'      => $this->getTags(),
            ],
            [
                'key'            => 'skip_if_exists',
                'require_list'   => false,
                'checkbox_label' => __('Skip if contact already exist in FluentCRM', 'fluent-booking'),
                'component'      => 'checkbox-single',
            ],
            [
                'key'            => 'skip_primary_data',
                'require_list'   => false,
                'checkbox_label' => __('Skip name update if existing contact have old data (per primary field)', 'fluent-booking'),
                'component'      => 'checkbox-single',
            ],
            [
                'key'            => 'double_opt_in',
                'require_list'   => false,
                'checkbox_label' => __('Enable Double opt-in for new contacts', 'fluent-booking'),
                'component'      => 'checkbox-single',
            ],
            [
                'key'            => 'force_subscribe',
                'require_list'   => false,
                'checkbox_label' => __('Enable Force Subscribe if contact is not in subscribed status (Existing contact only)', 'fluent-booking'),
                'component'      => 'checkbox-single',
                'inline_tip'     => __('If you enable this then contact will forcefully subscribed no matter in which status that contact had', 'fluent-booking'),
            ],
            [
                'require_list'   => false,
                'required'       => true,
                'key'            => 'event_trigger',
                'options'        => [
                    'after_booking_scheduled'    => __('Booking Confirmed', 'fluent-booking'),
                    'booking_schedule_completed' => __('Booking Completed', 'fluent-booking'),
                    'booking_schedule_cancelled' => __('Booking Canceled', 'fluent-booking'),
                    'after_booking_rescheduled'  => __('Booking Rescheduled', 'fluent-booking'),
                    'booking_schedule_rejected'  => __('Booking Rejected', 'fluent-booking'),
                ],
                'tips'           => __('Select in which booking stage you want to trigger this feed', 'fluent-booking'),
                'label'          => __('Event Trigger', 'fluent-booking'),
                'component'      => 'checkbox-multiple-text',
                'checkbox_label' => __('Event Trigger For This Feed', 'fluent-booking'),
            ]
        ];

        $fields[] = [
            'require_list' => false,
            'key'          => 'remove_tags',
            'label'        => __('Remove Contact Tags', 'fluent-booking'),
            'placeholder'  => __('Select Tags (remove from contact)', 'fluent-booking'),
            'tips'         => __('(Optional) The selected tags will be removed from the contact (if exist)', 'fluent-booking'),
            'component'    => 'select',
            'is_multiple'  => true,
            'required'     => false,
            'options'      => $this->getTags(),
        ];

        $fields[] = [
            'require_list'   => false,
            'key'            => 'enabled',
            'label'          => __('Status', 'fluent-booking'),
            'component'      => 'checkbox-single',
            'checkbox_label' => __('Enable This feed', 'fluent-booking'),
        ];

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => $this->title,
        ];
    }

    public function getMergeFields($list, $listId, $slotId)
    {
        return [];
    }

    public function getConfigFieldOptions($settings, $slotId)
    {
        return [];
    }

    protected function getLists()
    {
        $lists = Lists::orderBy('title', 'ASC')->get();
        $formattedLists = [];
        foreach ($lists as $list) {
            $formattedLists[$list->id] = $list->title;
        }

        return $formattedLists;
    }

    protected function getTags()
    {
        $tags = Tag::orderBy('title', 'ASC')->get();
        $formattedTags = [];
        foreach ($tags as $tag) {
            $formattedTags[strval($tag->id)] = $tag->title;
        }

        return $formattedTags;
    }

    /*
     * Submission Hooks Here
     */
    public function notify($feed, $booking, $calendarEvent)
    {
        $data = $feed['processedValues'];
        $contact = Arr::only($data, ['first_name', 'last_name', 'email']);

        if (empty($contact['email'])) {
            return false;
        }

        foreach (Arr::get($data, 'other_fields') as $field) {
            if ($field['item_value']) {
                $contact[$field['label']] = $field['item_value'];
            }
        }

        if ($booking->ip_address) {
            $contact['ip'] = $booking->ip_address;
        }

        if (!is_email($contact['email'])) {
            $this->addLog(
                $feed['settings']['name'],
                __('FluentCRM API called skipped because no valid email available', 'fluent-booking'),
                $booking->id,
                'failed'
            );
            return false;
        }

        $subscriber = Subscriber::where('email', $contact['email'])->first();
        if ($subscriber && Arr::isTrue($data, 'skip_if_exists')) {
            $this->addLog(
                $feed['settings']['name'],
                __('Contact creation has been skipped because contact already exist in the database', 'fluent-booking'),
                $booking->id,
                'failed'
            );
            return false;
        }

        if (!empty($contact['avatar'])) {
            // validate the avatar photo
            $validUrl = '';
            if (false !== filter_var($contact['avatar'], FILTER_VALIDATE_URL)) {
                $url = $contact['avatar'];
                $dots = explode('.', $url);
                $ext = strtolower(end($dots));

                if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                    $validUrl = $contact['avatar'];
                }
            }

            if (!$validUrl) {
                unset($contact['avatar']);
            }
        }

        if ($subscriber) {
            if ($subscriber->ip && isset($contact['ip'])) {
                unset($contact['ip']);
            }

            if (Arr::isTrue($data, 'skip_primary_data')) {
                if ($subscriber->first_name) {
                    unset($contact['first_name']);
                    unset($contact['last_name']);
                }
            }
        } else {
            if (empty($contact['source'])) {
                $contact['source'] = 'FluentBooking';
            }
            if (Arr::isTrue($data, 'double_opt_in')) {
                $contact['status'] = 'pending';
            } else {
                $contact['status'] = 'subscribed';
            }
        }

        $user = get_user_by('email', $contact['email']);
        if ($user) {
            $contact['user_id'] = $user->ID;
        }

        if (!empty($data['tag_ids'])) {
            $contact['tags'] = $data['tag_ids'];
        }

        if (!empty($data['list_ids'])) {
            $contact['lists'] = $data['list_ids'];
        }

        if (!$subscriber) {
            if (Arr::isTrue($data, 'double_opt_in')) {
                $contact['status'] = 'pending';
            } else {
                $contact['status'] = 'subscribed';
            }

            $subscriber = FluentCrmApi('contacts')->createOrUpdate($contact, false, false);

            if (!$subscriber) {
                return false;
            }

            if ($subscriber->status == 'pending') {
                $subscriber->sendDoubleOptinEmail();
            }

            if ($removeTags = Arr::get($feed, 'settings.remove_tags', [])) {
                $subscriber->detachTags($removeTags);
            }

            $this->addLog(
                $feed['settings']['name'],
                __('Contact has been created in FluentCRM. Contact ID: ', 'fluent-booking') . $subscriber->id,
                $booking->id,
                'success'
            );

            do_action('fluent_crm/contact_added_by_fluent_booking', $subscriber, $booking, $calendarEvent, $feed);
            return true;
        }

        // We have subscriber here

        $hasDouBleOptIn = Arr::isTrue($data, 'double_opt_in');

        $forceSubscribed = !$hasDouBleOptIn && ($subscriber->status != 'subscribed');

        if (!$forceSubscribed) {
            $forceSubscribed = Arr::isTrue($data, 'force_subscribe');
        }

        if ($forceSubscribed) {
            $contact['status'] = 'subscribed';
        }

        $subscriber = FluentCrmApi('contacts')->createOrUpdate($contact, $forceSubscribed, false);

        if (!$subscriber) {
            return false;
        }

        if ($hasDouBleOptIn && ($subscriber->status == 'pending' || $subscriber->status == 'unsubscribed')) {
            $subscriber->sendDoubleOptinEmail();
        }

        do_action('fluent_crm/contact_added_by_fluent_booking', $subscriber, $booking, $calendarEvent, $feed);

        if ($removeTags = Arr::get($feed, 'settings.remove_tags', [])) {
            $subscriber->detachTags($removeTags);
        }

        $this->addLog(
            $feed['settings']['name'],
            __('Contact has been updated in FluentCRM. Contact ID: ', 'fluent-booking') . $subscriber->id,
            $booking->id,
            'success'
        );

        return true;
    }

    public function isConfigured()
    {
        return true;
    }

    public function isEnabled()
    {
        return true;
    }
}
