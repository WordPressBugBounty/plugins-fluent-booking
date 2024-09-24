<?php

namespace FluentBooking\Package\CalDav\Entities;

class Event implements \JsonSerializable
{
	/**
	 * The \Entities\Calendar object
	 * @var null
	 */
	protected $calendar = null;

	/**
	 * The \ICal\Event object
	 * @var null
	 */
	protected $icalEvent = [];
	
	/**
	 * Information about the calebdar
	 * 
	 * @var array
	 */
	protected $meta = [];

	public function __construct($event, array $meta)
	{
		$this->meta = $meta;
		$this->icalEvent = $event;
	}

	/**
	 * Getter to get underlying Event Object
	 * @return \ICal\Event
	 */
	public function getIcalEvent()
	{
		return $this->icalEvent;
	}

	/**
	 * Dynamic property getter
	 * 
	 * @param  string $key
	 * @return mixed
	 */
	public function __get($key)
	{
		if (isset($this->meta[$key])) {
			return $this->meta[$key];
		}

		return $this->icalEvent->{$key};
	}

	/**
	 * Get the uid of underlying event
	 * @return [type] [description]
	 */
	public function getUid()
	{
		return $this->icalEvent->getUid();
	}

	/**
	 * Dynamic property setter
	 * 
	 * @param  string $key
	 * @param  mixed $value
	 * @return mixed
	 */
	public function __set($key, $value)
	{
		$this->icalEvent->{$key} = $value;
	}

	/**
	 * Create/Update an event
	 * 
	 * @return \Ical\Event
	 */
	public function save()
	{
		if ($this->calendar->addEvent($this->icalEvent)) {
			return $this->calendar->getEvent($this->icalEvent->getUid());
		}
	}

	public function delete()
	{
		return $this->calendar->deleteEvent(
			$this->icalEvent->getUid()
		);
	}

	/**
	 * Set the calendar object
	 * 
	 * @param \Entities\Calendar
	 */
	public function setCalendar($calendar)
	{
		$this->calendar = $calendar;
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		return $this->icalEvent;
	}
}
