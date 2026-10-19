<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/runtime.php';
use function fiber\{run, go, await_all, sleep};

// One instance of each service per request, keyed by the current Scope.
function session(): object
{
    static $all;
    $all ??= new WeakMap();
    return $all[Scope::getCurrent()] ??= new stdClass();
}

function handle(string $user, float $delay): array
{
    session()->user = $user;                    // request-local state
    sleep($delay);                              // the other request runs meanwhile
    return [session()->user, session()];
}

[[$ua, $sa], [$ub, $sb]] = run(fn() => await_all([
    go(fn() => (new Scope)->run(handle(...), 'alice', 0.2)),
    go(fn() => (new Scope)->run(handle(...), 'bob', 0.1)),
]));
echo "request 1 read back: $ua\nrequest 2 read back: $ub\n";
echo 'same session object: ', $sa === $sb ? 'yes' : 'no', "\n";
