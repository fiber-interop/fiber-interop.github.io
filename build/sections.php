<?php
// API reference and Runtimes pages. Facts come from the docblocks in fiber-interop/api and the adapter READMEs; see content notes in each entry.
$ex = fn(string $n) => '<pre><code class="language-php">' . $h(rtrim(file_get_contents("$root/examples/api/$n.php"))) . '</code></pre>';
$p = fn(string ...$s) => implode("\n", array_map(fn($x) => "<p>$x</p>", $s));

$api = [
 'setup' => ['setup(Adapter $adapter): void', 'Install the adapter that serves the fiber\\ functions.',
  $p('Calling it again with an adapter of the same class is a no-op (the first one stays). An adapter of another class throws <code>\\LogicException</code> naming both. An explicit <code>setup()</code> wins over registered candidates.',
     'Adapter packages register themselves on Composer autoload, so an application with one adapter installed never calls this. It is for choosing between several installed adapters.'), 'setup'],
 'register' => ['register(callable $factory, string $package): void', 'Offer an adapter as a candidate (called by adapter packages).',
  $p('Called by adapter packages from their Composer <code>files</code> entry; application code does not call it. Nothing is instantiated. On first use a single candidate is instantiated through <code>$factory</code>; two or more make <code>adapter()</code> throw <code>NotConfiguredException</code> naming the packages. <code>$package</code> is the Composer package name, used in messages.'), null],
 'adapter' => ['adapter(): Adapter', 'The configured adapter.',
  $p('Returns the adapter installed by <code>setup()</code>, else resolves a single registered candidate on first use. Throws <code>NotConfiguredException</code> if none is installed, or if several are registered and <code>setup()</code> was not called. It does not need <code>run()</code>.'), 'adapter'],
 'go' => ['go(callable $f, mixed ...$args): object', 'Start a task concurrently and return an opaque awaitable.',
  $p('Must be called inside <code>run()</code>; otherwise it throws <code>NotInRunException</code>. The awaitable may only be passed to <code>await()</code>, <code>await_all()</code> and <code>await_any()</code>. Its concrete type depends on the adapter (a Fiber, a Future, a promise) and calling methods on it is not portable.',
     'The current <code>\\Scope</code> is captured at <code>go()</code> and the task runs inside it. An exception from a task that nobody awaits goes to the adapter\'s unhandled-error mechanism; the enclosing <code>run()</code> rethrows the first such exception when it ends.'), 'go'],
 'await' => ['await(object $awaitable, float $timeout = PHP_FLOAT_MAX): mixed', 'Wait for an awaitable and return its result.',
  $p('Accepts awaitables from <code>go()</code>, any object with <code>then(callable, callable)</code>, and whatever the adapter\'s <code>canAwait()</code> accepts; anything else is a <code>TypeError</code>. Several fibers may await the same awaitable, and it can be awaited repeatedly. Outside <code>run()</code> it throws <code>NotInRunException</code>.',
     'An exception thrown by the task is rethrown unchanged. A non-Throwable rejection reason is wrapped in <code>RejectedException</code>. On timeout a <code>TimeoutException</code> is thrown; the task keeps running and its later result is ignored (there is no cancellation of the task).',
     'From a fiber the adapter did not create (and not {main}) the adapter runs its loop in place instead of suspending that fiber (the in-place rule); how that is done differs per runtime, see the <a href="runtimes.html">runtime pages</a>.'), 'await'],
 'await_all' => ['await_all(array $awaitables, float $timeout = PHP_FLOAT_MAX): array', 'Wait for every awaitable; results keep their keys.',
  $p('All awaitables are allowed to settle, then the first exception (in key order) is rethrown. The timeout covers the whole call; on expiry a <code>TimeoutException</code> is thrown and the tasks keep running.'), 'await_all'],
 'await_any' => ['await_any(array $awaitables, float $timeout = PHP_FLOAT_MAX): mixed', 'Return the result of the first awaitable to fulfil.',
  $p('Rejections are ignored while any awaitable is still pending; if all reject, the exception of the first to reject is thrown. An empty array throws <code>ValueError</code>. On timeout a <code>TimeoutException</code> is thrown. The other awaitables keep running.'), 'await_any'],
 'run' => ['run(callable $f, mixed ...$args): mixed', 'The only entry point: run $f and wait for every task it started.',
  $p('Structured: <code>run()</code> runs <code>$f</code> as a task, then waits until every task started inside it (transitively, except <code>service()</code> tasks) has finished. It rethrows <code>$f</code>\'s exception, else the first exception of a task nobody awaited, else returns <code>$f</code>\'s result.',
     'It nests freely and behaves the same in {main} with no loop running as inside a task. Every other function in this API throws <code>NotInRunException</code> when called outside it (except <code>setup()</code>, <code>register()</code> and <code>adapter()</code>).'), 'run'],
 'service' => ['service(callable $f, mixed ...$args): object', 'Start a task that does not count towards the enclosing run().',
  $p('For process-wide helpers such as a curl poller. It runs in the root scope and must be called inside <code>run()</code> (<code>NotInRunException</code> otherwise). It is driven only while some <code>run()</code> is active; what happens to it after the outermost <code>run()</code> returns is up to the runtime, so write services to be restartable. Its failure goes to the adapter\'s unhandled-error handler.',
     'Per adapter: phasync cancels services when the outermost run ends; Swoole delivers a <code>Cancelled</code> exception (implementing <code>CancelledException</code>) into the service\'s waits, and a service that catches it and keeps going would keep <code>Co\\run()</code> open (sources: the adapter READMEs).'), 'service'],
 'sleep' => ['sleep(float $seconds): void', 'Suspend the current fiber without blocking others.',
  $p('Throws <code>NotInRunException</code> outside <code>run()</code>. It may throw <code>CancelledException</code> when the owning loop cancels the task; loops implement that interface on their own exception class.'), 'sleep'],
 'readable' => ['readable(mixed $resource, float $timeout = PHP_FLOAT_MAX): mixed', 'Wait until a stream is readable and return it.',
  $p('Returns the resource so that <code>fread(readable($fp), 8192)</code> works. Several fibers may wait on the same resource at once. Throws <code>TimeoutException</code> on timeout and <code>NotInRunException</code> outside <code>run()</code>. Put the stream in non-blocking mode first, as the example does.'), 'readable'],
 'writable' => ['writable(mixed $resource, float $timeout = PHP_FLOAT_MAX): mixed', 'Wait until a stream is writable and return it.',
  $p('Same rules as <code>readable()</code>.'), 'writable'],
];
$classes = [
 ['Adapter', 'interface', 'What an event loop implements to serve the functions: go, service, canAwait, await, sleep, readable, writable. Every wait follows the in-place rule.'],
 ['TimeoutException', 'interface', 'Thrown by await*, readable() and writable() when the timeout expires. The awaited task keeps running.'],
 ['Timeout', 'class', 'The TimeoutException used when the adapter\'s loop has no native timeout exception.'],
 ['CancelledException', 'interface', 'Thrown by sleep() and the waits when the owning loop cancels the task. Loops implement it on their own exception class.'],
 ['NotInRunException', 'class (extends LogicException)', 'Thrown by go(), await(), sleep() and the other waits when no fiber\\run() is active.'],
 ['NotConfiguredException', 'class (extends LogicException)', 'No adapter is set up, or several are installed and setup() was not called.'],
 ['RejectedException', 'class (extends RuntimeException)', 'A promise was rejected with a reason that is not a Throwable; getReason() returns it unchanged.'],
 ['Deferred, Frame', 'internal', 'Not public API. Deferred is a minimal then-able used by await_any(); Frame tracks the tasks of one run().'],
];
$body = "<h1>API reference</h1>\n<p class=\"lead\">Everything lives in the <code>fiber\\</code> namespace of <a href=\"https://github.com/fiber-interop/api\">fiber-interop/api</a>; no global names are declared. Every page here is derived from the docblocks and code of that package, and every example runs on phasync and Revolt.</p>\n<h2>Functions</h2>\n<table class=\"apilist\"><tbody>\n";
foreach ($api as $n => [$sig, $one]) $body .= "<tr><td><a href=\"api-$n.html\"><code>$n()</code></a></td><td>$one</td></tr>\n";
$body .= "</tbody></table>\n<h2>Classes, interfaces and exceptions</h2>\n<table class=\"apilist\"><tbody>\n";
foreach ($classes as [$n, $k, $d]) $body .= "<tr><td><code>fiber\\" . $h($n) . "</code><br><small class=\"meta\">$k</small></td><td>" . $h($d) . "</td></tr>\n";
$body .= "</tbody></table>\n";
page('api.html', 'API', $body, 'Reference for every public function of fiber-interop/api: go, await, run, service, sleep and more.');
foreach ($api as $n => [$sig, $one, $beh, $exn]) {
    $b = "<h1><code>fiber\\$n()</code></h1>\n<p class=\"lead\">$one</p>\n<pre><code class=\"language-php\">" . $h("function fiber\\$sig") . "</code></pre>\n<h2>Behaviour</h2>\n$beh\n";
    $b .= $exn ? "<h2>Example</h2>\n<p class=\"meta\">Source: <code>examples/api/$exn.php</code>. Run on phasync and Revolt; the output is identical.</p>\n" . $ex($exn)
               : "<p class=\"meta\">No runnable example: adapter packages call this, applications do not.</p>\n";
    $b .= "<p><a href=\"api.html\">All functions</a></p>";
    page("api-$n.html", "fiber\\$n()", $b, "$one Reference for fiber\\$n() in fiber-interop/api.");
}

