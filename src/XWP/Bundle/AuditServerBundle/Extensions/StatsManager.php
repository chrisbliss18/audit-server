<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;

use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Stats manager.
 *
 * @since  v0.1
 */
class StatsManager extends BaseManager
{
    /**
     * Csv Writers.
     *
     * @var array
     */
    private $csvWriters;

    /**
     * Stopwatches.
     *
     * @var array
     */
    private $stopwatches;

    /**
     * StopwatchesEvents.
     *
     * @var array
     */
    private $stopwatchesEvents;

    /**
     * Stats headers.
     *
     * @var array
     */
    private $statsHeaders =[
        'fullRequest' => [
                'sourceUrl',
                'checksum',
                'size',
                'totalFiles',
                'totalLinesOfCode',
                'duration',
                'memoryUsed',
        ],
    ];

    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct()
    {
        if (!ini_get("auto_detect_line_endings")) {
            ini_set("auto_detect_line_endings", '1');
        }
    }

    /**
     * Initiate stats.
     *
     * @param  string $statsType Stats type.
     *
     * @return void
     */
    public function initiateStats($statsType)
    {
        if (isset($this->statsHeaders[$statsType])) {
            $statsFilename = 'stats-'.date('Y-m-d-h-i-s-v')."-{$statsType}.csv";
            $this->csvWriters[$statsType] = $this->helpers['stats']->createNewWriter($statsFilename);
            $this->csvWriters[$statsType]->insertOne($this->statsHeaders[$statsType]);
        }
    }

    /**
     * Start stats recording.
     *
     * @param  string $statsType Stats type.
     *
     * @return void
     */
    public function startStatsRecording($statsType)
    {
        if (isset($this->csvWriters[$statsType])) {
            $this->stopwatches[$statsType] = new Stopwatch();
            $this->stopwatches[$statsType]->start($statsType);
        }
    }

    /**
     * Stop stats recording.
     *
     * @param  string $statsType Stats type.
     *
     * @return void
     */
    public function stopStatsRecording($statsType)
    {
        if (isset($this->stopwatches[$statsType])) {
            $this->stopwatchesEvents[$statsType] = $this->stopwatches[$statsType]->stop($statsType);
        }
    }

    /**
     * Get stats info.
     *
     * @param  string $statsType Stats type.
     *
     * @return array
     */
    public function getStatsInfo($statsType)
    {
        $statsInfo = [
            'elapsedTime' => '',
            'memoryUsed' => '',
        ];

        if (isset($this->stopwatchesEvents[$statsType]) && isset($this->csvWriters[$statsType])) {
            $statsInfo = [
                'elapsedTime' => $this->helpers['stopwatch']->formatMilliseconds(
                    $this->stopwatchesEvents[$statsType]->getDuration()
                ),
                'memoryUsed'  => $this->helpers['stopwatch']->formatBytes(
                    $this->stopwatchesEvents[$statsType]->getMemory()
                ),
            ];
        }

        return $statsInfo;
    }

    /**
     * Write stats.
     *
     * @param  string $statsType Stats type.
     * @param  array $extraStatsInfo Extra stats info.
     *
     * @return void
     */
    public function writeStats($statsType, $extraStatsInfo)
    {
        if (isset($this->csvWriters[$statsType])) {
            $statsInfo = $this->getStatsInfo($statsType);


            if (isset($this->statsHeaders[$statsType]) && $statsType === 'fullRequest') {
                $statsInfoToWrite = [
                    isset($extraStatsInfo['sourceUrl'])
                        ? $extraStatsInfo['sourceUrl'] : '',
                    isset($extraStatsInfo['auditsFilesChecksum'])
                        ? $extraStatsInfo['auditsFilesChecksum'] : '',
                    isset($extraStatsInfo['auditsFilesDirectorySize'])
                        ? $extraStatsInfo['auditsFilesDirectorySize'] : '',
                    isset($extraStatsInfo['codeInfo']['cloc']['sum']['nFiles'])
                        ? $extraStatsInfo['codeInfo']['cloc']['sum']['nFiles'] : '',
                    isset($extraStatsInfo['codeInfo']['cloc']['sum']['code'])
                        ? $extraStatsInfo['codeInfo']['cloc']['sum']['code'] : '',
                    isset($statsInfo['elapsedTime'])
                        ? $statsInfo['elapsedTime'] : '',
                    isset($statsInfo['memoryUsed'])
                        ? $statsInfo['memoryUsed'] : '',
                ];

                $this->csvWriters[$statsType]->insertOne($statsInfoToWrite);
            }

            unset($this->stopwatches[$statsType]);
            unset($this->stopwatchesEvents[$statsType]);
        }
    }
}
