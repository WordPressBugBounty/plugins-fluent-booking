<?php

namespace FluentBooking\Framework\Container;

use Exception;
use FluentBooking\Framework\Container\Contracts\Psr\NotFoundExceptionInterface;

class EntryNotFoundException extends Exception implements NotFoundExceptionInterface
{
    //
}
