# fiber-interop

Ten Guzzle requests that take 3.0 s one after another take 0.3 s side by side. The code stays
ordinary PHP: no promises, no callbacks. It runs unchanged on phasync, Revolt, AMPHP, ReactPHP and
Swoole; you pick the event loop by installing one adapter package.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use function async\{run, go, await_all};

$client = async\guzzle\client(['base_uri' => 'http://127.0.0.1:8080']);

$t = microtime(true);
$bodies = run(fn() => await_all(array_map(
    fn($i) => go(fn() => (string) $client->get("/item/$i")->getBody()),
    range(1, 10),
)));
printf("%d responses in %.2f s\n", count($bodies), microtime(true) - $t);
```

| Ten requests to an endpoint that answers after 0.3 s | Time |
|---|---|
| plain Guzzle client, one after another | 3.02 s |
| the code above, phasync adapter | 0.33 s |
| the code above, Revolt, AMPHP or ReactPHP adapter | 0.32 s |

Measured on 2026-10-14 with PHP 8.5.11 (CLI, NTS) on Linux, Intel Xeon E5-2697 v3, against a
local `php -S` server with 16 workers; each number is the same in three runs. The sources are in
[examples/get-started](https://github.com/fiber-interop/fiber-interop.github.io/tree/main/examples/get-started).
Swoole was not measured on this machine.

## Get started

[Get started](get-started.html): which adapter to install, your first program, the five functions
you need, and what still blocks.

## Make your library fiber-friendly

[Library authors](tutorial-library.html): depend on `fiber-interop/api` instead of one event loop,
so your package overlaps its waiting on every runtime. Guzzle is the worked example.

## What overlaps today

Inside `async\run()`, with any adapter ([details](integrations.html)):

- **curl**: `async\curl\exec()` lets other tasks run while a transfer waits (`fiber-interop/curl`).
- **Guzzle**: requests from different tasks overlap (`fiber-interop/guzzle`).
- **PostgreSQL**: `async\pg\query()` waits for the server without stalling the other tasks (`fiber-interop/pg`).
- **MySQL**: `async\mysqli\query()` waits for the server without stalling the other tasks (`fiber-interop/mysqli`).
- **Processes**: `async\process\run()` waits for a command while the other tasks continue (`fiber-interop/process`).
- **Redis**: a Predis command suspends only the task that sent it (`fiber-interop/predis`).
- **Doctrine DBAL**: queries from different tasks overlap through the mysqli and pg drivers above (`fiber-interop/dbal`).

Outside `async\run()` each of them behaves exactly like the library it wraps.
