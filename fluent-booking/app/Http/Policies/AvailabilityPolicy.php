<?php

namespace FluentBooking\App\Http\Policies;

use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Foundation\Policy;

class AvailabilityPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param \FluentBooking\Framework\Http\Request\Request; $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if (current_user_can('manage_options') || PermissionManager::userCan('manage_other_availabilities')) {
            return true;
        }

        if ($request->method() == 'GET' && PermissionManager::userCan('read_and_use_other_availabilities')) {
            return true;
        }

        if ($request->schedule_id) {
            $availability = \FluentBooking\App\Models\Availability::find($request->schedule_id);
            
            if (!$availability) {
                return false;
            }

            return (int)$availability->object_id === get_current_user_id();
        }

        return PermissionManager::userCan('manage_own_calendar');
    }
}
