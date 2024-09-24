<?php

namespace FluentBooking\App\Services\Integrations\Calendars;

/*
 * Caching class for string remote response data or any type of high intensive calculated data
 * This call will use fcal_meta table with object_type value is: performance_cache
 * the updated_at column will be used to set when the cache will be expired. (it's wierd but it's the optimal solution)
 * The created_at column will be used when the last time the data has been updated
 * the parent id meant to be the ID of the another row of the fcal_meta table.
 */

class CalendarCache
{
    private static $objectType = 'performance_cache';

    public static function getCache($parentId, $key, $callback, $cacheTime = 600, $renew = false) // 10 minutes check
    {
        $db = self::db();

        $row = $db->table('fcal_meta')
            ->where('object_type', self::$objectType)
            ->where('key', $key)
            ->where('object_id', $parentId)
            ->first();

        if ($row && $row->value !== '' && !$renew) {
            if (strtotime($row->updated_at) > time()) {
                return maybe_unserialize($row->value);
            }
        }
        
        $value = $callback();

        if (is_wp_error($value)) {
            return null;
        }

        // In the mean time it may got called and create the row
        $row = $db->table('fcal_meta')
            ->where('object_type', self::$objectType)
            ->where('key', $key)
            ->where('object_id', $parentId)
            ->first();

        if ($row) {
            if ($value === null) { //  value got nulled so let's return the previous data
                return maybe_unserialize($row->value);
            }

            $db->table('fcal_meta')
                ->where('id', $row->id)
                ->update([
                    'updated_at' => gmdate('Y-m-d H:i:s', time() + $cacheTime), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    'created_at' => gmdate('Y-m-d H:i:s'), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    'value'      => maybe_serialize($value)
                ]);
        } else {
            if ($value === null) {
                return null;
            }

            $db->table('fcal_meta')
                ->insert([
                    'updated_at'  => gmdate('Y-m-d H:i:s', time() + $cacheTime), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    'created_at'  => gmdate('Y-m-d H:i:s'), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    'object_type' => self::$objectType,
                    'key'         => $key,
                    'object_id'   => $parentId,
                    'value'       => maybe_serialize($value)
                ]);
        }

        return $value;
    }

    public static function deleteCache($parentId, $key)
    {
        $db = self::db();
        $db->table('fcal_meta')
            ->where('object_type', self::$objectType)
            ->where('key', $key)
            ->where('object_id', $parentId)
            ->delete();
    }

    public static function deleteAllParentCache($parentId)
    {
        $db = self::db();
        $db->table('fcal_meta')
            ->where('object_type', self::$objectType)
            ->where('object_id', $parentId)
            ->delete();
    }

    public static function deleteAllOldExpiredCache()
    {
        $db = self::db();
        $db->table('fcal_meta')
            ->where('object_type', self::$objectType)
            ->where('updated_at', '<', gmdate('Y-m-d H:i:s', strtotime('-15 days'))) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ->delete();
    }

    public static function db()
    {
        return (\FluentBooking\App\App::getInstance())->db;
    }

}
