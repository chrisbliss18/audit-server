<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;
use Symfony\Component\Config\Definition\Exception\Exception;

/**
 * Audits manager.
 *
 * @since  v0.1
 */
class AuditsManager extends BaseManager
{
	/**
	 * Settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Is audits request successful.
	 *
	 * @var bool
	 */
	private $isAuditsRequestSuccesful = false;

	/**
	 * Files manager.
	 *
	 * @var \XWP\Bundle\AuditServerBundle\Extensions\FilesManager
	 */
	private $filesManager;

	/**
	 * Code identity manager.
	 *
	 * @var \XWP\Bundle\AuditServerBundle\Extensions\CodeIdentityManager
	 */
	private $codeIdentityManager;

	/**
	 * Audit managers.
	 *
	 * @var array
	 */
	private $auditManagers = array();

	/**
	 * Scoring managers.
	 *
	 * @var array
	 */
	private $scoringManagers = array();

	/**
	 * Constructor.
	 *
	 * @param  array $settings Settings.
	 * @param  \XWP\Bundle\AuditServerBundle\Extensions\FilesManager $filesManager  Files manager.
	 * @param  \XWP\Bundle\AuditServerBundle\Extensions\CodeIdentityManager $codeIdentityManager  Code identity manager.
	 *
	 * @return void
	 */
	public function __construct($settings, $filesManager, $codeIdentityManager)
	{
		$this->settings = $settings;
		$this->filesManager = $filesManager;
		$this->codeIdentityManager = $codeIdentityManager;
	}

	/**
	 * Set audit manager.
	 *
	 * @param string $managerName Audit manager name.
	 * @param object $manager     Audit manager.
	 *
	 * @return  void
	 */
	public function setAuditManager($managerName, $manager)
	{
		$this->auditManagers[$managerName] = $manager;
	}

	/**
	 * Set scoring manager.
	 *
	 * @param string $managerName Audit manager name.
	 * @param object $manager     Audit manager.
	 *
	 * @return  void
	 */
	public function setScoringManager($managerName, $manager)
	{
		$this->scoringManagers[$managerName] = $manager;
	}
	/**
	 * Set PHPCS standards.
	 *
	 * @param array $settings Audit settings.
	 *
	 * @throws \Exception Contains the output of the failed `exec` command.
	 */
	public function setPhpCsStandards($settings)
	{
		$directories = $this->filesManager->generateDirectoriesList($settings['standards_path'] . '/phpcs');
		$standards = implode(',', $directories);

		$command = "phpcs --config-set installed_paths $standards";
		list ( $output, $err ) = Helpers\ExecHelper::run( $command );

		if ( ! empty( $err ) ) {
			throw new \Exception( $output, $err );
		}
	}

