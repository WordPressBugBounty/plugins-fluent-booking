<?php

namespace FluentBooking\App\Models;

class BookingMeta extends Model
{
    protected $table = 'fcal_booking_meta';

    protected $guarded = ['id'];

    protected $fillable = [
        'booking_id',
        'meta_key',
        'value'
    ];

    public function setValueAttribute($value)
    {
        $this->attributes['value'] = \maybe_serialize($value);
    }

    public function getValueAttribute($value)
    {
        return \maybe_unserialize($value);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

}
