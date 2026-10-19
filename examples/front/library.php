<?php
require __DIR__ . '/vendor/autoload.php';
use function async\{go, await_all, sleep};

// A library function: depends on fiber-interop/api only, imports no runtime.
function fetchAll(array $ms): array
{
    return await_all(array_map(fn($m) => go(function () use ($m) { sleep($m / 1000); return "slept $m ms"; }), $ms));
}

// The application picks the runtime (here via runtime.php); the library is unchanged.
$t = microtime(true);
$r = async\run(fn() => fetchAll([200, 200, 200]));
echo implode(', ', $r), ' in ', round(microtime(true) - $t, 1), " s\n";
