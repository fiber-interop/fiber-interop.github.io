<?php
require __DIR__ . '/vendor/autoload.php';
use function async\{run, go, await, sleep};
use async\TimeoutException;

run(function () {
    $ticker = go(function () { for ($i = 1; $i <= 3; $i++) { sleep(0.05); echo "tick $i\n"; } });
    $slow = go(function () { sleep(5); return 'slow result'; });
    try {
        echo await($slow, 0.2), "\n";
    } catch (TimeoutException) {
        echo "gave up after 0.2 s; the ticker kept running\n";
    }
    await($ticker);
});
