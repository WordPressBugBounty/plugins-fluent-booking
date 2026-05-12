<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Booking;

class ExportHelper
{
    public static function mapBooking(Booking $booking): array
    {
        $event = $booking->calendar_event;
        $host  = $booking->user;

        return [
            'id'                  => (int) $booking->id,
            'group_id'            => (int) $booking->group_id,
            'event_type'          => self::sanitizeCell($booking->event_type),
            'event_title'         => self::sanitizeCell($event ? $event->title : ''),
            'host_name'           => self::sanitizeCell($host ? $host->display_name : ''),
            'first_name'          => self::sanitizeCell($booking->first_name),
            'last_name'           => self::sanitizeCell($booking->last_name),
            'email'               => self::sanitizeCell($booking->email),
            'phone'               => self::sanitizeCell($booking->phone),
            'message'             => self::compactText($booking->message),
            'internal_note'       => self::compactText($booking->internal_note),
            'status'              => self::sanitizeCell($booking->status),
            'cancelled'           => $booking->status === 'cancelled' ? 'Yes' : 'No',
            'cancelled_by'        => self::sanitizeCell($booking->getMeta('cancelled_by_type', 'host')),
            'cancellation_reason' => self::compactText($booking->getCancelReason(true)),
            'person_time_zone'    => self::sanitizeCell($booking->person_time_zone),
            'start_time'          => (string) $booking->start_time,
            'end_time'            => (string) $booking->end_time,
            'duration'            => (int) $booking->slot_minutes,
            'meeting_location'    => self::compactText($booking->getLocationAsText()),
            'source'              => self::sanitizeCell($booking->source),
            'source_url'          => self::sanitizeCell($booking->source_url),
            'utm_source'          => self::sanitizeCell($booking->utm_source),
            'utm_medium'          => self::sanitizeCell($booking->utm_medium),
            'utm_campaign'        => self::sanitizeCell($booking->utm_campaign),
            'utm_term'            => self::sanitizeCell($booking->utm_term),
            'utm_content'         => self::sanitizeCell($booking->utm_content),
            'created_at'          => (string) $booking->created_at,
            'event_price'         => $event ? $event->getEventPrice($booking->slot_minutes) : 0,
            'payment_status'      => self::sanitizeCell($booking->payment_status),
            'payment_method'      => self::sanitizeCell($booking->payment_method),
        ];
    }

    public static function sanitizeCell($value)
    {
        if (empty($value) && $value !== '0' && $value !== 0) {
            return '';
        }

        $value = (string) $value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        $check = ltrim($value, " \t\xC2\xA0");
        $first = isset($check[0]) ? $check[0] : '';
        if (in_array($first, ['=', '+', '-', '@'], true)) {
            $value = "'" . $value;
        }

        return $value;
    }

    public static function compactText($value)
    {
        if (empty($value) && $value !== '0' && $value !== 0) {
            return '';
        }

        $value = wp_strip_all_tags((string) $value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value);

        return self::sanitizeCell($value);
    }
}
