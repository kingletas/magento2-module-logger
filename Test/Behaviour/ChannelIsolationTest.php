<?php
/**
 * ChannelIsolationTest.php
 *
 * @package     Commerce_Logger
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\Logger\Test\Behaviour;

use Commerce\Logger\Handler;
use Commerce\Logger\Logger;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Two channels, two files, and nothing in anybody else's.
 */
class ChannelIsolationTest extends TestCase
{
    private string $logRoot = '';

    protected function setUp(): void
    {
        $this->logRoot = sys_get_temp_dir() . '/commerce-logger-' . bin2hex(random_bytes(6));
        mkdir($this->logRoot . '/log', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->logRoot);
    }

    public function testEachChannelWritesOnlyToItsOwnFile(): void
    {
        $orders = $this->channel('commerce_orders', 'orders.log');
        $inventory = $this->channel('commerce_inventory', 'inventory.log');

        $orders->error('an order failed to place');
        $inventory->error('a stock sync failed');

        $this->assertStringContainsString('an order failed to place', $this->contentsOf('orders'));
        $this->assertStringNotContainsString('a stock sync failed', $this->contentsOf('orders'));

        $this->assertStringContainsString('a stock sync failed', $this->contentsOf('inventory'));
        $this->assertStringNotContainsString('an order failed to place', $this->contentsOf('inventory'));
    }

    /**
     * Two modules' logs are read together, so every line says where it came
     * from.
     */
    public function testARecordNamesItsChannel(): void
    {
        $this->channel('commerce_orders', 'orders.log')->warning('a coupon was rejected');

        $this->assertStringContainsString('commerce_orders', $this->contentsOf('orders'));
    }

    /**
     * The level is a `di.xml` scalar so that a store can turn one channel up
     * during an incident without touching PHP.
     */
    public function testAChannelHonoursTheLevelItWasConfiguredWith(): void
    {
        $logger = $this->channel('commerce_orders', 'orders.log', 'error');

        $logger->info('routine, and not worth a line');
        $logger->error('not routine');

        $contents = $this->contentsOf('orders');

        $this->assertStringNotContainsString('routine, and not worth a line', $contents);
        $this->assertStringContainsString('not routine', $contents);
    }

    /**
     * This is the inherited-handler trap stated as an assertion.
     */
    public function testAChannelHasNoHandlersItWasNotGiven(): void
    {
        $handler = $this->handler('orders.log');
        $logger = new Logger('commerce_orders', [$handler]);

        $this->assertSame(
            [$handler],
            $logger->getHandlers(),
            'A channel that inherits handlers duplicates every record into whatever they point at.'
        );
    }

    /**
     * All fourteen modules write into `var/log/commerce/`, so the directory is
     * the one thing they genuinely do share.
     */
    public function testChannelsInTheSameDirectoryKeepSeparateFiles(): void
    {
        $this->channel('commerce_orders', 'orders.log')->error('first');
        $this->channel('commerce_inventory', 'inventory.log')->error('second');

        $files = $this->logFiles();

        $this->assertCount(2, $files, 'Two channels, two files: ' . implode(', ', $files));
    }

    private function channel(string $name, string $fileName, string $level = 'debug'): Logger
    {
        return new Logger($name, [$this->handler($fileName, $level)]);
    }

    private function handler(string $fileName, string $level = 'debug'): Handler
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')
            ->with(AppDirectoryList::LOG)
            ->willReturn($this->logRoot . '/log');

        return new Handler(new File(), $directoryList, $fileName, 'commerce', 7, $level);
    }

    /**
     * Monolog appends the rotation date to the file name, so the file is found
     * by its stem rather than named outright.
     */
    private function contentsOf(string $stem): string
    {
        $matches = glob($this->logRoot . '/log/commerce/' . $stem . '-*.log') ?: [];

        $this->assertNotSame([], $matches, sprintf('Nothing was written for the "%s" channel.', $stem));

        return (string) file_get_contents($matches[0]);
    }

    /**
     * @return string[]
     */
    private function logFiles(): array
    {
        return array_map('basename', glob($this->logRoot . '/log/commerce/*.log') ?: []);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
