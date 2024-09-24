<?php

namespace FluentBooking\Package\CalDav\Entities;

use FluentBooking\Package\CalDav\ICal\Event as ICalEvent;

class Calendar implements \JsonSerializable
{
	protected $data = [];

	protected $client = null;
	
	protected $events = [];

	protected $dateTimeFields = ['dtstart', 'dtend'];

	public function __construct(array $data, $client)
	{
		$this->data = $data;
		$this->client = $client;
	}

	public function getData()
	{
		return $this->data;
	}

	public function __get($key)
	{
		if (isset($this->data[$key])) {
			return $this->data[$key];
		}
	}

	public function createEvent()
	{
		$event = new Event(new ICalEvent([]), []);

		$event->setCalendar($this);

		return $event;
	}

	public function getEvents($start = null, $end = null)
	{
		$dateRange = [];
		
		if (isset($start, $end)) {
			$dateRange = [$start, $end];
		}

		$events = $this->client->getEvents(
			$this->data['href'], $dateRange
		);

		$meta = [
			'href' => $events['href'],
			'etag' => $events['etag'],
			'status' => $events['status'],
		];

		$this->events = array_map(function($e) use ($meta) {
			$data = get_object_vars($e);
			$event = new Event(new ICalEvent($data), $meta);
			$event->setCalendar($this);
			return $event;
		}, $events['events']);

		return $this->events;
	}

	public function getEvent($uid)
	{
		$url = rtrim($this->data['href'], '/') . '/' . $uid . '.ics';
		
		$events = $this->client->getEvent($url);

		$meta = [
			'href' => $url,
			'etag' => $events['etag'],
			'status' => $events['status'],
		];

		$this->events = array_map(function($e) use ($meta) {
			$data = get_object_vars($e);
			$event = new Event(new ICalEvent($data), $meta);
			$event->setCalendar($this);
			return $event;
		}, $events['event']);

		return $this->events ? reset($this->events) : $this->events;
	}

	public function addEvent($event)
	{
		if ($event instanceof Event) {
			$event = $event->getIcalEvent();
		}

		$response = $this->client->addEvent(
			$this->data['href'], $event
		);

		if (is_array($response) && array_key_exists('code', $response))  {
			return intval($response['code'] / 100) == 2;
		}
	}

	public function deleteEvent($uid)
	{
		if ($uid instanceof Event) {
			$uid = $uid->getUid();
		}

		$response = $this->client->deleteEvent(
			rtrim($this->data['href'], '/') . '/' . $uid . '.ics'
		);

		if (is_array($response) && array_key_exists('code', $response))  {
			return $response['code'] == 204;
		}
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		$this->data['events'] = $this->events;
		
		return $this->data;
	}
}
