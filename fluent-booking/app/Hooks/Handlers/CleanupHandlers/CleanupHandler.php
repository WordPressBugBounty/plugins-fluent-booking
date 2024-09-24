<?php

namespace FluentBooking\App\Hooks\Handlers\CleanupHandlers;

class CleanupHandler
{
    public function register()
    {
        (new CalenderCleaner())->register();
        (new CalenderEventCleaner())->register();
        (new BookingCleaner())->register();
        (new UserCleaner())->register();

        $this->registerProCleaners();
    }

    private function registerProCleaners()
    {
        if (!defined('FLUENT_BOOKING_PRO_DIR_FILE')) {
            (new OrderCleaner())->register();
        }
    }
}
