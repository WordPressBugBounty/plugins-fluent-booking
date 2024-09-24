<?php

namespace FluentBooking\App\Hooks\Handlers\CleanupHandlers;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\BookingHost;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;

class UserCleaner
{
    public function register()
    {
        add_action('delete_user', [$this, 'handleBeforeDelete'], 10, 2);
    }

    public function handleBeforeDelete($userId, $reassignId)
    {
        return;
        if ($reassignId) {
            $assignable = [
                Calendar::query(),
                CalendarSlot::query(),
                BookingHost::query()
            ];

            foreach ($assignable as $model) {
                $model::where('user_id', $reassignId)->update(['user_id' => $userId]);
            }
            return;
        }
        return;
    }

}