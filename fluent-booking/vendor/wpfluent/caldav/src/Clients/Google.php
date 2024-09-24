<?php

namespace FluentBooking\Package\CalDav\Clients;

use Exception;
use FluentBooking\Package\CalDav\ICal\XmlParser;
use FluentBooking\Package\CalDav\Entities\Calendar;

class Google extends Client
{
	public function __construct($credentials)
	{
		if (!is_array($credentials)) {
	        $credentials = array_combine(
	            ['base_url', 'username', 'password'], func_get_args()
	        );
		}

        $credentials['base_url'] .= '/caldav/v2';

        parent::__construct($credentials);

	}

	public function  getCalendars()
    {
        $url = $this->makeUrl("{$this->username}");

        return parent::getCalendarsInfo($url);
    }

    public function getCalendar($calendarId)
    {
    	$url = $this->buildUrlFrom($calendarId);

        return parent::getCalendarInfo($url);
    }

	public function getEvents($calendarId, $dateRange = [])
	{
		$url = $this->buildUrlFrom($calendarId);

		if (!str_contains($url, '/events')) {
			$url .= '/events';
		}

		return parent::getEventsFrom($url, $dateRange);
	}

	public function getEvent($uid)
	{
		$url = $this->buildUrlFrom($uid);

		return parent::getEventFrom($url);
	}

	public function addEvent($calendarId, $payload)
	{
		$url = $this->buildUrlFrom($calendarId);
		
		if (str_contains($url, '/events')) {
			$url = str_replace('/events', '', $url);
		}

        return parent::addEventTo($url . '/events', $payload);
	}

	public function deleteEvent($url)
    {
        $url = $this->buildUrlFrom($url);
		
		if (str_contains($url, '/events')) {
			$url = str_replace('/events', '', $url);
		}

        return parent::deleteEvent($url);
    }

	protected function getAuthCredential()
	{
		return 'Bearer ' . $this->password;
	}

	protected function buildUrlFrom($calendarId)
	{
		if (str_contains($calendarId, 'caldav')) {
			$calendarId = trim($calendarId, '/');
			$calendarId = str_replace('caldav/v2', '', $calendarId);
			$url = $this->makeUrl($calendarId);
		} else {
			$url = $this->makeUrl($calendarId);
		}

		return $url;
	}
}
