<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\Availability;
use FluentBooking\App\Services\PermissionManager;

class DataExporter
{
    public function exportCalendar()
    {
        $calendarId = (int)$_REQUEST['calendar_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$calendarId) {
            die(esc_html__('Please provide Calendar ID', 'fluent-booking'));
        }

        $calendar = Calendar::with(['metas', 'events' => function ($query) {
            $query->with('event_metas');
        }])->find($calendarId);

        if (!$calendar) {
            die(esc_html__('Calendar not found', 'fluent-booking'));
        }

        if (!PermissionManager::hasAllCalendarAccess() && !PermissionManager::hasCalendarAccess($calendar)) {
            die(esc_html__('You do not have permission to export data', 'fluent-booking'));
        }

        $calendarData = $this->prepareCalendarExportData($calendar);

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=CluentBookingHostExport-' . $calendarId . '.json');
        echo json_encode($calendarData, JSON_PRETTY_PRINT); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit();
    }

    public function exportBookingHosts()
    {
        if (!PermissionManager::hasAllCalendarAccess()) {
            die(esc_html__('You do not have permission to export data', 'fluent-booking'));
        }

        $groupId = (int)$_REQUEST['group_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$groupId) {
            die(esc_html__('Please provide Group ID', 'fluent-booking'));
        }

        $attendees = Booking::where('group_id', $groupId)->get();

        $csvData[] = [
            'First Name',
            'Last Name',
            'Email',
            'Message',
            'Location Details',
            'Source',
            'Booking Type',
            'Status',
            'Source URL',
            'Duration',
            'Start Time',
            'End Time',
            'Payment Status',
            'Payment Order Status',
            'Payment Method',
            'Currency',
            'Total Amount',
            'Order Created At',
            'Transaction ID',
            'Vendor Charge ID',
            'Transaction Payment Method',
            'Transaction Status',
            'Transaction Total',
            'Transaction Created At',
        ];

        foreach ($attendees as $attendee) {
            $row = [
                $attendee->first_name,
                $attendee->last_name,
                $attendee->email,
                $attendee->message,
                $attendee->getLocationAsText(),
                $attendee->source,
                $attendee->booking_type,
                $attendee->status,
                $attendee->source_url,
                $attendee->slot_minutes,
                $attendee->start_time,
                $attendee->end_time,
                $attendee->payment_status,
            ];

            if ($attendee->payment_status) {
                $order = $attendee->payment_order;
                if ($order) {
                    $order->load(['items', 'transaction']);
                    $row[] = $order->status;
                    $row[] = $order->payment_method;
                    $row[] = $order->currency;
                    $row[] = $order->total_amount / 100;
                    $row[] = $order->created_at;
                    $row[] = $order->transaction->id;
                    $row[] = $order->transaction->vendor_charge_id;
                    $row[] = $order->transaction->payment_method;
                    $row[] = $order->transaction->status;
                    $row[] = $order->transaction->total / 100;
                    $row[] = $order->transaction->created_at;
                }
            } else {
                // Fill empty columns for payment related data if payment_status is false
                $row = array_pad($row, 11, '');
            }

            $csvData[] = $row;
        }

        $csvData = apply_filters('fluent_booking/exporting_booking_data_csv', $csvData, $attendees);

        $output = fopen('php://output', 'w');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=Booking-Event-Guests-' . $groupId . '.csv');

        foreach ($csvData as $row) {
            fputcsv($output, $row);
        }

        fclose($output); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit();
    }

    /*
     * Prepare calendar data for export
     * @param Calendar|int $calendar Calendar Model or ID
     * @return array
     */
    public function prepareCalendarExportData($calendar = null)
    {
        if (is_numeric($calendar)) {
            $calendar = Calendar::with(['metas', 'events' => function ($query) {
                $query->with('event_metas');
            }])->find($calendar);
        } else if (is_null($calendar)) {
            $calendar = Calendar::with(['metas', 'events' => function ($query) {
                $query->with('event_metas');
            }])->first();
        }

        if (!$calendar) {
            return [];
        }

        $availabilities = [];

        foreach ($calendar->events as $event) {
            if (isset($availabilities[$event->availability_id])) {
                continue;
            }
            $availability = Availability::find($event->availability_id);
            if ($availability) {
                $availabilities[$event->availability_id] = $availability;
            }
        }

        $calendarData = $calendar->toArray();

        $calendarData['data_type'] = 'host';
        $calendarData['availabilities'] = $availabilities;

        $calendarData = apply_filters('fluent_booking/exporting_calendar_data_json', $calendarData, $calendar);

        return $calendarData;
    }
}
