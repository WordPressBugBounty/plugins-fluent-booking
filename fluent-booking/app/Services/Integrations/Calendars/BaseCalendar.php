<?php

namespace FluentBooking\App\Services\Integrations\Calendars;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Meta;
use FluentBooking\Framework\Support\Arr;

abstract class BaseCalendar
{
    protected $calendarKey;

    protected $calendarTitle;

    protected $logo;

    public function boot()
    {
        add_filter('fluent_booking/settings_menu_items', [$this, 'pushToGlobalMenu'], 10, 1);
        add_filter('fluent_booking/get_client_settings_' . $this->calendarKey, [$this, 'getClientSettingsForView'], 10, 1);
        add_filter('fluent_booking/get_client_field_settings_' . $this->calendarKey, [$this, 'getClientFieldSettings'], 10, 1);
        add_action('fluent_booking/save_client_settings_' . $this->calendarKey, [$this, 'saveClientSettings'], 10, 1);

        /*
         * oAuth From Handlers from Calendar
         */
        add_filter('fluent_booking/remote_calendar_providers', [$this, 'addAsProvider'], 10, 2);
        add_filter('fluent_booking/remote_calendar_connection_feeds', [$this, 'pushFeeds'], 10, 2);
        add_action('fluent_calendar/patch_calendar_config_settings__' . $this->calendarKey . '_user_token', [$this, 'updateConflictIds'], 10, 2);
        add_action('fluent_calendar/patch_calendar_additional_settings__' . $this->calendarKey . '_user_token', [$this, 'updateAdditionalSettings'], 10, 2);
        add_action('fluent_calendar/disconnect_remote_calendar__' . $this->calendarKey . '_user_token', [$this, 'authDisconnect'], 10, 1);

        /*
         * Booking Handlers
         */
        add_filter('fluent_booking/remote_booked_events', [$this, 'getBookedSlots'], 10, 6);
        add_action('fluent_booking/create_remote_calendar_event_' . $this->calendarKey, [$this, 'createEvent'], 10, 2);
        add_action('fluent_booking/refresh_remote_calendar_group_members_' . $this->calendarKey, [$this, 'maybeAddOrRemoveGroupMembers'], 10, 4);

        add_action('fluent_booking/cancel_remote_calendar_event_' . $this->calendarKey, [$this, 'cancelEvent'], 10, 2);
        add_action('fluent_booking/patch_remote_calendar_event_' . $this->calendarKey, [$this, 'patchEvent'], 10, 4);
        add_action('fluent_booking/delete_remote_calendar_event_' . $this->calendarKey, [$this, 'deleteEvent'], 10, 2);

    }

    public function pushToGlobalMenu($menuItems)
    {
        $menuItems[$this->calendarKey]['disable'] = false;

        return $menuItems;
    }

    abstract public function getClientSettingsForView($settings);

    abstract public function getClientFieldSettings($settings);

    abstract public function saveClientSettings($settings);

    public function addAsProvider($providers, $userId)
    {
        $providers[$this->calendarKey] = [
            'key'                  => $this->calendarKey,
            'icon'                 => $this->logo,
            'title'                => $this->calendarTitle,
            /* translators: %s is the name of the calendar title. */
            'subtitle'             => sprintf(__('Configure %s to sync your events', 'fluent-booking'), $this->calendarTitle),
            /* translators: %s is the name of the calendar title. */
            'btn_text'             => sprintf(__('Connect with %s', 'fluent-booking'), $this->calendarTitle),
            'auth_url'             => $this->getAuthUrl($userId),
            'is_global_configured' => $this->isConfigured(),
            'global_config_url'    => admin_url('admin.php?page=fluent-booking#/settings/configure-integrations/'.$this->calendarKey),
        ];

        return $providers;
    }

    abstract public function pushFeeds($feeds, $userId);

    public function updateConflictIds($conflictIds, $meta)
    {
        $meta = Meta::where('object_type', '_' . $this->calendarKey . '_user_token')
            ->where('id', $meta->id)
            ->first();

        $settings = $meta->value;
        $settings['conflict_check_ids'] = $conflictIds;
        $meta->value = $settings;
        $meta->save();
    }

    public function updateAdditionalSettings($additionalSettings, $meta)
    {
        $meta = Meta::where('object_type', '_' . $this->calendarKey . '_user_token')
            ->where('id', $meta->id)
            ->first();

        $settings = $meta->value;
        $settings['additional_settings'] = $additionalSettings;
        $meta->value = $settings;
        $meta->save();
    }

    abstract public function authDisconnect($meta);

    abstract public function getBookedSlots($books, $calendarSlot, $toTimeZone, $dateRange, $hostId, $isDoingBooking);

    abstract public function createEvent($config, Booking $booking);

    abstract public function cancelEvent($config, Booking $booking);

    abstract public function deleteEvent($config, Booking $booking);

    abstract public function patchEvent($config, Booking $booking, $updateData, $isRescheduling);

    abstract public function maybeAddOrRemoveGroupMembers($config, Booking $booking, $allGroupBookings, $isRescheduling);

    abstract public function getAuthUrl($userId = null);

    abstract public function isConfigured();

    protected function getStanadrdFields()
    {
        return [
            'client_id'     => [
                'type'        => 'text',
                'label'       => __('Client ID', 'fluent-booking'),
                'placeholder' => __('Enter Your Client ID', 'fluent-booking'),
            ],
            'client_secret' => [
                'type'        => 'text',
                'label'       => __('Secret Key', 'fluent-booking'),
                'placeholder' => __('Enter Your Secret Key', 'fluent-booking'),
            ],
            'redirect_url'  => [
                'type'        => 'text',
                'label'       => __('Redirect URI', 'fluent-booking'),
                'placeholder' => __('Enter Your Redirect URI', 'fluent-booking'),
                'readonly'    => true,
                'copy_btn'    => true,
            ],
            'caching_time'  => [
                'type'        => 'select',
                'options'     => [
                    '1'  => __('1 minute', 'fluent-booking'),
                    '5'  => __('5 minutes', 'fluent-booking'),
                    '10' => __('10 minutes', 'fluent-booking'),
                    '15' => __('15 minutes', 'fluent-booking'),
                ],
                'label'       => __('Caching Time', 'fluent-booking'),
                /* translators: Explanation for the cache duration setting. %1$s is the calendar title, %2$s is the calendar title repeated. */
                'inline_help' => sprintf(__('Select for how many minutes the %1$s event API call will be cached. Recommended 5/10 minutes. If you add lots of manual events in %2$s then you may lower the value',  'fluent-booking'), $this->calendarTitle, $this->calendarTitle)
            ],
        ];
    }

    public function getConflictCheckCalendars($hostIds)
    {
        $metaItems = Meta::where('object_type', '_'.$this->calendarKey.'_user_token')
            ->whereIn('object_id', $hostIds)
            ->get();

        if ($metaItems->isEmpty()) {
            return [];
        }

        $calendars = [];

        foreach ($metaItems as $item) {
            $settings = $item->value;
            $checkIds = Arr::get($settings, 'conflict_check_ids', []);
            if (empty($checkIds)) {
                continue;
            }

            $itemValidCalendars = [];
            $allCalendars = Arr::get($settings, 'calendar_lists', []);
            foreach ($allCalendars as $calendar) {
                if (in_array($calendar['id'], $checkIds)) {
                    $itemValidCalendars[] = $calendar['id'];
                }
            }

            if ($itemValidCalendars) {
                $calendars[] = [
                    'item'      => $item,
                    'check_ids' => $itemValidCalendars
                ];
            }
        }

        return $calendars;
    }

}
