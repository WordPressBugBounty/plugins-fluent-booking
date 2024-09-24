<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\App;
use FluentBooking\App\Models\Booking;
use FluentBooking\Framework\Support\Arr;

class LocationService
{
    public static function getLocationIconHeadingHtml($details = [], $calendarEvent = null)
    {
        $html = '';
        $app = App::getInstance();

        foreach ($details as $location) {

            $displayOnBooking = Arr::get($location, 'display_on_booking') == 'yes';

            $html .= '<div class="slot_location fcal_icon_item fcal_img_item fcal_loc_google_meet">';
            if ($location['type'] == 'google_meet') {
                $html .= '<img class="fcal_loc_icon" src="' . $app['url.assets'] . 'images/g-meet.svg" alt="Google Meet" />';
                $html .= '<span class="fcal_loc_text">' . __('Google Meet', 'fluent-booking') . '</span>';
            } else if ($location['type'] == 'ms_teams') {
                $html .= '<img class="fcal_loc_icon" src="' . $app['url.assets'] . 'images/ms-teams.svg" alt="MS Teams" />';
                $html .= '<span class="fcal_loc_text">' . __('MS Teams', 'fluent-booking') . '</span>';
            } else if ($location['type'] == 'zoom_meeting') {
                $html .= '<img class="fcal_loc_icon zoom_icon" src="' . $app['url.assets'] . 'images/zoom.svg" alt="Zoom Icon" />';
                $html .= '<span class="fcal_loc_text">' . __('Zoom Video', 'fluent-booking') . '</span>';
            } else if ($location['type'] == 'online_meeting') {
                $html .= '<img class="fcal_loc_icon link_icon" src="' . $app['url.assets'] . 'images/link.svg" alt="Online Meeting" />';
                if ($displayOnBooking == 'yes') {
                    $html .= '<span class="fcal_loc_text">' . '<span class="fcal_loc_title">' . $location['meeting_link'] . '</span>';
                } else {
                    $html .= '<span class="fcal_loc_text">' . __('Online Meeting', 'fluent-booking') . '</span>';
                }
            } else if ($location['type'] == 'in_person_guest') {
                $html .= '<img class="fcal_loc_icon location_icon" src="' . $app['url.assets'] . 'images/physical_location.svg" alt="' . __('In Person', 'fluent-booking') . '" />';
                $html .= '<span class="fcal_loc_text">' . __('In Person (Attendee Address)', 'fluent-booking') . '</span>';
            } else if ($location['type'] == 'custom') {
                $html .= '<img class="fcal_loc_icon location_icon" src="' . $app['url.assets'] . 'images/physical_location.svg" alt="' . __('Custom Icon', 'fluent-booking') . '" />';
                if ($displayOnBooking == 'yes') {
                    $html .= '<span class="fcal_loc_text">' . $location['description'] . '</span>';
                } else {
                    $html .= '<span class="fcal_loc_text">' . $location['title'] . '</span>';
                }
            } else if ($location['type'] == 'in_person_organizer') {
                $html .= '<img class="fcal_loc_icon location_icon" src="' . $app['url.assets'] . 'images/physical_location.svg" alt="' . __('In Person', 'fluent-booking') . '" />';
                if ($displayOnBooking == 'yes') {
                    $html .= '<span class="fcal_loc_text">' . $location['description'] . '</span>';
                } else {
                    $html .= '<span class="fcal_loc_text">' . __('In Person (Organizer Address)', 'fluent-booking') . '</span>';
                }
            } else if ($location['type'] == 'phone_guest') {
                $html .= '<img class="fcal_loc_icon phone_icon" src="' . $app['url.assets'] . 'images/phone_call.svg" alt="' . __('Phone', 'fluent-booking') . '" />';
                $html .= '<span class="fcal_loc_text">' . __('Attendee Phone Number', 'fluent-booking') . '</span>';
            } else if ($location['type'] == 'phone_organizer') {
                $html .= '<img class="fcal_loc_icon phone_icon" src="' . $app['url.assets'] . 'images/phone_call.svg" alt="' . __('Phone', 'fluent-booking') . '" />';
                if ($displayOnBooking == 'yes') {
                    $html .= '<span class="fcal_loc_text">' . $location['host_phone_number'] . '</span>';
                } else {
                    $html .= '<span class="fcal_loc_text">' . __('Phone Call', 'fluent-booking') . '</span>';
                }
            }
            $html .= '</div>';

        }

        return apply_filters('fluent_booking/location_icon_heading_html', $html, $details, $calendarEvent);

    }

