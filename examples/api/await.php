<?php
require __DIR__ . '/vendor/autoload.php';
use function fiber\{run, go, await, sleep};
use fiber\TimeoutException;
echo run(fn() => await(go(fn() => 6 * 7))), "\n";
run(function () {
    $t = go(function () { sleep(0.3); return 'done'; });
    try {
        await($t, 0.05);
    } catch (TimeoutException) {
        echo "timed out; task still running\n";
    }
    echo await($t), "\n"; // the same awaitable can be awaited again
});
