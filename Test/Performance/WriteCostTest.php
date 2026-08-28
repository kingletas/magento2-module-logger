<?php
/**
 * WriteCostTest.php
 *
 * @package     Commerce_Logger
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\Logger\Test\Performance;

use Commerce\Logger\Handler;
use Commerce\Logger\Logger;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverInterface;
use PHPUnit\Framework\TestCase;

/**
 * What logging costs the filesystem.
 */
final class WriteCostTest extends TestCase
{
    private int $pathLookups = 0;
    private int $directoryProbes = 0;
    private string $logRoot = '';

    protected function setUp(): void
    {
        $this->pathLookups = 0;
        $this->directoryProbes = 0;

        // Monolog opens the stream on the first record, so the directory has to
        // be real.
        $this->logRoot = sys_get_temp_dir() . '/commerce-logger-cost-' . bin2hex(random_bytes(6));
        mkdir($this->logRoot . '/log/commerce', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logRoot . '/log/commerce/*') ?: [] as $file) {
            unlink($file);
        }

        foreach ([$this->logRoot . '/log/commerce', $this->logRoot . '/log', $this->logRoot] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testTheFilesystemIsProbedTheSameWhateverTheNumberOfRecords(): void
    {
        $costs = [];

        foreach ([1, 500] as $records) {
            $this->pathLookups = 0;
            $this->directoryProbes = 0;

            $logger = $this->channel();

            for ($i = 0; $i < $records; $i++) {
                $logger->error('a line of the same length as any other', ['iteration' => $i]);
            }

            $costs[$records] = $this->pathLookups + $this->directoryProbes;
        }

        self::assertSame(
            $costs[1],
            $costs[500],
            sprintf(
                "Logging is probing the filesystem per record: 1 record cost %d, 500 cost %d.\n"
                . 'Resolving var/log belongs in the constructor, where it happens once per handler.',
                $costs[1],
                $costs[500]
            )
        );
    }

    /**
     * One lookup of `var/log`, one question about whether the subdirectory is
     * there.
     */
    public function testBuildingAHandlerCostsOneLookupAndOneProbe(): void
    {
        $this->channel();

        self::assertSame(1, $this->pathLookups, 'var/log should be resolved once per handler.');
        self::assertSame(1, $this->directoryProbes, 'The directory should be asked about once when it is there.');
    }

    /**
     * Every module here declares its own channel through a `virtualType`, so a
     * full install builds a dozen handlers in any process that logs.
     */
    public function testEachChannelCostsTheSameAsTheFirst(): void
    {
        $this->channel();
        $afterOne = $this->pathLookups + $this->directoryProbes;

        for ($i = 0; $i < 11; $i++) {
            $this->channel();
        }

        self::assertSame(
            $afterOne * 12,
            $this->pathLookups + $this->directoryProbes,
            'Twelve channels should cost twelve times one channel, and no more.'
        );
    }

    private function channel(): Logger
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')
            ->with(AppDirectoryList::LOG)
            ->willReturnCallback(function (): string {
                $this->pathLookups++;

                return $this->logRoot . '/log';
            });

        $driver = $this->createMock(DriverInterface::class);
        $driver->method('isDirectory')->willReturnCallback(function (): bool {
            $this->directoryProbes++;

            return true;
        });

        // The driver is asked whether the directory is there and says yes,
        // which is the probe being counted.
        return new Logger('commerce_cost', [new Handler($driver, $directoryList, 'cost.log', 'commerce')]);
    }
}
