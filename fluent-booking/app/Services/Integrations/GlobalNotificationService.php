<?php

namespace FluentBooking\App\Services\Integrations;

use FluentBooking\App\Models\Meta;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Services\ConditionAssesor;

class GlobalNotificationService
{
    public function checkCondition($parsedValue, $booking)
    {
        return true;

        $conditionSettings = Arr::get($parsedValue, 'conditionals');
        if (
            !$conditionSettings ||
            !Arr::isTrue($conditionSettings, 'status') ||
            !count(Arr::get($conditionSettings, 'conditions'))
        ) {
            return true;
        }

        return ConditionAssesor::evaluate($parsedValue, $booking);
    }

    public function getEntry($id, $form)
    {
        // $submission = Submission::find($id);
        // $formInputs = FormFieldsParser::getEntryInputs($form, ['admin_label', 'raw']);
        // return bookingParser::parseFormEntry($submission, $form, $formInputs);
    }

    /**
     * @param $feeds
     * @param $booking
     * @param $insertId
     *
     * @return array
     */
    public function getEnabledFeeds($feeds, $booking)
    {
        $enabledFeeds = [];
        foreach ($feeds as $feed) {
            $parsedValue = $feed->value;
            if (!$parsedValue || !Arr::isTrue($parsedValue, 'enabled')) {
                continue;
            }

            // Now check if conditions matched or not
            $isConditionMatched = $this->checkCondition($parsedValue, $booking);
            if ($isConditionMatched) {
                $item = [
                    'id'       => $feed->id,
                    'key'      => $feed->key,
                    'settings' => $parsedValue,
                ];
                $enabledFeeds[] = $item;
            }
        }

        return $enabledFeeds;
    }

    public function getNotificationFeeds($slotId, $feedMetaKeys)
    {
        return Meta::where('object_id', $slotId)->whereIn('key', $feedMetaKeys)->orderBy('id', 'ASC')->get();
    }
}
