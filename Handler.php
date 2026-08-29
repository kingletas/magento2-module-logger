<?php
/**
 * @package   Commerce_Logger
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\Logger;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\DirectoryList as FilesystemDirectoryList;
use Magento\Framework\Filesystem\DriverInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as MonologLogger;

/**
 * Rotating file handler that writes below the Magento var/log directory.
 */
class Handler extends RotatingFileHandler
{
    public const string DEFAULT_SUBDIRECTORY = 'commerce';
    public const string DEFAULT_FILENAME = 'default.log';
    public const int DEFAULT_MAX_FILES = 7;

    /**
     * @param DriverInterface          $filesystem     Driver for the log directory.
     * @param FilesystemDirectoryList  $directoryList  Resolves the absolute var/log path.
     * @param string                   $fileName       Base file name, rotated by date.
     * @param string                   $subDirectory   Folder under var/log; '' writes directly to var/log.
     * @param int                      $maxFiles       Rotated files to retain; 0 keeps every file.
     * @param string                   $level          Monolog level name, e.g. "debug" or "error".
     * @param bool                     $bubble         Whether records propagate to later handlers.
     * @param int|null                 $filePermission chmod applied to newly created log files.
     *
     * @throws FileSystemException When the log directory exists but cannot be created or written.
     */
    public function __construct(
        DriverInterface $filesystem,
        FilesystemDirectoryList $directoryList,
        string $fileName = self::DEFAULT_FILENAME,
        string $subDirectory = self::DEFAULT_SUBDIRECTORY,
        int $maxFiles = self::DEFAULT_MAX_FILES,
        string $level = 'debug',
        bool $bubble = true,
        ?int $filePermission = null
    ) {
        $directory = $this->resolveDirectory($filesystem, $directoryList, $subDirectory);

        parent::__construct(
            $directory . '/' . ltrim($fileName, '/'),
            max(0, $maxFiles),
            MonologLogger::toMonologLevel($level),
            $bubble,
            $filePermission
        );
    }

    /**
     * Resolve and create the target directory, tolerating a concurrent creator.
     *
     * @throws FileSystemException
     */
    private function resolveDirectory(
        DriverInterface $filesystem,
        FilesystemDirectoryList $directoryList,
        string $subDirectory
    ): string {
        $directory = rtrim($directoryList->getPath(DirectoryList::LOG), '/');
        $subDirectory = trim($subDirectory, '/');

        if ($subDirectory !== '') {
            $directory .= '/' . $subDirectory;
        }

        if ($filesystem->isDirectory($directory)) {
            return $directory;
        }

        try {
            $filesystem->createDirectory($directory);
        } catch (FileSystemException $e) {
            // Another process may have created it between the check and the
            // call.
            if (!$this->directoryExists($filesystem, $directory)) {
                throw $e;
            }
        }

        return $directory;
    }

    /**
     * Whether the directory is there *now*.
     */
    private function directoryExists(DriverInterface $filesystem, string $directory): bool
    {
        return $filesystem->isDirectory($directory);
    }
}
