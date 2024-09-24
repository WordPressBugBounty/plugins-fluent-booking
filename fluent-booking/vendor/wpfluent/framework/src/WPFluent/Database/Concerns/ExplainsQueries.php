<?php

namespace FluentBooking\Framework\Database\Concerns;

use FluentBooking\Framework\Support\Collection;

trait ExplainsQueries
{
    /**
     * Explains the query.
     *
     * @return \FluentBooking\Framework\Support\Collection
     */
    public function explain()
    {
        $sql = $this->toSql();

        $bindings = $this->getBindings();

        $explanation = $this->getConnection()->select('EXPLAIN '.$sql, $bindings);

        return new Collection($explanation);
    }
}
