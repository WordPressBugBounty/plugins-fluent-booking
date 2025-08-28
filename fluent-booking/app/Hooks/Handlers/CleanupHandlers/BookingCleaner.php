<?php

namespace FluentBooking\App\Hooks\Handlers\CleanupHandlers;

use FluentBooking\App\Models\BookingHost;

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
    
            if (!$order) {
                return;
            }

            if ($order->items) {
                $order->items()->delete();
            }

            if ($order->discounts) {
                $order->discounts()->delete();
            }

            if ($order->transaction) {
                $order->transaction()->delete();
            }

            do_action('fluent_booking/before_delete_order', $order, $booking);

            $order->delete();

            do_action('fluent_booking/after_delete_order', $order, $booking);
        }
    }
}
