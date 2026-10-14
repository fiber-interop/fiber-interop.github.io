# Get started

## Which adapter do I install?

Your application installs `fiber-interop/api` and exactly one adapter. The adapter decides which
event loop runs your tasks; code written against `async\...` does not change when you switch.

| Your application | Install |
|---|---|
| does not use an async library yet | `composer require fiber-interop/api fiber-interop/phasync` |
| runs on AMPHP | `composer require fiber-interop/api fiber-interop/amp` |
| runs on Revolt without AMPHP | `composer require fiber-interop/api fiber-interop/revolt` |
| runs on ReactPHP | `composer require fiber-interop/api fiber-interop/react` |
| runs on Swoole or OpenSwoole | `composer require fiber-interop/api fiber-interop/swoole` |

`fiber-interop/api` is what libraries depend on. The adapter is the application's choice, one per
application: with exactly one adapter installed it is picked up automatically. With several,
`async\run()` throws until you call `async\setup()` with the one you want.
Details per adapter: [Adapters](adapters.html).

## First program

Ten HTTP requests with Guzzle, side by side:

```
composer require fiber-interop/api fiber-interop/phasync fiber-interop/guzzle
```

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

Against an endpoint that answers after 0.3 s this prints `10 responses in 0.33 s` (phasync) or
`0.32 s` (Revolt, AMPHP, ReactPHP); the same ten requests one after another take 3.02 s. Measured on
2026-10-14 with PHP 8.5.11 on Linux, Intel Xeon E5-2697 v3, against a local `php -S` server with 16
workers. `async\guzzle\client()` returns an ordinary `GuzzleHttp\Client`; only its handler differs.

## The words you need

| Function | What it does |
|---|---|
| `async\run($f)` | Runs `$f` with the event loop and returns its result once `$f` and every task it started have finished. Your entry point. |
| `async\go($f)` | Starts `$f` as a task and returns an awaitable at once. |
| `async\await($task)` | Suspends the current task until `$task` is done; returns its result or throws its exception. |
| `async\await_all($tasks)` | Awaits an array of tasks and returns their results with the same keys. |
| `async\sleep($seconds)` | Pauses the current task only; other tasks keep running. |

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use function async\{run, go, await, await_all, sleep};

$t = microtime(true);
run(function () use ($t) {
    $a = go(function () { sleep(0.5); return 'a'; });
    $b = go(function () { sleep(0.5); return 'b'; });
    echo await($a), await($b), "\n";                          // ab
    print_r(await_all(['x' => go(fn() => 1), 'y' => go(fn() => 2)])); // [x => 1, y => 2]
});
printf("%.2f s\n", microtime(true) - $t);                       // about 0.5, not 1.0
```

The awaitable that `go()` returns may only be passed to `await()`, `await_all()` and `await_any()`;
its concrete type depends on the adapter. The full contract is in the [specification](spec.html).

## What else overlaps today

curl, Guzzle, PostgreSQL (`pg`), MySQL (`mysqli`), child processes, Predis and Doctrine DBAL have
cooperative packages that work on every adapter: [Integrations](integrations.html).

## Outside run() nothing changes

Outside `async\run()` the integration packages are plain blocking PHP and behave exactly like the
library they wrap: two requests with `async\guzzle\client()` outside `run()` take 0.61 s, as they
would with a plain Guzzle client. You can adopt fiber-interop one code path at a time. The
`async\*` primitives themselves (`go`, `await`, `sleep`, ...) need a running loop; called outside
`run()` they throw `async\NotInRunException`.

## What blocks anyway

Inside `run()`, a call that waits inside C holds up every task, not only its own. Measured with two
tasks, each waiting 0.3 s:

| Call in each task | Two tasks take |
|---|---|
| `async\sleep(0.3)` | 0.30 s |
| native `usleep(300_000)` (same for `sleep()`) | 0.60 s |
| `file_get_contents('http://...')` | 0.60 s |

Also blocking: PDO (`pdo_mysql`, `pdo_pgsql`; use the `mysqli`, `pg` or `dbal` packages instead),
`curl_exec()` (use `async\curl\exec()`), `stream_socket_client()` and other stream functions used
without `async\readable()` / `async\writable()`, and DNS lookups. The phasync extension and Swoole's
runtime hooks make some built-in calls cooperative; see [Adapters](adapters.html) for what each
covers. The numbers above are from the Revolt and phasync adapters without the extension.

Sources of every example on this page:
[examples/get-started](https://github.com/fiber-interop/fiber-interop.github.io/tree/main/examples/get-started).

Next: each package in [Integrations](integrations.html); for making a library fiber-friendly, [Library authors](tutorial-library.html). The [front page](index.html) has the measured numbers and the adapter overview.
