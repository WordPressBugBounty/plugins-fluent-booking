<?php

namespace FluentBooking\App\Hooks\Handlers\CleanupHandlers;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\BookingActivity;
use FluentBooking\App\Models\BookingHost;
use FluentBooking\App\Models\BookingMeta;

class BookingCleaner
{
    public function register()
    {
        add_action('fluent_booking/before_delete_booking', [$this, 'handleBeforeDelete'], 10, 1);
    }

    public function handleBeforeDelete($booking)
    {
        if (empty($booking)) {
            return;
        }

        BookingHost::query()->where('booking_id', $booking->id)->delete();

        if (defined('FLUENT_BOOKING_PRO_DIR_FILE')) {
            $order = \FluentBookingPro\App\Models\Order::query()
                ->where('parent_id', $booking->id)
                ->first();
    
            if ($order) {
                do_action('fluent_booking/before_delete_order', $order, $booking);
                $order->delete();
                do_action('fluent_booking/after_delete_order', $order, $booking);
            }
        }
    }
}
