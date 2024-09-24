<?php

namespace FluentBooking\Package\CalDav\Clients;

use Exception;
use FluentBooking\Package\CalDav\ICal\Event;
use FluentBooking\Package\CalDav\ICal\XmlParser;
use FluentBooking\Package\CalDav\Entities\Calendar;
use FluentBooking\Framework\Foundation\WPException;

class Client
{
	use CommonTrait;

	protected $username = null;
	
	protected $password = null;
	
	protected $baseUrl = null;

	public function __construct($credentials)
	{
        if (!is_array($credentials)) {
	        $credentials = array_combine(
	            ['base_url', 'username', 'password'], func_get_args()
	        );
		}

		if ($this->validateCredentials($credentials)) {
			$this->username = $credentials['username'];
			$this->password = $credentials['password'];
			$this->baseUrl = rtrim($credentials['base_url'], '/');
		}
	}

	public function validateCredentials($credentials)
	{
		$isset = isset(
			$credentials['username'],
			$credentials['password'],
			$credentials['base_url']
		);

		if (!$isset) {
			throw new Exception('Invalid credentials given.');
		}

		return true;
	}
	
	protected function makeUrl($param = '')
	{
		$param = trim($param, '/');

		return "{$this->baseUrl}/{$param}";
	}

	public function createCalendar($data)
	{
		return new Calendar($data, $this);
	}

	public function  getCalendarsInfo($url)
    {
        $payload = '<?xml version="1.0" encoding="utf-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav">
            <d:prop>
                <d:resourcetype />
                <d:displayname />
                <cs:getctag />
                <c:supported-calendar-component-set />
            </d:prop>
        </d:propfind>';

        $response = $this->propfind($url, [
			'payload' => $payload
		], ['depth' => 1]);

        $content = wp_remote_retrieve_body($response);

        $list = XmlParser::parse($content);

        $calendars = [];

        foreach ($list as $cal) {
            
            foreach ($cal['propstat'] as $propstat) {

                if (isset($propstat['status']) && is_array($propstat['status'])) {
                    $okay = str_contains($propstat['status'][0], '200 OK');
                }
                
                foreach ($propstat['prop'] as $prop) {

                    if ($okay && isset($prop['resourcetype'])) {
                        
                        $attrs = [];

                        if (isset($prop['supported-calendar-component-set'])) {
                            $sccs = $prop['supported-calendar-component-set'][0];
                        
                            foreach ($sccs['comp'] as $key => $value) {
                                $attrs[] = $value['attributers']['name'];
                            }
                        }

                        if (
                            array_key_exists('calendar', $prop['resourcetype'][0])
                            && in_array('VEVENT', $attrs)
                        ) {
                            $calendars[] = [
                                'href' => $cal['href'][0],
                                'displayname' => $prop['displayname'][0],
                                'getctag' => $prop['getctag'][0]
                            ];
                        }
                    }
                }
            }
        }
        
        return array_map(function($calendar) {
            return new Calendar($calendar, $this);
        }, $calendars);
    }

    public function getCalendarInfo($url)
    {
        $payload = '<?xml version="1.0" encoding="utf-8" ?>
        <d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">
          <d:prop>
             <d:displayname />
             <cs:getctag />
             <d:getetag />
          </d:prop>
        </d:propfind>';

        $response = $this->propfind(
        	$url, ['payload' => $payload], ['depth' => 1]
        );

        $content = wp_remote_retrieve_body($response);

        $content = XmlParser::parse($content);

        $items = [];

        foreach ($content as $key => $item) {

            foreach ($item['propstat'] as $propstatKey => $propstat) {

                if (!str_contains($propstat['status'][0], '200 OK')) {
                    continue;
                }

                $items[$propstatKey][$key]['href'] = $item['href'][0];

                foreach($propstat as $prop) {

                    foreach($prop as $k => $p) {

                        if (isset($p['displayname'])) {
                            $items[$propstatKey][$key]['displayname'] = $p['displayname'][0];
                        }

                        if (isset($p['getctag'])) {
                            $items[$propstatKey][$key]['getctag'] = $p['getctag'][0];
                        }

                        if (isset($p['getetag'])) {
                            $items[$propstatKey][$key]['getetag'] = $p['getetag'][0];
                        }
                    }
                }
            }

        }

        $items = reset($items);

        $items = array_merge($items[0], ['etag' => $items[1]]);

        return new Calendar(isset($items[0]) ? $items[0] : $items, $this);
    }

