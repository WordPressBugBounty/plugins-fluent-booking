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
        if (!$this->verifyNonce()) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'fluent-booking'), 403);
        }

        $calendarId = isset($_REQUEST['calendar_id']) ? (int)$_REQUEST['calendar_id'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$calendarId) {
            die(esc_html__('Please provide Calendar ID', 'fluent-booking'));
        }

        $calendar = Calendar::with(['metas', 'events' => function ($query) {
            $query->with('event_metas');
        }])->find($calendarId);

        if (!$calendar) {
            die(esc_html__('Calendar not found', 'fluent-booking'));
        }

        if (!PermissionManager::hasCalendarAccess($calendar)) {
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
        if (!$this->verifyNonce()) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'fluent-booking'), 403);
        }

        if (!PermissionManager::hasAllCalendarAccess()) {
            die(esc_html__('You do not have permission to export data', 'fluent-booking'));
        }

        $groupId = isset($_REQUEST['group_id']) ? (int)$_REQUEST['group_id'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
                $this->sanitizeCsvCell($attendee->first_name),
                $this->sanitizeCsvCell($attendee->last_name),
                $this->sanitizeCsvCell($attendee->email),
                $this->sanitizeCsvCell($attendee->message),
                $this->sanitizeCsvCell($attendee->getLocationAsText()),
                $this->sanitizeCsvCell($attendee->source),
                $this->sanitizeCsvCell($attendee->booking_type),
                $this->sanitizeCsvCell($attendee->status),
                $this->sanitizeCsvCell($attendee->source_url),
                $this->sanitizeCsvCell($attendee->slot_minutes),
                $this->sanitizeCsvCell($attendee->start_time),
                $this->sanitizeCsvCell($attendee->end_time),
                $this->sanitizeCsvCell($attendee->payment_status),
            ];

            if ($attendee->payment_status) {
                $order = $attendee->payment_order;
                if ($order) {
                    $order->load(['items', 'transaction']);
                    $row[] = $this->sanitizeCsvCell($order->status);
                    $row[] = $this->sanitizeCsvCell($order->payment_method);
                    $row[] = $this->sanitizeCsvCell($order->currency);
                    $row[] = $order->total_amount / 100;
                    $row[] = $this->sanitizeCsvCell($order->created_at);
                    $row[] = $this->sanitizeCsvCell($order->transaction->id);
                    $row[] = $this->sanitizeCsvCell($order->transaction->vendor_charge_id);
                    $row[] = $this->sanitizeCsvCell($order->transaction->payment_method);
                    $row[] = $this->sanitizeCsvCell($order->transaction->status);
                    $row[] = $order->transaction->total / 100;
                    $row[] = $this->sanitizeCsvCell($order->transaction->created_at);
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

        foreach ($csvData as $index => $row) {
            // Sanitize header row cells for consistency (formula-neutralize and strip control chars)
            if ($index === 0) {
                $row = array_map([$this, 'sanitizeCsvCell'], $row);
            }
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

    /**
     * Sanitize a value for safe CSV output: neutralize formula injection and strip control chars.
     * Prefix with single quote when value starts with =, +, -, or @ so spreadsheets treat as text.
     *
     * @param mixed $value Cell value (string, number, or null).
     * @return string Safe string for fputcsv.
     */
    private function sanitizeCsvCell($value)
    {
        if (empty($value)) {
            return '';
        }
        $value = (string) $value;
        // Strip control characters (ASCII 0-31 except tab, LF, CR).
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        // Neutralize formula injection: prefix with ' so Excel/LibreOffice treat as text.
        $first = isset($value[0]) ? $value[0] : '';
        if (in_array($first, ['=', '+', '-', '@'], true)) {
            $value = "'" . $value;
        }

        return $value;
    }

    /**
     * Verify the request nonce for AJAX actions.
     *
     * @return bool
     */
    private function verifyNonce()
    {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';

        return !empty($nonce) && wp_verify_nonce($nonce, 'fluent-booking');
    }
}
