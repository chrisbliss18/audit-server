<?php

namespace XWP\Bundle\AuditServerBundle\Extensions\Helpers;

use League\Csv\Reader;
use League\Csv\Writer;

/**
 * Stats helper.
 *
 * @since  v0.1
 */
class StatsHelper
{
	protected $statsPath = 'tmp';

	protected $writer = null;

	public function setStatsPath($settings)
	{
		$this->statsPath = isset($settings['stats_path']) ? $settings['stats_path'] : 'tmp';
	}

	public function createNewWriter($filename, $path = '')
	{
		if (empty($path)) {
			$path = $this->statsPath;
		}

		return Writer::createFromPath(new \SplFileObject($this->statsPath . '/' . $filename, 'a+'), 'w');
	}
}
