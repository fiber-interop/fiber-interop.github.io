<?php
require __DIR__ . '/vendor/autoload.php';
use function fiber\{run, go, await, sleep};
run(function () {
    $slow = go(function () { sleep(0.2); return 'slow'; });
    $fast = go(fn() => 'fast');
    echo await($fast), "\n", await($slow), "\n";
});
