<?php

namespace FluentBooking\Package\CalDav\Clients;

use Exception;
use FluentBooking\Package\CalDav\ICal\XmlParser;
use FluentBooking\Package\CalDav\Entities\Calendar;

class ICloud extends Client
{
	public function  getCalendars()
    {
        $url = $this->getCalendarHomeset($this->getPrincipal());

        return parent::getCalendarsInfo($url);
    }

    public function getCalendar($url)
    {
    	$url = $this->makeUrl($url);

        return parent::getCalendarInfo($url);
    }

	public function getEvents($url, $dateRange = [])
	{
		$url = $this->makeUrl($url);

		return parent::getEventsFrom($url, $dateRange);
	}

    public function getEvent($uid)
    {
        $url = $this->makeUrl($uid);

        return parent::getEventFrom($url);
    }

	public function addEvent($url, $payload)
	{
		$url = $this->makeUrl($url);

        return parent::addEventTo($url, $payload);
	}

    public function deleteEvent($url)
    {
        $url = $this->makeUrl($url);

        return parent::deleteEvent($url);
    }

	protected function getAuthCredential()
	{
		return 'Basic ' . base64_encode(
            $this->username . ':' . $this->password
        );
	}

	public function getPrincipal()
    {
        $payload = '<?xml version="1.0" encoding="utf-8"?>
        <propfind xmlns="DAV:">
            <prop>
                <current-user-principal/>
            </prop>
        </propfind>';

        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
            'Content-Type' => 'application/xml',
        );

        $response = $this->propfind($this->baseUrl, [
            'payload' => $payload
        ]);

        if (!is_wp_error($response)) {
            if ($body = wp_remote_retrieve_body($response)) {
                $data = XmlParser::parse($body);
                $href = $data[0]['propstat'][0]['prop'][0]['current-user-principal'][0]['href'][0];
                return $href;
            } elseif ($res = $response['response']) {
                return $res;
            }
        } else {
            return $response;
        }
    }

    public function getCalendarHomeset($url)
    {
        $url = $this->makeUrl($url);

        $payload = '<?xml version="1.0" encoding="utf-8"?>
        <propfind xmlns="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
            <prop><c:calendar-home-set/></prop>
        </propfind>';

        $response = $this->propfind($url, [
            'payload' => $payload
        ]);

        if (!is_wp_error($response)) {
            if ($body = wp_remote_retrieve_body($response)) {
                $data = XmlParser::parse($body);
                $href = $data[0]['propstat'][0]['prop'][0]['calendar-home-set'][0]['href'][0];
                return $href;
            } elseif ($res = $response['response']) {
                return $res;
            }
        } else {
            return $response;
        }
    }
}
