# Making a library fiber-friendly

This page is for package maintainers. It shows how to make a library cooperative under any
fiber-interop runtime while it keeps working, unchanged, for everyone who never calls
`fiber\run()`. The examples are small first (a retry loop, a log handler) and then Guzzle, where the
work is done in [fiber-interop/guzzle](integrations.html).

All code below is from [examples/library](https://github.com/fiber-interop/fiber-interop.github.io/tree/main/examples/library)
in this site's repository and was run on PHP 8.5 under three adapters (`FI_RUNTIME=phasync`,
`revolt`, `amp`; phasync without its extension). The timings shown are from phasync; the other two
were within 10 ms.

## The principle

- Require `fiber-interop/api` and nothing else from fiber-interop. Never require an adapter: the
  application picks the runtime, and two libraries that each picked one would conflict.
- Wherever the library would block, wait with `fiber\sleep()`, `fiber\readable()` or
  `fiber\writable()` instead. Inside `fiber\run()` that suspends only the calling task; the other
  tasks keep running.
- Outside `fiber\run()`, keep the old blocking call. The api functions throw
  `fiber\NotInRunException` when no `run()` is active, so the library checks first and falls back.

The check is one line. The api has no public function for it yet; the fiber-interop packages
(curl, pg, mysqli, guzzle) all read the run-frame registry directly, and so do the examples here:

```php
if (\fiber\Registry::$active === []) {
    // no run(): the old blocking call
} else {
    // inside run(): the fiber\ call
}
```

## A retry loop: `usleep()` to `fiber\sleep()`

A typical retry helper sleeps between attempts. With `\usleep()` the whole process stops, so every
other request in the same process waits too.

```php
function pause(float $seconds): void
{
    if (\fiber\Registry::$active === []) {
        \usleep((int) ($seconds * 1e6));   // no run(): behave as before
    } else {
        \fiber\sleep($seconds);            // inside run(): suspend this task only
    }
}

function retry(callable $op, int $tries = 3, float $delay = 0.2): mixed
{
    for ($i = 1; ; $i++) {
        try {
            return $op();
        } catch (\RuntimeException $e) {
            if ($i === $tries) {
                throw $e;
            }
            pause($delay);
        }
    }
}
```

`retry.php` runs three jobs that each fail twice before they succeed, so each spends 0.4 s in
`pause()`. First in a plain loop, then as three tasks:

```php
foreach (['a', 'b', 'c'] as $n) {
    $r[] = job($n);
}

$r = fiber\run(fn() => fiber\await_all([
    fiber\go(job(...), 'a'), fiber\go(job(...), 'b'), fiber\go(job(...), 'c'),
]));
```

```
outside run(): a ok, b ok, c ok in 1.20 s
inside run():  a ok, b ok, c ok in 0.41 s
```

Outside `run()` the result and the timing are what they were with `usleep()`. Inside, the three
waits overlap.

## A log handler: non-blocking writes and partial writes

A Monolog-style handler writes each record to a stream. On a blocking stream, `fwrite()` stops the
process when the reader is slow (a full pipe, a slow log shipper, a remote syslog). The cooperative
version puts the stream in non-blocking mode and waits for it to become writable when it is full.

On a non-blocking stream `fwrite()` may accept only part of the data, or nothing. The loop keeps the
rest and writes it after the wait:

```php
final class StreamHandler
{
    public int $partialWrites = 0;
    public int $waits = 0;

    /** @param resource $stream */
    public function __construct(private $stream)
    {
        \stream_set_blocking($this->stream, false);
    }

    public function handle(string $level, string $message): void
    {
        $this->write("$level: $message\n");
    }

    private function write(string $data): void
    {
        while ($data !== '') {
            $n = @\fwrite($this->stream, $data);
            if ($n === false) {
                throw new \RuntimeException('write failed');
            }
            if ($n > 0 && $n < \strlen($data)) {
                $this->partialWrites++;
            }
            $data = \substr($data, $n);
            if ($data !== '') {
                $this->waits++;
                $this->waitWritable();
            }
        }
    }

    private function waitWritable(): void
    {
        if (\fiber\Registry::$active === []) {
            // No run(): block until writable, as a blocking stream would.
            $r = $e = null;
            $w = [$this->stream];
            \stream_select($r, $w, $e, null);
        } else {
            // Inside run(): suspend this task; the others keep running.
            \fiber\writable($this->stream);
        }
    }
}
```

