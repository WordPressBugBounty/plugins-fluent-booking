<?php

namespace FluentBooking\App\Services;

use FluentBooking\Framework\Support\Arr;
use FluentBooking\Framework\Support\Str;

class ConditionAssesor
{
    public static function evaluate(&$field, &$inputs)
    {
        $status = Arr::get($field, 'conditionals.status');


        $conditionals =  $status ? Arr::get($field, 'conditionals.conditions') : false;


        $hasConditionMet = true;

        if ($conditionals) {
            $toMatch = Arr::get($field, 'conditionals.type');


            foreach ($conditionals as $conditional) {

                $hasConditionMet = static::assess($conditional, $inputs);

                if($hasConditionMet && $toMatch == 'any') {
                    return true;
                }

                if ($toMatch === 'all' && !$hasConditionMet) {
                    return false;
                }
            }
        }

        return $hasConditionMet;
    }

    public static function assess(&$conditional, &$inputs)
    {
        if ($conditional['field']) {
            $inputValue = Arr::get($inputs, $conditional['field'], '');
            $conditionValue = Arr::get($conditional, 'value', '');
            $stringInputValue = is_scalar($inputValue) ? (string) $inputValue : '';
            $stringConditionValue = is_scalar($conditionValue) ? (string) $conditionValue : '';

            switch ($conditional['operator']) {
                case '=':
                    if(is_array($inputValue)) {
                       return in_array($conditionValue, $inputValue);
                    }
                    return $inputValue == $conditionValue;
                    break;
                case '!=':
                    if(is_array($inputValue)) {
                        return !in_array($conditionValue, $inputValue);
                    }
                    return $inputValue != $conditionValue;
                    break;
                case '>':
                    return $inputValue > $conditionValue;
                    break;
                case '<':
                    return $inputValue < $conditionValue;
                    break;
                case '>=':
                    return $inputValue >= $conditionValue;
                    break;
                case '<=':
                    return $inputValue <= $conditionValue;
                    break;
                case 'startsWith':
                    return Str::startsWith($stringInputValue, $stringConditionValue);
                    break;
                case 'endsWith':
                    return Str::endsWith($stringInputValue, $stringConditionValue);
                    break;
                case 'contains':
                    return Str::contains($stringInputValue, $stringConditionValue);
                    break;
                case 'doNotContains':
                    return !Str::contains($stringInputValue, $stringConditionValue);
                    break;
                case 'length_equal':
                    if(is_array($inputValue)) {
                        return count($inputValue) == $conditionValue;
                    }
                    return strlen($stringInputValue) == $conditionValue;
                    break;
                case 'length_less_than':
                    if(is_array($inputValue)) {
                        return count($inputValue) < $conditionValue;
                    }
                    return strlen($stringInputValue) < $conditionValue;
                    break;
                case 'length_greater_than':
                    if(is_array($inputValue)) {
                        return count($inputValue) > $conditionValue;
                    }
                    return strlen($stringInputValue) > $conditionValue;
                    break;
                case 'test_regex':
                    if(is_array($inputValue)) {
                        $stringInputValue = implode(' ', $inputValue);
                    }
                    $result = preg_match('/'.$stringConditionValue.'/', $stringInputValue);
                    return !!$result;
                    break;
            }
        }

        return false;
    }
}
