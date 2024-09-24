<?php

namespace FluentBooking\App\Hooks\Handlers\CleanupHandlers;

use FluentBooking\App\Models\Booking;
use FluentBookingPro\App\Models\Transactions;
use FluentBookingPro\App\Models\OrderItems;

class OrderCleaner
{
    public function register()
    {
        add_action('fluent_booking/before_delete_order', [$this, 'handleBeforeDelete'], 10, 2);
    }

    public function handleBeforeDelete($order, $booking)
    {
        if (empty($order)) {
            return;
        }

        Transactions::query()
            ->where('object_type', 'order')
            ->where('object_id', $order->id)
            ->delete();

        OrderItems::query()->where('order_id', $order->id)
            ->when($booking, function ($query, $booking) {
                $query->where('booking_id', $booking->id);
            })->delete();

    }
}