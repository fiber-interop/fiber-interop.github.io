<?php
require __DIR__ . '/vendor/autoload.php';

use function fiber\{run, go, await, await_all, sleep};

$t = microtime(true);
run(function () use ($t) {
    $a = go(function () { sleep(0.5); return 'a'; });
    $b = go(function () { sleep(0.5); return 'b'; });
    echo await($a), await($b), "\n";                          // ab
    print_r(await_all(['x' => go(fn() => 1), 'y' => go(fn() => 2)])); // [x => 1, y => 2]
});
printf("%.2f s\n", microtime(true) - $t);                       // about 0.5, not 1.0
