<?php

namespace FluentBooking\App\Http\Policies;

use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Foundation\Policy;

class PublicPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param  \FluentBooking\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return true;
    }

    /**
     * Check user permission for any method
     * @param  \FluentBooking\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function create(Request $request)
    {
        return true;
    }
}
