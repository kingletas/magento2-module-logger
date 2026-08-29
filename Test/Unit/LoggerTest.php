<?php
/**
 * @package   Commerce_Logger
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\Logger\Test\Unit;

use Commerce\Logger\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The class adds no behaviour, and the two contracts di.xml relies on still
 * hold.
 */
class LoggerTest extends TestCase
{
    public function testItIsAPsrLoggerSoConsumersCanTypeHintTheFrameworkInterface(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, new Logger('commerce'));
    }

    public function testItIsAMonologLoggerSoHandlersAndProcessorsWorkUnchanged(): void
    {
        $this->assertInstanceOf(MonologLogger::class, new Logger('commerce'));
    }

    /**
     * The constructor argument di.xml sets.
     */
    public function testTheConstructorNameBecomesTheChannel(): void
    {
        $this->assertSame('commerce_healthcheck', (new Logger('commerce_healthcheck'))->getName());
    }

    public function testHandlersPassedInAreTheOnesUsed(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('commerce', [$handler]);

        $logger->warning('a queue consumer rethrew');

        $this->assertTrue($handler->hasWarningThatContains('a queue consumer rethrew'));
    }

    /**
     * Records carry the channel, which is what makes a shared log file readable
     * when two modules are writing to it.
     */
    public function testARecordCarriesTheChannel(): void
    {
        $handler = new TestHandler();
        (new Logger('commerce_search', [$handler]))->error('backend refused the batch');

        $records = $handler->getRecords();

        $this->assertCount(1, $records);
        $this->assertSame('commerce_search', $records[0]['channel']);
    }

    public function testContextIsCarriedThroughToTheHandler(): void
    {
        $handler = new TestHandler();
        (new Logger('commerce', [$handler]))->error('export failed', ['sku' => 'SKU-1']);

        $records = $handler->getRecords();

        $this->assertSame(['sku' => 'SKU-1'], $records[0]['context']);
    }

    /**
     * "Adds no behaviour" is a claim worth checking rather than trusting.
     */
    public function testItDeclaresNoMethodsOfItsOwn(): void
    {
        $ownMethods = array_values(array_filter(
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                (new \ReflectionClass(Logger::class))->getMethods()
            ),
            static fn (string $name): bool =>
                (new \ReflectionMethod(Logger::class, $name))->getDeclaringClass()->getName() === Logger::class
        ));

        $this->assertSame([], $ownMethods, 'Custom behaviour belongs in a Handler or a Processor.');
    }
}
