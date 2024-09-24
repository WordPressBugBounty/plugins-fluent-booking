<?php

namespace FluentBooking\App\Hooks\Scheduler;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\Meta;
use FluentBooking\App\Services\Helper;

class FiveMinuteScheduler
{
    public function register()
    {
        add_action('fluent_booking_five_minutes_tasks', [$this, 'handle']);
    }

    public function handle()
    {
        $this->maybeAutoCancelBooking();
        $this->maybeAutoCompleteBookings();
        $this->maybeAutoCancelPastBookings();
        $this->maybeAutoDeleteReservations();
        $this->maybeAutoExpireCalendarEvents();
        $this->maybeAutoExpireCalendars();
    }

    private function maybeAutoCompleteBookings()
    {
        $autoCompleteTimeOut = (int)Helper::getGlobalAdminSetting('auto_complete_timing', 60) * 60; // 10 minutes

        $bookings = Booking::where('status', 'scheduled')
            ->where('end_time', '<', gmdate('Y-m-d H:i:s', time() - $autoCompleteTimeOut)) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ->limit(500)
            ->get();

        foreach ($bookings as $booking) {
            $booking->status = 'completed';
            $booking->save();
            do_action('fluent_booking/booking_schedule_completed', $booking, $booking->calendar_event);
        }

        return true;
    }

    private function maybeAutoCancelPastBookings()
    {
        $autoCompleteTimeOut = (int)Helper::getGlobalAdminSetting('auto_complete_timing', 60) * 60; // 10 minutes

        Booking::query()
            ->where('status', 'pending')
            ->where('end_time', '<', gmdate('Y-m-d H:i:s', time() - $autoCompleteTimeOut)) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ->update([
                'status'     => 'cancelled',
                'updated_at' => gmdate('Y-m-d H:i:s') // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ]);

        return true;
    }

    private function maybeAutoCancelBooking()
    {
        $autoCancelTimeOut = (int)Helper::getGlobalAdminSetting('auto_cancel_timing', 10) * 60; // 10 minutes

        Booking::query()
            ->where('created_at', '<=', gmdate('Y-m-d H:i:s', time() - $autoCancelTimeOut)) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->update([
                'status'     => 'cancelled',
                'updated_at' => gmdate('Y-m-d H:i:s') // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ]);

        return true;
    }

    private function maybeAutoExpireCalendars()
    {
        Calendar::query()
            ->where('type', 'event')
            ->where('status', 'active')
            ->whereDoesntHave('events', function ($query) {
                $query->whereIn('status', ['active', 'draft']);
            })
            ->update(['status' => 'expired']);

        return true;
    }

    private function maybeAutoExpireCalendarEvents()
    {
        Meta::query()
            ->where('object_type', 'calendar_event')
            ->where('key', 'expire_time')
            ->where('value', '<=', gmdate('Y-m-d H:i:s', time())) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ->whereHas('calendar_event', function ($query) {
                $query->where('status', 'active');
            })
            ->with(['calendar_event' => function ($query) {
                $query->where('status', 'active');
            }])
            ->get()
            ->each(function ($meta) {
                $meta->calendar_event->update(['status' => 'expired']);
            });

        return true;
    }

    private function maybeAutoDeleteReservations()
    {
        Booking::query()
            ->whereIn('event_type', ['single_event', 'group_event'])
            ->where('status', 'reserved')
            ->where('start_time', '<=', gmdate('Y-m-d H:i:s', time())) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ->delete();

        return true;
    }
}
