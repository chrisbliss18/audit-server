<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;

/**
 * Base manager.
 *
 * @since  v0.1
 */
abstract class BaseManager
{

    /**
     * Output.
     *
     * @var object
     */
    public $output;

    /**
     * Loggers.
     *
     * @var array
     */
    protected $loggers = [];

    /**
     * Helpers.
     *
     * @var array
     */
    protected $helpers = [];

    /**
     * Set output.
     *
     * @param object $output Output.
     *
     * @return void
     */
    public function setOutput($output)
    {
        $this->output = $output;
    }

    /**
     * Set helpers.
     *
     * @param string   $name   Helper name.
     * @param object $helper Helper.
     *
     * @return void
     */
    public function setHelper($name, $helper)
    {
        $this->helpers[$name] = $helper;
    }

    /**
     * Set logger.
     *
     * @param \XWP\Bundle\AuditServerBundle\Extensions\LoggerManager $loggerManager Logger manager.
     *
     * @return void
     */
    public function setLogger($loggerManager)
    {
        $name = $loggerManager->getName();
        $this->loggers[$name] = $loggerManager->getLogger();
    }
}
