<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\Hooks\Handlers\FrontEndHandler;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Services\BookingService;
use FluentBooking\Framework\Http\Request\Request;

class WidgetController extends Controller
{
    public function getPublicVars(Request $request)
    {
        $slotId = (int)$request->get('event_id');
        $slot = CalendarSlot::findOrFail($slotId);
        $formFields = BookingFieldService::getBookingFields($slot);

        $calendarVars = [
            'slot'           => $slot,
            'calendar'       => $slot->calendar,
            'author_profile' => $slot->getAuthorProfile(true),
            'form_fields'    => $formFields,
            'disable_author' => false
        ];

        $globalVars = (new FrontEndHandler())->getGlobalVars();

        return [
            'global_vars' => $globalVars,
            'app_vars'    => $calendarVars
        ];
    }
}
