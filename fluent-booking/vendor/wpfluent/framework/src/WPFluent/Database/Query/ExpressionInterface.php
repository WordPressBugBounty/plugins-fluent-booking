<?php

namespace FluentBooking\Framework\Database\Query;

use FluentBooking\Framework\Database\BaseGrammar;

interface ExpressionInterface
{
    /**
     * Get the value of the expression.
     *
     * @param  \FluentBooking\Framework\Database\BaseGrammar $grammar
     * @return string|int|float
     */
    public function getValue(BaseGrammar $grammar);
}
