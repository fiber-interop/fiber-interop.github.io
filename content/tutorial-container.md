# Tutorial: scoped lifetime in a service container

For framework and container authors. In PHP-FPM one process serves one request, so "one instance
per process" and "one instance per request" are the same thing, and most containers only know two
lifetimes. In a long-running async runtime many requests are in flight in one process at once, and
they are not the same thing any more. This page adds the third lifetime to a container, keyed on
[`\Scope`](scope.html), and runs it on four adapters.

## Three lifetimes

| Lifetime | Instances | Examples |
|---|---|---|
| Transient | a new one on every `get()` | value objects, mailers, builders |
| Singleton | one per process, shared by every request | loggers, config, connection pools, routers |
| Scoped | one per `Scope` (request, job) | current user, request, session, DB transaction, locale |

Under PHP-FPM a Singleton that holds the current user works, because the process dies or resets
before the next request. Under phasync, Revolt, AMPHP, ReactPHP or Swoole the next request is
already running in the same process while the first one waits on IO. A Singleton is then shared by
concurrent requests, so it must be stateless or guard its state. Anything that was "a singleton, but
really per request" must become Scoped, or request B reads request A's user.

A Scoped service needs a key for "the current request" that follows execution across fibers, child
tasks and queued callbacks. `\Scope::getCurrent()` is that key: [`fiber-interop/api`](get-started.html)
runs every `go()` task, timer and IO callback in the scope that started it (see the
[Scope RFC](rfc-scope.html)). Storing instances in a `WeakMap<Scope, array>` means they go away
with the scope; the container never needs an "end of request" hook.

## The container

```php
<?php

enum Lifetime
{
    case Transient;  // a new instance on every get()
    case Singleton;  // one instance for the process
    case Scoped;     // one instance per \Scope (request, job)
}

final class Container
{
    /** @var array<string, array{Lifetime, Closure}> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $singletons = [];
    /** @var WeakMap<Scope, array<string, object>> */
    private WeakMap $scoped;

    public function __construct()
    {
        $this->scoped = new WeakMap();
    }

    public function set(string $id, Lifetime $lifetime, Closure $factory): void
    {
        $this->factories[$id] = [$lifetime, $factory];
    }

    public function get(string $id): object
    {
        [$lifetime, $factory] = $this->factories[$id] ?? throw new LogicException("Unknown service $id");

        return match ($lifetime) {
            Lifetime::Transient => $factory($this),
            Lifetime::Singleton => $this->singletons[$id] ??= $factory($this),
            Lifetime::Scoped => $this->getScoped($id, $factory),
        };
    }

    private function getScoped(string $id, Closure $factory): object
    {
        $scope = Scope::getCurrent();
        if ($scope === Scope::getRootScope()) {
            throw new LogicException("Scoped service $id requested outside a request scope");
        }
        $instances = $this->scoped[$scope] ?? [];
        if (!isset($instances[$id])) {
            $instances[$id] = $factory($this);
            $this->scoped[$scope] = $instances;
        }
        return $instances[$id];
    }
}
```

`getScoped()` refuses the root scope. `Scope::getCurrent()` is never null; outside every request it
returns `Scope::getRootScope()`, which lives as long as the process. A Scoped instance created
there would be a Singleton in disguise, which is the bug this lifetime exists to prevent.

The container itself is a Singleton and holds no request data: the `WeakMap` is keyed by request,
and the `Scope` objects are owned by whoever runs the requests.

## Two concurrent requests

A server starts each request in a new scope: `(new Scope())->run($handler)`. The demo does that
twice, concurrently, with two `go()` tasks under `run()`. Each request fans out into two child
tasks, which inherit the request's scope from `go()`.

```php
final class RequestContext // Scoped: one per request
{
    public static int $count = 0;
    public readonly int $n;
    public function __construct(public readonly string $path) { $this->n = ++self::$count; }
    public function __destruct() { echo "RequestContext #{$this->n} ({$this->path}) freed\n"; }
}

$c = new Container();
$c->set(Logger::class, Lifetime::Singleton, fn() => new Logger());
$c->set(Mailer::class, Lifetime::Transient, fn() => new Mailer());
$c->set(RequestContext::class, Lifetime::Scoped, fn() => new RequestContext($_GET['path']));

// What a server does per request: a fresh Scope, then the handler inside it.
function handle(Container $c, string $path): void
{
    (new Scope())->run(function () use ($c, $path) {
        $_GET['path'] = $path;   // per-scope superglobal
        $log = $c->get(Logger::class);
        $ctx = $c->get(RequestContext::class);
        $log->log(sprintf('%s: RequestContext #%d, Logger #%d, Mailer #%d',
            $path, $ctx->n, $log->n, $c->get(Mailer::class)->n));
        sleep(0.01);             // the other request runs here

        // Fan out: child tasks inherit the request's scope.
        await_all([
            go(fn() => $log->log("$path child 1: RequestContext #" . $c->get(RequestContext::class)->n)),
            go(fn() => $log->log("$path child 2: RequestContext #" . $c->get(RequestContext::class)->n)),
        ]);
        $log->log("$path: same RequestContext after the await: "
            . ($c->get(RequestContext::class) === $ctx ? 'yes' : 'no'));
    });
}

run(function () use ($c) {
    await_all([
        go(handle(...), $c, '/a'),
        go(handle(...), $c, '/b'),
    ]);
    gc_collect_cycles();
    echo "both requests done\n";
});

try {
    $c->get(RequestContext::class);
} catch (LogicException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
```

