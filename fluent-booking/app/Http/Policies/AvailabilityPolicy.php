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
        if (PermissionManager::userCan(['manage_all_data', 'manage_other_availabilities'])) {
            return true;
        }

        if ($request->method() == 'GET' && PermissionManager::userCan('read_and_use_other_availabilities')) {
            return true;
        }

        // Resolve the schedule from the URL route only — request-body values
        // must not be permitted to redirect the authorization target.
        $urlParams = (array) $request->get_url_params();
        $scheduleId = isset($urlParams['schedule_id']) ? (int) $urlParams['schedule_id'] : 0;

        if ($scheduleId) {
            $availability = \FluentBooking\App\Models\Availability::find($scheduleId);

            if (!$availability) {
                return false;
            }

            return (int)$availability->object_id === get_current_user_id();
        }

        return PermissionManager::userCan('manage_own_calendar');
    }
}
