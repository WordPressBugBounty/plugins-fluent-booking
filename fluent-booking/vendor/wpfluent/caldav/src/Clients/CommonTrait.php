<?php

namespace FluentBooking\Package\CalDav\Clients;

use DateTime;
use DateTimeZone;
use DOMXPath;
use DOMDocument;
use FluentBooking\Package\CalDav\ICal\ICal;

trait CommonTrait
{
	protected $options = [
		'format_date_times' => false,
		'date_time_format' => null,
		'timezone' => null,
	];


	protected $datetimeFields = [
		'dtstart',
		'dtend',
		'dtstamp',
		'created',
		'last_modified'
	];
	
	public function formatDateTimes($events)
	{
		$tz = $this->getTimezone();
		
		$format = $this->getDateTimeFormat();

		foreach ($events as $event) {

			foreach ($this->datetimeFields as $prop) {
			
				$dt = new DateTime($event->{$prop}, $tz);

				$event->{$prop} = $dt->format($format);
			}
		}

		return $events;
	}

	protected function getTimezone()
	{
		if ($tz = $this->getOption('timezone')) {
			return new DateTimeZone($tz);
		}
		
		return wp_timezone();
	}

	protected function getDateTimeFormat()
	{
		if ($format = $this->getOption('date_time_format')) {
			return $format;
		}

		return get_option('date_format');
	}

	public function setOptions($options = [])
	{
		foreach ($options as $key => $value) {
			$this->options[$key] = $value;
		}
	}

	public function getOptions($options = [])
	{
		return $this->options;
	}

	public function setOption($key, $value)
	{
		$this->options[$key] = $value;
	}

	public function getOption($key, $default = null)
	{
		if (isset($this->options[$key])) {
			return $this->options[$key];
		}

		return $default;
	}

	protected function extractReportinformation($content)
	{
		$doc = new DOMDocument();

        $doc->loadXML($content);

        $xpath = new DOMXPath($doc);

        $xpath->registerNamespace('d', 'DAV:');

        $xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');
		
		$xpath->registerNamespace('caldav', 'urn:ietf:params:xml:ns:caldav');

        if ($href = $xpath->query('//d:href')->item(0)) {
        	$href = $href->nodeValue;
        }
        
        if ($status = $xpath->query('//d:status')->item(0)) {
        	$status = $status->nodeValue;
        }
        
        if ($etag = $xpath->query('//d:getetag')->item(0)) {
        	$etag = $etag->nodeValue;
        }

        $events = [];

        foreach ($xpath->query('//cal:calendar-data') as $cal) {
            $events = array_merge(
            	$events, $this->extractSingleEvent($cal->nodeValue)
            );
        }

        if ($this->getOption('format_date_times')) {
        	$events = $this->formatDateTimes($events);
        }

        return compact('href', 'etag', 'status', 'events');
	}

	protected function extractSingleEvent($content)
	{
		$ical = new ICal($content, [
    		'defaultTimeZone' => 'UTC'
    	]);

    	return $ical->events();
	}
}
