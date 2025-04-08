<?php

defined('ABSPATH') || exit;

/**
 * @var $router FluentBooking\Framework\Http\Router
 */

$router->prefix('calendars')->withPolicy('CalendarPolicy')->group(function ($router) {

    $router->get('/', 'CalendarController@getAllCalendars')->meta('calendar_type', 'booking');

    $router->get('event-lists', 'CalendarController@getCalendarEventLists');

    $router->post('/', 'CalendarController@createCalendar');
    $router->post('check-slug', 'CalendarController@checkSlug');

    $router->get('/{id}', 'CalendarController@getCalendar')->int('id');
    $router->post('/{id}', 'CalendarController@updateCalendar')->int('id');
    $router->delete('/{id}', 'CalendarController@deleteCalendar')->int('id');

    $router->post('/{id}/events', 'CalendarController@createCalendarEvent')->int('id');
    $router->get('/{id}/event-schema', 'CalendarController@getEventSchema')->int('id');

    // Landing Page API
    $router->get('/{id}/sharing-settings', 'CalendarController@getSharingSettings')->int('id');
    $router->post('/{id}/sharing-settings', 'CalendarController@saveSharingSettings')->int('id');

    $router->post('/{id}/event-order', 'CalendarController@saveCalendarEventOrder')->int('id');

    $router->post('/{id}/clone-event/{event_id}', 'CalendarController@cloneCalendarEvent')->int('id')->int('event_id');

    $router->get('/{id}/events/{event_id}', 'CalendarController@getEvent')->int('id')->int('event_id');
    $router->put('/{id}/events/{event_id}', 'CalendarController@patchCalendarEvent')->int('id')->int('event_id');
    $router->delete('/{id}/events/{event_id}', 'CalendarController@deleteCalendarEvent')->int('id')->int('event_id');

    $router->get('/{id}/events/{event_id}/availability', 'CalendarController@getAvailabilitySettings')->int('event_id');
    $router->post('/{id}/events/{event_id}/details', 'CalendarController@updateEventDetails')->int('id')->int('event_id');
    $router->post('/{id}/events/{event_id}/availability', 'CalendarController@updateEventAvailability')->int('id')->int('event_id');
    $router->post('/{id}/events/{event_id}/limits', 'CalendarController@updateEventLimits')->int('id')->int('event_id');

    $router->get('/{id}/events/{event_id}/email-notifications', 'CalendarController@getEventEmailNotifications')->int('id')->int('event_id');
    $router->post('/{id}/events/{event_id}/email-notifications', 'CalendarController@saveEventEmailNotifications')->int('id')->int('event_id');
    $router->post('/{id}/events/{event_id}/email-notifications/clone', 'CalendarController@cloneEventEmailNotification')->int('id')->int('event_id');

    $router->get('/{id}/events/{event_id}/booking-fields', 'CalendarController@getEventBookingFields')->int('id')->int('event_id');
    $router->post('/{id}/events/{event_id}/booking-fields', 'CalendarController@saveEventBookingFields')->int('id')->int('event_id');
});

$router->prefix('admin')->withPolicy('AdminPolicy')->group(function ($router) {
    $router->get('remaining-hosts', 'AdminController@getRemainingHosts');
    $router->get('other-hosts', 'AdminController@getOtherHosts');
    $router->get('all-hosts', 'AdminController@getAllHosts');
});

$router->prefix('events')->withPolicy('CalendarEventPolicy')->group(function ($router) {
    $router->get('/{event_id}', 'EventController@getEvent')->int('event_id');
});

$router->prefix('bookings')->withPolicy('CalendarEventPolicy')->group(function ($router) {
    $router->get('/', 'BookingController@getBookings');
    $router->get('event/{event_id}', 'BookingController@getEvent')->int('event_id');
    $router->post('create/{event_id}', 'BookingController@createBooking')->int('event_id');
});

