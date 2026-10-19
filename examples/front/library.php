<?php
require __DIR__ . '/vendor/autoload.php';
use function fiber\{go, await_all, sleep};

// A library function: depends on fiber-interop/api only, imports no runtime.
function fetchAll(array $ms): array
{
    $tasks = [];
    foreach ($ms as $m) {
        $tasks[] = go(function () use ($m) {
            sleep($m / 1000);
            return "slept $m ms";
        });
    }
    return await_all($tasks);
}

// The application picks the runtime (here via runtime.php); the library is unchanged.
$t = microtime(true);
$r = fiber\run(fn() => fetchAll([200, 200, 200]));
echo implode(', ', $r), ' in ', round(microtime(true) - $t, 1), " s\n";
