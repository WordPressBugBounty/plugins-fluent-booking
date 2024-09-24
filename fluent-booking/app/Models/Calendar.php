<?php

namespace FluentBooking\App\Models;

use FluentBooking\App\Models\Model;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\LandingPage\LandingPageHelper;
use FluentBooking\Framework\Support\Arr;

class Calendar extends Model
{
    protected $table = 'fcal_calendars';

    protected $guarded = ['id'];

    protected $fillable = [
        'hash',
        'user_id',
        'account_id',
        'parent_id',
        'title',
        'slug',
        'media_id',
        'description',
        'settings',
        'status',
        'type',
        'event_type',
        'account_type',
        'visibility',
        'author_timezone',
        'max_book_per_slot',
        'created_at',
        'updated_at'
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->user_id)) {
                $model->user_id = get_current_user_id();
            }
            $model->hash = md5(wp_generate_uuid4() . time());
        });
    }

    public function setSettingsAttribute($settings)
    {
        $this->attributes['settings'] = \maybe_serialize($settings);
    }

    public function getSettingsAttribute($settings)
    {
        return \maybe_unserialize($settings);
    }

    public function slots()
    {
        return $this->hasMany(CalendarSlot::class, 'calendar_id');
    }

    public function events()
    {
        return $this->hasMany(CalendarSlot::class, 'calendar_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'calendar_id');
    }

    public function availabilities()
    {
        return $this->hasMany(Availability::class, 'object_id', 'user_id');
    }

    public function metas()
    {
        return $this->hasMany(Meta::class, 'object_id', 'id')
            ->where('object_type', 'Calendar');
    }

    public function isTeamCalendar()
    {
        return $this->type == 'team';
    }

    public function isEventCalendar()
    {
        return $this->type == 'event';
    }

    public function isHostCalendar()
    {
        return $this->type == 'simple';
    }

    public function updateEventOrder($eventId)
    {
        $eventOrder = $this->getMeta('event_order', []);

        if ($eventOrder) {
            $updatedOrder = array_merge($eventOrder, [$eventId]);

            if (in_array($eventId, $eventOrder)) {
                $updatedOrder = array_filter($eventOrder, function($id) use ($eventId) {
                    return $id !== $eventId;
                });
            }

            return $this->updateMeta('event_order', array_values($updatedOrder));
        }

        return $eventOrder;
    }

    public function getAuthorPhoto()
    {
        $photo = $this->getMeta('profile_photo_url');

        if (!$photo) {
            $photo = Helper::fluentBookingUserAvatar($this->user_id, $this->user_id);
        }

        return $photo;
    }

    public function getAuthorProfile($public = true)
    {
        $user = get_user_by('id', $this->user_id);
        if (!$user) {
            return [
                'avatar'         => $this->getMeta('profile_photo_url'),
                'name'           => 'Unknown',
                'email'          => '',
                'phone'          => '',
                'featured_image' => $this->getMeta('featured_image_url')
            ];
        }

        $name = trim($user->first_name . ' ' . $user->last_name);

        if (!$name) {
            $name = $user->display_name;
        }

        $data = [
            'ID'             => $user->ID,
            'name'           => $name,
            'author_slug'    => $user->user_nicename,
            'first_name'     => $user->first_name,
            'last_name'      => $user->last_name,
            'avatar'         => $this->getAuthorPhoto(),
            'phone'          => $this->user->getMeta('host_phone'),
            'featured_image' => $this->getMeta('featured_image_url')
        ];

        if (!$public) {
            $data['email'] = $user->user_email;
        }

        return $data;
    }

    public function getMeta($key, $default = null)
    {
        $meta = Meta::where('object_type', 'Calendar')
            ->where('object_id', $this->id)
            ->where('key', $key)
            ->first();

        if (!$meta) {
            return $default;
        }

        return $meta->value;
    }

    public function updateMeta($key, $value)
    {
        $exist = Meta::where('object_type', 'Calendar')
            ->where('object_id', $this->id)
            ->where('key', $key)
            ->first();

        if ($exist) {
            $exist->value = $value;
            $exist->save();
        } else {
            $exist = Meta::create([
                'object_type' => 'Calendar',
                'object_id'   => $this->id,
                'key'         => $key,
                'value'       => $value
            ]);
        }

        return $exist;
    }

    public function getLandingPageUrl($isForce = false)
    {
        $settings = LandingPageHelper::getSettings($this);
        if (Arr::get($settings, 'enabled') != 'yes' && !$isForce) {
            return '';
        }

        if (defined('FLUENT_BOOKING_LANDING_SLUG')) {
            return LandingPageHelper::getLandingBaseUrl() . $this->slug;
        }

        return LandingPageHelper::getLandingBaseUrl() . '&host=' . $this->slug;
    }

    /**
     * Get the attributes that have been changed since last sync.
     *
     * @return array
     */
    public function getDirty()
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->fillable)) {
                continue;
            }

            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif ($value !== $this->original[$key] &&
                !$this->originalIsNumericallyEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public static function getAllHosts()
    {
        $calendars = self::with(['user'])
            ->where('type', 'simple')
            ->get();

        $deletedUser = __('Deleted User', 'fluent-booking');

        return $calendars->map(function ($calendar) use ($deletedUser) {
            $user = $calendar->user;
            return [
                'id'           => $user ? $user->ID : (int)$calendar->user_id,
                'name'         => $user ? $user->full_name : $deletedUser,
                'label'        => $user ? $user->display_name . ' (' . $user->user_email . ')' : $deletedUser,
                'avatar'       => $calendar->getAuthorPhoto(),
                'calendar_id'  => $calendar->id,
                'deleted_user' => $user ? false : true
            ];
        });
    }
}
