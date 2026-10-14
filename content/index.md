# fiber-interop

<p class="lead">Run ordinary blocking-style PHP code concurrently on PHP Fibers. The code stays plain
PHP: no promises, no callbacks (a call returns its value, instead of taking a function to call later),
no <code>yield</code>. It runs on phasync, Revolt, AMPHP, ReactPHP or Swoole; you pick the event loop by
installing one adapter package.</p>

fiber-interop is young (0.x versions) and the [Scope RFC for php-src](rfc-scope.html) is a draft, so the
API may change before 1.0.

<nav class="toc" aria-label="On this page"><a href="#measured">Measured</a><a href="#overlaps">What overlaps</a><a href="#quickstart">Quickstart</a><a href="#adapter">Which adapter</a><a href="#constraints">Constraints</a><a href="#next">Next</a></nav>

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

Inside `async\run()`, with any adapter ([details](integrations.html)):

- **Guzzle**: requests from different tasks overlap (`fiber-interop/guzzle`).
- **curl**: `async\curl\exec()` lets other tasks run while a transfer waits (`fiber-interop/curl`).
- **PostgreSQL**: `async\pg\query()` waits for the server without stalling the other tasks (`fiber-interop/pg`).
- **MySQL**: `async\mysqli\query()` waits for the server without stalling the other tasks (`fiber-interop/mysqli`).
- **Processes**: `async\process\run()` waits for a command while the other tasks continue (`fiber-interop/process`).
- **Redis**: a Predis command suspends only the task that sent it (`fiber-interop/predis`).
- **Doctrine DBAL**: queries from different tasks overlap through the mysqli and pg drivers above (`fiber-interop/dbal`).

Outside `async\run()` each of them behaves exactly like the library it wraps, so adding them to an
existing application breaks nothing.

These still block every task inside `run()`: PDO, `file_get_contents('http://...')`, native
`sleep()`/`usleep()` (use `async\sleep()`), and DNS lookups, unless the phasync extension or Swoole's
hooks make them cooperative.

<h2 id="quickstart">Quickstart</h2>

```
composer require fiber-interop/api fiber-interop/phasync fiber-interop/guzzle
```

```php
use function async\{run, go, await_all};

$client = async\guzzle\client(['base_uri' => 'https://api.example.com']);

[$orders, $customer, $stock] = run(fn() => await_all([
    go(fn() => json_decode((string) $client->get('/orders/42')->getBody(), true)),
    go(fn() => json_decode((string) $client->get('/customers/7')->getBody(), true)),
    go(fn() => json_decode((string) $client->get('/stock/A-100')->getBody(), true)),
]));
```

What happens:

- `go()` starts a task and returns at once; the three `get()` calls run in separate tasks and overlap.
- `await_all()` waits for all three and returns their results under the same keys.
- `run()` returns when the awaited tasks are done.
- If a task throws, `await_all()` lets every task settle and then rethrows the first exception, out of `run()`.

This works in a controller, a command, a queue job or a service, in Laravel, Symfony, Slim,
CodeIgniter or plain PHP; fiber-interop does not replace the framework.

<h2 id="adapter">Which adapter do I install?</h2>

The application installs `fiber-interop/api` and exactly one adapter; libraries depend on the api
only. If your application already runs on an event loop, install that loop's adapter.

<div class="cards">
<div class="card"><h3>Revolt</h3><p>Running on Revolt without AMPHP? Also the smallest dependency tree of all adapters: <code>revolt/event-loop</code> and nothing else.</p><code>fiber-interop/revolt</code></div>
<div class="card"><h3>AMPHP</h3><p>Running on AMPHP? Tasks and AMPHP futures await each other.</p><code>fiber-interop/amp</code></div>
<div class="card"><h3>ReactPHP</h3><p>Running on ReactPHP? Tasks and React promises await each other on React's loop.</p><code>fiber-interop/react</code></div>
<div class="card"><h3>Swoole</h3><p>Running on Swoole or OpenSwoole? Uses Swoole's coroutines and runtime hooks.</p><code>fiber-interop/swoole</code></div>
<div class="card"><h3>Quick start</h3><p>No event loop yet? phasync is a complete runtime with nothing else to configure, and its optional bundled extension also makes PHP's own blocking calls (<code>sleep()</code>, <code>curl_exec()</code>) cooperative. phasync 2 is in beta.</p><code>fiber-interop/phasync</code></div>
</div>

With exactly one adapter installed, it is picked up automatically. With several, call
`async\setup(...)` in your bootstrap to choose one.

<h2 id="constraints">Constraints</h2>

- CPU-bound work does not get faster. Tasks take turns in one PHP process; only waiting overlaps.
- PostgreSQL and MySQL run one query at a time per connection, so concurrent tasks need one
  connection each: size connection pools for the number of tasks that query at once.
- Exceptions propagate: `await()` rethrows a task's exception, `await_all()` rethrows the first one
  after every task has settled.

<h2 id="next">Next</h2>

- [Get started](get-started.html): which adapter, a first program you can time, the functions you need, and what blocks.
- [Library authors](tutorial-library.html): depend on `fiber-interop/api` instead of one event loop. Guzzle is the worked example.
- [Containers](tutorial-container.html): scoped services per request when requests run concurrently.
- [Integrations](integrations.html): every package above in detail.
