<?php

namespace FluentBooking\App\Http\Controllers;

use Exception;
use FluentBooking\App\Models\Meta;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\App\Services\Integrations\CalendarIntegrationService;

class CalendarIntegrationController extends Controller
{
    public function index(CalendarIntegrationService $integrationService, $calendarId, $eventId)
    {
        try {
            $calendarEvent = $this->getCalendarEvent($calendarId, $eventId);
            $settings = $integrationService->get($eventId);

            $settings['smart_codes'] = [
                'texts' => Helper::getEditorShortCodes($calendarEvent),
                'html'  => Helper::getEditorShortCodes($calendarEvent, true)
            ];

            return $this->sendSuccess($settings);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function find(CalendarIntegrationService $integrationService, $calendarId, $slotId, $integrationId)
    {
        try {
            $this->getCalendarEvent($calendarId, $slotId);
            $data = $this->request->all();
            $data['slot_id'] = $slotId;
            $integration = $integrationService->find($data);
            return $this->sendSuccess($integration);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function update(CalendarIntegrationService $integrationService, $calendarId, $slotId, $integrationId)
    {

        $data = $this->request->all();
        $data['slot_id'] = $slotId;
        $data['integration_id'] = $integrationId;

        try {
            $integration = $integrationService->update($data);
            return $this->sendSuccess($integration);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        }
    }

    public function delete(CalendarIntegrationService $integrationService, $calendarId, $slotId, $integrationId)
    {
        try {
            $deleted = $integrationService->delete($integrationId, $slotId);

            if (!$deleted) {
                return $this->sendError([
                    'message' => __('Integration not found for this event.', 'fluent-booking'),
                ], 404);
            }

            return $this->sendSuccess([
                'message' => __('Successfully deleted the Integration.', 'fluent-booking'),
            ]);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function cloneIntegrations(CalendarIntegrationService $integrationService, $calendarId, $slotId)
    {
        $calendarEvent = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($slotId);

        $fromEventId = intval($this->request->get('from_event_id'));

        if (!$fromEventId || !PermissionManager::canUpdateCalendarEvent($fromEventId)) {
            return $this->sendError([
                'message' => __('You do not have permission to clone from the selected event.', 'fluent-booking')
            ], 403);
        }

        $fromEventIntegrations = Meta::where('object_id', $fromEventId)
            ->where('object_type', 'integration')
            ->get();
        
        if ($fromEventIntegrations->isEmpty()) {
            return $this->sendError([
                'message' => __('Integrations not found', 'fluent-booking')
            ], 422);
        }

        foreach ($fromEventIntegrations as $feed) {
            $cloneIntegration = $feed->replicate();
            $cloneIntegration->object_id = $calendarEvent->id;
            $cloneIntegration->save();
        }

        return [
            'message' => __('Integrations have been successfully cloned.', 'fluent-booking')
        ];
    }

    public function integrationListComponent($calendarId, $slotId, $integrationId)
    {
        try {
            $this->getCalendarEvent($calendarId, $slotId);
            $integrationName = $this->request->get('integration_name');
            $listId = $this->request->get('list_id');
            $merge_fields = false;

            $merge_fields = apply_filters('fluent_booking/get_integration_merge_fields_' . $integrationName, $merge_fields, $listId, $slotId);

            return $this->sendSuccess([
                'merge_fields' => $merge_fields,
            ]);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function getConfigFieldOptions($calendarId, $calendarEventId, $integrationId)
    {
        try {
            $this->getCalendarEvent($calendarId, $calendarEventId);
            $integrationName = $this->request->get('integration_name');
            $settings = $this->request->get('settings');
            
            $fieldOptions = apply_filters('fluent_booking/get_integration_config_field_options_' . $integrationName, $settings, $calendarEventId);

            return $this->sendSuccess([
                'field_options' => $fieldOptions,
            ]);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function getCalendarEvent($calendarId, $eventId)
    {
        return CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);
    }
}
