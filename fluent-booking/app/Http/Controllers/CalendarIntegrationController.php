<?php

namespace FluentBooking\App\Http\Controllers;

use Exception;
use FluentBooking\App\Models\Meta;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\Integrations\CalendarIntegrationService;

class CalendarIntegrationController extends Controller
{
    public function index(CalendarIntegrationService $integrationService, $calendarId, $eventId)
    {
        try {
            $calendarEvent = CalendarSlot::findOrFail($eventId);
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
            $id = $this->request->get('integration_id');
            $integrationService->delete($id);

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
            'message' => __('Integrations has been successfully cloned.', 'fluent-booking')
        ];
    }

    public function integrationListComponent($calendarId, $slotId, $integrationId)
    {
        try {
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
}
