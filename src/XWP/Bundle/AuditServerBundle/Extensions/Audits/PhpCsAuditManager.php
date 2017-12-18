<?php
/**
 * This file defines the PhpCsAuditManager class.
 *
 * @package WPTideAuditServer
 */

namespace XWP\Bundle\AuditServerBundle\Extensions\Audits;

use XWP\Bundle\AuditServerBundle\Extensions\BaseManager;
use XWP\Bundle\AuditServerBundle\Extensions\Helpers;

/**
 * PHPCS audit manager.
 *
 * @since  v0.1
 */
class PhpCsAuditManager extends BaseManager
{

    /**
     * Settings.
     *
     * @var array
     */
    private $settings;

    /**
     * AWS S3 Manager.
     *
     * @var \XWP\Bundle\AuditServerBundle\Extensions\AwsS3Manager
     */
    private $awsS3Manager;

    /**
     * AWS S3 client.
     *
     * @var \Aws\S3\S3Client
     */
    private $s3Client;

    /**
     * AWS S3 bucket name.
     *
     * @var string
     */
    private $s3BucketName;

    /**
     * Audit standard key.
     *
     * @var string
     */
    private $auditStandardKey = '';

    /**
     * Audit standard key.
     *
     * @var string
     */
    private $defaultOptions = array();

    /**
     * Constructor.
     *
     * @param array                                                 $settings Settings.
     * @param \XWP\Bundle\AuditServerBundle\Extensions\AwsS3Manager $awsS3Manager AWS S3 manager.
     */
    public function __construct($settings, $awsS3Manager)
    {
        $this->settings = $settings;

        $this->awsS3Manager = $awsS3Manager;
        $this->s3Client = $this->awsS3Manager->getClient();
        $this->s3BucketName = $this->awsS3Manager->getBucketName();
        $this->defaultOptions = array(
            'standard'   => 'wordpress',
            'extensions' => 'php',
            'report'     => 'json',
            'ignore'     => '*/vendor/*,*/node_modules/*',
            'parallel'   => 10,
        );
    }

