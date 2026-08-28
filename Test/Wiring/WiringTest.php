<?php
/**
 * WiringTest.php
 *
 * @package     Commerce_Logger
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\Logger\Test\Wiring;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;

/**
 * This module's `etc/` against the code it names.
 */
class WiringTest extends TestCase
{
    /**
     * Every XML file in `etc/` parses.
     */
    public function testEveryConfigFileParses(): void
    {
        $broken = [];

        foreach (glob(dirname(__DIR__, 2) . '/etc/*.xml') ?: [] as $file) {
            $previous = libxml_use_internal_errors(true);

            if (simplexml_load_file($file) === false) {
                $broken[] = basename($file);
            }

            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertSame([], $broken, 'These files do not parse as XML: ' . implode(', ', $broken));
    }

    public function testEveryVirtualTypeNamesAClassThatExists(): void
    {
        $missing = [];

        foreach ($this->virtualTypes() as $name => $type) {
            if (!class_exists($type)) {
                $missing[] = sprintf('%s is declared as a %s, which does not exist', $name, $type);
            }
        }

        $this->assertSame([], $missing, implode("\n  ", $missing));
    }

    /**
     * Magento ignores an argument name that matches no constructor parameter.
     */
    public function testEveryArgumentNamesARealConstructorParameter(): void
    {
        $unknown = [];

        foreach ($this->virtualTypes() as $name => $type) {
            if (!class_exists($type)) {
                continue;
            }

            $constructor = (new ReflectionClass($type))->getConstructor();
            $parameters = $constructor === null
                ? []
                : array_map(
                    static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                    $constructor->getParameters()
                );

            foreach ($this->argumentNames($name) as $argument) {
                if (!in_array($argument, $parameters, true)) {
                    $unknown[] = sprintf(
                        '%s passes "%s", which %s::__construct() does not take',
                        $name,
                        $argument,
                        $type
                    );
                }
            }
        }

        $this->assertSame([], $unknown, implode("\n  ", $unknown));
    }

    /**
     * The two names every other module's `di.xml` refers to.
     */
    public function testTheBaseVirtualTypesKeepTheirPublishedNames(): void
    {
        $this->assertSame(
            ['Commerce\Logger\Handler\Base', 'Commerce\Logger\Logger\Base'],
            array_keys($this->virtualTypes())
        );
    }

    /**
     * @return array<string, string> virtualType name => the type it extends.
     */
    private function virtualTypes(): array
    {
        $types = [];

        foreach ($this->config()->virtualType as $virtualType) {
            $types[(string) $virtualType['name']] = (string) $virtualType['type'];
        }

        return $types;
    }

    /**
     * @return string[]
     */
    private function argumentNames(string $virtualTypeName): array
    {
        foreach ($this->config()->virtualType as $virtualType) {
            if ((string) $virtualType['name'] !== $virtualTypeName) {
                continue;
            }

            return array_map(
                static fn (SimpleXMLElement $argument): string => (string) $argument['name'],
                iterator_to_array($virtualType->arguments->argument ?? [], false)
            );
        }

        return [];
    }

    private function config(): SimpleXMLElement
    {
        $config = simplexml_load_file(dirname(__DIR__, 2) . '/etc/di.xml');

        $this->assertInstanceOf(SimpleXMLElement::class, $config, 'etc/di.xml did not parse.');

        return $config;
    }
}
