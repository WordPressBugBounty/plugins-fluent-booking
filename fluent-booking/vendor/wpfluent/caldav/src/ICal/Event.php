<?php

namespace FluentBooking\Package\CalDav\ICal;

use Exception;
use DateTime;
use DateTimeZone;

class Event implements \JsonSerializable
{
	protected $data = [];
	
	protected $generatedEventData = '';

	protected $dateTimeFormat = 'Ymd\THis\Z';

	public function __construct($array = [])
	{
		$this->data = $array;

		$this->setDefaults();
	}

	public function preCompile()
	{
		return $this->prepareEventData();
	}

	protected function prepareEventData()
	{
		$this->setDateTime();
		$this->setCategories();
		$this->setAttendees();
		$this->setOrganizer();
		$this->setAlarm();
		$this->setRecurrence();
		$this->setAttachments();
	}

	protected function setDefaults()
	{
		if (!isset($this->data['uid'])) {
			$currentDateTime = $this->getCurrentDateTime();
			$this->data['created'] = $currentDateTime;
			$this->data['dtstamp'] = $currentDateTime;
			$this->data['last-modified'] = $currentDateTime;
			$this->data['uid'] = md5(__NAMESPACE__) . '-' . wp_generate_uuid4();
		}
	}

	protected function getCurrentDateTime()
	{
		return (
			new DateTime('now', new DateTimeZone('UTC'))
		)->format($this->dateTimeFormat);
	}

	protected function setDateTime()
	{
		$dates = [
			'dtstart' => $this->getDtStart(),
			'dtend' => $this->getDtEnd()
		];

		foreach ($dates as $key => $value) {

			$dateTime = $value;

			if (is_string($value)) {
				$dateTime = new DateTime($value, new DateTimeZone('UTC'));
			}

			if ($dateTime instanceof DateTime) {
				$this->data[$key] = $dateTime->format($this->dateTimeFormat);
			} else {
				throw new Exception("Invalid value: {$value} for {$key}.");
			}
		}
	}

	protected function getDtStart()
	{
		if (!isset($this->data['dtstart'])) {
			if (!isset($this->data['DTSTART'])) {
				throw new Exception('The Event needs an dtstart/DTSTART property.');
			} else {
				$dtStart = $this->data['DTSTART'];
			}
		} else {
			$dtStart = $this->data['dtstart'];
		}

		return $dtStart;
	}

	protected function getDtEnd()
	{
		if (!isset($this->data['dtend'])) {
			if (!isset($this->data['DTEND'])) {
				throw new Exception('The Event needs an dtend/DTEND property.');
			} else {
				$dtEnd = $this->data['DTEND'];
			}
		} else {
			$dtEnd = $this->data['dtend'];
		}

		return $dtEnd;
	}

	protected function setCategories()
	{
		if (!isset($this->data['categories'])) {
			return;
		}

		$categories = $this->data['categories'];

		if (is_array($categories)) {
			$categories = implode(',', $categories);
		}

		$categories = str_replace(', ', ',', $categories);
		
		$this->data['categories'] = $categories;
	}

	protected function setAttendees()
	{
		if (!isset($this->data['attendees'])) {
			return;
		}

		foreach ($this->data['attendees'] as &$attendee) {
			
			if (!isset($attendee['email'])) {
				throw new Exception('An attendee must have an email.');
			}

			if (!isset($attendee['name'])) {
				$attendee['name'] = $attendee['email'];
			}

			$str = '';
			
			foreach ($attendee as $key => $value) {	
				$key = strtolower($key);

				if ($key == 'name') {
					$str .= "CN={$value};";
				} elseif ($key == 'role') {
					$value = strtoupper($value);
					$str .= "ROLE={$value};";
				}  elseif ($key == 'rsvp') {
					if (is_bool($value)) {
						$value = $value === true ? 'TRUE' : 'FALSE';
					}
					$value = strtoupper($value);
					$str .= "RSVP={$value};";
				} elseif ($key == 'partstat') {
                    $value = strtoupper($value);
                    $str .= "PARTSTAT={$value};";
                }
			}

			$str = rtrim($str, ';');

			$str .= ":mailto:{$attendee['email']}";

			$attendee['str'] = $str;
		}
	}

	protected function setOrganizer()
	{
		if (!isset($this->data['organizer'])) {
			return;
		}

		$organizer = &$this->data['organizer'];

		if (!isset($organizer['email'])) {
			throw new \Exception('An organizer must have an email.');
		}

		if (!isset($organizer['name'])) {
			$organizer['name'] = $organizer['email'];
		}

		$str = '';

		foreach ($organizer as $key => $value) {
			
			$key = strtolower($key);

			if ($key == 'name') {
				$str .= "CN={$value};";
			} elseif ($key == 'language') {
				$str .= "LANGUAGE={$value};";
			}
		}

		if (isset($organizer['sent_by'])) {
			$str .= "SENT-BY=mailto:{$organizer['sent_by']}";
		}

		$str = rtrim($str, ';');

		$str .= ":mailto:{$organizer['email']}";

		$organizer['str'] = $str;
	}

