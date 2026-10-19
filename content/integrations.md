# Integrations

Packages that make an existing blocking library cooperative on top of
[fiber-interop/api](spec.html). Each one works on every adapter: through a native driver where the
runtime has one, otherwise through a generic driver written against the api alone. Outside
`fiber\run()` each behaves exactly like the library it wraps.

## curl: `fiber-interop/curl`

`fiber\curl\exec(\CurlHandle $h)` has `curl_exec()`'s contract (return value, `curl_errno()`,
`CURLOPT_RETURNTRANSFER`), but suspends only the calling task. `fiber\curl\start($h)` begins the
transfer now and returns an awaitable. [README](https://github.com/fiber-interop/curl#readme)

```
composer require fiber-interop/curl
```

| Runtime | Driver |
|---|---|
| phasync with the phasync extension | native: the extension makes `curl_exec()` cooperative |
| phasync without the extension | generic `MultiDriver` |
| Swoole built with `--enable-swoole-curl` | native: `SWOOLE_HOOK_NATIVE_CURL` |
| Swoole without native curl | generic `MultiDriver` |
| Revolt, AMPHP, ReactPHP | generic `MultiDriver` |

The generic driver polls one shared `curl_multi` handle with `fiber\sleep()` (1 ms after activity,
backing off to 10 ms). PHP exposes no file descriptor or socket callback for `curl_multi`, so there
is nothing to hand to an event loop; a php-src change exposing `CURLMOPT_SOCKETFUNCTION` /
`CURLMOPT_TIMERFUNCTION` (or a selectable fd) would remove the polling.

## Guzzle: `fiber-interop/guzzle`

A Guzzle handler built on `fiber\curl\start()`, structured like Guzzle's own `CurlHandler`, so
responses and exceptions are Guzzle's. Concurrent `get()` calls in separate tasks overlap, and so do
`getAsync()` promises inside one task. [README](https://github.com/fiber-interop/guzzle#readme)

```
composer require fiber-interop/guzzle
```

```php
$client = fiber\guzzle\client(['base_uri' => 'https://example.com']);
```

One line of configuration is needed: Guzzle chooses its default handler in the static
`Utils::chooseHandler()`, which has no override. A `Utils::setDefaultHandler()` in Guzzle would let
installing the package be enough. Drivers per runtime are those of fiber-interop/curl.

## pg: `fiber-interop/pg`

`fiber\pg\query()` and `fiber\pg\query_params()` have the contracts of `pg_query()` and
`pg_query_params()` (the result, or `false` with `pg_last_error()` set), but suspend only the calling
task. `fiber\pg\start()` sends the query now and returns an awaitable. `fiber\pg\connect()` is
`pg_connect()` using `PGSQL_CONNECT_ASYNC` and `pg_connect_poll()`. Version 0.1.0; 9/9 tests pass on
Revolt, AMPHP, ReactPHP, phasync and Swoole. [README](https://github.com/fiber-interop/pg#readme)

```
composer require fiber-interop/pg
```

| Runtime | Driver |
|---|---|
| Revolt, AMPHP, ReactPHP, phasync (with or without the extension), Swoole | generic `SocketDriver` |

There are no native drivers. The generic driver waits on the real socket from `pg_socket()` with
`fiber\readable()`, so there is no polling: libpq hands out its descriptor and the event loop
watches it directly. Use one connection per task for parallelism; libpq runs one query at a time per
connection.

## mysqli: `fiber-interop/mysqli`

`fiber\mysqli\query()` has the contract of `$m->query()` (a `mysqli_result`, `true`, or `false` with
`$m->errno` / `$m->error` set), but suspends only the calling task. `fiber\mysqli\start()` sends the
query now and returns an awaitable. Version 0.1.0; 9/9 tests pass on Revolt, AMPHP, ReactPHP,
phasync (generic and native) and Swoole (generic and native).
[README](https://github.com/fiber-interop/mysqli#readme)

```
composer require fiber-interop/mysqli
```

| Runtime | Driver |
|---|---|
| phasync with the phasync extension | native: the extension makes a plain `$m->query()` cooperative |
| Swoole | native: the TCP hook makes a plain `$m->query()` cooperative |
| Revolt, AMPHP, ReactPHP, phasync without the extension | generic `PollDriver` |

The generic driver sends with `MYSQLI_ASYNC` and polls `mysqli_poll()` from one task, sleeping with
`fiber\sleep()` (1 ms after activity, backing off to 10 ms). mysqli exposes no file descriptor for a
connection, so there is nothing to hand to an event loop; a `mysqli_socket()` in php-src would remove
the polling. Use one connection per task for parallelism: a query started on a busy connection is
queued and sent when the previous one completes.

## process: `fiber-interop/process`

`fiber\process\run($command, $cwd, $env, $stdin, $timeout)` runs a command to completion and returns a
`Result` (`exitCode`, `stdout`, `stderr`, `signal`), suspending only the calling task.
`fiber\process\start()` takes the same arguments and returns an awaitable `Process` with `pid` and
`kill()`. Version 0.1.0; 9/9 tests pass on Revolt, AMPHP, ReactPHP, phasync (with and without the
extension) and Swoole. [README](https://github.com/fiber-interop/process#readme)

```
composer require fiber-interop/process
```

There are no native drivers; one implementation serves every runtime. Inside `run()` stdin is written
on `fiber\writable()` while stdout and stderr are drained concurrently on `fiber\readable()`, so a
child producing a lot of output cannot deadlock against one still being fed. Outside `run()` the
calls block.

A timeout sends SIGTERM, then SIGKILL after a 2 second grace, and throws an `fiber\TimeoutException`.

Process exit has no file descriptor PHP can wait on, so exit is detected by polling
`proc_get_status()` with `fiber\sleep()` between polls (1 ms backing off to 10 ms). A pidfd exposed
from php-src would remove the polling.

## predis: `fiber-interop/predis`

`fiber\predis\client($parameters, $options)` returns a `Predis\Client` whose `tcp`, `tls` and `unix`
connections are `fiber\predis\Connection`, so a Redis command suspends only the calling task. The
exceptions are Predis's own. Version 0.1.0. [README](https://github.com/fiber-interop/predis#readme)

```
composer require fiber-interop/predis
```

## dbal: `fiber-interop/dbal`

Doctrine DBAL 3.8+ and 4 drivers: `fiber\dbal\params($params)` turns `'driver' => 'pgsql'` /
`'mysqli'` into the matching `driverClass` for the installed DBAL major (`fiber\dbal\PgSQL\Driver`
on 4, `fiber\dbal\V3\PgSQL\Driver` on 3, likewise for mysqli), so Symfony and Doctrine ORM
queries suspend only the calling task. DBAL's own results and exceptions. On mysqli, queries with
parameters are prepared statements and block (PHP has no async `mysqli_stmt::execute()`); on pgsql
everything is cooperative. Version 0.2.0. [README](https://github.com/fiber-interop/dbal#readme)

```
composer require fiber-interop/dbal
```

| Runtime | Result |
|---|---|
| Revolt, AMPHP, ReactPHP, Swoole, phasync without the extension | 8/8 |
| phasync with the phasync extension | 7/8, pub/sub skipped: extension bug under investigation |

There are no native drivers; one implementation serves every runtime. `Connection` is a Predis
`StreamConnection` subclass with a non-blocking socket: connecting waits on `fiber\writable()`, the
TLS handshake loops on `fiber\readable()`, and reads and writes wait on `fiber\readable()` and
`fiber\writable()`. Outside `run()` the calls block.

Give each task its own client: one connection is one socket. The overlap test uses `BLPOP`, because
`DEBUG SLEEP` blocks the Redis server itself for every client.
