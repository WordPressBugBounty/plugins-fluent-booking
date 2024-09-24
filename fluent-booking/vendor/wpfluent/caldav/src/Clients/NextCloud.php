<?php

namespace FluentBooking\Package\CalDav\Clients;

use Exception;
use FluentBooking\Package\CalDav\ICal\XmlParser;
use FluentBooking\Package\CalDav\Entities\Calendar;

class NextCloud extends Client
{
	public function  getCalendars()
    {
        $url = $this->makeUrl("calendars/{$this->username}");

        return parent::getCalendarsInfo($url);
    }

    public function getCalendar($calendarName)
    {
    	$url = $this->buildUrlFrom($calendarName);

        return parent::getCalendarInfo($url);
    }

	public function getEvents($calendarName, $dateRange = [])
	{
		$url = $this->buildUrlFrom($calendarName);

		return parent::getEventsFrom($url, $dateRange);
	}

	public function getEvent($uid)
	{
		$url = $this->buildUrlFrom($uid);

		return parent::getEventFrom($url);
	}

	public function addEvent($calendarName, $payload)
	{
		$url = $this->buildUrlFrom($calendarName);

        return parent::addEventTo($url, $payload);
	}

	public function deleteEvent($url)
    {
        $url = $this->buildUrlFrom($url);

        return parent::deleteEvent($url);
    }

	protected function getAuthCredential()
	{
		return 'Basic ' . base64_encode(
            $this->username . ':' . $this->password
        );
	}

	protected function buildUrlFrom($calendarName)
	{
		if (str_contains($calendarName, 'remote.php/dav/calendars')) {
			$calendarName = str_replace('remote.php/dav/', '', $calendarName);
			$url = $this->makeUrl($calendarName);
		} else {
			$url = $this->makeUrl(
				"calendars/{$this->username}/{$calendarName}"
			);
		}

		return $url;
	}
}
