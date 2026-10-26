# PHP RFC: Scope

- Version: 0.9.1
- Date: 2026-10-26
- Author: Frode Børli, frode@ennerd.com
- Status: Draft
- Implementation: https://github.com/fiber-interop/ext-scope (extension); php-src pull request to follow
- Discussion thread: tbd
- Voting thread: tbd

## Introduction

PHP has no representation of a request. Its state is either process-wide or, under ZTS, per
thread, and the request exists only as a phase of the process: whatever runs between request
startup and shutdown. While one process served one request at a time, that was enough. This RFC
proposes `Scope`, an object that stands for one unit of work, such as an HTTP request, a queued job
or a CLI command, so that code can create one, run code in it, and ask which one is current.

```php
// A server runs each request in a scope of its own:
(new Scope())->run($handler, $request);

// Code anywhere in that request can ask which request it belongs to:
$this->connections ??= new WeakMap();
$db = $this->connections[Scope::getCurrent()] ??= new PDO($dsn);
```

Long-running PHP servers serve many requests in one process, one after another or interleaved,
and each of them reinvents the request. Frameworks reset their state between requests, containers
key request-lifetime services by whatever identity the runtime offers, and event loops track
which work belongs together in their own way. Code that needs to know which request it is serving
has nothing in the language to ask.

Three things need a request scope.

**Request-lifetime services.** A container offers services with a request lifetime (Laravel's
scoped bindings, `Lifetime::Scoped` in Mini), and it needs to know which request is current to key
them by. A long-running server today either resets the container between requests, which works
only while requests never overlap, or keys services by `Fiber::getCurrent()`, which breaks as soon
as a request starts a second fiber: the container then creates a second session, a second database
connection, a second unit of work, inside the same request. A logger that adds a request id, a
tracing span and a per-request cache need the same anchor.

**Request state in the engine.** PHP keeps per-request state in process-wide (or per-thread)
globals: the superglobals, the session, the output buffers, the default locale and timezone, error
handlers, ini values. PHP resets that state between requests, but while a request runs, the state
belongs to the process, not to the request, because there is nothing that represents the request
to hold it. A scope is that owner. This RFC provides it, and two secondary votes move the first
state into it: the request superglobals with the session, and the default locale and timezone.
Moving more state is for later RFCs (see Future Scope). Code that never creates a scope runs in the
root scope and keeps today's behaviour exactly.

**Interoperability.** Each runtime today invents its own request identity; phasync, for example,
exposes `phasync::getContext()`. A library that relies on one runtime's identity is tied to that
runtime. An identity shared by all of them has to come from the language.

A scope is also the groundwork for making PHP a good language for concurrent programming. In a
long-running server, one request may run in several fibers: parallel database queries, a streamed
response, background work that outlives the response, often started by libraries on the
application's behalf. A scope follows its request into the fibers and generators created in it, so
"which request is this" keeps its answer when the request runs concurrently, and per-request state
has an owner that is neither the process nor a single fiber. Tying that state to each fiber
instead, as the per-coroutine stores of the
[Concurrency Support in the PHP Engine](https://wiki.php.net/rfc/async_scheduler_abi) RFC would (a
new coroutine starts empty, with `ob_start()` named as the first state to move), is backward
incompatible: today every fiber shares the output buffers, locale and timezone of the code that
started it. The fibers of one scope share its state, which is how code already behaves. The
Concurrency Support RFC needs such a unit of work and doesn't define one; the two compose, and
engine coroutines that need not be `Fiber` objects would get scope membership exactly as fibers do.

Java (`ScopedValue`), .NET (`ExecutionContext`, `AsyncLocal<T>`), Python (`contextvars`), Go
(`context.Context`) and JavaScript (`AsyncLocalStorage`, the `AsyncContext` proposal) each have
this primitive, and Java and .NET enterprise frameworks build request-scoped services on it (see
Prior art). This RFC proposes its PHP-native form: an identity for the current unit of work that
follows execution. Everything built on that identity is left to libraries and to later RFCs.

## Proposal

A new final class, and one new method on `Fiber` and on `Generator`:

