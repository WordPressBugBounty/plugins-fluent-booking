<?php

namespace FluentBooking\App\Models;
class Meta extends Model
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
    }

    public function setValueAttribute($value)
    {
        $this->attributes['value'] = \maybe_serialize($value);
    }

    public function getValueAttribute($value)
    {
        return \maybe_unserialize($value);
    }

    public function calendar_event()
    {
        return $this->belongsTo(CalendarSlot::class, 'object_id');
    }

}
