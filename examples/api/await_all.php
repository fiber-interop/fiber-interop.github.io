<?php
require __DIR__ . '/vendor/autoload.php';
use function async\{run, go, await_all, sleep};
run(function () {
    $t = microtime(true);
    $r = await_all(['a' => go(function () { sleep(0.2); return 1; }), 'b' => go(function () { sleep(0.2); return 2; })]);
    echo json_encode($r), ' in ', round(microtime(true) - $t, 1), " s\n";
});
