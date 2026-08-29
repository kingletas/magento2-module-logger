<?php
/**
 * @package   Commerce_Logger
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\Logger\Test\Unit;

use Commerce\Logger\Handler;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HandlerTest extends TestCase
{
    private DriverInterface&MockObject $driver;
    private DirectoryList&MockObject $directoryList;

    protected function setUp(): void
    {
        $this->driver = $this->createMock(DriverInterface::class);
        $this->directoryList = $this->createMock(DirectoryList::class);
        $this->directoryList->method('getPath')
            ->with(AppDirectoryList::LOG)
            ->willReturn('/srv/app/var/log');
    }

    public function testItResolvesThePathFromDirectoryListRatherThanTheBpConstant(): void
    {
        $this->driver->method('isDirectory')->willReturn(true);

        $handler = new Handler($this->driver, $this->directoryList, 'orders.log', 'acme');

        // Monolog appends the rotation date, so assert on the directory and stem.
        $this->assertStringStartsWith('/srv/app/var/log/acme/orders-', (string) $handler->getUrl());
        $this->assertStringEndsWith('.log', (string) $handler->getUrl());
    }

    public function testAnEmptySubDirectoryWritesDirectlyToVarLog(): void
    {
        $this->driver->method('isDirectory')->willReturn(true);

        $handler = new Handler($this->driver, $this->directoryList, 'orders.log', '');

        $this->assertStringStartsWith('/srv/app/var/log/orders-', (string) $handler->getUrl());
    }

    public function testItCreatesTheDirectoryWhenMissing(): void
    {
        $this->driver->method('isDirectory')->willReturn(false);
        $this->driver->expects($this->once())
            ->method('createDirectory')
            ->with('/srv/app/var/log/acme');

        new Handler($this->driver, $this->directoryList, 'orders.log', 'acme');
    }

    /**
     * Two PHP workers can race between isDirectory() and createDirectory().
     */
    public function testItToleratesAConcurrentCreatorWinningTheRace(): void
    {
        $this->driver->method('isDirectory')
            ->willReturnOnConsecutiveCalls(false, true);
        $this->driver->method('createDirectory')
            ->willThrowException(new FileSystemException(new Phrase('exists')));

        $handler = new Handler($this->driver, $this->directoryList, 'orders.log', 'acme');

        // Monolog appends the rotation date, so assert on the directory and stem.
        $this->assertStringStartsWith('/srv/app/var/log/acme/orders-', (string) $handler->getUrl());
        $this->assertStringEndsWith('.log', (string) $handler->getUrl());
    }

    public function testItRethrowsWhenTheDirectoryGenuinelyCannotBeCreated(): void
    {
        $this->driver->method('isDirectory')->willReturn(false);
        $this->driver->method('createDirectory')
            ->willThrowException(new FileSystemException(new Phrase('permission denied')));

        $this->expectException(FileSystemException::class);

        new Handler($this->driver, $this->directoryList, 'orders.log', 'acme');
    }

    public function testLevelIsAcceptedAsAConfigFriendlyString(): void
    {
        $this->driver->method('isDirectory')->willReturn(true);

        $handler = new Handler($this->driver, $this->directoryList, 'a.log', 'acme', 7, 'error');

        // Monolog 2 exposes an int here, Monolog 3 a Level enum; compare on the
        // numeric value so the assertion holds on both.
        $level = $handler->getLevel();

        $this->assertSame(400, $level instanceof \Monolog\Level ? $level->value : $level);
    }
}