// ---- Scope class page (public methods of fiber-interop/scope src/Scope.php, same style as the api-*.html pages)
$sm = [
 ['new Scope()', 'A new scope, starting with copies of its creator\'s (the current scope\'s) superglobals.'],
 ['run(callable $callback, mixed ...$args): mixed', 'Call $callback with this scope as the current scope and return its result.'],
 ['static getCurrent(): Scope', 'The current scope: the innermost run() of this fiber, else the root scope in {main} or the active scope in a fiber without a run() of its own.'],
 ['static getRootScope(): Scope', 'The root scope: one object for the whole request, current in {main} outside any run().'],
];
$b = "<h1><code>Scope</code></h1>\n<p class=\"lead\">The identity of the request or job that code belongs to, kept across fibers. Global class <code>\\Scope</code>, from ext-scope or the <a href=\"scope.html\">polyfill</a>.</p>\n<table class=\"apilist\"><tbody>\n";
foreach ($sm as [$sig, $one]) $b .= "<tr><td><code>" . $h($sig) . "</code></td><td>" . $h($one) . "</td></tr>\n";
$b .= "</tbody></table>\n<p>Scopes cannot be cloned or serialized. <code>fiber_interop\\scope\\IS_POLYFILL</code> tells whether the class is the polyfill. See <a href=\"scope.html\">the Scope page</a> and <a href=\"tutorial-container.html\">scoped services</a>.</p>";
page('api-Scope.html', 'Scope class', $b, 'Public methods of the Scope class: new Scope, run, getCurrent, getRootScope.');

