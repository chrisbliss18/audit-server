<?php

namespace XWP\Bundle\AuditServerBundle\Command;

use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Wrep\Daemonizable\Command\EndlessContainerAwareCommand;
use XWP\Bundle\AuditServerBundle\Extensions\ApiManager;

class LaunchAuditServerCommand extends EndlessContainerAwareCommand
{

    /**
     * Audits manager.
     *
     * @var \XWP\Bundle\AuditServerBundle\Extensions\AuditsManager
     */
    private $auditsManager;

    /**
     * AWS SQS manager.
     *
     * @var \XWP\Bundle\AuditServerBundle\Extensions\AwsSqsManager
     */
    private $awsSqsManager;

    /**
     * AWS SQS Lighthouse manager.
     *
     * @var \XWP\Bundle\AuditServerBundle\Extensions\AwsSqsManager
     */
    private $awsSqsLhManager;

    /**
     * API manager.
     *
     * @var \XWP\Bundle\AuditServerBundle\Extensions\ApiManager
     */
    private $apiManager;

    /**
     * Stats manager.
     *
     * @var \XWP\Bundle\AuditServerBundle\Extensions\StatsManager
     */
    private $statsManager;

    /**
     * Check is stats has been enabled.
     * @var bool
     */
    private $isStatsEnabled;

    /**
     * Logger.
     * @var \Monolog\Logger
     */
    private $logger;

    /**
     * Helpers.
     *
     * @var array
     */
    public $helpers = [];

    // This is just a normal Command::configure() method
    protected function configure()
    {
        $this->setName('tide:audit-server')
             ->setDescription('Tide Audit Server')
             ->addOption(
                 'enableStats',
                 null,
                 InputOption::VALUE_NONE,
                 'Run with stats',
                 null
             )
             ->setTimeout(1.5); // Set the timeout in seconds between two calls to the "execute" method.
    }

    // This is a normal Command::initialize() method and it's called exactly once before the first execute call.
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Do one time initialization here.
        $loggerManager = $this->getContainer()->get('app.logger_manager');
        $this->logger = $loggerManager->getLogger();
        $this->logger->info('Starting Audit Server');
        $this->auditsManager = $this->getContainer()->get('app.audits_manager');
        $this->auditsManager->setOutput($output);
        $this->apiManager = $this->getContainer()->get('app.api_manager');
        $this->awsSqsManager = $this->getContainer()->get('app.aws_sqs_manager');
        $this->awsSqsLhManager = $this->getContainer()->get('app.aws_sqs_lh_manager');
        $this->statsManager = $this->getContainer()->get('app.stats_manager');

