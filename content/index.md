# fiber-interop

<p class="lead">Run ordinary blocking-style PHP code concurrently on PHP Fibers. The code stays plain
PHP: no promises, no callbacks (a call returns its value, instead of taking a function to call later),
no <code>yield</code>. It runs on phasync, Revolt, AMPHP, ReactPHP, Swoole or TrueAsync; you pick the event loop by
installing one adapter package.</p>

fiber-interop is young (0.x versions) and the [Scope RFC for php-src](rfc-scope.html) is a draft, so the
API may change before 1.0.

<nav class="toc" aria-label="On this page"><a href="#examples">Examples</a><a href="#api">API</a><a href="#scope">Scope</a><a href="#measured">Measured</a><a href="#overlaps">What overlaps</a><a href="#adapter">Which adapter</a><a href="#constraints">Constraints</a><a href="#bootstrap">Bootstrapping</a><a href="#next">Next</a></nav>

<h2 id="examples">What it looks like</h2>

Four common situations. The code is the same on every adapter: each snippet is a file in
[examples/front](https://github.com/fiber-interop/fiber-interop.github.io/tree/main/examples/front),
and the four files were run unchanged on phasync, Revolt, AMPHP and ReactPHP with identical output.

<h3 class="ex">Three HTTP requests at once (Guzzle)</h3>

```php
use function fiber\{run, go, await_all};

$client = fiber\guzzle\client(['base_uri' => 'https://api.example.com']);

[$orders, $customer, $stock] = run(function () use ($client) {
    $orders = go(fn() => (string) $client->get('/orders/42')->getBody());
    $customer = go(fn() => (string) $client->get('/customers/7')->getBody());
    $stock = go(fn() => (string) $client->get('/stock/A-100')->getBody());
    return await_all([$orders, $customer, $stock]);
});

$orders = json_decode($orders, true);
$customer = json_decode($customer, true);
$stock = json_decode($stock, true);
```

The three `get()` calls run in separate tasks and overlap; `await_all()` returns the three response bodies in the order given. If a task throws, `await_all()` lets every task settle and then rethrows the first exception, out of `run()`.

<h3 class="ex">Several database queries at once (PostgreSQL)</h3>

```php
use function fiber\{run, go, await_all};

$rows = run(function () use ($dsn) {
    $query = function ($id) use ($dsn) {
        $c = fiber\pg\connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        $r = fiber\pg\query_params($c, 'SELECT $1::int AS id, pg_sleep(0.3)', [$id]);
        return pg_fetch_assoc($r);
    };
    $a = go(fn() => $query(1));
    $b = go(fn() => $query(2));
    $c = go(fn() => $query(3));
    return await_all([$a, $b, $c]);
});
```

Three queries that each take 0.3 s finish in 0.3 s in total (local PostgreSQL, same on phasync and Revolt). One connection per task, because a connection runs one query at a time.

<h3 class="ex">A timeout, and a sleep that holds nobody up</h3>

```php
use function fiber\{run, go, await, sleep};
use fiber\TimeoutException;

run(function () {
    $ticker = go(function () {
        for ($i = 1; $i <= 3; $i++) {
            sleep(0.05);
            echo "tick $i\n";
        }
    });
    $slow = go(function () {
        sleep(5);
        return 'slow result';
    });
    try {
        echo await($slow, 0.2), "\n";
    } catch (TimeoutException) {
        echo "gave up after 0.2 s; the ticker kept running\n";
    }
    await($ticker);
});
```

`await($task, 0.2)` throws `TimeoutException` when the task is not done in time (the task itself keeps running). `fiber\sleep()` pauses only the calling task, so the ticker keeps ticking.

<h3 class="ex">For library authors: one function, every runtime</h3>

```php
use function fiber\{go, await_all, sleep};

function fetchAll(array $ms): array
{
    $tasks = [];
    foreach ($ms as $m) {
        $tasks[] = go(function () use ($m) {
            sleep($m / 1000);
            return "slept $m ms";
        });
    }
    return await_all($tasks);
}
```

The library depends on `fiber-interop/api` and imports no runtime. The application calls `fiber\run(fn() => fetchAll([200, 200, 200]))` and the three 200 ms sleeps take 0.2 s on whichever adapter is installed. See [Library authors](tutorial-library.html).

<h2 id="api">The API</h2>

Eight functions cover most code; the [API reference](api.html) has signatures and a runnable example for each.

- [`run($fn)`](api-run.html): the entry point; runs `$fn` and returns its result when the tasks it awaits are done.
- [`go($fn)`](api-go.html): start a task, return an awaitable at once.
- [`await($x, $timeout)`](api-await.html): wait for one task, rethrow its exception; `TimeoutException` on timeout.
- [`await_all($xs)`](api-await_all.html): wait for all; results under the same keys.
- [`await_any($xs)`](api-await_any.html): wait for the first to finish.
- [`sleep($s)`](api-sleep.html): pause this task only.
- [`readable($stream)`](api-readable.html) / [`writable($stream)`](api-writable.html): wait until a stream can be read or written.
- [`Scope`](scope.html): not a function but the other half of the API; one per request, so that anything keyed by `Scope::getCurrent()` is per request.

This works in a controller, a command, a queue job or a service, in Laravel, Symfony, Slim,
CodeIgniter or plain PHP; fiber-interop does not replace the framework.

<h2 id="scope">One request, one Scope</h2>

```php
function session(): object              // one per request, in any fiber
{
    static $all;
    $all ??= new WeakMap();
    return $all[Scope::getCurrent()] ??= new stdClass();
}

// each request runs in its own Scope; session() never mixes them up
go(fn() => (new Scope)->run(handle(...), $request1));
go(fn() => (new Scope)->run(handle(...), $request2));
```

A request handled inside `(new Scope)->run(...)` has an identity that follows the code across fibers, so a registry like this, a container's `Lifetime::Scoped` services, the locale or the timezone become per request, with per-scope superglobals on top. Application authors without a framework get it directly, and framework authors can build on it instead of shipping a second "worker mode" runtime or a process per request. Library authors never see it: they call `fiber\go()` and the scope is carried along. Every runtime gets a global `\Scope`, the native extension or the polyfill. Runnable version: [examples/front/scope.php](https://github.com/fiber-interop/fiber-interop.github.io/blob/main/examples/front/scope.php), same output on phasync and Revolt. More on the [Scope page](scope.html) and in [scoped services](tutorial-container.html).

<h2 id="measured">Measured: overlapping waiting time</h2>

<div class="bars" role="img" aria-label="Ten requests: 3.02 s one after another, 0.33 s side by side">
<div class="bar"><span class="bar-label">One after another, plain Guzzle</span><span class="bar-track"><span class="bar-fill seq" style="width:100%"></span></span><span class="bar-num">3.02 s</span></div>
<div class="bar"><span class="bar-label">Side by side, <code>run</code>/<code>go</code>/<code>await_all</code></span><span class="bar-track"><span class="bar-fill par" style="width:11%"></span></span><span class="bar-num">0.33 s</span></div>
</div>

| Ten requests to an endpoint that answers after 0.3 s | Time |
|---|---|
| plain Guzzle client, one after another | 3.02 s |
| side by side with `run`/`go`/`await_all`, phasync adapter | 0.33 s |
| side by side with `run`/`go`/`await_all`, Revolt, AMPHP or ReactPHP adapter | 0.32 s |

Measured on 2026-10-14 with PHP 8.5.11 (CLI, NTS) on Linux, Intel Xeon E5-2697 v3, against a
local `php -S` server with 16 workers; each number is the same in three runs. The sources are in
[examples/get-started](https://github.com/fiber-interop/fiber-interop.github.io/tree/main/examples/get-started).
Swoole was not measured on this machine.

<h2 id="overlaps">What overlaps and what blocks</h2>

Inside `fiber\run()`, with any adapter ([details](integrations.html)):

- **Guzzle**: requests from different tasks overlap (`fiber-interop/guzzle`).
- **curl**: `fiber\curl\exec()` lets other tasks run while a transfer waits (`fiber-interop/curl`).
- **PostgreSQL**: `fiber\pg\query()` waits for the server without stalling the other tasks (`fiber-interop/pg`).
- **MySQL**: `fiber\mysqli\query()` waits for the server without stalling the other tasks (`fiber-interop/mysqli`).
- **Processes**: `fiber\process\run()` waits for a command while the other tasks continue (`fiber-interop/process`).
- **Redis**: a Predis command suspends only the task that sent it (`fiber-interop/predis`).
- **Doctrine DBAL**: queries from different tasks overlap through the mysqli and pg drivers above (`fiber-interop/dbal`).

Outside `fiber\run()` each of them behaves exactly like the library it wraps, so adding them to an
existing application breaks nothing.

These still block every task inside `run()`: PDO, `file_get_contents('http://...')`, native
`sleep()`/`usleep()` (use `fiber\sleep()`), and DNS lookups, unless the phasync extension or Swoole's
hooks make them cooperative.

<h2 id="adapter">Which adapter do I install?</h2>

The application installs `fiber-interop/api` and exactly one adapter; libraries depend on the api
only. If your application already runs on an event loop, install that loop's adapter.

<div class="cards">
<div class="card"><h3>Revolt</h3><p>Running on Revolt without AMPHP? Also the smallest dependency tree of all adapters: <code>revolt/event-loop</code> and nothing else.</p><code>fiber-interop/revolt</code></div>
<div class="card"><h3>AMPHP</h3><p>Running on AMPHP? Tasks and AMPHP futures await each other.</p><code>fiber-interop/amp</code></div>
<div class="card"><h3>ReactPHP</h3><p>Running on ReactPHP? Tasks and React promises await each other on React's loop.</p><code>fiber-interop/react</code></div>
<div class="card"><h3>Swoole</h3><p>Running on Swoole or OpenSwoole? Uses Swoole's coroutines and runtime hooks.</p><code>fiber-interop/swoole</code></div>
<div class="card"><h3>TrueAsync</h3><p>Running on TrueAsync? Tasks are TrueAsync coroutines.</p><code>fiber-interop/trueasync</code></div>
<div class="card"><h3>Quick start</h3><p>No event loop yet? phasync is a complete runtime with nothing else to configure, and its optional extension (MIT, bundled with phasync or <code>pie install phasync/ext-phasync</code>) also makes PHP's own blocking calls (<code>sleep()</code>, <code>curl_exec()</code>) cooperative.</p><code>fiber-interop/phasync</code></div>
</div>

<h2 id="constraints">Constraints</h2>

- CPU-bound work does not get faster. Tasks take turns in one PHP process; only waiting overlaps.
- PostgreSQL and MySQL run one query at a time per connection, so concurrent tasks need one
  connection each: size connection pools for the number of tasks that query at once.
- Exceptions propagate: `await()` rethrows a task's exception, `await_all()` rethrows the first one
  after every task has settled.

<h2 id="bootstrap">Bootstrapping: one line per runtime</h2>

```
composer require fiber-interop/api fiber-interop/phasync fiber-interop/guzzle
```

One adapter installed is picked up automatically; with several, call `fiber\setup(new FiberInterop\Revolt\Adapter())` once. See [Get started](get-started.html).

<h2 id="next">Next</h2>

- [Get started](get-started.html): which adapter, a first program you can time, the functions you need, and what blocks.
- [Library authors](tutorial-library.html): depend on `fiber-interop/api` instead of one event loop. Guzzle is the worked example.
- [Containers](tutorial-container.html): scoped services per request when requests run concurrently.
- [API reference](api.html): every function of `fiber-interop/api` with signature, behaviour and a runnable example.
- [Runtimes](runtimes.html): Revolt, AMPHP, ReactPHP, Swoole, OpenSwoole, TrueAsync and phasync, with conformance status and known gaps.
- [Integrations](integrations.html): every package above in detail.
