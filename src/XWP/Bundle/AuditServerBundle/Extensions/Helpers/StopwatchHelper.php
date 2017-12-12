<?php

namespace XWP\Bundle\AuditServerBundle\Extensions\Helpers;

use League\Csv\AbstractCsv;
use League\Csv\Reader;
use League\Csv\Writer;

/**
 * Stopwatch helper.
 *
 * @since  v0.1
 */
class StopwatchHelper
{
    protected $statsPath = 'tmp';

    public function setStatsPath($settings)
    {
        $this->statsPath = isset($settings['stats_path']) ? $settings['stats_path'] : 'tmp';
    }

    /**
     * Format milliseconds.
     *
     * @param  integer $milliseconds Milliseconds.
     *
     * @return string               Formatted milliseconds.
     */
    public function formatMilliseconds($milliseconds)
    {
        $seconds = floor($milliseconds / 1000);
        $minutes = floor($seconds / 60);
        $hours = floor($minutes / 60);
        $milliseconds = $milliseconds % 1000;
        $seconds = $seconds % 60;
        $minutes = $minutes % 60;

        $format = '%u:%02u:%02u.%03u';
        $time = sprintf($format, $hours, $minutes, $seconds, $milliseconds);
        return rtrim($time, '0');
    }

    /**
     * Format Bytes.
     *
     * @param  integer  $size      Size in bytes.
     * @param  integer $precision Precision.
     *
     * @return string             Formatted bytes.
     */
    public function formatBytes($size, $precision = 2)
    {
        $base = log($size, 1024);
        $suffixes = array('b', 'kB', 'MB', 'GB', 'TB');

        return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
    }
}
