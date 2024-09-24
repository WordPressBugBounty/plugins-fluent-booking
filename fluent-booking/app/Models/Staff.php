<?php

namespace FluentBooking\App\Models;
class Staff extends Meta
{
    public static function boot()
    {
        parent::boot();

        static::addGlobalScope('staff', function ($builder) {
            $builder->where('object_type', 'staff');
        });

        static::creating(function ($model) {
            $model->object_type = 'staff';
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'object_id');
    }

}
