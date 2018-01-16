<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;

/**
 * Email manager.
 *
 * @since  v0.1
 */
class EmailManager
{
    /**
     * Mailer.
     *
     * @var object
     */
    private $mailer;

    /**
     * Templating.
     *
     * @var object
     */
    private $templating;

    /**
     * Constructor.
     *
     * @param  object $mailer mailer.
     * @param  object $templating templating.
     *
     * @return void
     */
    public function __construct($mailer, $templating)
    {
        $this->mailer = $mailer;
        $this->templating = $templating;
    }

    /**
     * Send email.
     *
     * @param  array $context Context.
     *
     * @return boolean          True on success.
     */
    public function sendEmail($template, $context, $format = 'text/html')
    {
        $message = \Swift_Message::newInstance()
            ->setSubject($context['subject'])
            ->setFrom($context['from'])
            ->setTo($context['to'])
            ->setBody(
                $this->templating->render(
                    $template,
                    $context['body']
                ),
                $format
            )
        ;

        // Register a logger for debugging purpose (optional)
        $mailLogger = new \Swift_Plugins_Loggers_ArrayLogger();
        $this->mailer->registerPlugin(new \Swift_Plugins_LoggerPlugin($mailLogger));

        if ($this->mailer->send($message)) {
            return true;
        } else {
            return false;
        }
    }
}
