# PHP RFC: Scope

- Version: 0.9.1
- Date: 2026-10-26
- Author: Frode Børli, <frodeborli@gmail.com>
- Status: Draft
- Implementation: <https://github.com/fiber-interop/ext-scope> (extension); php-src pull request to follow
- Discussion thread: tbd
- Voting thread: tbd

## Introduction

PHP has no object that represents a request. Most request state is stored in process-wide globals, or thread-local globals under ZTS. The request is simply the period between startup and shutdown. That works when a process handles one request at a time. It does not work as well when one process handles many requests, especially when they overlap. This RFC adds `Scope`: an object that identifies a unit of work, such as an HTTP request, queue job, or CLI command. Code can create a scope, run code in it, and get the current scope.

``` php
// A server runs each request in a scope of its own:
(new Scope())->run($handler, $request);

// Code anywhere in that request can ask which request it belongs to:
$this->connections ??= new WeakMap();
$db = $this->connections[Scope::getCurrent()] ??= new PDO($dsn);
```

A long-running PHP server handles many requests in one process. Frameworks reset state between requests. Containers use runtime-specific identities to track request services. Event loops have their own way to track related work. PHP itself offers no common way to ask which request code is handling.

There are three immediate uses for this.

**Request-lifetime services.** A container needs to create one instance of a scoped service per request. Resetting the container works only when requests cannot overlap. Using `Fiber::getCurrent()` as the key is also wrong: one request may start several fibers, and they should not get separate sessions, database connections, or units of work. Loggers, tracers, and request caches have the same problem.

**Engine request state.** PHP keeps superglobals, sessions, output buffers, locale, timezone, error handlers, and ini settings in global state. They are reset between conventional requests, but two overlapping requests in the same process cannot safely use different values. A scope provides an owner for this state. Two secondary votes in this RFC move the request superglobals and session, and the default locale and timezone, into scopes. Other state is left for later RFCs. Code that does not create scopes retains its current behavior.

**Interoperability.** Runtimes provide their own request identity. For example, phasync uses `phasync::getContext()`. A library written for one runtime cannot use that identity in another. `Scope` gives them one common identity.