	public function getEventsFrom($url, $dateRange = [])
	{
		$dateFilter = '';

		if (!empty($dateRange)) {
			$startDate = $dateRange[0];
			$endDate = $dateRange[1];

			$dateFilter = '<c:time-range start="' . $startDate->format('Ymd\THis\Z') . '" end="' . $endDate->format('Ymd\THis\Z') . '" />';
		}

		$payload = '<?xml version="1.0" encoding="utf-8" ?>
        <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
            <d:prop>
                <d:getetag />
                <c:calendar-data />
            </d:prop>
            <c:filter>
                <c:comp-filter name="VCALENDAR">
                    <c:comp-filter name="VEVENT">'. $dateFilter .'</c:comp-filter>
                </c:comp-filter>
            </c:filter>
        </c:calendar-query>';

		$response = $this->report($url, [
			'payload' => $payload
		], ['depth' => 1]);

		$content = wp_remote_retrieve_body($response);

		return $this->extractReportinformation($content);
	}

	public function getEventFrom($url)
	{
		$response = $this->get($url, [], [
			'depth' => 1,
			'Content-Type' => 'application/json'
		]);

		$content = wp_remote_retrieve_body($response);

		return [
			'event' => $this->extractSingleEvent($content),
			'etag' => wp_remote_retrieve_header($response, 'etag'),
			'status' => 'HTTP/1.1 200 OK'
		];
	}

	public function addEventTo($url, $payload)
	{
        $event = $payload instanceof Event ? $payload : new Event($payload);

        $uid = $event->getUid();

        $url = rtrim($url, '/') . '/'. $uid.'.ics';

        $response = $this->put($url, [
            'payload' => $event->compile()
        ], ['Content-Type' => 'text/calendar; charset=utf-8']);

		return $response['response'];
	}

	public function deleteEvent($url)
	{
		// dd($url);
        $response = $this->delete(
        	$url, [], ['Content-Type' => 'application/json']
        );

		return $response['response'];
	}

	public function propfind($url, $args, $headers = [])
	{
		return $this->request('PROPFIND', $url, $args, $headers);
	}
	
	public function report($url, $args, $headers = [])
	{
		return $this->request('REPORT', $url, $args, $headers);
	}

	public function put($url, $args, $headers = [])
	{
		return $this->request('PUT', $url, $args, $headers);
	}

	public function get($url, $args = [], $headers = [])
	{
		return $this->request('GET', $url, $args, $headers);
	}

	public function delete($url, $args = [], $headers = [])
	{
		return $this->request('DELETE', $url, $args, $headers);
	}

	public function request($method, $url, $args, $headers = [])
	{
        $response = $this->sendRequest($method, $url, $args, $headers);

		if (is_wp_error($response)) {
		    throw new WPException($response);
		} else {
			$statusCode = wp_remote_retrieve_response_code($response);

			if (intval($statusCode / 100) !== 2) {
				$this->throwException($statusCode, $response);
			}
		}

		return $response;
	}

	protected function sendRequest($method, $url, $args, $headers)
	{
		// if ($method == 'DELETE') dd($url, $this->prepareArgs($method, $args, $headers));
		return wp_remote_request(
			$url, $this->prepareArgs($method, $args, $headers)
		);
	}

	protected function prepareArgs($method, $args, $headers)
	{
		$ns = strtolower(substr(__NAMESPACE__, 0, strpos(__NAMESPACE__, '\\')));

		$args = array_merge($args, [
			'method' => $method,
			'headers' => [
				'User-Agent' => 'wpfluent-' . $ns
			]
		]);

		if (!isset($headers['Authorization'])) {
        	$headers['Authorization'] = $this->getAuthCredential();
        }

        $args['headers'] = array_merge($args['headers'], $headers);

        if (isset($args['payload'])) {
        	$args['body'] = $args['payload'];
        }

        unset($args['payload']);

        return $args;
	}

	protected function throwException($statusCode, $response)
	{
		$message = wp_remote_retrieve_response_message($response);

		if (class_exists($class = "WpOrg\Requests\Exception\Http\Status{$statusCode}")) {
			throw new $class($message);
		}

		throw new Exception($message, $statusCode);
	}
}
