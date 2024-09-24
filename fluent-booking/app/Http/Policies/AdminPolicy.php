<?php

namespace FluentBooking\App\Http\Policies;

use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Foundation\Policy;

class AdminPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param \FluentBooking\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return PermissionManager::userCan(['invite_team_members']);
    }
    
    /**
     * Check user permission for any method
     * @param \FluentBooking\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function create(Request $request)
    {
        return PermissionManager::userCan(['invite_team_members']);
    }
}
