<?php

namespace FluentBooking\App\Models;

use FluentBooking\Framework\Database\Orm\Model as BaseModel;

class Model extends BaseModel
{
    public function __construct($attributes = [])
    {
        parent::__construct($attributes);
    }

    public function scopeLatest($query, $field = 'created_at')
    {
        return $query->orderBy($field, 'DESC');
    }

    public function scopeNewest($query, $field = 'created_at')
    {
        return $query->orderBy($field, 'ASC');
    }

    public function getPerPage()
    {
        return (isset($_REQUEST['per_page'])) ? intval($_REQUEST['per_page']) : 15; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Get a fresh timestamp for the model.
     *
     * @return \DateTime
     */
    public function freshTimestamp()
    {
        return new \DateTime(gmdate('Y-m-d H:i:s')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    }

    public function getTimezone()
    {
        return new \DateTimeZone('UTC');
    }

    /**
     * Determine if the new and old values for a given key are numerically equivalent.
     *
     * @param  string  $key
     * @return bool
     */
    protected function originalIsNumericallyEquivalent($key)
    {
        $current = $this->attributes[$key];

        $original = $this->original[$key];

        return is_numeric($current) && is_numeric($original) && strcmp((string) $current, (string) $original) === 0;
    }
}
