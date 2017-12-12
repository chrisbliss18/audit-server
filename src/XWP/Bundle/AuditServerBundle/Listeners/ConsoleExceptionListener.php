<?php

namespace XWP\Bundle\AuditServerBundle\Listeners;

use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Psr\Log\LoggerInterface;

/**
 * Console exception listener.
 *
 * @since  v0.1
 */
class ConsoleExceptionListener
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
	 * Console exception event handler.
	 *
	 * @param  ConsoleExceptionEvent $event Event.
	 *
	 * @return void
	 */
	public function onConsoleException(ConsoleExceptionEvent $event)
	{
		$command = $event->getCommand();
		$exception = $event->getException();

		$message = sprintf(
			'%s: %s (uncaught exception) at %s line %s while running console command `%s`',
			get_class($exception),
			$exception->getMessage(),
			$exception->getFile(),
			$exception->getLine(),
			$command->getName()
		);

		$this->logger->error($message, array('exception' => $exception));
	}
}
