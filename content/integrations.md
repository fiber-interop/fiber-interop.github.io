# Integrations

Packages that make an existing blocking library cooperative on top of
[fiber-interop/api](spec.html). Each one works on every adapter: through a native driver where the
runtime has one, otherwise through a generic driver written against the api alone. Outside
`async\run()` each behaves exactly like the library it wraps.

## curl: `fiber-interop/curl`

`async\curl\exec(\CurlHandle $h)` has `curl_exec()`'s contract (return value, `curl_errno()`,
`CURLOPT_RETURNTRANSFER`), but suspends only the calling task. `async\curl\start($h)` begins the
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

The generic driver polls one shared `curl_multi` handle with `async\sleep()` (1 ms after activity,
backing off to 10 ms). PHP exposes no file descriptor or socket callback for `curl_multi`, so there
is nothing to hand to an event loop; a php-src change exposing `CURLMOPT_SOCKETFUNCTION` /
`CURLMOPT_TIMERFUNCTION` (or a selectable fd) would remove the polling.

## Guzzle: `fiber-interop/guzzle`

A Guzzle handler built on `async\curl\start()`, structured like Guzzle's own `CurlHandler`, so
responses and exceptions are Guzzle's. Concurrent `get()` calls in separate tasks overlap, and so do
`getAsync()` promises inside one task. [README](https://github.com/fiber-interop/guzzle#readme)

```
composer require fiber-interop/guzzle
```

```php
$client = async\guzzle\client(['base_uri' => 'https://example.com']);
```

One line of configuration is needed: Guzzle chooses its default handler in the static
`Utils::chooseHandler()`, which has no override. A `Utils::setDefaultHandler()` in Guzzle would let
installing the package be enough. Drivers per runtime are those of fiber-interop/curl.

## pg: `fiber-interop/pg`

`async\pg\query()` and `async\pg\query_params()` have the contracts of `pg_query()` and
`pg_query_params()` (the result, or `false` with `pg_last_error()` set), but suspend only the calling
task. `async\pg\start()` sends the query now and returns an awaitable. `async\pg\connect()` is
`pg_connect()` using `PGSQL_CONNECT_ASYNC` and `pg_connect_poll()`. Version 0.1.0; 9/9 tests pass on
Revolt, AMPHP, ReactPHP, phasync and Swoole. [README](https://github.com/fiber-interop/pg#readme)

```
composer require fiber-interop/pg
```

| Runtime | Driver |
|---|---|
| Revolt, AMPHP, ReactPHP, phasync (with or without the extension), Swoole | generic `SocketDriver` |

There are no native drivers. The generic driver waits on the real socket from `pg_socket()` with
`async\readable()`, so there is no polling: libpq hands out its descriptor and the event loop
watches it directly. Use one connection per task for parallelism; libpq runs one query at a time per
connection.

## Coming

mysqli.