$router->prefix('schedules')->withPolicy('MeetingPolicy')->group(function ($router) {
    $router->get('/', 'SchedulesController@index'); // Need to check permission on the controller method
    $router->get('/{id}', 'SchedulesController@getBooking')->int('id');
    $router->delete('/{id}', 'SchedulesController@deleteBooking')->int('id');
    $router->get('/{id}/slot', 'SchedulesController@getScheduleSpot')->int('id');
    $router->put('/{id}', 'SchedulesController@patchBooking')->int('id');
    $router->get('/{id}/activities', 'SchedulesController@getBookingActivities')->int('id');
    $router->get('/{id}/meta-info', 'SchedulesController@getBookingMetaInfo')->int('id');
    $router->post('/{id}/send-confirmation-email', 'SchedulesController@sendConfirmationEmail')->int('id');

    $router->get('/group-bookings/{group_id}/attendees', 'SchedulesController@getGroupAttendees')->int('group_id');
});

$router->prefix('integrations')->withPolicy('SettingsPolicy')->group(function ($router) {
    $router->get('/', 'IntegrationController@index');
    $router->post('/', 'IntegrationController@update');
});

$router->prefix('settings')->withPolicy('SettingsPolicy')->group(function ($router) {
    $router->get('/general', 'SettingsController@getGeneralSettings');
    $router->post('/general', 'SettingsController@updateGeneralSettings');
    $router->post('/payment', 'SettingsController@updatePaymentSettings');
    $router->post('/theme', 'SettingsController@updateThemeSettings');
    $router->get('/menu', 'SettingsController@getSettingsMenu');
    $router->get('/global-modules', 'SettingsController@getGlobalModules');
    $router->post('/global-modules', 'SettingsController@updateGlobalModules');
    $router->get('/pages', 'SettingsController@getPages');
    $router->post('/addons-settings', 'SettingsController@saveAddonsSettings');
    $router->post('/install-plugin', 'SettingsController@installPlugin');
});

$router->prefix('availability')->withPolicy('AvailabilityPolicy')->group(function ($router) {
    $router->get('/', 'AvailabilityController@index');
    $router->post('/', 'AvailabilityController@createSchedule');

    $router->get('/{schedule_id}', 'AvailabilityController@getSchedule')->int('schedule_id');
    $router->get('/{schedule_id}/usages', 'AvailabilityController@getAvailabilityUsages')->int('schedule_id');
    $router->post('/{schedule_id}', 'AvailabilityController@updateSchedule')->int('schedule_id');
    $router->post('/{schedule_id}/update-title', 'AvailabilityController@updateScheduleTitle')->int('schedule_id');
    $router->post('/{schedule_id}/update-status', 'AvailabilityController@updateDefaultStatus')->int('schedule_id');
    $router->post('/{schedule_id}/clone', 'AvailabilityController@cloneSchedule')->int('schedule_id');
    $router->delete('/{schedule_id}', 'AvailabilityController@deleteSchedule')->int('schedule_id');

});

$router->prefix('reports')->withPolicy('UserPolicy')->group(function ($router) {
    $router->get('/', 'ReportController@getReports');
    $router->get('/graph-reports', 'ReportController@getGraphReports');
    $router->get('/activities', 'ReportController@getActivities');
});

$router->prefix('calendars')->withPolicy('CalendarPolicy')->group(function ($router) {
    $router->prefix('{id}/events/{event_id}/integrations')->group(function ($router) {
        $router->get('/', 'CalendarIntegrationController@index')->int('id')->int('event_id');
        $router->post('/clone', 'CalendarIntegrationController@cloneIntegrations')->int('id')->int('event_id');

        $router->prefix('{integration_id}')->group(function ($router) {
            $router->get('/', 'CalendarIntegrationController@find')->int('id')->int('event_id')->int('integration_id');
            $router->post('/', 'CalendarIntegrationController@update')->int('id')->int('event_id')->int('integration_id');
            $router->delete('/', 'CalendarIntegrationController@delete')->int('id')->int('event_id')->int('integration_id');
            $router->get('/merge-fields', 'CalendarIntegrationController@integrationListComponent');
            $router->get('/config-field-options', 'CalendarIntegrationController@getConfigFieldOptions');
        });
    });
});
