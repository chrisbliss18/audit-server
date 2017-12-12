<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Logger manager.
 *
 * Note: this Manager does not need to extend the BaseManager class.
 *
 * @since  v0.1
 */
class LoggerManager
{
    /**
     * Logger.
     *
     * @var \Monolog\Logger
     */
    private $logger;

    /**
     * Logger level.
     *
     * @var array
     */
    private $loggerLevel = array(
        'DEBUG' => Logger::DEBUG,
        'INFO' => Logger::INFO,
        'NOTICE' => Logger::NOTICE,
        'WARNING' => Logger::WARNING,
        'ERROR' => Logger::ERROR,
        'CRITICAL' => Logger::CRITICAL,
        'ALERT' => Logger::ALERT,
        'EMERGENCY' => Logger::EMERGENCY,
    );

    /**
     * Constructor.
     *
     * @param  array $settings Settings.
     * @param  string $symfonyLogFile  Symfony log file.
     */
    public function __construct($settings, $symfonyLogFile)
    {
        $defaultSettings = [
            'name' => 'Tide',
            'handlers' => []
        ];

        $settings = array_merge($defaultSettings, $settings);
        $this->name = strtolower($settings['name']);
        $this->symfonyLogFile = $symfonyLogFile;
        $this->logger = new Logger($this->name);
        $this->setLoggerHandlers($this->logger, $settings['handlers']);
    }

    /**
     * Set logger handlers.
     *
     * @param  \Monolog\Logger $logger Logger.
     * @param  array $handlers Handlers.
     */
    private function setLoggerHandlers($logger, $handlers)
    {
        $hasHandler = false;
        foreach ($handlers as $hander => $handlerSettings) {
            $level = isset($handlerSettings['level']) ? $this->loggerLevel[strtoupper($handlerSettings['level'])] : $this->loggerLevel['DEBUG'];
            switch ($hander) {
                case 'file':
                default:
                    if (!empty($handlerSettings['path'])) {
                        $handler = new StreamHandler($handlerSettings['path'], $level);
                        $logger->pushHandler($handler);
                        $hasHandler = true;
                    }
                    break;
            }
        }

        if (!$hasHandler) {
            $handler = new StreamHandler($this->symfonyLogFile, $this->loggerLevel['DEBUG']);
            $logger->pushHandler($handler);
        }
    }

    /**
     * Get logger.
     *
     * @return object
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }
}