$cj = $conf;
// ---- runtimes. status: conformance.json (20 examples, PHP 8.5.11, 2026-10-13) and phpunit run 2026-10-19; CI: none of the adapter repos has a workflow.
$rt = [
 'revolt' => ['Revolt', 'https://revolt.run', 'revoltphp/event-loop', 'fiber-interop/revolt', 'Revolt is a PHP event loop built on fibers; AMPHP, and other libraries, run on it.',
   '', '25 tests, 38 assertions, OK', 'The in-place wait for a foreign fiber drives the loop by hand and reads a private closure of the driver by reflection, so it is tied to Revolt 1.x internals. Nested in-place waits across two foreign fibers are not supported.',
   'Documented: <code>Suspension::suspend()</code> calls <code>Fiber::suspend()</code> on a foreign fiber and <code>EventLoop::run()</code> throws inside any fiber, so the adapter works around both by reflection. A public way to run or tick the loop from a fiber the loop does not own would remove the reflection. Scope switching: see the AMPHP page (the driver has no hook; the adapter README documents its approach).'],
 'amp' => ['AMPHP', 'https://amphp.org', 'amphp/amp (on Revolt)', 'fiber-interop/amp', 'AMPHP is a collection of non-blocking PHP libraries; amphp/amp 3 is built on fibers and the Revolt event loop.',
   '', '22 tests, 39 assertions, OK', 'The in-place wait for a foreign fiber reads Revolt driver internals by reflection, as the Revolt adapter does, so it is tied to Revolt 1.x.',
   'Documented: Revolt has no hook that runs when the loop hands control to user code, so the adapter installs a decorating driver via <code>EventLoop::setDriver()</code> to call <code>fiber_interop\\scope\\activate()</code>. A scope or resume hook in Revolt would replace the decorator. Probes <code>scope-api.php</code> and <code>scope-loop.php</code> pass; <code>scope-loop</code> fails if the decorator is removed.'],
 'react' => ['ReactPHP', 'https://reactphp.org', 'react/event-loop, react/async, react/promise', 'fiber-interop/react', 'ReactPHP is an event-driven, non-blocking I/O toolkit for PHP; react/async provides fiber-based <code>async()</code> and <code>await()</code>.',
   '', '17 tests, 28 assertions, OK', 'Probe <code>scope-loop.php</code> measures code that bypasses fiber-interop: with raw <code>React\\Async\\await()</code>, per-scope superglobals can show the previously active scope\'s values after a wait. Waiting through <code>fiber\\sleep()</code> / <code>fiber\\await()</code> is safe. In-place waits from a foreign fiber started inside a running loop callback throw <code>LogicException</code> because the loop cannot be re-entered.',
   'Documented ask from the adapter README: a resume hook in react/async (a callback run in the target fiber, or around <code>$fiber->resume()</code>, in <code>SimpleFiber</code>/<code>await()</code>), or routing <code>await()</code> through a suspension object that user-visible code returns from, as Revolt\'s <code>Suspension::suspend()</code> does. Re-entrant <code>Loop::run()</code> is the other gap.'],
 'swoole' => ['Swoole', 'https://swoole.com', 'ext-swoole 6.x', 'fiber-interop/swoole', 'Swoole is a PHP extension providing coroutines, an event loop and async I/O. Its coroutines are not PHP Fibers.',
   '', 'phpunit not runnable in the verification shell (the extension is not loaded there); conformance snapshot ran with the extension', 'Needs ext-swoole 6.x (developed against 6.3.0RC1, PHP 8.5 NTS). <code>Fiber::getCurrent()</code> is null in every coroutine, so per-coroutine scopes use a context-keyed provider with the userland polyfill; the ext-scope C extension cannot see coroutine switches, so use the polyfill. Probe <code>scope-loop.php</code> measures code that bypasses fiber-interop: a coroutine that Swoole itself resumes (no <code>fiber\\</code> call) sees the scope that was active last. A service that catches the cancellation and keeps going keeps <code>Co\\run()</code> open.',
   'Documented: Swoole has no userland coroutine-switch hook, so the adapter calls <code>activate()</code> at every point where it regains control. A switch hook in Swoole would make raw coroutines scope-correct. Nothing else is documented.'],
 'openswoole' => ['OpenSwoole', 'https://openswoole.com', 'ext-openswoole', 'fiber-interop/swoole (shared)', 'OpenSwoole is a PHP extension for coroutines and async I/O that forked from Swoole and shares its API surface.',
   '', 'no separate adapter', 'There is no separate OpenSwoole adapter. The swoole adapter README states it requires ext-swoole 6.x <em>or</em> ext-openswoole (tested 26.2.0); its probes give the same results on Swoole 6.3 and OpenSwoole 26.2. The site\'s <code>conformance.php</code> has an <code>only=openswoole</code> mode for CI, but the committed snapshot has no OpenSwoole entry, so no conformance number is published here.',
   'Not assessed beyond the above: the adapter for Swoole is documented to work on OpenSwoole 26.2 unchanged, and the same missing coroutine-switch hook applies. A dedicated adapter would be the same code against the same API (<code>Coroutine</code>, <code>Channel</code>, <code>Co\\run</code>, context-keyed scope provider); this was read from the swoole README, not tested separately.'],
 'trueasync' => ['TrueAsync', 'https://github.com/true-async', 'ext-true_async 0.10+ (PHP 8.6 with TrueAsync)', 'fiber-interop/trueasync', 'TrueAsync is asynchronous PHP: coroutines (<code>Async\\spawn</code>) on its own scheduler over libuv, in a PHP 8.6 build with the <code>true_async</code> extension. Its engine RFC is <a href="https://wiki.php.net/rfc/async_scheduler_abi">async_scheduler_abi</a>. Its coroutines are not PHP Fibers.',
   '', 'Adapter unit tests pass in the <code>trueasync/php-true-async</code> Docker image', 'Requires PHP 8.6 built with TrueAsync and ext <code>true_async</code> 0.10+ (tested on 0.10.0, ABI v0.26.0), for example the Docker image <code>trueasync/php-true-async:latest</code>. Mapping: <code>go</code> is <code>Async\\spawn</code>, <code>await</code> is <code>Async\\await</code> with <code>Async\\timeout</code>, <code>sleep</code> is <code>Async\\delay</code>, <code>readable</code>/<code>writable</code> use <code>stream_select()</code>. Differences from the other adapters: children started with native <code>Async\\spawn</code> do not join the creator\'s Scope, because there is no spawn hook (started through <code>fiber\\go()</code> they do); <code>fclose()</code> does not wake a <code>stream_select()</code> waiting on that stream, so the adapter polls in 20 ms slices; <code>stream_select</code> timeouts below 1 ms are rounded up to 1 ms; an unawaited failing coroutine ends the script, so the adapter keeps task failures inside the task.',
   'A spawn hook in TrueAsync, run when a coroutine is created, would let raw <code>Async\\spawn</code> children inherit the creator\'s Scope. A <code>stream_select()</code> woken by <code>fclose()</code> on a watched stream would remove the 20 ms polling. Nothing else is documented.'],
 'phasync' => ['phasync', 'https://phasync.dev', 'phasync/phasync', 'fiber-interop/phasync', 'phasync is a PHP library for fiber-based concurrency with its own event loop, coroutines and services.',
   '', '35 tests, 41 assertions, OK', 'None known.',
   'phasync switches scope itself in phasync 2.0 (also for raw <code>phasync::go()</code> coroutines), a foreign fiber waits by driving <code>EventLoop::tick()</code> in place, and <code>phasync::run()</code> nests freely — a library may call it whenever it needs the loop, and inside a parent run it cooperates with that run\'s coroutines (a route for making an application fiber-aware gradually, also under PHP-FPM). Only the outermost run is unique; the adapter issues it when <code>fiber\\run()</code> is entered from {main}.'],
];
$logos = ['swoole' => '<img class="rt-logo" src="assets/logos/swoole.svg" alt="" width="128" height="44"> ', 'phasync' => '<img class="rt-logo" src="assets/logos/phasync.png" alt="" width="44" height="44"> '];
$cards = '';
foreach ($rt as $k => [$name, $site, $pkg, $ad, $what, $conf, $ut, $lim, $gap]) {
    $lg = $logos[$k] ?? '';
    $cards .= "<div class=\"card rt rt-$k\"><a class=\"wordmark\" href=\"runtime-$k.html\">$lg$name</a><p>$what</p><p class=\"meta\">Adapter: <code>$ad</code><br>Conformance: $conf<br><a href=\"runtime-$k.html\">Status and notes</a> · <a href=\"$site\">$name site</a></p></div>\n";
}
$body = "<h1>Runtimes</h1>\n<p class=\"lead\">The same program runs on each of these through one adapter package. Status below is read from each adapter's repository; where nothing is documented the page says so.</p>\n<div class=\"cards\">\n$cards</div>\n"
  . "<p class=\"meta\">Conformance: the examples of the conformance suite, computed from conformance.json (see the <a href=\"conformance.html\">matrix</a>); TrueAsync runs the 22 examples of the current suite on PHP 8.6 in CI. Logos are trademarks of their respective projects and are used to identify them; no endorsement is implied. Where no logo is shown, none was usable; accent colours are ours unless a logo gives one. See <code>assets/LOGOS.md</code>.</p>";
