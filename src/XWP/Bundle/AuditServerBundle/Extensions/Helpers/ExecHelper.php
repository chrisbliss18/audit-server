<?php
/**
 * Helper utilities for interacting with the shell.
 *
 * @package WPTideAuditServer
 */

namespace XWP\Bundle\AuditServerBundle\Extensions\Helpers;

/**
 * Exec helper.
 *
 * @since  v0.1
 */
class ExecHelper
{

    /**
     * Helper function to run `exec()`.
     *
     * This will return an array with stdOut and shell exit code.
     *
     * @param string $command The shell command to execute.
     * @param bool   $stringify Stringify output? Array used if false.
     * @param bool   $devnull Send output to /dev/null first.
     * @param bool   $assoc Return results as associative array.
     *
     * @return array Contains the ['output'] and shell exec code in ['exit'].
     */
    public static function run($command, $stringify = true, $devnull = false, $assoc = false)
    {

        $output = false;
        $exit   = false;

        if ($devnull) {
            $command .= ' > /dev/null';
        }

        exec($command . ' 2>&1', $output, $exit);

        if ($stringify) {
            $output = implode($output, "\n");
        }

        if ($assoc) {
            return array(
                'output' => $output,
                'exit'   => $exit,
            );
        } else {
            return array( $output, $exit );
        }
    }
}