Outside `run()` the handler must still block when the stream is full, because a caller that logs
and then exits expects the record to be written. `stream_select()` with no timeout does that.

`stream-handler.php` writes 5,000 records (3.1 MB) to a pipe whose reader is a child process that
reads 8 KiB and then sleeps 2 ms. Every 50th record is a 20 KB context dump, larger than the pipe has
room for, so the kernel accepts part of it. The reader prints the byte count and CRC32 of what it
received; the script compares them with the same records built in memory. Inside `run()` a second
task wakes every 10 ms while the handler writes:

```
outside run(): 0.86 s, reader got 3103890 3d1d124f (intact), partial writes 195, waits 372
inside run():  0.86 s, reader got 3103890 3d1d124f (intact), partial writes 195, waits 372, other task ticked 81 times meanwhile
```

The total time is set by the reader in both cases. The difference is what else can happen during
it: outside `run()` nothing, inside it the other task ran 81 times. Without the partial-write loop,
195 records would have been cut off.

## Guzzle

### Where Guzzle blocks

Guzzle sends requests through a handler: a callable that takes a PSR-7 request and options and
returns a promise. `HandlerStack::create()` wraps middleware (redirects, cookies, HTTP errors) around
the handler that `Utils::chooseHandler()` returns. With the curl extension available that is
`Proxy::wrapSync(new CurlMultiHandler(...), new CurlHandler(...))`:

- Synchronous requests (`$client->get()`) go to `CurlHandler`, which calls `curl_exec()` and holds
  the thread until the response is complete.
- Asynchronous requests (`$client->getAsync()`) go to `CurlMultiHandler`. They overlap with each
  other, but `wait()` on the promise drives the transfers in `CurlMultiHandler::tickFor()`, which
  sits in `curl_multi_select()`. That also holds the thread, so other fibers do not run.

(Guzzle 7.15.5, the version installed in the examples.) So a stock client inside `run()` is a
blocking client: three tasks that each call `$client->get()` take as long as three requests in a row.

### What fiber-interop/guzzle does

