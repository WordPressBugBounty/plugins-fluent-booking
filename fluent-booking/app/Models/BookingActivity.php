<?php

namespace FluentBooking\App\Models;

class BookingActivity extends Model
{
    protected $table = 'fcal_booking_activity';

    protected $guarded = ['id'];

    protected $fillable = [
        'booking_id',
        'parent_id',
        'created_by',
        'status',
        'type',
        'title',
        'description'
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!isset($model->created_by) && $userId = get_current_user_id()) {
                $model->created_by = $userId;
            }
        });
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

}