    public static function getBookingLocationUrl(Booking $booking)
    {
        $details = $booking->location_details;

        if (!$details || empty($details['type'])) {
            return $booking->getConfirmationUrl();
        }

        if (!empty($details['online_platform_link'])) {
            return Arr::get($details, 'online_platform_link');
        }

        return $booking->getConfirmationUrl();
    }

    public static function getLocationDetails($calendarEvent, $userInput = [], $allInput = [])
    {
        $userInput = array_map('sanitize_text_field', $userInput);
        $locations = $calendarEvent->location_settings;

        if (empty($locations)) {
            return [
                'type'        => 'custom',
                'description' => ''
            ];
        }

        if (empty($allInput)) {
            $locations = [$locations[0]];
        }

        if (count($locations) == 1) {
            // return the first location
            $defaultLocation = $locations[0];

            $type = Arr::get($defaultLocation, 'type');

            $userInput = [
                'driver' => $type . '__:__0'
            ];

            if ($type == 'phone_guest') {
                $userInput['user_location_input'] = Arr::get($allInput, 'phone_number');
            } else if ($type == 'in_person_guest') {
                $userInput['user_location_input'] = Arr::get($allInput, 'address');
            }
        }

        $keyedLocations = [];
        foreach ($locations as $index => $location) {
            $keyedLocations[$location['type'] . '__:__' . $index] = $location;
        }

        $driver = Arr::get($userInput, 'driver');

        if (empty($keyedLocations[$driver])) {
            return [
                'type'        => 'custom',
                'description' => ''
            ];
        }

        $selectedLocation = $keyedLocations[$driver];

        $selectedType = $selectedLocation['type'];

        // custom user input location types
        if (in_array($selectedType, ['in_person_guest', 'phone_guest'])) {
            return [
                'type'        => $selectedType,
                'description' => Arr::get($userInput, 'user_location_input')
            ];
        }

        // Check provided description location type to store as description
        if (in_array($selectedType, ['custom', 'phone_organizer', 'in_person_organizer'])) {
            $fieldMaps = [
                'custom'              => 'description',
                'in_person_organizer' => 'description',
                'phone_organizer'     => 'host_phone_number'
            ];

            $key = $fieldMaps[$selectedType];

            return [
                'type'        => $selectedType,
                'description' => Arr::get($selectedLocation, $key)
            ];
        }

        if ($selectedType == 'online_meeting') {
            return [
                'type'                 => $selectedType,
                'online_platform_link' => Arr::get($selectedLocation, 'meeting_link')
            ];
        }

        return [
            'type'        => $selectedType,
            'description' => ''
        ];
    }

    public static function getLocationsConfig()
    {
        return [];
    }

    public static function getLocationOptions($calendarEvent, $keyed = false)
    {
        $locationSettings = $calendarEvent->location_settings;

        $locationOptions = [];
        foreach ($locationSettings as $index => $location) {
            $title = Arr::get($location, 'title');

            $locationType = Arr::get($location, 'type');

            if ($locationType == 'custom') {
                if (Arr::get($location, 'display_on_booking') == 'yes') {
                    $title = Arr::get($location, 'description');
                } else {
                    $title = Arr::get($location, 'title');
                }
            }

            if (!$title) {
                $title = str_replace('_', ' ', ucfirst($locationType));
            }

            $slug = Arr::get($location, 'type') . '__:__' . $index;

            if ($keyed) {
                $locationOptions[$slug] = [
                    'type'  => Arr::get($location, 'type'),
                    'title' => $title,
                    'slug'  => $slug
                ];
            } else {
                $locationOptions[] = [
                    'type'  => Arr::get($location, 'type'),
                    'title' => $title,
                    'slug'  => $slug
                ];
            }
        }
        return $locationOptions;
    }
}
