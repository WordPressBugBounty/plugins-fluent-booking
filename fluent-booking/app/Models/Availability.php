<?php

namespace FluentBooking\App\Models;

use FluentBooking\App\Services\Helper;

class Availability extends Model
{
    protected $table = 'fcal_meta';

    protected $guarded = ['id'];

    protected $fillable = [
        'object_type',
        'object_id',
        'key',
        'value'
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->object_type = 'availability';
        });

        static::updating(function ($model) {
            $model->object_type = 'availability';
        });

        static::addGlobalScope('object_type', function ($query) {
            $query->where('object_type', 'availability');
        });
    }

    public function setValueAttribute($value)
    {
        $this->attributes['value'] = \maybe_serialize($value);
    }

    public function getValueAttribute($value)
    {
        return \maybe_unserialize($value);
    }

    public function getAuthor()
    {
        $user = get_user_by('ID', $this->object_id);
        if(!$user) {
            return [
                'name' => __('Deleted user', 'fluent-booking'),
                'avatar' => ''
            ];
        }

        $name = trim($user->first_name . ' ' . $user->last_name);
        if(!$name) {
            $name = $user->display_name;
        }

        return [
            'name' => $name,
            'avatar' => Helper::fluentBookingUserAvatar($user->user_email, $user)
        ];
    }
}
