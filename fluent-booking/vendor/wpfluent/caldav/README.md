# A simple CalDav client.

Right now tested with [nextcloude](https://nextcloud.com/), [icloud](https://developer.apple.com/documentation/devicemanagement/caldav) and [Google](https://developers.google.com/calendar/caldav/v2/guide).


# How to use:

At first install it as a `wpfluent-package` using `wpf package:install caldav`

# Updated the API (New way to use):

```php

// Create the client
$client = new Google($base_url, $username, $password);
$client = new ICloud($base_url, $username, $password);
$client = new NextCloud($base_url, $username, $password);
```

**After creating the client, use case is same, for example:**

```php
// Get all calendars
$calendars = $client->getCalendars();
```
Once you get all the calendars, you'll get an array where each array will contain something like this:

```php
{
    displayname: "Home",
    events: [],
    getctag: "HwoQEgwAAGj/iqqR3wABAAEYARgAIhUI1YCtyZ6LsNFMEPLT3MannZbujAEoAA==",
    href : "/10337091274/calendars/home/"
}
```

You should save these to use later, otherwise you'll need to call the `$client->getCalendars()` method again and again, which is expensive. If you save the calendar's data then you may make a calendar instance from this data, for example:

```php
$calendar = $client->createCalendar({
    displayname: "Home",
    events: [],
    getctag: "HwoQEgwAAGj/iqqR3wABAAEYARgAIhUI1YCtyZ6LsNFMEPLT3MannZbujAEoAA==",
    href : "/10337091274/calendars/home/"
});

// Add an event into the first calendar
return $calendars->addEvent([...]);

// Get all events from the first calendar
return $calendars->getEvents();
```

Once you have the calendar intance, you can use this instance to get or add events. On the initial call to `getCalendars()`, you don't need to make an instance of the calendar because each item will be an intance of a calendar, for example:

```php
// Get all calendars
$calendars = $client->getCalendars();

// Add an event into the first calendar
return $calendars[0]->addEvent([...]);

// Get all events from the first calendar
return $calendars[0]->getEvents();

// Get a single calendar from the server
$calendar = $client->getCalendar($calendars[1]->href);

// Add an event to the calendar
return $calendar->addEvent([...]);

// Get all events from the calendar
return $calendar->getEvents();
```

**Alternatively you may create an event using the following approach:**

```php
$event = $calendar->createEvent();

$event->dtstart = '2023-11-15 01:30:00';
$event->dtend = '2023-11-16 03:00:00';
$event->status = 'confirmed';
$event->summary = 'Another Event';
$event->description = 'A short description about the another event.';
$event->location = 'Office';

// Using $calendar->addEvent method
if ($calendar->addEvent($event)) {
    return $calendar->getEvent($event->getUid());
}
// Or using $event->save method
$event =  $event->save();
```

**To update an existing event:**

```php
// Pass the saved calendar data
$calendar = $client->createCalendar([
    'href' => '/10337091274/calendars/work/',
    'displayname' => 'Work',
    'getctag' => 'HwoQEgwAAGlcwC6jWgAAAAAYARgAIhUI3pahwNDCrtloEJSXjYThi4nzhwEoAA==',
]);

$events = $calendar->getEvents();

// Update the first event
$events[0]->summary = 'New Summary';
$updatedEevent = $events[0]->save();
```

**To delete an existing event:**

```php
$event->delete();
$calendar->deleteEvent($eventUid);
```

**Note:** If the `$event->save` method is used then you'll get back the newly created or updated event back.

Available setable properties in an event:

```php
$event = [
    'dtstart' => '2023-10-15 01:30:00', // or new \DateTime('2023-10-15 03:00:00')
    'dtend' => '2023-10-16 03:00:00',
    'status' => 'confirmed',
    'summary' => 'Another Event',
    'description' => 'A short description about the another event.',
    'location' => 'Office',
    'categories' => ['category-1', 'category-2', 'cat-3'],
    'attendees' => [
        [
            'name' => 'Arif',
            'email' => 'arif@ymail.com',
            'role' => 'participant',
            'rsvp' => true,
            'partstat' => 'accepted'
        ],
        [
            'name' => 'Jewel',
            'email' => 'jewel@ymail.com',
            'role' => 'chair',
            'rsvp' => true,
            'partstat' => 'accepted'
        ]
    ],
    'organizer' => [
        'name' => 'Jinn Doe',
        'email' => 'jinn@ymail.com',
        'sent_by' => 'joe@gmail.com'
    ],
    'alarm' => [
        'action' => 'display',
        'description' => 'Reminder of the Event.',
        'trigger' => [
            'days' => 1,
            'hours' => 2,
            'minutes' => 30

        ]
    ],
    // For recurring events: birthday, daily meeting, weekly meeting and so on.
    'repeat' => [
        // DAILY, WEEKLY, MONTHLY, YEARLY
        'freq' => 'weekly',
        // Interval between every event day/week/month/year
        'interval' => 2,
        // How many times the event will recur
        'count' => 3,
        // Only for weekly freq (MO = Monday, WE = Wednesday
        'byday' => ['mo', 'we'],
        // Only for monthly freq (5 for the 5th day of the month
        'bymonthday' => [5],
        // Specifies the month for yearly recurrence (7 for July).
        'bymonth' => 7,
        // Specifies the occurrence within the set of selected days (Monday and Wednesday).
        // "-1" means the last occurrence of those days in the month.
        'bysetpos' => -1
    ]
];
```