A request can run in several fibers: for example, to perform parallel queries or stream a response. Those fibers should belong to the same request even when a library creates them. A scope is inherited when a fiber or generator is created. Each can therefore identify its request without relying on the scheduler. Request state belongs to the scope, not to the process or an individual fiber. This differs from the per-coroutine state proposed by the [Concurrency Support in the PHP Engine](https://wiki.php.net/rfc/async_scheduler_abi) RFC. Today fibers share output buffers, locale, and timezone. Giving each coroutine its own empty copy would change that behavior, including for fibers within the same request. The two proposals can work together: schedulers can assign their coroutines to scopes, including coroutines that are not `Fiber` objects.

Other languages provide related mechanisms: Java's `ScopedValue`, .NET's `ExecutionContext` and `AsyncLocal<T>`, Python's `contextvars`, Go's `context.Context`, and Node's `AsyncLocalStorage`. See Prior art. This RFC adds only an operation identity to PHP. Libraries and later RFCs decide what state to associate with it.

## Proposal

The proposal adds one final class and one method each to `Fiber` and `Generator`:

``` php
final class Scope
{
    public function __construct() {}

    /**
     * Calls $callback with this scope as the current scope, and returns its result. The previous
     * current scope is restored when $callback returns or throws.
     */
    public function run(callable $callback, mixed ...$args): mixed {}

    /**
     * Returns a new closure that runs $closure with this scope as the current scope, whoever
     * calls it, as run() does, with any previous binding replaced. The new closure keeps
     * $closure's signature (by-reference parameters, defaults, return type), $this and class
     * scope. $closure itself is not modified, as with Closure::bind().
     */
    public function bind(Closure $closure): Closure {}

    /** The current scope. In {main} outside any run(), and in fibers and generators created there, the root scope. */
    public static function getCurrent(): Scope {}

    /** The scope that stands for code that is part of no operation. One object per PHP request: in the CLI, for the life of the process. */
    public static function getRootScope(): Scope {}
}

final class Fiber
{
    /** The scope this fiber was constructed in. */
    public function getScope(): Scope {}

    // Existing methods unchanged.
}

final class Generator
{
    /** The scope this generator was created in. */
    public function getScope(): Scope {}

    // Existing methods unchanged.
}
```

A `Scope` is an identity, not a container for values and not a task manager. It has these rules:

1.  `$scope->run($callback)` makes `$scope` the current scope while `$callback` runs, and restores the previous one afterwards, also when `$callback` throws. Calls nest: an inner `run()` replaces the current scope until it returns. A scope keeps no link to the scope it was run from.
2.  A fiber belongs to the scope that is current when it is constructed, for its whole life. The fiber holds a strong reference to its scope. Fibers that libraries construct on the application's behalf join the operation without any cooperation from the library.
3.  A generator belongs to the scope that is current when it is created, that is, when the generator function is called, for its whole life, like a fiber.
4.  When a fiber or a generator runs, the current scope is its own: the scope it belongs to, or the scope of a `run()` call active inside it. Where it is resumed from doesn't matter. Each fiber, each generator, and `{main}`, has its own current scope, saved and restored when execution switches between them. The current scope is therefore that of the innermost executing context, fiber or generator, together with any `run()` active inside that context; a `run()` active in a caller never overrides the membership of a generator or fiber it resumes.
5.  Functions and closures run in the scope of their caller. A closure returned by `$scope->bind($closure)` runs in `$scope`, whoever calls it. `bind()` returns a new closure, with any previous binding replaced: `$a->bind($b->bind($f))` runs `$f` in `$a`.
6.  In `{main}`, outside any `run()`, and in fibers and generators created there, the current scope is the root scope, `Scope::getRootScope()`. The root scope is a single object that lives as long as the PHP request: under the CLI that is the whole process, under a SAPI that serves several requests per process, such as php-fpm, there is one root per request.
7.  A destructor runs in the scope that is current when its object is released, like any other function. An object released during another operation's `run()` therefore runs its destructor in that operation's scope. An object that needs its own scope at destruction captures it when it is constructed, and runs its cleanup with `$scope->run()`.

The root scope is used when code is not running inside an explicitly created scope. It covers ordinary CLI and php-fpm execution and the server loop. `Scope::getCurrent() !== Scope::getRootScope()` tests whether code is in an explicit operation. `getCurrent()` always returns an object, so callers never need a null case and can always use a scope as a `WeakMap` key. To run code outside an operation, call `Scope::getRootScope()->run($f)`. State keyed by the root persists for the PHP request (for the lifetime of the process in a typical CLI program).

Ordinary closures do not capture a scope. Most applications create route handlers, middleware, listeners, and container factories at startup, then call them during requests. If closures inherited their creation scope, those handlers would always run in the root scope. When a callback must run in the scope where it was registered, the caller binds it explicitly:

``` php
$listener = Scope::getCurrent()->bind(function (Event $e) {
    // runs in the scope that registered it, whichever scope dispatches the event
});
$bus->on('order.paid', $listener);
```

`bind()` is similar to wrapping a closure in `fn(...$a) => $scope->run($closure, ...$a)`. Unlike a userland wrapper, it preserves by-reference parameters, defaults, return type, `$this`, and class scope. Fibers and generators created by the bound closure inherit the bound scope. Node's `AsyncLocalStorage.bind()` and the proposed JavaScript `AsyncContext.Snapshot.wrap()` have a similar purpose.

The key rule is that a function keeps its current scope across `yield` and `Fiber::suspend()`. Its scope changes only when it calls `run()` (or enters another execution context). Resuming it from another scope does not change its scope.

A scope does not wait for fibers, cancel work, or finish when `run()` returns. It stays alive while something references it: a fiber, queued callback, request object, or other value. The server releases its references when it is done. Once the scope becomes unreachable, `WeakMap` entries keyed by it can be collected.

Libraries store per-scope data in a `WeakMap` keyed by `Scope`. They do not need to share a registry or agree on keys. PHP 8.3 and later can also collect cycles where a `WeakMap` value refers back to its key:

``` php
final class RequestServices
{
    private static ?WeakMap $services = null;

    public static function get(string $id): object
    {
        $scope = Scope::getCurrent();
        if ($scope === Scope::getRootScope()) {
            throw new LogicException('Not running inside a request scope');
        }
        self::$services ??= new WeakMap();
        self::$services[$scope] ??= [];
        return self::$services[$scope][$id] ??= self::create($id);
    }

    private static function create(string $id): object
    {
        // application-specific
    }
}

// In the server, for each request:
$scope = new Scope();
$scope->run(function () {
    $db = RequestServices::get('db');             // created for this request
    $fiber = new Fiber(function () {
        RequestServices::get('db');               // the same instance: same scope
    });
    $fiber->start();
});
```

A container that currently keys services by `Fiber::getCurrent()` can use `Scope::getCurrent()` instead. That gives the same service to all fibers in one request and also works without fibers. A library that uses `phasync::getContext()` today can use `Scope::getCurrent()` instead and run under any runtime.

`Scope` is not serializable. It identifies work within one PHP process. To send a trace or request ID to another process, store that ID as ordinary data associated with the scope and transmit the ID. The receiver creates its own scope.

A scope has no start or end event and may be entered repeatedly, even from different fibers. In this example, one fiber inherits the scope when constructed; the other enters it explicitly:

``` php
$scope = new Scope();
$a = $scope->run(fn() => new Fiber(fn() => Scope::getCurrent()));   // constructed inside $scope
$b = new Fiber(fn() => $scope->run(fn() => Scope::getCurrent()));   // constructed outside, enters it
$a->start(); $b->start();
var_dump($a->getReturn() === $scope, $b->getReturn() === $scope);   // bool(true) bool(true)
```

### Fibers and generators

A fiber inherits the scope current at construction. Existing fiber APIs do not change. `Fiber::getScope()` lets schedulers and other code inspect its membership.

The scope is captured at construction, not at `start()`, and never changes. Starting a fiber from another scope does not move it:

``` php
$fiber = new Fiber(fn() => var_dump(Scope::getCurrent() === Scope::getRootScope()));
$scope->run(fn() => $fiber->start());   // bool(true): the fiber was constructed outside any scope
```

If starting or resuming a fiber changed its membership, a fiber's request identity could change depending on who resumed it. This proposal forbids that.

An event loop may need a service fiber that belongs to the loop rather than the request that caused its creation. It can construct that fiber in the loop's own scope:

``` php
$this->timerFiber = $this->servicesScope->run(fn() => new Fiber($this->runTimers(...)));
```

Constructing a fiber allocates no stack; `start()` does.

Generators also capture the scope at creation, when the generator function is called. A generator may be resumed by code from a different scope after each `yield`. Its request identity must not change in between:

``` php
$db = $container->get(PDO::class);
yield;
$db = $container->get(PDO::class);   // a different connection would be unexpected
```

This also matters for streamed responses. The application may create a response-body generator inside a request while the server consumes it later from its own event loop. The generator still uses the request's services:

``` php
// In the application, inside the request's scope:
return new Response(body: (function () {
    foreach (RequestServices::get('db')->query('...') as $row) {   // the request's connection
        yield json_encode($row);
    }
})());
```

A generator that delegates with `yield from` runs the inner generator in the inner generator's own scope, the one it was created in.

### Functions and closures

Ordinary function and closure calls use the caller's current scope. They do not capture one at definition time. Otherwise, handlers created during startup would always run in the root scope. Capturing at every closure call would also add unnecessary switches to calls such as `array_map()` and `usort()`.

Code that wants a closure to run in a particular scope binds it with `$scope->bind()`.

### Callbacks

An event loop can preserve the registering scope when it queues a callback:

``` php
// When the callback is registered:
$this->queue[] = [Scope::getCurrent(), $callback];

// When it is due:
[$scope, $callback] = array_shift($this->queue);
$scope->run($callback);
```

This works for fiber-based and callback-based loops. The loop must preserve scope explicitly for callbacks, but fibers inherit it automatically. A scope does not run anything by itself.

The queue's reference keeps the scope, and its per-scope state, alive until the callback is released. Scopes have reference-based lifetimes, not enforced task lifetimes.

### Secondary vote A: the request superglobals follow the scope

The primary vote adds the identity only. Two independent secondary votes move specific PHP state into scopes. Vote A covers request input and sessions.

Six superglobals hold request data: `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, and `$_SERVER`. `$_SESSION` and ext/session hold the session. `$_ENV` is process state and is not included. Today these values are shared between overlapping requests in one PHP process unless the server replaces or avoids them. One request can overwrite another's input or session.

If vote A passes, each scope has its own request arrays and session:

- The root scope's arrays are the ones the SAPI populates: unchanged behaviour for every script that never creates a scope.
- A new scope starts empty: the six arrays are empty and no session is active (`session_status()` is `PHP_SESSION_NONE`). Whoever creates the scope seeds it, as a server does for each request. A new scope does not inherit its creator's values: a copy of an array is shallow, so objects in it would stay shared with the creator, and an inherited copy would promise an isolation it cannot give.
- An assignment to one of the six inside a scope changes only that scope's array. Reads see the current scope's array. All fibers and generators of the scope share it, as the rules above give.
- `$_REQUEST` is a scope-local snapshot like the others, not a live merge: a scope that assigns `$_GET` and relies on `$_REQUEST` assigns it too, as code that assigns `$_GET` must today. Leaving `$_REQUEST` shared while the three it is built from are per scope would give one request two inconsistent views of its input, which is why it is in the set.
- `$_SERVER` mixes request facts (`REQUEST_URI`, `REQUEST_METHOD`, the `HTTP_*` entries, `REMOTE_ADDR`, `HTTPS`) with the environment (`PATH`, `DOCUMENT_ROOT`, what the application put there while booting). A request's scope starts with an empty `$_SERVER` like the others, and the server writes both into it: the environment entries it wants the request to see, then the request's own. Leaving it shared would leave a request's own URI, method, headers and client address to whichever request wrote them last: read after the request's first suspension, they would be another request's, silently.
- The session is the scope's: `session_start()` inside a scope starts that scope's session, and its state (`$_SESSION`, the session id and status, the save handler and its data) is the scope's alone. The session's settings are not: the `session.*` ini entries, and `session_name()`, `session_set_cookie_params()` and the other functions that write them, stay request-wide like any `ini_set()` value. A started session can't have them changed, as today. All fibers and generators of the scope share it, as they share the scope's other superglobals. A scope has no end state (see above), so the session is written and closed when the server calls `session_write_close()` inside the scope at the end of the request, or at the latest when the scope is destroyed with the session still active, as at the end of a request today. The headers `session_start()` sends (`Set-Cookie`, the cache limiter's) belong to the scope's response: until per-scope headers have their own RFC (see Future Scope), a server that hosts several requests in one process must route them, as it already routes the rest of each response.
- Save handlers that lock a session while it is open (files, memcached, redis) block the whole process when two scopes in it open the same session id at once, as a browser's parallel requests do: the second `session_start()` waits for a lock that only the first scope can release. Under php-fpm or ZTS the second request waits in its own process or thread. A server that runs requests as scopes needs a handler that waits cooperatively, or must not open one session id in two scopes at once.

The server remains responsible for initializing request data. It can do so inside the scope:

``` php
$env   = $_SERVER;  // the process's environment, read once in the root scope
$scope = new Scope();
$scope->run(function () use ($request, $handler, $env) {
    $_GET    = $request->query;
    $_POST   = $request->parsedBody;
    $_COOKIE = $request->cookies;
    $_FILES  = $request->uploads;
    $_SERVER = $request->serverParams + $env; // the request's entries over the environment
    $handler->handle();        // every fiber of this request sees these; no other request does
});
```

Two scopes can hold different values at the same time:

``` php
$a = new Scope(); $b = new Scope();
$a->run(fn() => $_GET = ['page' => '1']);
$b->run(fn() => $_GET = ['page' => '2']);
$a->run(fn() => var_dump($_GET['page']));   // string(1) "1"
var_dump($_GET);                             // the SAPI's array: unchanged
```

**References and globals.** `$GLOBALS['_GET']` and `global $_GET` refer to the current scope's value. A reference taken before switching scopes, such as `$g = &$_GET`, continues to refer to the earlier array. Switching scopes changes the global binding, not existing references.

**Implementation.** The engine can swap six `zval` entries in the global symbol table on each scope switch, saving the old entries in the outgoing scope and loading those of the incoming scope. Arrays are not copied. Reads and writes retain their current cost. The extension's tests cover `PG(http_globals)`, the lazily created auto-globals, `unset($_GET)` and `global $_GET`; the php-src implementation must pass the same tests. Under ZTS, each thread has its own symbol table and root scope.

**Why include them now?** These are the request values that long-running servers already need to isolate. Moving them first tests the scope-switching mechanism against existing PHP behavior. The same approach can later apply to other engine state. Vote A covers request input and sessions together.

**Reference implementation.** The accompanying extension implements this vote: the six arrays are swapped in the symbol table (and `PG(http_globals)`) at every scope change together with ext/session's state (version 0.9 of this text changed the rule for a new scope from inheriting its creator's arrays to starting empty, and added the session; the extension and the polyfill implement this since 0.4.0, and `scope.session=0` leaves the session process-wide), and the lazily created auto-globals are forced at the first scope creation so a later-compiled `$_GET` read cannot re-arm the engine's JIT callback over a scope's array. It is on by default and can be turned off with `scope.superglobals=0` so this vote can be evaluated independently. The userland polyfill does the same when an event loop reports a change of scope; the interoperability project where this is measured across event loops (<https://fiber-interop.github.io/conformance.html>, column "Scope state") shows which loops can report every switch and which cannot, which is the case for doing it in the engine.

### Secondary vote B: the default locale and timezone follow the scope

`Locale::getDefault()` and `Locale::setDefault()` (ext/intl), and `date_default_timezone_get()` and `date_default_timezone_set()`, currently operate on ambient settings shared by requests in the same process. Vote B makes those defaults follow the scope. The root retains the SAPI's settings. A new scope starts from the ini defaults rather than copying its creator's values. Changing either setting affects only the current scope. The implementation swaps the current values when the scope changes.

**Reference implementation.** The accompanying extension implements this vote too: the default timezone is one pointer swap of ext/date's current string per scope change; the locale, whose globals ext/intl does not expose to other extensions, is applied through the `intl.default_locale` ini entry only when the entering scope's locale differs from the live one (in core it would be the same plain swap as the timezone). `scope.locale_timezone=0` turns it off. Two details the text above leaves open, decided in the implementation and tested: `ini_set('date.timezone')` stays request-wide (a scope that has set its own timezone ignores it; one that never did follows it), and a new scope starts with the ini value of `date.timezone` (or "unset", that is the ini fallback) and `intl.default_locale`, not with its creator's. (Version 0.9 of this text changed this from inheriting the creator's values; the extension and the polyfill implement it since 0.4.0.)

Applications already set locale and timezone per request. Without isolation, two concurrent requests cannot reliably use different defaults. This is a separate vote because ext/intl and ext/date have different implementation details and maintainers. ICU's process-wide default and timezone validation also need review.

### What follows the scope, and what does not

Only state that belongs to a logical operation should follow its scope. A global variable is not automatically request state.

| State                                                                                                  | Belongs to                               | Follows the scope                      |
|:-------------------------------------------------------------------------------------------------------|:-----------------------------------------|:---------------------------------------|
| `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, `$_SERVER`, `$_SESSION` and ext/session's state | the request                              | yes (vote A); a new scope starts empty |
| default locale, default timezone                                                                       | the operation                            | yes (vote B)                           |
| output buffers, error and exception handlers, `ini_set()` values                                       | the operation                            | later, one RFC each                    |
| `json_last_error()`, `preg_last_error()`, `error_get_last()`                                           | the call just made                       | no: see below                          |
| class statics, function statics, `$GLOBALS`                                                            | the application, shared on purpose       | no                                     |
| `$_ENV`, `setlocale()`, `chdir()`, `umask()`                                                           | the process                              | no                                     |
| persistent resources: `PDO::ATTR_PERSISTENT`, `pg_pconnect()`, mysqli `p:` hosts, `pfsockopen()`       | the process, lent to one scope at a time | later, on its own: see below           |

Last-error functions do not need scope isolation in this design: fibers switch only when suspended, so a call to `json_decode()` immediately followed by `json_last_error()` is not interrupted by another fiber. Reading another coroutine's last error is not a supported pattern. Class and function statics, and ordinary `$GLOBALS` entries, stay shared because applications use them for caches, registries, and singletons. Virtualizing them for legacy compatibility is a different feature.

Persistent connections are process-wide. PHP caches them in `EG(persistent_list)`, usually by connection parameters. Two scopes can obtain the same underlying database session at once. A transaction started in one scope could then affect the other scope's queries and rollback.

A future RFC should ensure that two live scopes never use the same persistent connection concurrently, while allowing a connection to return to the pool after cleanup. That work affects PDO, pgsql, mysqli, and streams, and is not part of this RFC. Until then, concurrent scopes should use non-persistent connections, for example through a pool. Code that never creates scopes keeps its current behavior.

## Prior art

Other languages already propagate execution context, though their APIs and inheritance rules differ:

- **Python:** `contextvars` ([PEP 567](https://peps.python.org/pep-0567/), Python 3.7). `Context.run()` calls a function in a given context, and asyncio runs each task in a copy of the context that was current when the task was created. Making generators keep a context of their own was proposed in [PEP 568](https://peps.python.org/pep-0568/), which is deferred.
- **JavaScript:** Node.js has `AsyncLocalStorage`, with `run()`. The TC39 [AsyncContext](https://github.com/tc39/proposal-async-context) proposal (Stage 2) moves the mechanism into the language, including `AsyncContext.Snapshot` for running a callback in the context it was captured in, because userland solutions such as Zone.js can't follow `async`/`await` without transpilation. Generators restore the context that was current when they were created, as in this RFC; other functions run in their caller's context.
- **Java:** `InheritableThreadLocal` values are inherited when a thread is constructed. Scoped values ([JEP 506](https://openjdk.org/jeps/506), JDK 25) are bound for the duration of a `run()` call and inherited by the child threads of a `StructuredTaskScope`.
- **.NET:** `ExecutionContext` is captured when asynchronous work is started and restored when it runs, and `ExecutionContext.Run()` runs a callback inside a given context, the same shape as `run()` here. `AsyncLocal<T>` (.NET Framework 4.6) holds "ambient data that is local to a given asynchronous control flow" and flows with it.
- **Kotlin:** a coroutine inherits the `CoroutineContext` of the coroutine that launches it. `launch(Job())` starts one that is independent of its parent.
- **Swift:** task-local values ([SE-0311](https://github.com/swiftlang/swift-evolution/blob/main/proposals/0311-task-locals.md), Swift 5.5) are bound for the duration of a closure and inherited by child tasks, and not by detached tasks.
- **Go:** `context.Context` is passed explicitly, as a function parameter. That works in Go because its standard library and ecosystem adopted the convention. PHP's libraries have no such convention, and fibers are created inside code that has no parameter to pass a context through.

**Service containers.** ASP.NET Core, Spring, and Jakarta CDI all support services tied to request or dependency-injection scopes. In PHP, Laravel supports scoped bindings and Symfony resets services between requests in long-running workers. These mechanisms have no shared language-level operation identity. `Scope` would give frameworks and libraries one.

**Earlier PHP proposals.** The [Fibers RFC](https://wiki.php.net/rfc/fibers) added the minimum needed for userland coroutines and left scheduling to libraries. It discussed synchronization of shared state but did not distinguish shared application state from state owned by a request. The [Concurrency Support](https://wiki.php.net/rfc/async_scheduler_abi) RFC proposes per-coroutine engine state, including output buffering. This RFC instead assigns request state to an operation that may contain several fibers or coroutines. These are different ownership models.

`Scope` carries an identity, not a table of values. `WeakMap` provides per-scope storage without adding a general-purpose storage API to PHP core.

The [Async Scope](https://wiki.php.net/rfc/true_async_scope) proposal also uses the name *Scope*. Its scope controls a group of coroutines and their lifetime. This RFC's `Scope` only identifies an operation; it owns no execution. A scheduler could use both. *Context* was also considered, but commonly implies a collection of values and already appears in PHP stream contexts. Both names have the same backward-compatibility footprint: 3 of 100 sampled PHP files on GitHub declare a global `class Context`, and 3 of 100 a global `class Scope`.

## Backward Incompatible Changes

- A class named `Scope` in the global namespace can no longer be declared. Classes named `Scope` in a namespace are unaffected. Of the first 100 results of a GitHub code search for PHP files declaring `class Scope`, 88 are namespaced, and 2 declare it in the global namespace: a toy interpreter in a multi-language repository, and a project last changed in 2018 (a second sample on 2026-10-14 found 3 of 100). None of the top 1000 Composer packages (Packagist's list of 2026-10-26, latest stable versions, 998 downloaded and parsed with nikic/php-parser) declares a class, interface, trait or enum named `Scope` in the global namespace, or aliases one to it. 34 declarations in 14 packages are namespaced (among them Laravel's `Illuminate\Database\Eloquent\Scope`, Sentry and league/fractal) and are unaffected; the one non-namespaced file that refers to `Scope` imports `PHPStan\Analyser\Scope` with `use`.

## Proposed PHP Version(s)

Next PHP 8.x (PHP 8.7).

## RFC Impact

### To the Ecosystem

IDEs, language servers and static analysers need stubs for the `Scope` class and the two new `getScope()` methods; nothing in the syntax changes. Libraries can adopt `Scope` before it ships: the polyfill `fiber-interop/scope` declares the same class on PHP 8.2 and later when the engine doesn't, and event loops adopt it with the callback pattern above. Containers, loggers and tracers that key state by `Fiber::getCurrent()` today can key it by `Scope::getCurrent()` instead.

### To SAPIs

None for the primary proposal: code outside any scope runs in the root scope and sees what it sees today. Under secondary vote A, the six request superglobals and the session are the SAPI's in the root scope and follow the scope elsewhere, a new scope starting empty; SAPIs keep populating them as now.

### To Existing Extensions

None until the follow-up RFCs: this RFC converts no extension state, and extensions never need to handle the absence of a scope. Secondary vote B touches ext/intl (`Locale`) and ext/date (default timezone).

### To Opcache

None.

### New Constants

None.

### php.ini Defaults

None.

## Open Issues

No unresolved API decisions are listed. The remaining work is implementation validation.

The extension has phpt tests for the described rules and secondary votes on PHP 8.2 through 8.5, with and without opcache, and they also pass under the tracing and the function JIT on PHP 8.5. The php-src implementation still needs to be written and tested. It will put the current scope in executor globals and is planned before voting.

## Unaffected PHP Functionality

Code that never creates a `Scope` runs in the root scope and behaves exactly as before. Functions and closures run in the scope of their caller, `Fiber` and `Generator` behave as before apart from the added `getScope()` method, and state not covered by the secondary votes stays process-wide.

## Discussion

The primary proposal adds the identity. The secondary votes cover only the state listed above. Output buffering, error handlers, and ini values could also be converted now, but each has its own behavior and implementation issues. Combining them would make the primary vote depend on those decisions.

Keeping those conversions separate lets each subsystem be reviewed on its own. The cost is that applications may need temporary workarounds until more state follows scopes. Either approach can preserve ordinary code's behavior outside explicit scopes.

Each subsystem must decide how a new scope starts: with fresh state or a copy of its creator's state. Votes A and B use fresh request state and ini defaults. Output buffering is deferred.

## Future Scope

PHP exposes useful ambient state without requiring context objects: for example, `json_last_error()` and `header()`. The long-term goal is to keep that API while allowing several operations to coexist safely in one process. Each additional kind of state should be evaluated separately and leave code outside explicit scopes unchanged.

State that could follow the scope includes:

- output buffering;
- error and exception handlers, `ini_set()` values and shutdown functions;
- the SAPI's response side, `header()`, `headers_list()`, `headers_sent()`, `http_response_code()` and the sink that receives the response body: an RFC that exposes these SAPI integration points to userland, bound to the scope, so that a server can supply them per request; it is the same RFC that supplies the bottom of a request scope's output stack (see output buffering below).

**Output buffering.** `ob_start()` is used both for request output and for capturing output from a piece of code, such as a template. Request output belongs to the operation; all its fibers should write to the same output stack. Capture instead belongs to an execution block. A future RFC could make request output follow the scope and add a separate `ob_context_run(callable): string` for structured capture. The latter would prevent a suspended capture from accidentally popping another fiber's buffer with `ob_get_clean()`. The output RFC must also define the request's output sink and what nested scopes inherit. None of that is proposed here.

Another RFC will propose an internal API for per-scope engine and extension state. It would let extensions allocate one value per scope, with the root holding the existing request state. A compatibility bridge could swap module globals on scope changes. The current extension prototypes both approaches.

Within one scope, all fibers continue to share that state. The difference is that a different operation cannot overwrite it.

Some state belongs to the operating system and cannot cheaply follow a scope: `setlocale()`, `chdir()`, `umask()`, environment variables, and C syslog's `openlog()` identity. Per-scope versions would need emulation.

A later RFC can also add operations on an operation as a whole, for example:

``` php
Fiber::cancelScope($scope);   // illustration only: not part of this RFC
```

Cancellation would need to define when a fiber observes cancellation, how running and not-yet-started fibers behave, and how `finally` and cleanup work. It would also need a way to find all fibers in a scope; this RFC only stores the scope on each fiber. Cancellation is not part of this proposal.

The [Concurrency Support](https://wiki.php.net/rfc/async_scheduler_abi) RFC introduces engine coroutines that need not be `Fiber` objects. A scheduler built on it would give those coroutines the same scope membership.

## Voting Choices

Primary vote, 2/3 majority:

- Add Scope, Fiber::getScope() and Generator::getScope() as described in this RFC?

Secondary votes, 50%+1 each, counted only if the primary passes; B does not depend on A:

- A. Should \$\_GET, \$\_POST, \$\_COOKIE, \$\_FILES, \$\_REQUEST, \$\_SERVER and the session (\$\_SESSION and ext/session's state) follow the current scope as described, a new scope starting empty?
- B. Should the default locale and the default timezone follow the current scope as described?

Generators are part of the main proposal rather than a separate vote. Leaving them out would not keep options open: generators would run in the scope of whatever code advances them, existing code would come to depend on that, and capturing the scope later would itself be a backward incompatible change.

## Patches and Tests

An extension implementing this RFC, including both secondary votes, is released for PHP 8.2 to 8.5 as `fiber-interop/ext-scope` 0.4.0 (<https://github.com/fiber-interop/ext-scope>, `pie install fiber-interop/ext-scope`), with phpt tests for each rule in this text. A userland polyfill with the same class and tests is `fiber-interop/scope` 0.4.0. A pull request against php-src master will accompany the RFC before the vote.

## Implementation

The engine stores the current scope for `{main}` and for each fiber and generator. It restores that value when execution switches. Fibers and generators also store the scope they captured at creation.

`run()` saves the current scope, installs the new one, calls the callback, and restores the old scope. A fiber may suspend inside nested `run()` calls, so the saved values must survive suspension and be visible to the cycle collector. Fatal-error bailouts also restore the saved scope before continuing the bailout.

The tests cover, among other things:

- a fiber that suspends inside `$scope->run()`: the resuming code keeps its own scope, and the fiber finds its scope again when resumed;
- a fiber constructed in one scope that suspends inside another scope's `run()`, and is then dropped: both scopes stay reachable while the fiber is suspended, and are collected afterwards, also through cycles;
- a fatal error inside nested `run()` calls, with the cycle collector enabled, followed by request shutdown, including with fibers suspended inside `run()`;
- generators created in one scope and consumed from another, across several yields and through `yield from`;
- a generator running inside a fiber that suspends while the generator is inside a `run()` call: the generator and the fiber both find their scope again when resumed. (A generator can't `yield` while a `run()` call is active in its own body: a `yield` inside the callback makes the callback a generator.)
- `unset($_GET)` inside a scope does not affect another scope or the root; `global $_GET` inside a function called in a scope binds to that scope's array; a scope that only reads the arrays owns no copies.

An extension cannot add fields directly to native fiber and generator objects. The prototype therefore keeps each one's scope in a `WeakMap`, follows fibers through the fiber switch observer, and compiles scope switches into generator bodies; ordinary calls pay nothing. Measured on PHP 8.5.12, x86-64, with opcache on and the JIT off (median of 5 runs):

| | Without | With the extension |
|---|---|---|
| Function call | ~23 ns | ~23 ns |
| Generator step | ~37 ns | ~57 ns |
| Fiber resume and suspend | ~110 ns | ~141 ns |

These figures measure the extension's hash-table-based approach, not the proposed php-src implementation. In core, each fiber and generator can hold a scope pointer directly. The php-src patch will include its own measurements.

### Threads

Engine state currently has process/thread and PHP-request lifetimes. `Scope` adds an operation lifetime. It works within ZTS threads and does not replace them.

- The current scope and the root scope live in the executor globals, which ZTS makes per thread. Each thread has its own root scope, current scope and fibers. A `Scope` object never crosses a thread boundary, because no PHP object does. No lock enters the design: the fibers of one thread run one at a time.
- Scope multiplexes one thread's state between operations; threads multiplex one process's state between executors. A server may use both: worker threads, each serving several requests as scopes.
- For extension authors, per-scope state is the module-globals pattern with a shorter lifetime: declared the same way, constructed when a scope first touches it, destroyed when the scope is released. The follow-up RFC describes it in that vocabulary.
- Cost: state converted to follow the scope is swapped on switch, not reached through an indirection, so access costs what it costs today on both builds and a switch costs a bounded number of word moves proportional to the converted state. State that hands out pointers into itself cannot be moved by value and is reached indirectly; the follow-up RFC lists such cases.
- Scope adds no concurrency. State that follows the scope is never visible to another operation, so code that was correct under one request per process stays correct for that state. State kept shared on purpose stays shared, as it is across requests in any long-running process today.

## References

- [Fibers](https://wiki.php.net/rfc/fibers)
- [Concurrency Support in the PHP Engine](https://wiki.php.net/rfc/async_scheduler_abi)
- [Async Scope](https://wiki.php.net/rfc/true_async_scope)
- The prototype and its measurements across event loops: <https://fiber-interop.github.io/>

## Rejected Features

- **A scope that must be passed on explicitly,** without inheritance. Every library that creates fibers would need to pass the right scope on, which is the interoperability problem this RFC sets out to solve.
- **`null` outside any scope.** Every consumer would handle two cases, state keyed by scope would need a fallback key, and a root scope added in a later version would change what existing code observes. A root object from the first version avoids all three.
- **One thread per request under ZTS, instead of scopes.** Threads do not help the common case, a request whose fibers must share its state; most PHP deployments are NTS and the extension ecosystem is less exercised under ZTS; and the two are not alternatives, since scopes work inside each thread.

## Changelog

- 0.9.1 (2026-10-26): first version on the wiki.