`Logger` and `Mailer` count their instances the same way (full source in
[`examples/container/demo.php`](https://github.com/fiber-interop/fiber-interop.github.io/tree/main/examples/container)).
The factory for `RequestContext` reads `$_GET['path']`, which is per scope, so it sees the value
its own request set even though both requests write `$_GET` in the same process.

Output with `FI_RUNTIME=phasync` (PHP 8.5, scope polyfill):

```
runtime: phasync
/a: RequestContext #1, Logger #1, Mailer #1
/b: RequestContext #2, Logger #1, Mailer #2
/a child 1: RequestContext #1
/a child 2: RequestContext #1
/a: same RequestContext after the await: yes
RequestContext #1 (/a) freed
/b child 1: RequestContext #2
/b child 2: RequestContext #2
/b: same RequestContext after the await: yes
RequestContext #2 (/b) freed
both requests done
LogicException: Scoped service RequestContext requested outside a request scope
```

- The two requests get different `RequestContext` instances and the same `Logger`. `Mailer` is new
  on each `get()`.
- `/b` starts while `/a` is suspended in `sleep()`, and `/a` still gets its own instance after
  resuming: the scope followed the task across the suspension.
- The child tasks started with `go()` see their parent request's instance.
- Each `RequestContext` is freed when its request's scope is released. Nothing called a cleanup
  method; the `WeakMap` entry went with the `Scope`.
- After `run()` returns, `{main}` is in the root scope and a Scoped `get()` fails.

## Other adapters

`revolt` prints exactly the same output. `amp` and `react` print the same request and child lines
in the same order. What differs is when each `RequestContext` is freed, because that is when the
adapter drops its last reference to the finished task and so to the `Scope`:

| Adapter | `RequestContext` freed |
|---|---|
| phasync, Revolt | as each request ends |
| AMPHP | before `run()` returns |
| ReactPHP | `/a` before or after `run()` returns, `/b` at script shutdown |

The ReactPHP adapter keeps the last settled task until later; in this short script "later" is
shutdown. That is a delay, not a leak: the `WeakMap` holds no reference, so the instance goes when
the adapter lets go. We have not measured how long that takes in a long-running ReactPHP server.

With the native extension (`php -d extension=.../scope.so`) phasync and Revolt print the same
output as with the polyfill; on AMPHP and ReactPHP only the position of the "freed" lines moves. The demo was not run on Swoole.

## What to audit in a framework

Moving a framework from PHP-FPM to an async runtime means finding state that is per request but
lives longer than one:

- **Static properties** that hold request data: `static $currentUser`, request caches, "last
  query" buffers, locale or timezone set per request.
- **`$instance` singletons** (`getInstance()`, facades, service locators) whose object holds the
  request, the session, the authenticated user, an open transaction or a per-request cache.
- **Container bindings registered as shared** whose factory reads the request (`$_SERVER`,
  `$_GET`, headers, cookies). Shared means Singleton; these must become Scoped.
- **Services that resolve a Scoped dependency in their constructor.** A Singleton that receives a
  `RequestContext` at construction time keeps the first request's instance forever. Either make
  the consumer Scoped, or inject the container (or a small accessor) and resolve per call.
- **"Reset at end of request" hooks.** Code that clears static state after the response assumes
  one request at a time. With a scope-keyed `WeakMap` there is nothing to reset, and the reset
  would wipe state another request is still using.

Code that only reads configuration, or holds a pool and hands out connections per call, is a valid
Singleton and needs no change.

## Run it

```sh
git clone https://github.com/fiber-interop/fiber-interop.github.io
# composer.json uses path repositories: check out api, scope, phasync, revolt, amp and react
# next to fiber-interop.github.io, as the integration packages' CI does.
cd fiber-interop.github.io/examples/container
composer install
FI_RUNTIME=phasync php demo.php    # or revolt, amp, react
```

For library authors keeping per-request state without a container, see
[Tutorial: a scope-aware library](tutorial-library.html).