    /**
     * Run PHPCS audit.
     *
     * @param array  $auditsRequest Audits request.
     * @param array  $audit Audit.
     * @param string $auditsFilesDirectory Audits files directory.
     * @param string $auditsReportsDirectory Audits reports directory.
     * @param string $auditsFilesChecksum Audits files checksum.
     * @param array  $options PHPCS Options.
     *
     * @throws \Exception When something goes wrong.
     *
     * @return array Audit results.
     */
    public function runAudit(
        $auditsRequest,
        $audit,
        $auditsFilesDirectory,
        $auditsReportsDirectory,
        $auditsFilesChecksum,
        $options = array()
    ) {
        $auditReports = array();

        $options = array_merge($this->defaultOptions, $options);
        $this->auditStandardKey = $this->setAuditStandardKey($options);

        if (! $this->checkAuditStandardExists($this->auditStandardKey)) {
            $this->output->writeln(
                "<error>{$audit['type']} standard {$options['standard']} does not exists. " .
                "Skipping running audit...</error>"
            );
            throw new \Exception(
                "{$audit['type']} standard {$options['standard']} does not exists. Skipping running audit..."
            );
        }

        $this->output->writeln(
            "<info>Performing {$audit['type']} (standard: {$options['standard']}) on {$auditsRequest['sourceUrl']} " .
            "({$auditsRequest['sourceType']})</info>"
        );

        // Ensure utf-8 encoding is the default.
        if (empty($options['encoding'])) {
            $options['encoding'] = 'utf-8';
        }

        $stringOptions = '';
        foreach ($options as $option => $value) {
            if ('runtime-set' === $option) {
                $stringOptions .= ' --' . $option . ' ' . $value;
            } else {
                $stringOptions .= ' --' . $option . '=' . $value;
            }
        }

        $fullReportFilename = $auditsFilesChecksum.'-phpcs-'.$this->auditStandardKey.'-full.'.$options['report'];
        $fullReportPath = $auditsReportsDirectory . '/' . $fullReportFilename;

        $command = "phpcs $stringOptions --report-{$options['report']}=$fullReportPath $auditsFilesDirectory -q";

        list ( $output, $err ) = Helpers\ExecHelper::run($command, true, true);

        // For PHPCS codes of 0, 1 and 2 are acceptable.
        $err = (int) $err;
        if (! in_array($err, array( 0, 1, 2 ), true)) {
            throw new \Exception($output, $err);
        }

        if (file_exists($fullReportPath)) {
            $result = $this->s3Client->putObject(array(
                'Bucket'     => $this->s3BucketName,
                'Key'        => $fullReportFilename,
                'SourceFile' => $fullReportPath,
            ));

            // We can poll the object until it is accessible.
            $this->s3Client->waitUntil('ObjectExists', array(
                'Bucket' => $this->s3BucketName,
                'Key'    => $fullReportFilename,
            ));

            $auditReports['full'] = array(
                'type'       => 's3',
                'bucketName' => $this->s3BucketName,
                'key'        => $fullReportFilename,
            );
        }

        $detailsReportFilename = $auditsFilesChecksum.'-phpcs-'.$this->auditStandardKey.'-details.'.$options['report'];
        $detailsReportPath = $auditsReportsDirectory . '/' . $detailsReportFilename;

        if ('phpcompatibility' === strtolower($this->auditStandardKey)) {
            $details = $this->getPHPCompatibilityReport($fullReportPath, true);
        } else {
            $details = $this->parseDetailedReport($fullReportPath);
        }
        file_put_contents($detailsReportPath, json_encode($details));

        if (file_exists($detailsReportPath)) {
            // @todo: Handle errors.
            $result = $this->s3Client->putObject(array(
                'Bucket'     => $this->s3BucketName,
                'Key'        => $detailsReportFilename,
                'SourceFile' => $detailsReportPath,
            ));

            // We can poll the object until it is accessible.
            $this->s3Client->waitUntil('ObjectExists', array(
                'Bucket' => $this->s3BucketName,
                'Key'    => $detailsReportFilename,
            ));

            $auditReports['details'] = array(
                'type'       => 's3',
                'bucketName' => $this->s3BucketName,
                'key'        => $detailsReportFilename,
            );
        }

        return $auditReports;
    }

    /**
     * Get Existing audit report.
     *
     * @param array $auditsRequest The audits request.
     * @param array $options PHPCS Options.
     * @param array $existingAuditReports Existing audit reports.
     * @param array $existingAuditReportsKeys Existing audit reports keys.
     *
     * @return array Audit results.
     */
    public function getExistingAuditReport(
        $auditsRequest,
        $options = array(),
        $existingAuditReports = array(),
        $existingAuditReportsKeys = array()
    ) {

        $options = array_merge($this->defaultOptions, $options);
        $this->auditStandardKey = $this->setAuditStandardKey($options);

        $existingReport = array();

        $auditReportKey = "phpcs_{$this->auditStandardKey}";

        $exists = in_array($auditReportKey, $existingAuditReportsKeys);

        // Ignore existing if...
        $ignoreExisting =
            // It has an error.
            ( ! empty($existingAuditReports[$auditReportKey])
              && array_key_exists('error', $existingAuditReports[$auditReportKey])) ||
            // It's been forced for re-audit.
            true === $auditsRequest['force'];

        if ($exists && ! $ignoreExisting) {
            $this->output->writeln(
                "<info>The audit report {$auditReportKey} already exists. Skipping running audit...</info>"
            );
            $existingReport = isset($existingAuditReports[$auditReportKey])
                ? $existingAuditReports[$auditReportKey]
                : array();
        }

        return $existingReport;
    }

    /**
     * Get audit standard key.
     *
     * @return string
     */
    public function getAuditStandardKey()
    {
        return $this->auditStandardKey;
    }

    /**
     * Check if audit standard exits.
     *
     * @param  string $auditStandardKey Audit standard key.
     *
     * @throws \Exception When exec() command fails.
     *
     * @return bool
     */
    public function checkAuditStandardExists($auditStandardKey)
    {
        $command = "phpcs -i | grep -i {$auditStandardKey}";

        list ( $output, $err ) = Helpers\ExecHelper::run($command);

        if (! empty($err)) {
            throw new \Exception($output, $err);
        }

        $exists = true;
        if (empty($output)) {
            $exists = false;
        }

        return $exists;
    }