        $this->isStatsEnabled = $input->getOption('enableStats');
        if ($this->isStatsEnabled) {
            $this->statsManager->initiateStats('fullRequest');
        }
    }

    // Execute will be called in a endless loop.
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        if ('prod' !== $this->getContainer()->getParameter('kernel.environment')) {
            $output->writeln('Polling audits requests... ');
        }

        if ($this->isStatsEnabled) {
            $this->statsManager->startStatsRecording('fullRequest');
        }

        try {
            $sqsMessage = $this->awsSqsManager->getSingleMessage();
        } catch (\Exception $e) {
            $errorMessage = $e;
            $this->logger->error($errorMessage);
            $output->writeln('<error>' . $errorMessage. '</error>');
            return false;
        }

        // No audits request found. Carry on polling.
        if (!isset($sqsMessage['Body'])) {
            return false;
        }

        $originalAuditsRequest = json_decode($sqsMessage['Body'], true);

        $auditsRequestReports = array();
        $auditsRequestStatus  = false;
        $existingAuditReports = array();
        $auditsRequest        = array();

        $hasError = false;
        if (!$this->auditsManager->validateOriginalAuditsRequest($originalAuditsRequest)) {
            $errorMessage = 'The audits requests message is incorrect';
            $this->logger->error($errorMessage, ['originalAuditsRequest' => $originalAuditsRequest]);
            $output->writeln('<error>' . $errorMessage . '</error>');
            $hasError = true;
        }

        // Attempt to prepare the audit.
        if (! $hasError) {
            try {
                $auditsRequest = $this->auditsManager->prepareForAudits($originalAuditsRequest);
            } catch (\Exception $e) {
                $errorMessage = 'Cannot prepare the audit.';
                $this->logger->error($errorMessage, [ 'originalAuditsRequest' => $originalAuditsRequest ]);
                $output->writeln('<error>' . $errorMessage . '</error>');
                $output->writeln('<error>' . $e . '</error>');
                $hasError = true;
            }
        }

        if (! $hasError) {
            try {
                $existingAuditReports = $this->apiManager->checkForExistingAuditReports($auditsRequest);
                $auditsRequestReports = $this->auditsManager->runAudits($auditsRequest, $existingAuditReports);

                // Themes will get a Lighthouse Audit if AWS_SQS_LH_QUEUE_ENABLED is set to `yes`.
                $lhQueueEnabled = $this->awsSqsLhManager->getSetting('queue_enabled', 'no');
                if ('yes' === strtolower($lhQueueEnabled) && 'theme' === $auditsRequest['codeInfo']['type']) {
                    if (empty($existingAuditReports['lighthouse']) || array_key_exists(
                        'error',
                        $existingAuditReports['lighthouse']
                    )) {
                        $sqsTask = $this->auditsManager->prepareThemeTaskForSQS($originalAuditsRequest, $auditsRequest);
                        if (! empty($sqsTask) && $this->awsSqsLhManager->createAuditTask($sqsTask)) {
                            $output->writeln('<info>Theme submitted for Lighthouse Audit.</info>');
                        } else {
                            $output->writeln('<info>Theme could not be submitted for Lighthouse Audit.</info>');
                        }
                    } else {
                        $output->writeln(
                            '<info>The Lighthouse audit report already exists. Skipping running audit...</info>'
                        );
                    }
                }

                $auditsRequestStatus = $this->auditsManager->getAuditsRequestOverallStatus();
            } catch (\Exception $e) {
                $errorMessage = $e;
                $this->logger->error($errorMessage, array(
                    'originalAuditsRequest' => $originalAuditsRequest,
                ));
                $output->writeln('<error>' . $errorMessage . '</error>');
                $hasError = true;
            }
        }

        if (! empty($auditsRequestReports['results']) && ! empty($auditsRequest['responseApiEndpoint'])) {
            try {
                $payload = $this->apiManager->createPayload(
                    $auditsRequest,
                    $auditsRequestReports,
                    $auditsRequestStatus
                );

                /**
                 * If the request is for a collection endpoint, but we have an existing audit
                 * then we need to update the endpoint for the API payload.
                 */
                if (! empty($existingAuditReports) && ApiManager::isCollectionEndpoint(
                    $auditsRequest['responseApiEndpoint']
                ) && ! empty($auditsRequest['auditsFilesChecksum'])) {
                    $auditsRequest['responseApiEndpoint'] = sprintf(
                        '%s/%s',
                        $auditsRequest['responseApiEndpoint'],
                        $auditsRequest['auditsFilesChecksum']
                    );
                }

                $apiResponse = $this->apiManager->sendPayload($auditsRequest['responseApiEndpoint'], $payload);
                $apiUpdated = true;
            } catch (\Exception $e) {
                $errorMessage = $e;
                $this->logger->error($errorMessage, ['auditsRequest' => $auditsRequest]);
                $output->writeln('<error>' . $errorMessage . '</error>');
                $hasError = true;
                $apiUpdated = false;
            }
        }

        $extraInfo = '';
        if ($this->isStatsEnabled) {
            $this->statsManager->stopStatsRecording('fullRequest');
            $statsInfo = $this->statsManager->getStatsInfo('fullRequest');
            $extraInfo = ' (Duration: '.$statsInfo['elapsedTime'].', Memory Usage: '.$statsInfo['memoryUsed'].')';
            $this->statsManager->writeStats('fullRequest', $auditsRequest);
        }

        if (!$auditsRequestStatus || empty($auditsRequest['auditsFilesChecksum']) || $hasError) {
            if ($apiUpdated) {
                $output->writeln('<error>Audits request failed. Error sent to API.</error>');
            } else {
                $output->writeln('<error>Audits request failed. Error could not be sent to API.</error>');
            }
        } else {
            if (isset($sqsMessage['ReceiptHandle'])) {
                try {
                    $output->writeln('<info>Deleting SQS message from the queue.</info>');
                    $this->awsSqsManager->deleteMessage($sqsMessage);
                } catch (\Exception $errorMessage) {
                    $output->writeln('<error>Could not delete message from SQS queue.</error>');
                }
            }

            $output->writeln('<info>Audits request completed' . $extraInfo . '</info>');
        }
    }

    /**
     * Called after each iteration.
     *
     * @param  InputInterface  $input  Input interface.
     * @param  OutputInterface $output Output interface.
     *
     * @return void
     */
    protected function finishIteration(InputInterface $input, OutputInterface $output)
    {
        // Do some cleanup/memory management here, don't forget to call the parent implementation!
        parent::finishIteration($input, $output);
    }

    /**
     * Called once on shutdown after the last iteration finished.
     *
     * @param  InputInterface  $input  Input interface.
     * @param  OutputInterface $output Output interface.
     *
     * @return void
     */
    protected function finalize(InputInterface $input, OutputInterface $output)
    {
        // Do some cleanup here, don't forget to call the parent implementation!
        parent::finalize($input, $output);
        // Keep it short! We may need to exit because the OS wants to shutdown
        // and we can get killed if it takes to long!
    }
}