	/**
	 * Prepare for audits such as cloning or extracting audits files to destination.
	 *
	 * @param  array  $originalAuditsRequest Original audits request.
	 *
	 * @throws \Exception When audit can't be prepared.
	 *
	 * @return array Audits request.
	 */
	public function prepareForAudits($originalAuditsRequest)
	{
		$originalAuditsRequest = $this->helpers['array']->camelCaseKeys($originalAuditsRequest);
		$this->output->writeln("\n<info>Audits request found. Preparing... " . $originalAuditsRequest['sourceUrl'] . "</info>");
		$defaultAuditsRequest = array(
			'sourceUrl'                => '',
			'sourceType'               => '',
			'archiveFile'              => '',
			'destinationBaseDirectory' => '',
			'auditsFilesDirectory'     => '',
			'auditsReportsDirectory'   => '',
			'auditsFilesDirectorySize' => '',
			'auditsFiles'              => array(),
			'auditsFilesChecksum'      => '',
			'codeInfo'                 => array(),
			'revisionMeta'             => array(),
			'audits'                   => array(),
			'requestClient'            => '',
		);

		$auditsRequest = array_merge($defaultAuditsRequest, $originalAuditsRequest);

		$prefix = md5(php_uname('a'));
		$file = uniqid($prefix) . '-' . basename($auditsRequest['sourceUrl']);
		$fileParts = pathinfo($file);
		$destinationBasePath = !empty($this->settings['auditsBaseDir']) ? $this->settings['auditsBaseDir'] : '/tmp';
		$destinationBaseDirectory = $destinationBasePath . '/' . $fileParts['filename'];
		$auditsFilesDirectory = $destinationBaseDirectory . '/files';
		$auditsReportsDirectory = $destinationBaseDirectory . '/reports';

		$this->filesManager->deleteDirectory($destinationBaseDirectory);

		$auditsRequest['destinationBaseDirectory'] = $destinationBaseDirectory;
		$auditsRequest['auditsFilesDirectory'] = $auditsFilesDirectory;
		$auditsRequest['auditsReportsDirectory'] = $auditsReportsDirectory;

		$created = false;
		switch ($auditsRequest['sourceType']) {
			case 'git':
				$created = $this->filesManager->cloneRepo($auditsRequest['sourceUrl'], $auditsFilesDirectory);
				break;
			case 'zip':
				$archiveFile = $destinationBasePath . '/' . $fileParts['basename'];
				$auditsRequest['archiveFile'] = $archiveFile;
				$this->filesManager->downloadFile($auditsRequest['sourceUrl'], $auditsRequest['archiveFile']);
				$created = $this->filesManager->extractZipArchive($auditsRequest['archiveFile'], $auditsFilesDirectory);
				break;
			default:
				break;
		}

		if (!$created) {
			$auditsRequest['auditsFilesDirectory'] = '';
			$auditsRequest['auditsReportsDirectory'] = '';
		} else {
			$this->filesManager->createDirectory($auditsReportsDirectory);

			$auditsRequest['auditsFilesChecksum']      = $this->filesManager->generateChecksum( $auditsFilesDirectory );
			$auditsRequest['auditsFilesDirectorySize'] = $this->filesManager->getDirectorySize( $auditsFilesDirectory );
			$auditsRequest['codeInfo']                 = $this->codeIdentityManager->getWordPressCodeInfo( $auditsFilesDirectory );
			$auditsRequest['codeInfo']['cloc']         = $this->codeIdentityManager->getLinesOfCodeCounts( $auditsFilesDirectory );
		}

		return $auditsRequest;
	}

	 /**
	 * Validate original audits request.
	 *
	 * @todo: Need improvement.
	 *
	 * @param  array  $originalAuditsRequest Original requests.
	 *
	 * @return bool                        Whether or not the request format is validated.
	 */
	public function validateOriginalAuditsRequest($originalAuditsRequest)
	{
		$validated = true;
		if (!isset($originalAuditsRequest['source_url']) || !isset($originalAuditsRequest['source_type']) || !isset($originalAuditsRequest['audits'])) {
			$validated = false;
		}

		return $validated;
	}

