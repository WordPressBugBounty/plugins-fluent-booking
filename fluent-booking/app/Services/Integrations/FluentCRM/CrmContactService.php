<?php

namespace FluentBooking\App\Services\Integrations\FluentCRM;

/**
 * Read/manage a FluentCRM contact's profile, tags and lists for the
 * booking details CRM Profile card. All methods are no-ops (return null)
 * when FluentCRM is inactive or the booking email has no CRM contact.
 */
class CrmContactService
{
    public static function isActive()
    {
        return defined('FLUENTCRM');
    }

    /**
     * Profile payload for the CRM Profile sidebar card.
     */
    public static function getProfileData($email)
    {
        $contact = self::getContact($email);

        if (!$contact) {
            return null;
        }

        return [
            'contact_id'  => (int) $contact->id,
            'photo'       => $contact->photo,
            'full_name'   => $contact->full_name,
            'status'      => $contact->status,
            'profile_url' => fluentcrm_menu_url_base() . 'subscribers/' . $contact->id,
            'stats'       => $contact->stats(),
            'tag_ids'     => self::taxonomyIds($contact, 'tags'),
            'list_ids'    => self::taxonomyIds($contact, 'lists'),
            'tags'        => self::selectedOptions($contact, 'tags'),
            'lists'       => self::selectedOptions($contact, 'lists'),
        ];
    }

    /**
     * Current tag/list selection (ids + titles) for the contact.
     */
    public static function getContactState($email)
    {
        $contact = self::getContact($email);

        if (!$contact) {
            return null;
        }

        return [
            'exists'         => true,
            'tag_ids'        => self::taxonomyIds($contact, 'tags'),
            'list_ids'       => self::taxonomyIds($contact, 'lists'),
            'selected_tags'  => self::selectedOptions($contact, 'tags'),
            'selected_lists' => self::selectedOptions($contact, 'lists'),
        ];
    }

    /**
     * Guest-field prefill for the CRM "Book Appointment" action.
     * Null when FluentCRM is inactive or the contact is unknown.
     * Extend the payload via the filter.
     */
    public static function getBookingPrefillData($contactId)
    {
        if (!self::isActive()) {
            return null;
        }

        $contact = FluentCrmApi('contacts')->getContact((int) $contactId);

        if (!$contact) {
            return null;
        }

        $data = [
            'name'     => trim((string) $contact->full_name),
            'email'    => (string) $contact->email,
            'phone'    => (string) $contact->phone,
            'timezone' => (string) $contact->timezone,
            'address'  => implode(', ', array_filter([
                (string) $contact->address_line_1,
                (string) $contact->address_line_2,
                (string) $contact->city,
                (string) $contact->state,
                (string) $contact->postal_code,
                (string) $contact->country,
            ])),
        ];

        return apply_filters('fluent_booking/crm_booking_prefill_data', $data, $contact);
    }

    /**
     * Bounded, optionally-searched option set for the tag/list picker.
     */
    public static function getOptions($type, $search = '', $limit = 50)
    {
        if (!self::isActive()) {
            return [];
        }

        return self::taxonomyOptions($type, $search, $limit);
    }

    /**
     * Bounded search of subscribed contacts for the booking typeahead.
     */
    public static function searchContacts($search, $limit = 20)
    {
        if (!self::isActive()) {
            return [];
        }

        $search = trim((string) $search);
        if (mb_strlen($search) < 3) {
            return [];
        }

        $like = '%' . addcslashes($search, '%_\\') . '%';

        return \FluentCrm\App\Models\Subscriber::where('status', 'subscribed')
            ->where(function ($q) use ($like) {
                $q->where('email', 'LIKE', $like)
                  ->orWhere('first_name', 'LIKE', $like)
                  ->orWhere('last_name', 'LIKE', $like);
            })
            ->orderBy('email', 'ASC')
            ->limit($limit)
            ->get()
            ->map(function ($contact) {
                return [
                    'id'    => (int) $contact->id,
                    'name'  => trim((string) $contact->full_name),
                    'email' => (string) $contact->email,
                ];
            })
            ->toArray();
    }

    /**
     * Attach/detach the contact's tags or lists to match the desired set.
     * Only IDs the CRM actually knows about are honoured (no bogus pivots).
     *
     * @param string $type 'tags' | 'lists'
     * @return array|null  Fresh {tag_ids, list_ids} or null when no contact.
     */
    public static function syncTaxonomy($email, $type, array $desiredIds)
    {
        $contact = self::getContact($email);

        if (!$contact) {
            return null;
        }

        $model      = self::taxonomyModel($type);
        $desiredIds = array_values(array_map('intval', $desiredIds));
        $valid      = $desiredIds
            ? array_map('intval', $model::whereIn('id', $desiredIds)->pluck('id')->toArray())
            : [];
        $desired    = array_values(array_intersect($desiredIds, $valid));

        $current  = self::taxonomyIds($contact, $type);
        $toAttach = array_values(array_diff($desired, $current));
        $toDetach = array_values(array_diff($current, $desired));

        if ($type === 'tags') {
            if ($toAttach) {
                $contact->attachTags($toAttach);
            }
            if ($toDetach) {
                $contact->detachTags($toDetach);
            }
        } else {
            if ($toAttach) {
                $contact->attachLists($toAttach);
            }
            if ($toDetach) {
                $contact->detachLists($toDetach);
            }
        }

        $contact->load(['tags', 'lists']);

        return [
            'tag_ids'  => self::taxonomyIds($contact, 'tags'),
            'list_ids' => self::taxonomyIds($contact, 'lists'),
        ];
    }

    private static function getContact($email)
    {
        if (!self::isActive() || !$email) {
            return null;
        }

        return FluentCrmApi('contacts')->getContact($email) ?: null;
    }

    private static function taxonomyIds($contact, $type)
    {
        $relation = $type === 'tags' ? $contact->tags : $contact->lists;

        return array_map('intval', $relation->pluck('id')->toArray());
    }

    private static function taxonomyOptions($type, $search = '', $limit = 50)
    {
        $model = self::taxonomyModel($type);
        $query = $model::orderBy('title', 'ASC');

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where('title', 'LIKE', '%' . addcslashes($search, '%_\\') . '%');
        }

        return $query->limit($limit)->get()->map(function ($item) {
            return [
                'id'    => (int) $item->id,
                'title' => $item->title,
            ];
        })->toArray();
    }

    private static function selectedOptions($contact, $type)
    {
        $relation = $type === 'tags' ? $contact->tags : $contact->lists;

        return $relation->map(function ($item) {
            return [
                'id'    => (int) $item->id,
                'title' => $item->title,
            ];
        })->values()->toArray();
    }

    private static function taxonomyModel($type)
    {
        return $type === 'tags'
            ? \FluentCrm\App\Models\Tag::class
            : \FluentCrm\App\Models\Lists::class;
    }
}