```php
final class Scope
{
    public function __construct() {}

    /**
     * Calls $callback with this scope as the current scope, and returns its result. The previous
     * current scope is restored when $callback returns or throws.
     */
    public function run(callable $callback, mixed ...$args): mixed {}

    /**
     * Returns a closure that, whenever and wherever it is called, runs $closure with this scope
     * as the current scope, as run() does. The returned closure keeps $closure's signature
     * (by-reference parameters, defaults, return type), $this and class scope. $closure itself is
     * not modified, as with Closure::bind(). Binding a closure that bind() returned replaces
     * its scope.
     */
    public function bind(Closure $closure): Closure {}

    /** The current scope. In {main} outside any run(), and in fibers and generators created there, the root scope. */
    public static function getCurrent(): Scope {}

    /** The scope that stands for code that is part of no operation. One object for the life of the process. */
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

A `Scope` identifies one operation. It carries no data and no behaviour beyond `run()` and
`bind()`. The rules:

1. `$scope->run($callback)` makes `$scope` the current scope while `$callback` runs, and restores
   the previous one afterwards, also when `$callback` throws. Calls nest: an inner `run()`
   replaces the current scope until it returns. A scope keeps no link to the scope it was run
   from.
2. A fiber belongs to the scope that is current when it is constructed, for its whole life. The
   fiber holds a strong reference to its scope. Fibers that libraries construct on the
   application's behalf join the operation without any cooperation from the library.
3. A generator belongs to the scope that is current when it is created, that is, when the
   generator function is called, for its whole life, like a fiber.
4. When a fiber or a generator runs, the current scope is its own: the scope it belongs to, or
   the scope of a `run()` call active inside it. Where it is resumed from doesn't matter. Each
   fiber, each generator, and `{main}`, has its own current scope, saved and restored when execution switches between them. The current scope is therefore that of the
   innermost executing context, fiber or generator, together with any `run()` active inside that
   context; a `run()` active in a caller never overrides the membership of a generator or fiber it resumes.
5. Functions and closures run in the scope of their caller. A closure returned by
   `$scope->bind($closure)` runs in `$scope`, whoever calls it. Binding a closure that `bind()`
   returned replaces its scope: `$a->bind($b->bind($f))` runs `$f` in `$a`.
6. In `{main}`, outside any `run()`, and in fibers and generators
   created there, the current scope is the root scope, `Scope::getRootScope()`. The root scope is a
   single object that lives for the whole process (or request, under a SAPI that serves several).
7. A destructor runs in the scope that is current when its object is released, like any other
   function. An object released during another operation's `run()` therefore runs its destructor
   in that operation's scope. An object that needs its own scope at destruction captures it when
   it is constructed, and runs its cleanup with `$scope->run()`.

The root scope stands for code that is part of no operation: a CLI script, a php-fpm request,
the server's own loop. Code asks whether it runs inside an operation with
`Scope::getCurrent() !== Scope::getRootScope()`. Having a root scope from the start means
`getCurrent()` never returns `null`, every consumer handles one case, state keyed by scope in a
`WeakMap` always has a key, and "run this outside any operation" is an ordinary call,
`Scope::getRootScope()->run($f)`. State keyed by the root lives for the process, which is what
process-wide state does today.

Rule 5 is deliberate. Closures are mostly created once and called per operation: route handlers,
middleware, event listeners and container factories are created while an application boots, in
the root scope, and called during each request. If a closure captured the scope it was created in,
as a fiber or a generator does, every one of them would run in the root scope instead of the
request's. The opposite case, code in one scope handing a callback to code in another and meaning
it to run as its own, is rare and deliberate, so it is explicit:

```php
$listener = Scope::getCurrent()->bind(function (Event $e) {
    // runs in the scope that registered it, whichever scope dispatches the event
});
$bus->on('order.paid', $listener);
```

`bind()` adds nothing `run()` cannot express (`fn(...$a) => $scope->run($closure, ...$a)`), except
that a userland wrapper loses by-reference parameters and the closure's signature; the native
method keeps them. Generators and fibers created inside a bound closure belong to the bound scope,
as they would inside `run()`. JavaScript's proposed `AsyncContext.Snapshot.wrap()` and Node's
`AsyncLocalStorage.bind()` serve the same purpose.

Together these keep one invariant: the scope never changes within an executing function, except
through `run()` calls that function makes itself. A `yield` or a `Fiber::suspend()` may hand
control to code in any other scope, but when the function continues, `Scope::getCurrent()`
returns what it returned before.

A scope here is only an identity. It doesn't wait for its fibers or cancel them (see Future Scope), and it has no end state: nothing happens to it when a `run()` call
returns. A scope lives as long as something references it, whether that is a fiber, a queued
callback, the server's request object, or anything else. A server drops its references to an
operation's scope when the operation completes, so that state keyed by the scope can be released.

Code that needs per-scope state keeps it in a `WeakMap` keyed by the scope. Independent
libraries can attach state without knowing about each other, and a `WeakMap` entry is removed
once its scope is no longer reachable. Since PHP 8.3 that includes entries whose value references
the scope, such as a service holding on to the scope it was created for:

```php
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

A container that keys its scoped services by `Fiber::getCurrent()` today changes one line, to
`Scope::getCurrent()`, and becomes correct under any event loop, and in servers that don't use
fibers. A library written against `phasync::getContext()` does the same and no longer depends on
phasync.

A `Scope` is not serializable, like `Fiber`: it identifies an operation within one process. An
identifier that must travel, to a queue worker or another service, is data; the application
attaches it with a `WeakMap` and sends it as such, and the receiving side creates a scope of its
own and attaches the same identifier.

A scope has no lifecycle of its own: nothing starts it, nothing ends it, and it can be entered any
number of times from independently created fibers. Both fibers below report the same scope, one by
construction and one by entering it:

```php
$scope = new Scope();
$a = $scope->run(fn() => new Fiber(fn() => Scope::getCurrent()));   // constructed inside $scope
$b = new Fiber(fn() => $scope->run(fn() => Scope::getCurrent()));   // constructed outside, enters it
$a->start(); $b->start();
var_dump($a->getReturn() === $scope, $b->getReturn() === $scope);   // bool(true) bool(true)
```

### Fibers and generators

Fibers are the main use case, and they need no change: a fiber constructed while a scope is
current belongs to it. The one addition, `Fiber::getScope()`, is for code that manages fibers and
needs to know where one belongs.

Membership is decided at construction, not at `start()`, and can't change afterwards. A fiber
constructed outside any scope stays outside, even when it is started inside one:

```php
$fiber = new Fiber(fn() => var_dump(Scope::getCurrent() === Scope::getRootScope()));
$scope->run(fn() => $fiber->start());   // bool(true): the fiber was constructed outside any scope
```

Making membership dynamic would reopen the ambiguity the scope exists to remove.

A fiber that must not join the task it is constructed in is constructed in the scope it belongs
to. An event loop does this for the service fibers it creates on demand, for example a fiber that
drives the loop's timers, which would otherwise join whichever request happened to trigger its
creation:

```php
$this->timerFiber = $this->servicesScope->run(fn() => new Fiber($this->runTimers(...)));
```

Constructing a fiber allocates no stack; `start()` does.

Generators follow the same rule, because a generator is resumed later, from wherever the code
consuming it happens to run. Code in a generator would otherwise see its scope change at every
`yield`:

```php
$db = $container->get(PDO::class);
yield;
$db = $container->get(PDO::class);   // a different connection would be unexpected
```

The same holds for a generator that is created inside a request and consumed outside it. A
server that streams a response body produced by a generator consumes it from its own loop,
outside the request; the generator still runs in the request's scope:

```php
// In the application, inside the request's scope:
return new Response(body: (function () {
    foreach (RequestServices::get('db')->query('...') as $row) {   // the request's connection
        yield json_encode($row);
    }
})());
```

A generator that delegates with `yield from` runs the inner generator in the inner generator's own
scope, the one it was created in.

### Functions and closures

Functions and closures run in the scope of their caller, and don't capture the scope they were
created in. Applications create most long-lived closures once, at startup, outside any scope:
container factories, event listeners, middleware, route handlers. They are called during requests,
and must see the request's scope. Capturing would also add a scope switch to every closure call,
including the callbacks of `array_map()` and `usort()`.

Code that wants a closure to run in a particular scope says so: `fn() => $scope->run($callback)`.

### Callbacks

An event loop that queues a callback and calls it later can run it in the scope it was queued
from:

```php
// When the callback is registered:
$this->queue[] = [Scope::getCurrent(), $callback];

// When it is due:
[$scope, $callback] = array_shift($this->queue);
$scope->run($callback);
```

This works the same for event loops built on fibers and for callback-based ones. A `Scope`
executes nothing on its own; holding one only allows running code in it. Callbacks follow their
scope once an event loop adopts this pattern; fibers follow it without any change to the loop.

The queue holds the scope, so a callback that outlives its operation keeps the operation's scope,
and any state keyed by it, alive until the callback is released. That follows from scopes being
identities owned by reference rather than tasks with an enforced lifetime.

### Secondary vote A: the request superglobals follow the scope

This RFC proposes the identity and converts no engine state, except two cases, each small enough
to show the shape of the rest and useful enough on its own to decide separately. This is the first.

Of PHP's superglobals, six describe the current request: `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`,
`$_REQUEST` and `$_SERVER`; a seventh, `$_SESSION`, holds the request's session together with the
rest of ext/session's state. `$_ENV` describes the process and is not proposed here. Today a process
that serves several requests at once has one copy of each, so every long-running PHP server either
rewrites them behind the application's back or tells its users not to touch them, and
`session_start()` in one request replaces another's session.

With a yes on this vote, the request superglobals and the session follow the scope:

- The root scope's arrays are the ones the SAPI populates: unchanged behaviour for every script
  that never creates a scope.
- A new scope starts empty: the six arrays are empty and no session is active
  (`session_status()` is `PHP_SESSION_NONE`). Whoever creates the scope seeds it, as a server does
  for each request. A new scope does not inherit its creator's values: a copy of an array is
  shallow, so objects in it would stay shared with the creator, and an inherited copy would
  promise an isolation it cannot give.
- An assignment to one of the six inside a scope changes only that scope's array. Reads see the
  current scope's array. All fibers and generators of the scope share it, as the rules above
  give.
- `$_REQUEST` is a scope-local snapshot like the others, not a live merge: a scope that assigns
  `$_GET` and relies on `$_REQUEST` assigns it too, as code that assigns `$_GET` must today.
  Leaving `$_REQUEST` shared while the three it is built from are per scope would give one
  request two inconsistent views of its input, which is why it is in the set.
