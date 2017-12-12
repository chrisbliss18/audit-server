<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;

use Alchemy\Zippy\Zippy;
use GuzzleHttp\Client as guzzleClient;
use GuzzleHttp\Exception\ClientException as guzzleClientException;

/**
 * Files manager.
 *
 * @since  v0.1
 */
class FilesManager extends BaseManager
{
    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Clone git repo.
     *
     * @param  string $gitRepoUrl  Git repo url.
     * @param  string $destination Destination directory.
     *
     * @throws \Exception When repo can't be cloned.
     *
     * @return boolean
     */
    public function cloneRepo($gitRepoUrl = '', $destination = '')
    {
        $created = false;
        if (!empty($gitRepoUrl) && !empty($destination)) {
            $output = array();
            $exitCode = 0;
            exec('git clone --quiet ' . $gitRepoUrl . ' ' . $destination . ' > /dev/null 2>&1', $output, $exitCode);

            if (file_exists($destination) && 0 === $exitCode) {
                $created = true;
            }

            if (0 < $exitCode) {
                throw new \Exception('The git repo could not be cloned', $exitCode);
            }
        }

        return $created;
    }

    /**
     * Extract ZIP Archive.
     *
     * @param  string $filename File name.
     * @param  string $destination     Destination directory.
     *
     * @return boolean.
     */
    public function extractZipArchive($filename, $destination)
    {
        $created = false;

        // Create destination folder
        $this->createDirectory($destination);

        // Extract archive contents to destination
        $zippy = Zippy::load();
        $archive = $zippy->open($filename);
        $archive->extract($destination);

        if (file_exists($destination)) {
            $created = true;
        }

        return $created;
    }

    /**
     * Get directory size.
     *
     * @param  string $directory Directory.
     *
     * @throws \Exception When shell command can't be run.
     *
     * @return string
     */
    public function getDirectorySize($directory)
    {
        $command = 'du -h --max-depth=0 ' . $directory . ' | cut -f1';

        list ( $output, $err ) = Helpers\ExecHelper::run($command);

        if (! empty($err)) {
            throw new \Exception($output);
        }

        return $output;
    }

    /**
     * Download file from url.
     *
     * @param  string $fileUrl File url.
     * @param string  $downloadedFilePath Path to downloaded file.
     *
     * @throws \Exception When file can't be downloaded.
     *
     * @return  void
     */
    public function downloadFile($fileUrl, $downloadedFilePath)
    {
        $client = new guzzleClient();
        try {
            $client->request('GET', $fileUrl, ['sink' => $downloadedFilePath]);
        } catch (guzzleClientException $e) {
            throw new \Exception($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Generate files lists from directory.
     *
     * @param  string $directory Directory path.
     *
     * @return array            Hashed values of all files.
     */
    public function generateFilesList($directory)
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS));

        $processed_files = array();

        /**
         * The file to checksum.
         *
         * @var \DirectoryIterator $file
         */
        foreach ($files as $file) {
            if (! $file->isDir() && ! $file->isLink()) {
                // More reliable to use hash of file contents than trying to assert the correct path.
                $processed_files[] = hash_file('sha256', $file->getPathname());
            }
        }

        asort($processed_files); // Sort hashes to keep it uniform.

        return array_values($processed_files); // Reset indexes after `asort` and return.
    }

    /**
     * Generate directories list.
     *
     * @param  array $directory Directory path.
     *
     * @return array            Directories.
     */
    public function generateDirectoriesList($directory)
    {
        $rii = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);

        $directories = array();
        foreach ($rii as $file) {
            if ($file->isDir()) {
                $directories[] = $file->getPathname();
            }
        }

        return $directories;
    }

    /**
     * Generate checksum.
     *
     * @param  string $auditsFilesDirectory Directory to checksum.
     *
     * @return string Checksum
     */
    public function generateChecksum($auditsFilesDirectory)
    {
        $filesList = $this->generateFilesList($auditsFilesDirectory);

        return hash('sha256', \json_encode($filesList));
    }

    /**
     * Create directory.
     *
     * @param  string  $directoryPath Directory path
     * @param  integer $permissions   permissions.
     * @param  boolean $recursive     Create recursively.
     *
     * @return void
     */
    public function createDirectory($directoryPath, $permissions = 0777, $recursive = true)
    {
        mkdir($directoryPath, $permissions, true);
    }

    /**
     * Recursively Delete a directory and all its contents with PHP.
     * http://www.internoetics.com/2016/01/23/recursively-delete-directory-contents-php/
     *
     * @param  string $dir Directory to delete.
     *
     * @return boolean True if deleted.
     */
    public function deleteDirectory($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    (filetype($dir . '/' . $object) == 'dir') ? $this->deleteDirectory($dir . '/' . $object) : unlink($dir . '/' . $object);
                }
            }
            reset($objects);
            return rmdir($dir) ? true : false;
        }
    }
}
