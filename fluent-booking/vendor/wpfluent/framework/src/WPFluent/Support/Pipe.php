<?php

namespace FluentBooking\Framework\Support;

/**
 * @phpstan-consistent-constructor
 */
class Pipe
{
    use Tappable, Conditionable, MacroableTrait;

    /**
     * The value being piped.
     *
     * @var mixed
     */
    protected $value;

    /**
     * Create a new pipe instance.
     *
     * @param  mixed  $value
     * @return void
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    /**
     * Get a new pipe instance for the given value.
     *
     * @param  mixed  $value
     * @return static
     */
    public static function of($value)
    {
        return new static($value);
    }

    /**
     * Pass the value to the callback and return a new pipe for the result.
     *
     * Any extra arguments are passed to the callback after the value.
     *
     * @param  callable  $callback
     * @param  mixed  ...$args
     * @return static
     */
    public function pipe(callable $callback, ...$args)
    {
        // A copy, so by-reference callbacks like sort() can't change this pipe.
        $value = $this->value;

        return new static($callback($value, ...$args));
    }

    /**
     * Get the piped value.
     *
     * @return mixed
     */
    public function value()
    {
        return $this->value;
    }
}
