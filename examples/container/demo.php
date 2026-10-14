<?php
// Source for tutorial-container.html. composer.json uses path repositories, so check out api, scope,
// phasync, revolt, amp and react next to fiber-interop.github.io, then:
//   composer install && FI_RUNTIME=phasync|revolt|amp|react php demo.php
require __DIR__ . '/vendor/autoload.php';

use function async\{await_all, go, run, sleep};

$runtime = getenv('FI_RUNTIME') ?: 'phasync';
async\setup(new ('FiberInterop\\' . ucfirst($runtime) . '\\Adapter')());
echo "runtime: $runtime\n";

final class Logger         // Singleton: shared by all requests, holds no request data
{
    public static int $count = 0;
    public readonly int $n;
    public function __construct() { $this->n = ++self::$count; }
    public function log(string $line): void { echo $line, "\n"; }
}

final class RequestContext // Scoped: one per request
{
    public static int $count = 0;
    public readonly int $n;
    public function __construct(public readonly string $path) { $this->n = ++self::$count; }
    public function __destruct() { echo "RequestContext #{$this->n} ({$this->path}) freed\n"; }
}

final class Mailer         // Transient: a new one per get()
{
    public static int $count = 0;
    public readonly int $n;
    public function __construct() { $this->n = ++self::$count; }
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
