<?php

namespace FluentBooking\App\Models;

class BookingHost extends Model
{
    protected $table = 'fcal_booking_hosts';

    protected $guarded = ['id'];

    protected $fillable = [
        'booking_id',
        'user_id',
    ];


    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

}
