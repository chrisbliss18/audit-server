<?php

namespace XWP\Bundle\AuditServerBundle\Listeners;

use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Psr\Log\LoggerInterface;

/**
 * Console Terminate listener.
 *
 * @since  v0.1
 */
class ConsoleTerminateListener
{
	/**
	 * Logger.
	 *
	 * @var object
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param \XWP\Bundle\AuditServerBundle\Extensions\LoggerManager $loggerManager Logger Manager.
	 *
	 * @return  void
	 */
	public function __construct($loggerManager)
	{
		$this->logger = $loggerManager->getLogger();
	}

	/**
	 * Console terminate event handler.
	 *
	 * @param  ConsoleTerminateEvent $event Event.
	 *
	 * @return void
	 */
	public function onConsoleTerminate(ConsoleTerminateEvent $event)
	{
		$statusCode = $event->getExitCode();
		$command = $event->getCommand();

		if ($statusCode === 0) {
			return;
		}

		if ($statusCode > 255) {
			$statusCode = 255;
			$event->setExitCode($statusCode);
		}

		$this->logger->warning(sprintf(
			'Command `%s` exited with status code %d',
			$command->getName(),
			$statusCode
		));
	}
}