[fiber-interop/guzzle](https://github.com/fiber-interop/guzzle) is one handler class. It follows the
structure of `CurlHandler` (`CurlFactory::create()`, the transfer, `curl_errno()`,
`CurlFactory::finish()`), so responses, exceptions and options are Guzzle's own. Only the transfer
changes: outside `run()` it is `curl_exec()`, inside it is `fiber\curl\start()` from
[fiber-interop/curl](integrations.html), and the promise's `wait()` awaits
that. An excerpt from `src/Handler.php`:

```php
public function __invoke(RequestInterface $request, array $options): PromiseInterface
{
    HostValidator::assertRequestHost($request);
    $inRun = \fiber\Registry::$active !== [];

    if (!$inRun) {
        if (isset($options['delay'])) {
            \usleep($options['delay'] * 1000);
        }
        $easy = $this->factory->create($request, $options);
        \curl_exec($easy->handle);
        $easy->errno = \curl_errno($easy->handle);
        return CurlFactory::finish($this, $easy, $this->factory);
    }

    // A delay only postpones this transfer: a task sleeps, then starts it, so sending stays eager.
    $easy = null;
    $begin = function () use ($request, $options, &$easy): object {
        $easy = $this->factory->create($request, $options);
        return \fiber\curl\start($easy->handle);
    };
    $transfer = isset($options['delay']) && $options['delay'] > 0
        ? \fiber\go(static function () use ($begin, $options) {
            \fiber\sleep($options['delay'] / 1000);
            return \fiber\await($begin());
        })
        : $begin();

    $promise = new Promise(function () use (&$promise, &$easy, $transfer): void {
        \fiber\await($transfer);
        $easy->errno = \curl_errno($easy->handle);
        $promise->resolve(CurlFactory::finish($this, $easy, $this->factory));
    });
    return $promise;
}
```

The application passes it in once; `fiber\guzzle\client($config)` is
`new Client($config + ['handler' => HandlerStack::create(new Handler())])`, so the middleware stack
is the usual one.

`guzzle.php` runs three `get()` calls against a local `php -S` server where each request takes
300 ms:

```php
$stock = new GuzzleHttp\Client(['base_uri' => $base]);
$fiber = fiber\guzzle\client(['base_uri' => $base]);

fiber\run(fn() => fiber\await_all([
    fiber\go(fn() => (string) $fiber->get('/a')->getBody()),
    fiber\go(fn() => (string) $fiber->get('/b')->getBody()),
    fiber\go(fn() => (string) $fiber->get('/c')->getBody()),
]));
```

```
stock Guzzle, outside run():                 0.91 s  hello from /a | hello from /b | hello from /c
fiber\guzzle\client(), outside run():        0.90 s  hello from /a | hello from /b | hello from /c
stock Guzzle, three tasks in run():          0.92 s  hello from /a | hello from /b | hello from /c
fiber\guzzle\client(), three tasks in run(): 0.31 s  hello from /a | hello from /b | hello from /c
```

Outside `run()` the two clients behave the same. Inside, the stock client serialises the three tasks
and the fiber-interop handler overlaps them.

### What Guzzle could do itself

Guzzle could ship this handler: it is one class, it depends only on `fiber-interop/api` and
`fiber-interop/curl`, and outside `run()` it is `CurlHandler`. Short of that, one static seam would
be enough. Today `Utils::chooseHandler()` has no override, which is why the application has to pass
`handler` explicitly. A `Utils::setDefaultHandler(callable $factory)` that `chooseHandler()`
consults first would let a package register its handler on install, and every
`new GuzzleHttp\Client()` in the application and its dependencies would pick it up. Guzzle 7.15.5
has no such method.

The handler seam is the right place because it is Guzzle's public extension point: the contract is
documented (`callable(RequestInterface, array): PromiseInterface`), middleware stays in front of it,
and options such as `delay`, `timeout` and `on_stats` reach it through `CurlFactory` as they reach
`CurlHandler`. The alternative, replacing `CurlHandler` or `CurlMultiHandler` with a patched copy
(an autoloader that loads a different file, `class_alias()` before Guzzle loads, or runtime patching
with uopz), depends on Guzzle's private internals, breaks silently on a Guzzle upgrade, and fails
when two packages try it. It also changes behaviour for code that never asked for it.

### The polling gap in PHP

With phasync's extension and with Swoole's native curl hook, `curl_exec()` itself is cooperative.
Everywhere else fiber-interop/curl uses its generic driver, which polls one shared `curl_multi` handle
with `fiber\sleep()` (1 ms after activity, backing off to 10 ms). That is the driver behind the
numbers above. PHP exposes no file descriptor or socket callback for `curl_multi`, so there is
nothing to hand to an event loop; a php-src change exposing `CURLMOPT_SOCKETFUNCTION` /
`CURLMOPT_TIMERFUNCTION` (or a selectable fd) would remove the polling. See
[Integrations](integrations.html) for the driver per runtime.

## Checklist

- Require `fiber-interop/api` only. Put adapters in `require-dev`, never in `require`.
- At each blocking point, check whether a `run()` is active; if so wait with `fiber\sleep()`,
  `fiber\readable()` or `fiber\writable()`, otherwise keep the blocking call.
- On non-blocking streams, handle partial writes and zero-byte writes.
- Behaviour outside `run()` stays identical: same results, same errors, same blocking.
- Where the library has a pluggable transport or handler, extend through it; do not replace the
  library's classes.
- Test with every adapter. The fiber-interop packages' CI runs the suite once per runtime with
  `FI_RUNTIME=revolt|amp|react|phasync|swoole`, and the test bootstrap calls
  `fiber\setup(new ('FiberInterop\\' . ucfirst($runtime) . '\\Adapter')())`. Run the suite once
  without any `run()` as well. See [Adapters](adapters.html) and the [specification](spec.html).