	protected function setAlarm()
	{
		if (!isset($this->data['alarm'])) {
			return;
		}

		$alarm = &$this->data['alarm'];

		$action = strtoupper($alarm['action']);

		$alarm['action'] = $action;

		if (isset($alarm['trigger'])) {
			
			// For exact time of the event
			if ($alarm['trigger'] === true) {
				$alarm['trigger_str'] = "RELATED=START:PT0S";

				return;
			}

			if (!isset($alarm['trigger']['after'])) {

				if (isset($alarm['trigger']['before'])) {
					$isBefore = $alarm['trigger']['before'] === true;
				} else {
					$isBefore = true;
				}
			} else {
				$isBefore = $alarm['trigger']['after'] === false;
			}

			$period = $isBefore ? '-P' : 'P';
			
			$time = '';

			foreach ($alarm['trigger'] as $key => $value) {
				if ($key == 'years' && is_numeric($value) && $value) {
					$period = $period . $value . 'Y';
				} elseif ($key == 'months' && is_numeric($value) && $value) {
					$period = $period . $value . 'M';
				} elseif ($key == 'days' && is_numeric($value) && $value) {
					$period = $period . $value . 'D';
				} elseif ($key == 'hours' && is_numeric($value) && $value) {
					$time = $time . $value . 'H';
				} elseif ($key == 'minutes' && is_numeric($value) && $value) {
					$time = $time . $value . 'M';
				} elseif ($key == 'seconds' && is_numeric($value) && $value) {
					$time = $time . $value . 'S';
				}
			}
		}

		if ($time) {
			$period = $period . 'T' . $time;
		}
		
		$alarm['trigger_str'] = "RELATED=START:{$period}";
	}

	protected function setRecurrence()
	{
		if (!isset($this->data['repeat'])) {
			return;
		}
		
		$str = '';
		
		$recurrence = &$this->data['repeat'];

		foreach ($recurrence as $key => $value) {

			$value = is_array($value) ? implode(',', $value) : $value;

			$str .= strtoupper($key) . '=' . strtoupper($value) . ';';
		}

		$str = rtrim($str, ';');

		$recurrence['str'] = $str;
	}

	public function setAttachments()
	{
		if (!isset($this->data['attachments'])) {
			return;
		}

		$attachments = &$this->data['attachments'];

		foreach ($this->data['attachments'] as $attachment) {

			if (!isset($attachment['url'], $attachment['name'])) {
				throw new Exception('An attachment requires a name and url key.');
			}

			$url = $attachment['url'];
			
			$filename = $attachment['name'];

			$attachments['list'][] = 'ATTACH;FILENAME=' . $filename . ':' . $url;
		}
	}

	public function compile()
	{
		$this->preCompile();

		if (!isset($this->data['sequence'])) {
			$this->data['sequence'] = 0;
		} else {
			$this->data['sequence'] += 1;
		}
		
		$this->data['last-modified'] = $this->getCurrentDateTime();

		$appNS = strtolower(explode('\\', __NAMESPACE__)[0]);
		$calendar[] = "BEGIN:VCALENDAR";
		$calendar[] = "VERSION:2.0";
		$calendar[] = "CALSCALE:GREGORIAN";
		$calendar[] = "PRODID:-//authlab.{$appNS}//CalDAV Client//EN";
		$calendar[] = "BEGIN:VEVENT";
		$calendar[] = "CREATED:{$this->data['created']}";
		$calendar[] = "DTSTAMP:{$this->data['dtstamp']}";
		$calendar[] = "LAST-MODIFIED:{$this->data['last-modified']}";
		$calendar[] = "SEQUENCE:{$this->data['sequence']}";
		$calendar[] = "UID:{$this->data['uid']}";
		$calendar[] = "DTSTART:{$this->data['dtstart']}";
		$calendar[] = "DTEND:{$this->data['dtend']}";
		
		if (isset($this->data['transp'])) {
			$calendar[] = "TRANSP:{$this->data['transp']}";
		} else {
			$calendar[] = "TRANSP:TRANSPARENT";
		}

		if (isset($this->data['status'])) {
			$eventStatus = strtoupper($this->data['status']);
			$calendar[] = "STATUS:{$eventStatus}";
		}

		if (isset($this->data['summary'])) {
			$calendar[] = "SUMMARY:{$this->data['summary']}";
		}

		if (isset($this->data['description'])) {
			$eventDescription = str_replace("\n", " ", $this->data['description']);
			$calendar[] = "DESCRIPTION:{$eventDescription}";
		}

		if (isset($this->data['location'])) {
			$calendar[] = "LOCATION:{$this->data['location']}";
		}

		if (isset($this->data['categories'])) {
			$calendar[] = "CATEGORIES:{$this->data['categories']}";
		}

		if (isset($this->data['repeat'])) {
			$calendar[] = "RRULE:{$this->data['repeat']['str']}";
		}

		if (isset($this->data['attendees'])) {
			foreach ($this->data['attendees'] as $attendee) {
				$calendar[] = "ATTENDEE;{$attendee['str']}";
			}
		}

		if (isset($this->data['organizer'])) {
			$calendar[] = "ORGANIZER;{$this->data['organizer']['str']}";
		}

		if (isset($this->data['attachments'])) {
			$calendar[] = implode("\n", $this->data['attachments']['list']);
		}

		if (isset($this->data['alarm'])) {
			$calendar[] = "BEGIN:VALARM";
			
			if (isset($this->data['alarm']['action'])) {
				$calendar[] = "ACTION:{$this->data['alarm']['action']}";
			}

			if (isset($this->data['alarm']['description'])) {

				$alarmDescription = str_replace(
					"\n", " ", $this->data['alarm']['description']
				);
				
				$calendar[] = "DESCRIPTION:{$alarmDescription}";
			}

			if (isset($this->data['alarm']['trigger'])) {
				$calendar[] = "TRIGGER;{$this->data['alarm']['trigger_str']}";
			}

			$calendar[] = "END:VALARM";
		}

		$calendar[] = "END:VEVENT";
		$calendar[] = "END:VCALENDAR";

		$this->generatedEventData = implode("\n", $calendar);

		return $this->generatedEventData;
	}

	public function getUid()
	{
		return $this->data['uid'];
	}

	public function __get($key)
	{
		return $this->data[$key];
	}

	public function __set($key, $value)
	{
		$this->data[$key] = $value;
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		return $this->data;
	}
}