page('runtimes.html', 'Runtimes', $body, 'Revolt, AMPHP, ReactPHP, Swoole, OpenSwoole, TrueAsync and phasync: adapter, conformance status and notes.');
foreach ($rt as $k => [$name, $site, $pkg, $ad, $what, $_c, $ut, $lim, $gap]) {
    $rj = $cj['runtimes'][$k] ?? null;
    $res = $rj['results'] ?? [];
    $np = count(array_keys($res, 'ok'));
    $nt = count($res);
    $confv = $nt ? "$np/$nt" : 'not in the published snapshot';
    $full = $nt && $np === $nt;
    $when = $rj ? 'PHP ' . $h($rj['php'] ?? 'n/a') . ', ' . $h(substr($rj['run_at'] ?? '', 0, 10)) : '';
    $native = ['react' => 'React\'s <code>async()</code>', 'swoole' => 'Swoole\'s <code>go()</code>', 'openswoole' => 'OpenSwoole\'s <code>go()</code>', 'trueasync' => '<code>Async\\spawn()</code>'][$k] ?? null;
    $pre = ($full ? "Fully conformant ($confv)." : 'No published conformance result.') . ($native ? " Scope follows tasks started with <code>fiber\\go()</code>; coroutines started with $native don't inherit it." : '') . ' ';
    $inst = $k === 'openswoole' ? 'composer require fiber-interop/swoole   # with ext-openswoole loaded' : "composer require $ad";
    $b = "<h1 class=\"wordmark rt rt-$k\">" . ($logos[$k] ?? '') . "$name</h1>\n<p class=\"lead\">$what</p>\n<p><a href=\"$site\">$name project site</a> · adapter: <code>$ad</code></p>\n<pre><code class=\"language-php\">" . $h($inst) . "</code></pre>\n"
       . "<h2>Conformance and test status</h2>\n<table class=\"status\"><tbody><tr><th>Conformance examples (of " . ($nt ?: count($cj['examples'] ?? [])) . ")</th><td>$confv</td></tr><tr><th>Adapter unit tests</th><td>$ut</td></tr><tr><th>CI</th><td>" . 'The conformance numbers come from the site\'s daily CI run (<code>conformance.json</code>' . ($when ? ", $when" : '') . ')' . ($k === 'trueasync' ? ', inside the <code>trueasync/php-true-async</code> Docker image' : '') . "</td></tr><tr><th>Skipped</th><td>None in the conformance suite" . ($k === 'openswoole' ? ' (not run)' : '') . "</td></tr></tbody></table>\n"
       . "<h2>Differences</h2>\n<p>$lim</p>\n<h2>Beyond the contract</h2>\n<p>$pre$gap</p>\n"
       . '<p><a href="runtimes.html">All runtimes</a> · <a href="conformance.html">Conformance matrix</a></p>';
    page("runtime-$k.html", $name, $b, "$name with fiber-interop: adapter, conformance status, differences and notes beyond the contract.");
}
