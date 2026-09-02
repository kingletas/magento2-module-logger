# Commerce_Logger

A small, dependency-free logging package for Magento 2 modules. It gives you one class to point a `virtualType` at, and a rotating file handler that writes under the store's real `var/log` directory.

---

## What it adds

Core's handler is fine, but every module ends up re-declaring the same boilerplate and hardcoding `BP . '/var/log/...'`. This package fixes three things:

| Problem | Fix |
| --- | --- |
| `BP . '/var/log/...'` breaks when `var/` is relocated (containers, read-only roots, shared volumes) | Resolves the path via `Filesystem\DirectoryList` |
| Directory creation races between concurrent PHP workers throw `FileSystemException` | Creation is retried-and-verified, not assumed |
| Log level, retention and permissions are hardcoded in a subclass | All are `di.xml` scalars, so channels need no PHP |

---

## Installation

```bash
composer require commerce/module-logger
bin/magento module:enable Commerce_Logger
```

---

## Usage

Declare one channel per module. No PHP required:

```xml
<virtualType name="Acme\Orders\Logger\Handler" type="Commerce\Logger\Handler">
    <arguments>
        <argument name="fileName" xsi:type="string">orders.log</argument>
        <argument name="subDirectory" xsi:type="string">acme</argument>
        <argument name="maxFiles" xsi:type="number">14</argument>
        <argument name="level" xsi:type="string">info</argument>
    </arguments>
</virtualType>

<virtualType name="Acme\Orders\Logger" type="Commerce\Logger\Logger">
    <arguments>
        <argument name="name" xsi:type="string">acme_orders</argument>
        <argument name="handlers" xsi:type="array">
            <item name="system" xsi:type="object">Acme\Orders\Logger\Handler</item>
        </argument>
    </arguments>
</virtualType>

<type name="Acme\Orders\Model\Processor">
    <arguments>
        <argument name="logger" xsi:type="object">Acme\Orders\Logger</argument>
    </arguments>
</type>
```

Then type-hint the PSR-3 interface, never the concrete class:

```php
public function __construct(private readonly \Psr\Log\LoggerInterface $logger) {}
```

That writes to `var/log/acme/orders-2026-08-26.log`, keeping 14 days.

---

## Configuration reference

`Commerce\Logger\Handler` constructor arguments:

| Argument | Type | Default | Meaning |
| --- | --- | --- | --- |
| `fileName` | string | `default.log` | Base name; Monolog appends the rotation date |
| `subDirectory` | string | `commerce` | Folder under `var/log`; `''` writes to `var/log` directly |
| `maxFiles` | int | `7` | Rotated files retained; `0` keeps every file |
| `level` | string | `debug` | Monolog level name (`debug`, `info`, `warning`, `error`, ...) |
| `bubble` | bool | `true` | Whether records continue to later handlers |
| `filePermission` | int\|null | `null` | chmod for newly created files, e.g. `0640` |

---

## Compatibility

Monolog 2 and 3 are both supported; the level argument is normalised through `Monolog\Logger::toMonologLevel()` rather than referencing the v3-only `Level` enum.

---

## Gotchas

- **Never extend `Magento\Framework\Logger\Monolog`.** That type carries core's `system`, `debug` and `syslog` handlers, so a "dedicated" channel is silently duplicated into `system.log` as well as its own file. `Commerce\Logger\Logger` extends `Monolog\Logger` for exactly this reason. Verify by counting the channel name in both files, not just yours.
- **`maxFiles = 0` keeps every rotated file forever.** It is Monolog's "unlimited", not "none".
- **The level is a string and is resolved at construction.** An unrecognised name throws while the object graph is being built, which surfaces as a DI error rather than a logging error.
- **Do not redeclare `protected $fileName` with a type.** `RotatingFileHandler` declares it untyped, and typing a parent's untyped property is a PHP fatal. Pass the value through the constructor instead.

---

## Tests

```bash
make check
```

The coding standard and all four suites — 25 tests, no database and no Magento bootstrap. Narrow it to one suite with `SUITE`:

```bash
make test SUITE=behaviour
```

The suites run against a real Magento installation without being installed into it. `M2_VENDOR` names that installation's `vendor` directory, and `Test/bootstrap.php` builds an autoloader from its composer map — which is also why they work where the host's own `vendor/autoload.php` is broken.

---

## Rebranding

This package ships under the `Commerce` vendor namespace. To adopt your own, run the shared script from the repository root:

```bash
php bin/rebrand Acme
```