- `$_SERVER` mixes request facts (`REQUEST_URI`, `REQUEST_METHOD`, the `HTTP_*` entries,
  `REMOTE_ADDR`, `HTTPS`) with the environment (`PATH`, `DOCUMENT_ROOT`, what the application put
  there while booting). A request's scope starts with an empty `$_SERVER` like the others, and the
  server writes both into it: the environment entries it wants the request to see, then the
  request's own. Leaving it shared would leave a request's own URI, method, headers and client
  address to whichever request wrote them last: read after the request's first suspension, they
  would be another request's, silently.
- The session is the scope's: `session_start()` inside a scope starts that scope's session, and
  its state (`$_SESSION`, the session id and status, the save handler and its data, the cookie
  parameters and the cache limiter) is the scope's alone. All fibers and generators of the scope
  share it, as they share the scope's other superglobals. A scope has no end state (see above), so
  the session is written and closed when the server calls `session_write_close()` inside the scope
  at the end of the request, or at the latest when the scope is destroyed with the session still
  active, as at the end of a request today. The headers
  `session_start()` sends (`Set-Cookie`, the cache limiter's) belong to the scope's response:
  until per-scope headers have their own RFC (see Future Scope), a server that hosts several
  requests in one process must route them, as it already routes the rest of each response.

No method is added to `Scope`, and nothing decides who fills the arrays. A server does what it
does today, inside the scope:

```php
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

Two requests in one process:

```php
$a = new Scope(); $b = new Scope();
$a->run(fn() => $_GET = ['page' => '1']);
$b->run(fn() => $_GET = ['page' => '2']);
$a->run(fn() => var_dump($_GET['page']));   // string(1) "1"
var_dump($_GET);                             // the SAPI's array: unchanged
```

**Semantics worth stating.** `$GLOBALS['_GET']` and `global $_GET` reach the same array and
follow the scope. A reference bound before a scope switch (`$g = &$_GET`) keeps pointing at the
array it was bound to, as references do: the scope isolates the binding of the name, not objects
already reached through it.

**Implementation.** The intended mechanism is a swap of the six `zval`s: the engine keeps the six `zval`s of
the current scope in the global symbol table where they are now, and on a scope switch stores them
into the outgoing scope and loads the incoming scope's, with no copying of arrays. That would make
reads and writes cost what they cost today, on both NTS and ZTS builds, and a scope that never wrote
any of the six would own no copies. Whether the swap is sufficient against the auto-global
machinery (`PG(http_globals)`, JIT auto-globals, `unset($_GET)`, `global $_GET`, lazily initialised
auto-globals) is what the php-src patch must demonstrate, and the RFC's claim is only as strong as
that patch. Under ZTS each thread has its own symbol table, so the per-thread arrays nest inside it
as the root of that thread.

**Why these and why now.** They are the state the introduction's long-running-server
argument is about; converting them demonstrates the backward-compatibility rule (code outside a
scope sees the SAPI's arrays) on real state rather than asserting it; and the mechanism, swap on
switch, is the one the follow-up RFC generalises, so a yes here settles its shape with the
smallest possible surface. With all seven, vote A isolates the whole of a request's input, its
`$_SERVER` entries included, and its session.

**Reference implementation.** The accompanying extension implements this vote: the six arrays are swapped in the symbol table (and `PG(http_globals)`) at every scope change (version 0.9 of this text changed the rule for a new scope from inheriting its creator's arrays to starting empty, and added the session; the extension and the polyfill are being updated to match, and the phasync extension's `sapi()` already swaps ext/session's state per request), and the lazily created auto-globals are forced at the first scope creation so a later-compiled `$_GET` read cannot re-arm the engine's JIT callback over a scope's array. It is on by default and can be turned off with `scope.superglobals=0` so this vote can be evaluated independently. The userland polyfill does the same when an event loop reports a change of scope; the interoperability project where this is measured across event loops (https://fiber-interop.github.io/conformance.html, column "Scope state") shows which loops can report every switch and which cannot, which is the case for doing it in the

### Secondary vote B: the default locale and timezone follow the scope

`Locale::getDefault()`/`Locale::setDefault()` (ext/intl) and `date_default_timezone_get()`/
`date_default_timezone_set()` are ambient settings of the current operation: code sets them to
interpret and format values for the request it is serving, and a server that hosts several
requests at once has today one value of each for all of them. With a yes on this vote they follow
the scope with the rule of vote A: the root scope's values are today's (from ini and the SAPI), a
new scope starts with PHP's defaults (the ini values) rather than its creator's, a change inside a
scope affects only that scope, and code outside any scope sees no change. A new scope behaves as a
freshly started PHP environment: whoever creates it configures what it needs, as a request's
bootstrap does today. One rule for every piece of state, with no exception for values that happen
to be cheap to copy, is easier to reason about than inheriting some and not others. The implementation is the same swap on
switch: two words per switch.

**Reference implementation.** The accompanying extension implements this vote too: the default timezone is one pointer swap of ext/date's current string per scope change; the locale, whose globals ext/intl does not expose to other extensions, is applied through the `intl.default_locale` ini entry only when the entering scope's locale differs from the live one (in core it would be the same plain swap as the timezone). `scope.locale_timezone=0` turns it off. Two details the text above leaves open, decided in the implementation and tested: `ini_set('date.timezone')` stays request-wide (a scope that has set its own timezone ignores it; one that never did follows it), and a new scope starts with the ini value of `date.timezone` (or "unset", that is the ini fallback) and `intl.default_locale`, not with its creator's. (Version 0.9 of this text changed this from inheriting the creator's values; the extension and the polyfill are being updated to match.)

The two are chosen because they are request-scoped by nature and because they are what unmodified
applications (content management systems, frameworks) set per request today; without them such an
application cannot serve two requests with different locales in one process. They are the first
instalment of the principle below, voted separately because ext/intl and ext/date have their own
maintainers and their own edge cases (ICU's process-wide default, timezone strings validated at
set time) that deserve a focused discussion.

### What follows the scope, and what does not

The principle for this RFC and the ones after it: **the scope isolates state that belongs to the
logical operation, not state merely because it is global.**

| State | Belongs to | Follows the scope |
|---|---|---|
| `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, `$_SERVER`, `$_SESSION` and ext/session's state | the request | yes (vote A); a new scope starts empty |
| default locale, default timezone | the operation | yes (vote B) |
| output buffers, error and exception handlers, `ini_set()` values | the operation | later, one RFC each |
| `json_last_error()`, `preg_last_error()`, `error_get_last()` | the call just made | no: see below |
| class statics, function statics, `$GLOBALS` | the application, shared on purpose | no |
| `$_ENV`, `setlocale()`, `chdir()`, `umask()` | the process | no |
| persistent resources: `PDO::ATTR_PERSISTENT`, `pg_pconnect()`, mysqli `p:` hosts, `pfsockopen()` | the process, lent to one scope at a time | later, on its own: see below |

Two rows deserve a word. Last-error state needs no scoping because fibers switch only at
suspension points, and `json_decode()` followed by `json_last_error()` has none between them;
code that reads an error state in a different coroutine from the call that produced it is already
wrong today and does not become right by scoping. Class statics and `$GLOBALS` are shared on
purpose: a registry, a cache, a singleton. Giving each scope its own copy would change the
meaning of `static`. Techniques that virtualize them exist, for running unmodified legacy code
concurrently, and they are a compatibility measure, not a language semantic; this RFC and its
follow-ups do not propose them.

Persistent resources are process-wide, and concurrency makes that visible. PHP keeps them in
`EG(persistent_list)`, keyed by DSN and user, and a second `new PDO()` with the same arguments gets
the cached handle even while the first object is still live. With one request per process two such
objects rarely coexist; with concurrent scopes they do, and they share one database session: a
transaction begun in one scope covers writes made in another, and its rollback erases them.

The rule should be that a persistent resource is never shared between scopes, while within one
scope it behaves exactly as today. A lookup that finds the cached handle in use by another live
scope opens a new one instead; when a scope ends, its handles return to the process for the next
scope, after the same cleanup PHP does at the end of a request today. Code that never creates a
scope runs in the root scope and sees no change, so the semantics of persistent connections stay
what they are. This touches `ext/pdo`, `ext/pgsql`, `ext/mysqli` and the streams layer, so it is
proposed as its own follow-up RFC rather than in this one. Until then, code serving concurrent
scopes should use one non-persistent connection per scope, for example from a pool.

## Prior art

Most languages with lightweight concurrency propagate task context implicitly, and inherit it
when a task is created. Several have this RFC's shape: an object that code runs inside.

- **Python:** `contextvars` ([PEP 567](https://peps.python.org/pep-0567/), Python 3.7).
  `Context.run()` calls a function in a given context, and asyncio runs each task in a copy of the
  context that was current when the task was created. Making generators keep a context of their
  own was proposed in [PEP 568](https://peps.python.org/pep-0568/), which is deferred.
- **JavaScript:** Node.js has `AsyncLocalStorage`, with `run()`. The TC39
  [AsyncContext](https://github.com/tc39/proposal-async-context) proposal (Stage 2) moves the
  mechanism into the language, including `AsyncContext.Snapshot` for running a callback in the
  context it was captured in, because userland solutions such as Zone.js can't follow
  `async`/`await` without transpilation. Generators restore the context that was current when they
  were created, as in this RFC; other functions run in their caller's context.
- **Java:** `InheritableThreadLocal` values are inherited when a thread is constructed. Scoped
  values ([JEP 506](https://openjdk.org/jeps/506), JDK 25) are bound for the duration of a
  `run()` call and inherited by the child threads of a `StructuredTaskScope`.
- **.NET:** `ExecutionContext` is captured when asynchronous work is started and restored when it
  runs, and `ExecutionContext.Run()` runs a callback inside a given context, the same shape as
  `run()` here. `AsyncLocal<T>` (.NET Framework 4.6) holds "ambient data that is local to a given
  asynchronous control flow" and flows with it.
- **Kotlin:** a coroutine inherits the `CoroutineContext` of the coroutine that launches it.
  `launch(Job())` starts one that is independent of its parent.
- **Swift:** task-local values
  ([SE-0311](https://github.com/swiftlang/swift-evolution/blob/main/proposals/0311-task-locals.md),
  Swift 5.5) are bound for the duration of a closure and inherited by child tasks, and not by
  detached tasks.
- **Go:** `context.Context` is passed explicitly, as a function parameter. That works in Go
  because its standard library and ecosystem adopted the convention. PHP's libraries have no such
  convention, and fibers are created inside code that has no parameter to pass a context through.

**Service containers.** The idea that service lifetimes hang on a scope rather than on a thread or a process is established in dependency-injection containers. ASP.NET Core registers services as singleton, scoped or transient; the host creates one scope per request, and code creates its own with `IServiceScopeFactory.CreateScope()`, so a scoped service lives exactly as long as the scope it was resolved in. Spring's `@RequestScope` and Jakarta CDI's `@RequestScoped` are the same contract with the container deciding what the current request is. PHP frameworks do this without a primitive to hang it on: Laravel's scoped bindings exist because Octane serves many requests from one process, and Symfony resets services tagged `kernel.reset` between requests under long-running runtimes. Each defines "the current request" for itself, and none can share it with a library that uses a different container or event loop. The identity proposed here is what they would key on instead.

**Earlier PHP discussions.** The [Fibers RFC](https://wiki.php.net/rfc/fibers) (PHP 8.1) set
the pattern this RFC follows: it proposed "only the bare minimum required to allow user code to
implement full-stack coroutines", and left event loops and scheduling to libraries because "it
is best to provide the bare minimum in core and allow user code to implement other components as
they desire". On state it said that fibers interleaving in one thread may need "mutexes,
semaphores, memory parcels, and channels", to be implemented in user space. That framing treats
all state as shared and asks code to synchronise access to it. This RFC adds the distinction the
Fibers RFC did not need to make while PHP served one request per process: some state is shared
on purpose and should stay so, and some belongs to one operation and should never be visible to
another. The [Concurrency Support](https://wiki.php.net/rfc/async_scheduler_abi) RFC (July 2026)
recognises the second kind, naming output buffering and `gethostbyname()` as state a userland
scheduler cannot manage safely, and proposes per-coroutine stores in the engine for it. The
difference is the owner: that proposal attaches the state to a coroutine, the unit a scheduler
resumes; this one attaches it to the operation, which several fibers may serve at once and which
exists whether or not a scheduler is present.

This RFC is deliberately smaller than most of these: it propagates an identity, not values. An
identity together with `WeakMap` gives the same per-task storage, needs no storage API in the
engine, and lets libraries keep private state without agreeing on keys.

The Concurrency Support project also uses the word *Scope*, for a group of coroutines with a
shared lifetime and cancellation ([Async Scope](https://wiki.php.net/rfc/true_async_scope)).
The two are different things: that Scope owns execution and ends it; this one identifies an
operation and owns nothing, so that any scheduler, including one built on that project, can
give its coroutines membership in it. The name is kept here because it is the word for a
dynamically bound context in Java's scoped values and in the service containers above; a reader
of both proposals should hold the two meanings apart. *Context* was considered and not chosen: in
the languages above a context carries values, and this class carries none; in PHP the word already
names stream contexts. The backward-compatibility footprint of the two names is the same (3 of 100
sampled PHP files on GitHub declare a global `class Context`, 3 of 100 a global `class Scope`).

## Backward Incompatible Changes

- A class named `Scope` in the global namespace can no longer be declared. Classes named `Scope`
  in a namespace are unaffected. Of the first 100 results of a GitHub code search for PHP files
  declaring `class Scope`, 88 are namespaced, and 2 declare it in the global namespace: a toy
  interpreter in a multi-language repository, and a project last changed in 2018 (a second sample
  on 2026-10-14 found 3 of 100).

## Proposed PHP Version(s)

Next PHP 8.x (PHP 8.7).

## RFC Impact

### To the Ecosystem

IDEs, language servers and static analysers need stubs for the `Scope` class and the two new
`getScope()` methods; nothing in the syntax changes. Libraries can adopt `Scope` before it ships:
the polyfill `fiber-interop/scope` declares the same class on PHP 8.2 and later when the engine
doesn't, and event loops adopt it with the callback pattern above. Containers, loggers and tracers
that key state by `Fiber::getCurrent()` today can key it by `Scope::getCurrent()` instead.

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

None at present. See the Discussion section for the case for and against converting engine state together with the primitive.

## Unaffected PHP Functionality

Code that never creates a `Scope` runs in the root scope and behaves exactly as before. Functions and closures run in the scope of their caller, `Fiber` and `Generator` behave as before apart from the added `getScope()` method, and state not covered by the secondary votes stays process-wide.

## Discussion

This RFC adds the identity, and converts no state apart from the two secondary votes. An argument can be made for converting PHP's
own implicit state right away, together with the mechanism: output buffering, the error
handlers, `ini_set()` values. If each subsystem waits for an RFC of its own, applications may
need an opt-in or an ini setting per subsystem for a long time, and some subsystems may never be
converted.

The case for converting later is the review surface. Each subsystem has its own maintainers and
its own peculiarities, existing code has workarounds for some of them, and one disputed subsystem
should not decide the fate of the primitive. The planned mechanism keeps both paths open: either way,
code outside scopes sees the state it sees today, so a subsystem can be converted early or late
without breaking that code.

Each conversion also has to decide how a new scope's state starts: fresh, as in a new request, or
as a copy of the state current when the scope was created. Output buffers are left to their own RFC. The two secondary votes settle it the same way for their state: a new scope starts as a new request does, with empty superglobals, no session, and the default locale and timezone from ini, and changes inside a scope stay inside it.

## Future Scope

PHP has a great deal of implicit, process-wide state, and that directness is part of what makes
the language pleasant: `json_last_error()` needs no context object, `header()` needs no response
object. The goal this RFC works towards is letting several applications, or several operations of
one application, coexist in one process without giving that up. Getting it right matters more than
getting it fast, so it should happen one subsystem at a time, each in an RFC of its own that keeps
today's behaviour for code outside scopes.

State that could follow the scope includes:
- `Locale::getDefault()` and `Locale::setDefault()`, and the default timezone (secondary vote B);
- output buffering;
- error and exception handlers, `ini_set()` values and shutdown functions;
- the SAPI's response side, `header()`, `headers_list()`, `headers_sent()`, `http_response_code()`
  and the sink that receives the response body: an RFC that exposes these SAPI integration points to
  userland, bound to the scope, so that a server can supply them per request; it is the same RFC that
  supplies the bottom of a request scope's output stack (see output buffering below);
- the superglobals (the request superglobals are secondary vote A).

**Output buffering, the first follow-up.** Output is the hardest of the candidates and will be an
RFC of its own. `ob_start()` serves two uses that one mechanism conflates: request output, which
belongs to the operation (every fiber of a request writes into the request's stack, as every line
of a php-fpm request does today), and capture, which belongs to a piece of code (a template engine
wrapping an `include`). The first calls for the output stack to follow the scope, with a server
supplying the sink at the bottom of a request scope's stack (the response body); whether a nested scope shares its creator's stack or starts its own is left to that RFC, so no existing `ob_*` call changes meaning within one request. The second
calls for a structured capture, `ob_context_run(callable): string`, a fresh buffer bound to the
callable's execution, returned when it returns or throws, inherited by fibers started inside it;
it is useful without any concurrency and it fixes the one real concurrency bug in capture, an
`ob_get_clean()` after a suspension popping a buffer someone else pushed meanwhile. Per-execution
stores, as proposed elsewhere, would change the first use for existing code; the two-part design
above does not. Neither part is proposed here.

A follow-up RFC is planned for the mechanism: an internal API through which an extension, or a
core subsystem, gives a piece of its state one copy per scope, reached through the engine, with the root scope holding the state of code outside any operation, so that extensions never handle the absence of a scope. A
bridge that swaps registered globals when execution changes scope would serve extensions that
won't be changed. The extension that accompanies this RFC prototypes both.

Moving such state into the scope changes nothing within one operation: its fibers share it, as all
fibers share it today. What changes is that operations stop overwriting each other's state.

Some state belongs to the operating system rather than to PHP, and can't follow a scope cheaply:
`setlocale()`, `chdir()`, `umask()`, environment variables, and the identity `openlog()` gives the C
library's syslog. PHP could only emulate per-scope values for those.

A later RFC can also add operations on an operation as a whole, for example:

```php
Fiber::cancelScope($scope);   // illustration only: not part of this RFC
```

The semantics of such an operation need a design of their own: how a fiber learns that it was
cancelled (an exception at its next suspension, a flag it checks, something else), what happens to
a fiber that is running or has never started, and how `finally` blocks and cleanup interact with
it. Such an operation also needs to find a scope's fibers: this RFC records the scope of each
fiber, not the fibers of each scope, so the engine or the scheduler would have to track them. This
RFC doesn't settle any of that. It only makes sure there is a unit to apply it to.

The [Concurrency Support](https://wiki.php.net/rfc/async_scheduler_abi) RFC introduces engine
coroutines that need not be `Fiber` objects. A scheduler built on it would give those coroutines
the same scope membership.

## Voting Choices

Primary vote, 2/3 majority:

- Add `Scope`, `Fiber::getScope()` and `Generator::getScope()` as described in this RFC?

Secondary votes, 50%+1 each, counted only if the primary passes; B does not depend on A:

- A. Should `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, `$_SERVER` and the session (`$_SESSION` and ext/session's state) follow the current scope as described, a new scope starting empty?
- B. Should the default locale and the default timezone follow the current scope as described?

Generators are part of the main proposal rather than a separate vote. Leaving them out would not
keep options open: generators would run in the scope of whatever code advances them, existing code
would come to depend on that, and capturing the scope later would itself be a backward
incompatible change.

## Patches and Tests

An extension implementing this RFC, including both secondary votes, is released for PHP 8.2 to 8.5 as `fiber-interop/ext-scope` 0.4.0 (https://github.com/fiber-interop/ext-scope, `pie install fiber-interop/ext-scope`), with phpt tests for each rule in this text. A userland polyfill with the same class and tests is `fiber-interop/scope` 0.4.0. A pull request against php-src master will accompany the RFC before the vote.

## Implementation

The engine keeps the current scope for `{main}`, for each fiber and for each generator, and switches
it along with them. Each fiber and generator also records the scope it was created in.

`run()` saves the current scope, sets its own, and restores the saved one when the callback
returns or throws. A fiber can suspend inside a `run()` call, even inside several nested ones, so
the saved scopes belong to the fiber, not to the C stack, and are reported to the cycle collector
with its membership. A bailout (a fatal error) unwinds past ordinary cleanup, so `run()` catches
it, restores the saved scope and continues the bailout.

The tests cover, among other things:
- a fiber that suspends inside `$scope->run()`: the resuming code keeps its own scope, and the
  fiber finds its scope again when resumed;
- a fiber constructed in one scope that suspends inside another scope's `run()`, and is then
  dropped: both scopes stay reachable while the fiber is suspended, and are collected afterwards,
  also through cycles;
- a fatal error inside nested `run()` calls, with the cycle collector enabled, followed by request
  shutdown, including with fibers suspended inside `run()`;
- generators created in one scope and consumed from another, across several yields and through
  `yield from`;
- a generator running inside a fiber that suspends while the generator is inside a `run()` call:
  the generator and the fiber both find their scope again when resumed. (A generator can't
  `yield` while a `run()` call is active in its own body: a `yield` inside the callback makes the
  callback a generator.)
- `unset($_GET)` inside a scope does not affect another scope or the root; `global $_GET` inside a
  function called in a scope binds to that scope's array; a scope that only reads the arrays owns no copies.

The extension can't add fields to the engine's fiber and generator objects, so it keeps their
scopes in a `WeakMap` and follows execution through the observer API. Measured on PHP 8.5, x86-64:

| | Without | With the extension |
|---|---|---|
| Function call | ~25 ns | ~29 ns |
| Generator step | ~38 ns | ~79 ns |
| Fiber resume and suspend | ~108 ns | ~163 ns |

These figures are an upper bound from a hash-table implementation. In the engine, the scope is a
field of the fiber or generator, and following it costs one pointer assignment per switch; the
php-src pull request will show that with a measurement.

### Threads

PHP already has two lifetimes for engine state: the process (the thread, under ZTS) and the
request. Scope adds a third, the operation, with the same shape, and it composes with threads
rather than competing with them.

- The current scope and the root scope live in the executor globals, which ZTS makes per thread.
  Each thread has its own root scope, current scope and fibers. A `Scope` object never crosses a
  thread boundary, because no PHP object does. No lock enters the design: the fibers of one
  thread run one at a time.
- Scope multiplexes one thread's state between operations; threads multiplex one process's state
  between executors. A server may use both: worker threads, each serving several requests as
  scopes.
- For extension authors, per-scope state is the module-globals pattern with a shorter lifetime:
  declared the same way, constructed when a scope first touches it, destroyed when the scope is
  released. The follow-up RFC describes it in that vocabulary.
- Cost: state converted to follow the scope is swapped on switch, not reached through an
  indirection, so access costs what it costs today on both builds and a switch costs a bounded
  number of word moves proportional to the converted state. State that hands out pointers into
  itself cannot be moved by value and is reached indirectly; the follow-up RFC lists such cases.
- Scope adds no concurrency. State that follows the scope is never visible to another operation, so code that was correct under one request per process stays correct for that state. State kept shared on purpose stays shared, as it is across requests in any long-running process today.

## References

- [Fibers](https://wiki.php.net/rfc/fibers)
- [Concurrency Support in the PHP Engine](https://wiki.php.net/rfc/async_scheduler_abi)
- [Async Scope](https://wiki.php.net/rfc/true_async_scope)
- The prototype and its measurements across event loops: https://fiber-interop.github.io/

## Rejected Features

- **A scope that must be passed on explicitly,** without inheritance. Every library that creates
  fibers would need to pass the right scope on, which is the interoperability problem this RFC
  sets out to solve.
- **`null` outside any scope.** Every consumer would handle two cases, state keyed by scope
  would need a fallback key, and a root scope added in a later version would change what
  existing code observes. A root object from the first version avoids all three.
- **One thread per request under ZTS, instead of scopes.** Threads do not help the common case,
  a request whose fibers must share its state; most PHP deployments are NTS and the extension
  ecosystem is less exercised under ZTS; and the two are not alternatives, since scopes work
  inside each thread.

## Changelog

- 0.9.1 (2026-10-26): first version on the wiki.