    /**
     * Get audit standard key.
     *
     * @param array $options PHPCS Options.
     *
     * @return string
     */
    private function setAuditStandardKey($options)
    {
        return strtolower(trim($options['standard']));
    }

    /**
     * Gets summary report from detailed report.
     *
     * @param  array $detailedReport Summary report data.
     * @return array Summary report data.
     */
    public function getSummaryReport($detailedReport)
    {
        $summaryData = array(
            'files' => array(),
            'filesCount' => 'N/A',
            'errorsCount' => 'N/A',
            'warningsCount' => 'N/A',
        );

        $errorsCount = 0;
        $warningsCount = 0;
        $filesCount = 0;
        foreach ($detailedReport['files'] as $file => $issues) {
            $summaryData['files'][ $file ] = array(
                'errors'   => $issues['errors'],
                'warnings' => $issues['warnings'],
            );
            $filesCount ++;
            $warningsCount += $issues['warnings'];
            $errorsCount += $issues['errors'];
        }

        $summaryData['filesCount'] = $filesCount;
        $summaryData['errorsCount'] = $errorsCount;
        $summaryData['warningsCount'] = $warningsCount;

        return $summaryData;
    }

    /**
     * Adjust report details for the response.
     *
     * @param string $reportFile Report file.
     * @return array Report details.
     * @throws \Exception When report file cannot be decoded.
     */
    public function parseDetailedReport($reportFile)
    {
        try {
            $report = json_decode(file_get_contents($reportFile), true);
        } catch (\Exception $e) {
	        $report = null;
        }

	    // Just because json_decode() didn't throw an exception doesn't mean that $reportFile
	    // was successfully decoded.
	    if ( null === $report ) {
		    $reportFileError = json_last_error_msg();
		    $message = 'Attempting to parse the report file caused a JSON decoding issue.';
		    $this->output->writeln('<error>' . $message . '</error>');
		    if ( $reportFileError !== JSON_ERROR_NONE ) {
			    $this->output->writeln('<error>JSON Error: ' . $reportFileError . '</error>');
		    }
		    throw new \Exception($message);
        }

        unset($report['totals']['fixable']);
        foreach ($report['files'] as $file => $issues) {
            // Get the file name only.
            $split = explode('/files/', $file);
            $filename = $split[1];
            $report['files'][ $filename ] = $report['files'][ $file ];
            unset($report['files'][ $file ]);

            foreach ($issues['messages'] as $index => $message) {
                unset($report['files'][ $filename ]['messages'][ $index ]['severity']);
                unset($report['files'][ $filename ]['messages'][ $index ]['fixable']);
            }
        }
        return $report;
    }

