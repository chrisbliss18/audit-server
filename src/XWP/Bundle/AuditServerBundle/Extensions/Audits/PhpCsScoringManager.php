<?php
/**
 * This file defines the PhpCsScoringManager class.
 *
 * @package WPTideAuditServer
 */

namespace XWP\Bundle\AuditServerBundle\Extensions\Audits;

use XWP\Bundle\AuditServerBundle\Extensions\BaseManager;

/**
 * PHPCS scoring manager.
 *
 * @since  v0.1
 */
class PhpCsScoringManager extends BaseManager {

	/**
	 * Settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param  array $settings Settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Get scores.
	 *
	 * @param string  $reportFile     Report file.
	 * @param array   $scoringOptions Scoring Options.
	 * @param integer $linesOfCode    Lines of code.
	 *
	 * @return array
	 */
	public function getScores( $reportFile, $scoringOptions, $linesOfCode ) {
		$defaultScoringOptions = array(
			'defaultPoints' => array(
				'score' => 100,
				'total' => 0,
			),
		);
		$scoringOptions = array_merge( $defaultScoringOptions, $scoringOptions );

		$defaultPoints = ! empty( $scoringOptions['defaultPoints'] ) ? $scoringOptions['defaultPoints'] : $defaultScoringOptions['defaultPoints'];
		$weightingsFile = ! empty( $scoringOptions['weightingsFile'] ) ? $scoringOptions['weightingsFile'] : '';

		$report = json_decode( file_get_contents( $reportFile ), true );
		$weightings = json_decode( file_get_contents( $this->settings['weightings_path'] . '/phpcs/' . $weightingsFile ), true );

		$scores = array();

		if ( ! empty( $report ) && ! empty( $weightings ) ) {
			$issuesFrequency = $this->getIssuesFrequency( $report );

			foreach ( $weightings as $category => $categoryConf ) {
				$scores[ $category ] = ! empty( $issuesFrequency ) ? $this->calculateScore( $issuesFrequency, $categoryConf, $defaultPoints, $linesOfCode ) : 100;
			}
		}

		return $scores;
	}

	/**
	 * Get issue frequency.
	 *
	 * @param  array $report Report.
	 *
	 * @return array
	 */
	private function getIssuesFrequency( $report ) {
		$sources = array();

		foreach ( $report['files'] as $file => $issues ) {
			foreach ( $issues['messages'] as $message ) {
				$sources[] = $message['source'];
			}
		}

		return array_count_values( $sources );
	}

	/**
	 * Calculate score.
	 *
	 * @param array   $issuesFrequency Issues frequency.
	 * @param array   $categoryConf    Sniff category conf.
	 * @param array   $defaultPoints  Default points.
	 * @param integer $linesOfCode Lines of code.
	 *
	 * @return int
	 */
	public function calculateScore( $issuesFrequency, $categoryConf, $defaultPoints, $linesOfCode ) {
		$score = $defaultPoints['score'];
		$total = $defaultPoints['total'];

		/*
		 * Multiplier is for considering the lines of code within the score.
		 * The logic is that the issue should have 100% weight in case of 100 lines.
		 * For example 1 incorrect space used in case of 100 lines will take off 1*weight.
		 * In case of 1000 lines 0.1* weight and in case of 10 lines 10*weight.
		 * Essentially that's dividing 100 with lines of code
		 * and using that as the multiplier for the weight.
		 *
		 * 100 / 1000 = 0.1
		 */
		$multiplier = 100 / $linesOfCode;

		$occurredIssues = array();
		foreach ( $issuesFrequency as $issue => $frequency ) {
			$sniffs = $categoryConf['sniffs'];

			// If the child sniff is in the file and if the weighting is set, take that instead of the main sniff.
			if ( array_key_exists( $issue, $sniffs ) && isset( $sniffs[ $issue ]['weighting'] ) ) {
				$issueConf = $issue;
			} else {
				$issueConf = $this->getIssueParent( $issue );
			}

			// If the parent is not found but the issue itself is configured, use that.
			if ( ! array_key_exists( $issueConf, $sniffs ) ) {
				if ( ! array_key_exists( $issue, $sniffs ) ) {
					continue;
				} else {
					$issueConf = $issue;
				}
			}

			if ( ! isset( $sniffs[ $issueConf ]['weighting'] ) ) {
				continue;
			}

			$penalty = 0;

			/*
			 * If the issue has an initial value, take the initial once
			 * and the others occurrences get the "normal" weighting.
			 */
			if ( isset( $sniffs[ $issueConf ]['initial'] ) ) {
				if ( ! isset( $occurredIssues[ $issueConf ] ) ) {
					$penalty += ( $sniffs[ $issueConf ]['initial'] * $multiplier );
					$frequency--;
					$occurredIssues[ $issueConf ] = array(
						'used' => 0,
					);
				}
			}

			$penalty += $frequency * ( $sniffs[ $issueConf ]['weighting'] * $multiplier );

			// If the issue has max weighting value, don't go over that.
			if ( isset( $sniffs[ $issueConf ]['max'] ) ) {
				if ( ! isset( $occurredIssues[ $issueConf ] ) ) {
					if ( $penalty >= $sniffs[ $issueConf ]['max'] ) {
						$penalty = $sniffs[ $issueConf ]['max'];
					}
					$occurredIssues[ $issueConf ] = array(
						'used' => $penalty,
					);
				} else {
					$weightingLeft = $sniffs[ $issueConf ]['max'] - $occurredIssues[ $issueConf ]['used'];
					if ( $penalty >= $weightingLeft ) {
						$penalty = $weightingLeft;
					}
					$occurredIssues[ $issueConf ]['used'] += $penalty;
				}
			}

			$total += $penalty;
		}

		$finalScore = $score - $total;

		return $finalScore > 0 ? round( $finalScore, 2 ) : 0;
	}

	/**
	 * Get issue parent.
	 *
	 * @param string $issue Issue.
	 *
	 * @return string
	 */
	private function getIssueParent( $issue ) {

		// @todo: this is not very elegant, surely there is a better way.
		$issueParts = explode( '.', $issue );

		// We are assuming we are always looking for the first 3 parts of 4. This may not always be the case.
		array_pop( $issueParts );

		$issueConf = implode( '.', $issueParts );
		return $issueConf;
	}

	/**
	 * Gets rating of one audit (standard).
	 *
	 * @param array $auditsResults  The results of an audit.
	 * @param array $scoringOptions The options used to score an audit.
	 * @return bool|int False or rating.
	 */
	public function getAuditRating( $auditsResults, $scoringOptions ) {

		$weightingsFile = ! empty( $scoringOptions['weightingsFile'] ) ? $scoringOptions['weightingsFile'] : '';

		try {
			$weightings = json_decode( file_get_contents( $this->settings['weightings_path'] . '/phpcs/' . $weightingsFile ), true );
		} catch ( \Exception $e ) {
			return false;
		}

		/*
		 * Render through results per category.
		 * Get the weight of a category.
		 * Get score of each audit.
		 */
		$rating = 0;
		foreach ( $auditsResults as $scores ) {
			foreach ( $scores as $category => $score ) {
				if ( ! isset( $weightings[ $category ] ) ) {
					continue;
				}
				if ( ! isset( $weightings[ $category ]['weight'] ) ) {
					$this->output->writeln( '<error>Category weight is missing, skipping: ' . $category . '</error>' );
					return false;
				}
				$rating += round( $weightings[ $category ]['weight'] * $score, 2 );
			}
		}
		return $rating;
	}
}
