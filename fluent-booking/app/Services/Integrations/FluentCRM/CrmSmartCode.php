<?php

namespace FluentBooking\App\Services\Integrations\FluentCRM;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Services\EditorShortCodeParser;
use FluentCrm\App\Models\FunnelSubscriber;

class CrmSmartCode
{
    public function register()
    {
        add_filter('fluent_crm_funnel_context_smart_codes', array($this, 'pushContextCodes'), 10, 2);
        add_filter('fluent_crm/smartcode_group_callback_fcal', array($this, 'parseBookingData'), 10, 4);
    }

    public function pushContextCodes($codes, $context)
    {

        $triggers = [
            'fluent_booking/after_booking_scheduled',
            'fluent_booking/booking_schedule_cancelled',
            'fluent_booking/booking_schedule_completed'
        ];

        if (!in_array($context, $triggers)) {
            return $codes;
        }

        $codes[] = [
            'key'        => 'fcal',
            'title'      => 'Booking Data',
            'shortcodes' => $this->getSmartCodes()
        ];

        return $codes;
    }

    public function parseBookingData($code, $valueKey, $defaultValue, $subscriber)
    {
        $booking = $this->getBooking($subscriber);

        if (!$booking) {
            $booking = Booking::where('email', $subscriber->email)->orderBy('id', 'desc')->first();
        }

        if (!$booking) {
            return $defaultValue;
        }

        $value = EditorShortCodeParser::parse('{{' . $valueKey . '}}', $booking, true);

        if (!$value) {
            return $defaultValue;
        }

        return $value;
    }


    private function getBooking($subscriber)
    {
        if (empty($subscriber->funnel_subscriber_id)) {
            return null;
        }

        $funnelSub = FunnelSubscriber::where('id', $subscriber->funnel_subscriber_id)->first();

        if (!$funnelSub) {
            return null;
        }

        return Booking::where('id', $funnelSub->source_ref_id)->first();
    }

    private function getSmartCodes()
    {
        return [
            '{{fcal.guest.first_name}}'                      => __('Guest First Name', 'fluent-booking'),
            '{{fcal.guest.last_name}}'                       => __('Guest Last Name', 'fluent-booking'),
            '{{fcal.guest.full_name}}'                       => __('Guest Full Name', 'fluent-booking'),
            '{{fcal.guest.email}}'                           => __('Guest Email', 'fluent-booking'),
            '{{fcal.booking.phone}}'                         => __('Guest Main Phone Number (if provided)', 'fluent-booking'),
            '{{fcal.guest.note}}'                            => __('Guest Note', 'fluent-booking'),
            '{{fcal.guest.timezone}}'                        => __('Guest Timezone', 'fluent-booking'),
            '{{fcal.guest.form_data_html}}'                  => __('Guest Form Submitted Data (HTML)', 'fluent-booking'),
            '{{fcal.booking.event_name}}'                    => __('Event Name', 'fluent-booking'),
            '{{fcal.booking.description}}'                   => __('Event Description', 'fluent-booking'),
            '{{fcal.booking.full_start_end_guest_timezone}}' => __('Full Start & End Time (with guest timezone)', 'fluent-booking'),
            '{{fcal.booking.full_start_end_host_timezone}}'  => __('Full Start & End Time (with host timezone)', 'fluent-booking'),
            '{{fcal.booking.start_date_time}}'               => __('Event Date Time (UTC)', 'fluent-booking'),
            '{{fcal.booking.start_date_time_for_attendee}}'  => __('Event Date time (with guest timezone)', 'fluent-booking'),
            '{{fcal.booking.start_date_time_for_host}}'      => __('Event Date time (with host timezone)', 'fluent-booking'),
            '{{fcal.booking.location_details_html}}'         => __('Event Location Details (HTML)', 'fluent-booking'),
            '{{fcal.booking.cancel_reason}}'                 => __('Event Cancel Reason', 'fluent-booking'),
            '{{fcal.booking.start_time_human_format}}'       => __('Event Start Time (ex: 2 hours from now)', 'fluent-booking'),
            '##fcal.booking.cancelation_url##'               => __('Booking Cancellation URL', 'fluent-booking'),
            '##fcal.booking.reschedule_url##'                => __('Booking Reschedule URL', 'fluent-booking'),
            '##fcal.booking.admin_booking_url##'             => __('Booking Details Admin URL', 'fluent-booking'),
            '{{fcal.booking.booking_hash}}'                  => __('Unique Booking Hash', 'fluent-booking'),
            '{{fcal.booking.reschedule_reason}}'             => __('Event Reschedule Reason', 'fluent-booking'),
            '{{fcal.host.name}}'                             => __('Host Name', 'fluent-booking'),
            '{{fcal.host.email}}'                            => __('Host Email', 'fluent-booking'),
            '{{fcal.host.timezone}}'                         => __('Host Timezone', 'fluent-booking'),
            '{{fcal.event.id}}'                              => __('Event ID', 'fluent-booking'),
            '{{fcal.event.calendar_id}}'                     => __('Calendar ID', 'fluent-booking'),
            '{{fcal.calendar.title}}'                        => __('Calendar Title', 'fluent-booking'),
            '{{fcal.calendar.description}}'                  => __('Calendar Description', 'fluent-booking')
        ];
    }
}
