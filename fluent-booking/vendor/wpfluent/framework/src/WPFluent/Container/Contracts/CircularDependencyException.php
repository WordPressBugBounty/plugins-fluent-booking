<?php

namespace FluentBooking\Framework\Container\Contracts;

use Exception;
use FluentBooking\Framework\Container\Contracts\Psr\ContainerExceptionInterface;

class CircularDependencyException extends Exception implements ContainerExceptionInterface
{
    //
}