    /**
     * Gets PHP compatibility information.
     *
     * @param string  $phpcs_report_file Location of the report file.
     * @param boolean $details           Return the detailed report, else PHP compatibility.
     *
     * @return array Details.
     * @throws \Exception When report file cannot be decoded.
     */
    public function getPHPCompatibilityReport($phpcs_report_file, $details = false)
    {

        // Official PHP versions.
        $php_versions = array(
            '5.2',
            '5.3',
            '5.4',
            '5.5',
            '5.6',
            '7.0',
            '7.1',
            '7.2',
        );

        $compatible_versions = array(); // PHP versions that this code is compatible with.
        $compat              = array(); // Sniff results keyed by PHP version.
        $fatal               = false; // If `phpcs` cannot continue.
        $highest_version     = false; // Non-existent purposefully high version.
        $lowest_version      = false; // Non-existent purposefully low version.

        // Count issues.
        $counts = array(
            'totals' => array(
                'errors'   => 0,
                'warnings' => 0,
            ),
        );

        // Only proceed if phpcs successfully created a report file.
        if (file_exists($phpcs_report_file)) {

	        try {
		        $json = file_get_contents( $phpcs_report_file );
		        $json = json_decode( $json, true );
		        $reportFileError = json_last_error_msg();
	        } catch (\Exception $e) {
		        $json = null;
	        }

	        // Just because json_decode() didn't throw an exception doesn't mean that $reportFile was
	        // successfully decoded.
	        if ( null === $json ) {
		        $reportFileError = json_last_error_msg();
		        $message = 'Attempting to parse the report file caused a JSON decoding issue.';
		        $this->output->writeln('<error>' . $message . '</error>');
		        if ( $reportFileError !== JSON_ERROR_NONE ) {
			        $this->output->writeln('<error>JSON Error: ' . $reportFileError . '</error>');
		        }
		        throw new \Exception($message);
	        }

            // Map errors into an array keyed by PHP version.
            foreach ($json['files'] as $file => $errors) {
                // Get the file name only.
                $split = explode('/files/', $file);
                $filename = $split[1];

                foreach ($errors['messages'] as $message) {
                    if (array_key_exists('source', $message) && $message['source'] === "Internal.Exception") {
                        $fatal = true;
                    }

                    $php_version = false;
                    $match = [];
                    preg_match('/(\d)(.\d)+/', $message['message'], $match);
                    if (! isset($match['0'])) {
                        // Perhaps the version is just 'since PHP 7' or sth similar.
                        preg_match('/PHP (\d)/', $message['message'], $match);
                        if (isset($match['1'])) {
                            if ('5' === $match['1']) {
                                $php_version = $match['1'] . '.2';
                            } else {
                                $php_version = $match['1'] . '.0';
                            }
                        }
                    } else {
                        $php_version = $match['0'];
                    }
                    if (false === $php_version) {
                        $php_version = 'general';
                    }

                    if (! array_key_exists($php_version, $compat)) {
                        $compat[ $php_version ] = array(
                            'errors'   => 0,
                            'warnings' => 0,
                            'files'    => array(),
                        );
                    }

                    if (! isset($compat[ $php_version ]['files'][ $filename ])) {
                        $compat[ $php_version ]['files'][ $filename ] = array(
                            'errors'   => 0,
                            'warnings' => 0,
                            'messages' => array(),
                        );
                    }

                    // Count the errors & warnings.
                    if ('ERROR' === $message['type']) {
                        $counts['totals']['errors']++;
                        $compat[ $php_version ]['errors']++;
                        $compat[ $php_version ]['files'][ $filename ]['errors']++;
                    } elseif ('WARNING' === $message['type']) {
                        $counts['totals']['warnings']++;
                        $compat[ $php_version ]['warnings']++;
                        $compat[ $php_version ]['files'][ $filename ]['warnings']++;
                    }

                    unset($message['severity']);
                    unset($message['fixable']);

                    $compat[ $php_version ]['files'][ $filename ]['messages'][] = $message;

                    // Ensure only errors with a version number make it through.
                    if ('general' !== $php_version && 'ERROR' === $message['type']) {
                        // If the message contains 'earlier' any lower versions are not compatible.
                        if (false !== strpos($message['message'], 'earlier')) {
                            if (false === $lowest_version || version_compare($php_version, $lowest_version, '>')) {
                                $lowest_version = $php_version;
                            }
                        }

                        // If the message contains 'since' any higher versions are not compatible.
                        if (false !== strpos($message['message'], 'since')) {
                            if (false === $highest_version || version_compare($php_version, $lowest_version, '<')) {
                                $highest_version = $php_version;
                            }
                        }
                    }
                }
            }

            ksort($compat);

            // Get all versions that report errors.
            foreach ($php_versions as $php_version) {
                // Don't add the general category.
                if ('general' === $php_version) {
                    continue;
                }

                // If the lowest version was found, don't add any lower versions.
                if (false !== $lowest_version && version_compare($php_version, $lowest_version, '<=')) {
                    continue;
                }

                // If the highest version was found, don't add any higher versions.
                if (false !== $highest_version && version_compare($php_version, $lowest_version, '>=')) {
                    continue;
                }

                // Add to compatible versions.
                $compatible_versions[] = $php_version;
            }
        }

        // Return the results.
        if (true === $details) {
            return array_merge($counts, $compat);
        }

        // If not fatal.
        if (! $fatal) {
            return $compatible_versions;
        } else {
            return [ "unknown" ];
        }
    }
}
