<?php
require __DIR__ . '/vendor/autoload.php';
use function async\{run, go, await_all, sleep};
run(function () {
    $t = microtime(true);
    await_all([go(fn() => sleep(0.2)), go(fn() => sleep(0.2))]);
    echo round(microtime(true) - $t, 1), " s for two 0.2 s sleeps\n";
});