	/**
	 * Run audits.
	 *
	 * @param  array $auditsRequest        Audits request.
	 * @param  array $existingAuditReports Existing audit reports.
	 *
	 * @throws \Exception When an audit can't be processed.
	 *
	 * @return array           Audits results.
	 */
	public function runAudits($auditsRequest = array(), $existingAuditReports = array())
	{
		$auditsResults = array();
		$audits = $auditsRequest['audits'] ?? array();
		$auditsFilesDirectory = !empty($auditsRequest['auditsFilesDirectory']) ? $auditsRequest['auditsFilesDirectory'] : '';
		$auditsReportsDirectory = !empty($auditsRequest['auditsReportsDirectory']) ? $auditsRequest['auditsReportsDirectory'] : '';
		$auditsFilesChecksum = !empty($auditsRequest['auditsFilesChecksum']) ? $auditsRequest['auditsFilesChecksum'] : '';
		$codeInfoType = $auditsRequest['codeInfo']['type'] ?? '';

		/*
		 * The following Exceptions are all critical.
		 */

		if ( empty( $audits ) ) {
			$message = 'No audits found.';
			$this->output->writeln( '<error>' . $message . '</error>' );
			throw new \Exception( $message );
		}

		if ( empty( $auditsFilesDirectory ) ) {
			$message = 'No audits files found on the server.';
			$this->output->writeln( '<error>' . $message . '</error>' );
			throw new \Exception( $message );
		}

		if ( empty( $auditsReportsDirectory ) ) {
			$message = 'No audits report directory found on the server.';
			$this->output->writeln( '<error>' . $message . '</error>' );
			throw new \Exception( $message );
		}

		if ( empty( $auditsFilesChecksum ) ) {
			$message = 'Audits files checksum could not be calculated.';
			$this->output->writeln( '<error>' . $message . '</error>' );
			throw new \Exception( $message );
		}

		/*
		 * Not technically critical, but will yield no results.
		 */

		if ( ! in_array( $codeInfoType, array( 'plugin', 'theme' ), true ) ) {
			$this->output->writeln( '<error>Skipping audit. The code does not appears to be a WordPress theme or plugin.</error>' );
			$this->filesManager->deleteDirectory( $auditsFilesDirectory );

			if ( ! empty( $auditsRequest['archiveFile'] ) ) {
				unlink( $auditsRequest['archiveFile'] );
			}

			return $auditsResults;
		}

		// We have everything we need to proceed.
		$this->isAuditsRequestSuccesful = true;

		$existingAuditReportsKeys = array_keys( $existingAuditReports );

		if ( isset( $auditsRequest['codeInfo']['cloc']['php'] ) ) {
			$linesOfCode = $auditsRequest['codeInfo']['cloc']['php']['code'];
		} else {

			// Default to 100.
			$linesOfCode = 100;
		}

		$auditRatings = array();
		foreach ($audits as $key => $audit) {

			$auditOptions = ! empty( $audit['options'] ) ? $audit['options'] : array();
			$audit_standard = ! empty( $auditOptions['standard'] ) ? strtolower( trim( $auditOptions['standard'] ) ) : false;

			// Skip any unknown audit type.
			if ( ! array_key_exists( $audit['type'], $this->auditManagers ) || false === $audit_standard ) {
				$this->output->writeln( 'Skipping unknown audit: ' . json_encode( $audit ) );
				continue;
			}

			// Skip empty audits. Sometimes explode() is not our friend.
			if ( ! array_key_exists( $audit['type'], $this->auditManagers ) || false === $audit_standard ) {
				continue;
			}

			$results = array();
			$error = false;

			$auditReportKey = "{$audit['type']}_{$audit_standard}";

			try {
				if ( 'phpcs' === $audit['type'] ) {
					$scoringOptions = ! empty( $audit['scoring'] ) ? $audit['scoring'] : array();
					$weightingsFile = ! empty( $audit['scoring']['weightingsFile'] ) ? $audit['scoring']['weightingsFile'] : '';

					$this->auditManagers[ $audit['type'] ]->setOutput( $this->output );
					$results = $this->auditManagers[ $audit['type'] ]->getExistingAuditReport( $auditOptions, $existingAuditReports, $existingAuditReportsKeys );

					if ( empty( $results ) ) {

						$results = $this->auditManagers[ $audit['type'] ]->runAudit( $auditsRequest, $audit, $auditsFilesDirectory, $auditsReportsDirectory, $auditsFilesChecksum, $auditOptions );

						// If it's phpcompatibility, check that instead of getting the scores.
						if ( isset( $results['full']['key'] ) && 'json' === $audit['options']['report'] ) {
							if ( 'phpcompatibility' === $audit_standard ) {
								$results['compatible_versions'] = $this->auditManagers[ $audit['type'] ]->getPHPCompatibilityReport( $auditsReportsDirectory . '/' . $results['full']['key'], false );
							} elseif ( ! empty( $weightingsFile ) ) {
								$results['scores']  = $this->scoringManagers[ $audit['type'] ]->getScores( $auditsReportsDirectory . '/' . $results['full']['key'], $scoringOptions, $linesOfCode );
								$details            = $this->auditManagers[ $audit['type'] ]->parseDetailedReport( $auditsReportsDirectory . '/' . $results['full']['key'], $weightingsFile );
								$results['summary'] = $this->auditManagers[ $audit['type'] ]->getSummaryReport( $details );

								if ( empty( $results['scores'] ) ) {
									$results = false;
									if ( false !== $results && 'phpcompatibility' !== $audit_standard ) {
									}
								}
							}
						}
						if ( false !== $results && 'phpcompatibility' !== $audit_standard ) {
							$auditRatings[ $auditReportKey ]['rating'] = $this->scoringManagers[ $audit['type'] ]->getAuditRating( $results, $scoringOptions );
						}
					}
				}
			} catch ( \Exception $e ) {
				$error   = array(
					'error' => $e,
				);
				$results = $error;
			}

			$auditsResults = is_array( $auditsResults ) ? $auditsResults : array();
			if ( ! array_key_exists( 'results', $auditsResults ) ) {
				$auditsResults['results'] = array();
			}
			$auditsResults['results'][ $auditReportKey ] = $results;

			if ( false !== $error ) {
				$this->isAuditsRequestSuccesful = false;
			}
		}

		/*
		 * Calculation for general rating. Takes the average of all audits.
		 * Some audits might be empty and without rating, doesn't count these in.
		 */
		$generalRating = 0;
		$auditsCount = 0;
		foreach ( $auditRatings as $auditReport => $data ) {
			if ( isset( $data['rating'] ) && false !== $data['rating'] ) {
				$auditsCount++;
				$generalRating += $data['rating'];
			}
		}
		if ( 0 < $generalRating ) {
			$generalRating = round( $generalRating / $auditsCount, 2 );
		}

		$auditsResults['rating'] = $generalRating;

		if (!empty($auditsRequest['archiveFile'])) {
			unlink($auditsRequest['archiveFile']);
		}

		$this->filesManager->deleteDirectory($auditsFilesDirectory);

		return $auditsResults;
	}


