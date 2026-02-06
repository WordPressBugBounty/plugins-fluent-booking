<?php

namespace FluentBooking\App\Http\Policies;

use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Foundation\Policy;
use FluentBooking\App\Services\PermissionManager;

class SettingsPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param \FluentBooking\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return PermissionManager::userCan('manage_all_data');
    }

}
