<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;

use XWP\Bundle\AuditServerBundle\Extensions\Helpers\YamlHelper;

/**
 * Code identity manager.
 *
 * @since  v0.1
 */
class CodeIdentityManager extends BaseManager
{
    /**
     * Constructor.
     */
    public function __construct()
    {
    }

    /**
     * Get WordPress code info.
     *
     * @param  string  $auditsFilesDirectory Audits files directory.
     *
     * @return array WordPress code info.
     */
    public function getWordPressCodeInfo($auditsFilesDirectory)
    {
        $codeInfo = array(
            'type' => 'unknown',
            'details' => array(),
        );

        if (empty($auditsFilesDirectory)) {
            return $codeInfo;
        }

        $plugin_theme_header = $this->getPluginThemeHeader($auditsFilesDirectory);

        if (isset($plugin_theme_header['ThemeURI'])) {
            $codeInfo['type'] = 'theme';
        } elseif (isset($plugin_theme_header['PluginURI'])) {
            $codeInfo['type'] = 'plugin';
        } else {
            return $codeInfo;
        }

        foreach ($plugin_theme_header as $key => $value) {
            if ($value) {
                $codeInfo['details'][] = array(
                    'key' => $key,
                    'value' => $value,
                );
            }
        }

        return $codeInfo;
    }

    /**
     * Get theme or plugin header.
     * The function can only recognize and get the header of a valid plugin/theme.
     *
     * @param string $auditsFilesDirectory Audit files directory path.
     * @param boolean $directory_scanned Used to stop the recursion after a directory has been checked.
     *
     * @return array $header
     */
    public function getPluginThemeHeader($auditsFilesDirectory, $directory_scanned = false)
    {

        $files = array_diff(scandir($auditsFilesDirectory), array( '.', '..' ));
        $header = array();

        /**
         * Plugin
         *  - A plugin may have only one .php file.
         *  - If a folder is used, it must have at least one .php file.
         *  - Plugin's main file cannot be inside a subdirectory.
         *  - A plugin file must have a comment header and it should at least have 'Plugin Name'.
         *
         * Theme
         *  - Unlike a plugin, only style.css can not be used as a theme, it must be inside a folder.
         *  - A theme's style.css cannot be inside a subdirectory.
         *  - A theme's style.css must have a comment header and it should at least have 'Theme Name'.
         */
        if (! empty($files)) {
            foreach ($files as $file) {
                if (! empty($header)) {
                    break;
                }

                $file_path = $auditsFilesDirectory . '/' . $file;
                $file_parts = pathinfo($file_path);
                $file_extension = isset($file_parts['extension']) ? $file_parts['extension'] : '';

                if (is_dir($file_path) && ! $directory_scanned) {
                    $header = $this->getPluginThemeHeader($file_path, true);
                } elseif ($directory_scanned && 'style.css' === $file && is_readable($file_path)) {
                    $header = $this->getThemeHeader($file_path);
                } elseif ('php' === $file_extension && is_readable($file_path)) {
                    $header = $this->getPluginHeader($file_path);
                }
            }
        }

        return $header;
    }

    /**
     * Gets theme header.
     *
     * @param string $file file path.
     * @return array $header.
     */
    protected function getThemeHeader($file)
    {
        $default_headers = array(
            'Name' => 'Theme Name',
            'ThemeURI' => 'Theme URI',
            'Version' => 'Version',
            'Description' => 'Description',
            'Author' => 'Author',
            'AuthorURI' => 'Author URI',
            'TextDomain' => 'Text Domain',
            'DomainPath' => 'Domain Path',
        );

        return $this->getFileHeader($file, $default_headers);
    }

    /**
     * Gets plugin header.
     *
     * @param string $file file path.
     * @return array $header.
     */
    protected function getPluginHeader($file)
    {
        $default_headers = array(
            'Name' => 'Plugin Name',
            'PluginURI' => 'Plugin URI',
            'Version' => 'Version',
            'Description' => 'Description',
            'Author' => 'Author',
            'AuthorURI' => 'Author URI',
            'TextDomain' => 'Text Domain',
            'DomainPath' => 'Domain Path',
        );

        return $this->getFileHeader($file, $default_headers);
    }

    /**
     * Gets file header.
     * Part of code is taken from WordPress get_file_data() function.
     *
     * @param string $file File path.
     * @param array $default_headers Default header for theme or plugin.
     * @return array|boolean $all_headers.
     */
    protected function getFileHeader($file, $default_headers)
    {
        $opened_file = fopen($file, 'r');

        /**
         * Read only first 8kiB of the file.
         */
        $file_data = fread($opened_file, 8192);
        fclose($opened_file);

        /**
         * Make sure we catch CR-only line endings.
         */
        $file_data = str_replace("\r", "\n", $file_data);

        $headers = $default_headers;

        foreach ($headers as $field => $regex) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
                $headers[ $field ] = trim(preg_replace("/\s*(?:\*\/|\?>).*/", '', $match[1]));
            } else {
                $headers[ $field ] = '';
            }
        }

        if (empty($headers['Name'])) {
            $headers = false;
        }

        return $headers;
    }

    /**
     * Get lines of codes counts.
     *
     * @param  string $auditsFilesDirectory Audits files directory.
     *
     * @throws \Exception Contains the output of the failed `exec` command.
     *
     * @return array Lines of codes counts.
     */
    public function getLinesOfCodeCounts($auditsFilesDirectory)
    {
        $command = 'cloc ' . $auditsFilesDirectory . ' --quiet --not-match-f=".*\.min.(js|css)" --yaml';

        list ( $output, $err ) = Helpers\ExecHelper::run($command);

        if (! empty($err)) {
            throw new \Exception($output, $err);
        }

        try {
            $parsedOutput = yaml_parse($output);
        } catch (\Symfony\Component\Debug\Exception\ContextErrorException $e) {
            $y = new YamlHelper();
            $parsedOutput = $y->load($output);
        }

        if (!is_array($parsedOutput)) {
            $parsedOutput = [];
        }

        // Rename keys to be consistent with the snake_case standard.
        $keysToClean = [
            'PHP' => 'php',
            'CSS' => 'css',
            'SUM' => 'sum',
        ];

        foreach ($keysToClean as $key => $newKey) {
            if (isset($parsedOutput[$key])) {
                $parsedOutput[$newKey] = $parsedOutput[$key];
                unset($parsedOutput[$key]);
            }
        }

        // Remove header which is not useful to pass back to the api.
        if (isset($parsedOutput['header'])) {
            unset($parsedOutput['header']);
        }

        return $parsedOutput;
    }

    /**
     * Get the codeInfo with the "details" transformed into usable array.
     *
     * @param array $codeInfo Original item.
     *
     * @return array Transformed item.
     */
    public static function transformCodeInfo($codeInfo)
    {

        $details = array();

        foreach ($codeInfo['details'] as $item) {
            $key             = strtolower($item['key']);
            $details[ $key ] = $item['value'];
        }

        $codeInfo['details'] = $details;

        return $codeInfo;
    }
}