	/**
	 * Prepare an audit task to send to SQS.
	 *
	 * @param array $originalRequest The original SQS message.
	 * @param array $modifiedTask The modified message.
	 *
	 * @return array
	 */
	public function prepareThemeTaskForSQS( $originalRequest, $modifiedTask = array() ) {

		// First lets convert the keys.
		$sqsTask      = $this->helpers['array']->snakeCaseKeys( $originalRequest, [], [] );
		$modifiedTask = $this->helpers['array']->snakeCaseKeys( $modifiedTask, [], [] );

		if ( 'theme' === $modifiedTask['code_info']['type'] ) {

			// Add code_info to SQS task.
			$sqsTask['code_info'] = $modifiedTask['code_info'];

			if ( empty( $modifiedTask['audits_files_checksum'] ) ) {
				return $sqsTask;
			}

			// Set to checksum endpoint if its a collection endpoint.
			if ( ApiManager::is_collection_endpoint( $sqsTask['response_api_endpoint'] ) ) {
				$sqsTask['response_api_endpoint'] = rtrim( $sqsTask['response_api_endpoint'], '/' );
				$sqsTask['response_api_endpoint'] = sprintf( '%s/%s', $sqsTask['response_api_endpoint'], $modifiedTask['audits_files_checksum'] );
			} else {
				// Make sure its not a postID endpoint, but a checksum endpoint.
				$sqsTask['response_api_endpoint'] = preg_replace( '/([\d]+)$/', $modifiedTask['audits_files_checksum'], $sqsTask['response_api_endpoint'] );
			}

			return $sqsTask;
		} else {
			return [];
		}
	}

	/**
	 * Get audits request overall status.
	 *
	 * @return bool
	 */
	public function getAuditsRequestOverallStatus()
	{
		return $this->isAuditsRequestSuccesful;
	}
}
