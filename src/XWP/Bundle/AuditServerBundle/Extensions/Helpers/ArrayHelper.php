<?php

namespace XWP\Bundle\AuditServerBundle\Extensions\Helpers;

/**
 * Array helper.
 *
 * @since  v0.1
 */
class ArrayHelper
{
    /**
     * Convert snake_case type array's keys to camelCase type array's keys.
     * Inspired from from https://gist.github.com/goldsky/3372487
     *
     * @param   array   $array          Array to convert.
     * @param   array   $arrayHolder    Parent array holder for recursive array.
     * @param   array   $ignoreKeys    Keys to ignore.
     *
     * @return  array   camelCase array.
     */
    public function camelCaseKeys($array, $arrayHolder = [], $ignoreKeys = [])
    {
        $camelCaseArray = !empty($arrayHolder) ? $arrayHolder : [];
        foreach ($array as $key => $val) {
            if (!in_array($key, $ignoreKeys, true)) {
                $newKey = is_string($key) ? explode('_', $key): $key;

                if (is_array($newKey)) {
                    array_walk($newKey, create_function('&$v', '$v = ucwords($v);'));
                    $newKey = implode('', $newKey);
                    $newKey[0] = strtolower($newKey[0]);
                }

                if (!is_array($val)) {
                    $camelCaseArray[$newKey] = $val;
                } else {
                    $camelCaseArray[$newKey] = $this->camelCaseKeys($val, $array[$key]);
                }

                if ($newKey != $key) { // Not doing strict check to allow comparison between a number as a string and an integer.
                    unset($camelCaseArray[$key]);
                }
            } else {
                $underscoreArray[$key] = $val;
            }
        }
        return $camelCaseArray;
    }

    /**
     * Convert camelCase type array's keys to snake_case type array's keys.
     * Inspired from https://gist.github.com/goldsky/3372487
     *
     * @param   array   $array          Array to convert.
     * @param   array   $arrayHolder    Parent array holder for recursive array.
     * @param   array   $ignoreKeys    Keys to ignore.
     *
     * @return  array   snake_case array.
     */
    public function snakeCaseKeys($array, $arrayHolder = [], $ignoreKeys = [])
    {
        $underscoreArray = !empty($arrayHolder) ? $arrayHolder : [];
        foreach ($array as $key => $val) {
            if (!in_array($key, $ignoreKeys, true)) {
                $newKey = preg_replace('/[A-Z]/', '_$0', $key);
                $newKey = strtolower($newKey);
                $newKey = ltrim($newKey, '_');
                if (!is_array($val)) {
                    $underscoreArray[$newKey] = $val;
                } else {
                    $underscoreArray[$newKey] = $this->snakeCaseKeys($val, $array[$key], $ignoreKeys);
                }
                if ($newKey != $key) { // Not doing strict check to allow comparison between a number as a string and an integer.
                    unset($underscoreArray[$key]);
                }
            } else {
                $underscoreArray[$key] = $val;
            }
        }
        return $underscoreArray;
    }
}
