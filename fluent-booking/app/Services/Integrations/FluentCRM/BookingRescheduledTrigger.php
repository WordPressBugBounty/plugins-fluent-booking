<?php

namespace FluentBooking\App\Services\Integrations\FluentCRM;

use FluentBooking\Framework\Support\Arr;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentBooking\App\Services\CalendarService;

class BookingRescheduledTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fluent_booking/after_booking_rescheduled';
        $this->actionArgNum = 2;
        $this->priority = 20;
        parent::__construct();
    }

    public function getCalendarOptions()
    {
        $calendarOptions = CalendarService::getCalendarOptionsByTitle();

        return apply_filters('fluent_booking/crm_trigger_calendar_options', $calendarOptions);
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Booking', 'fluent-booking'),
            'label'       => __('Booking Rescheduled', 'fluent-booking'),
            'description' => __('This Funnel will be initiated when a booking has been rescheduled', 'fluent-booking')
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'run_only_one' => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'run_only_one' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Run this automation only once per contact. If unchecked then it will over-write existing flow', 'fluent-booking'),
                'help'        => __('If you enable this then this will run only once per customer otherwise, It will delete the existing automation flow and start new', 'fluent-booking'),
                'options'     => FunnelHelper::getUpdateOptions()
            ],
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Booking Rescheduled Funnel', 'fluent-booking'),
            'sub_title' => __('This Funnel will be initiated when a booking has been rescheduled.', 'fluent-booking'),
            'fields'    => [
                'event_id'            => [
                    'type'        => 'grouped-select',
                    'label'       => __('Booking Calendar', 'fluent-booking'),
                    'placeholder' => __('Select Calendar', 'fluent-booking'),
                    'is_multiple' => false,
                    'options'     => $this->getCalendarOptions()
                ],
                'subscription_status' => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fluent-booking'),
                    'placeholder' => __('Select Status', 'fluent-booking')
                ],
                'subscription_status_info' => [
                    'type' => 'html',
                    'info' => '<b>'.__('An Automated double-optin email will be sent for new subscribers', 'fluent-booking').'</b>',
                    'dependency'  => [
                        'depends_on'    => 'subscription_status',
                        'operator' => '=',
                        'value'    => 'pending'
                    ]
                ]
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $booking = $originalArgs[0];

        $willProcess = $this->isProcessable($funnel, $booking);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $originalArgs);

        if (!$willProcess) {
            return;
        }

        $subscriberData = array_filter([
            'first_name' => $booking->first_name,
            'last_name'  => $booking->last_name,
            'email'      => $booking->email,
            'phone'      => $booking->phone,
            'user_id'    => $booking->person_user_id,
            'timezone'   => $booking->person_time_zone,
            'status'     => Arr::get($funnel->settings, 'subscription_status'),
        ]);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $booking->id
        ]);
    }

    private function isProcessable($funnel, $booking)
    {
        $slotId = Arr::get($funnel->settings, 'event_id');

        if ($slotId != $booking->event_id) {
            return false;
        }

        $subscriber = FunnelHelper::getSubscriber($booking->email);

        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {

            $runMultiple = Arr::get($funnel->conditions, 'run_only_one') == 'no';

            if ($runMultiple) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            }

            return $runMultiple;
        }

        return true;
    }
}
