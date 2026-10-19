<?php
require __DIR__ . '/vendor/autoload.php';
use function fiber\{run, go, await_any, sleep};
run(function () {
    echo await_any([go(function () { sleep(0.3); return 'slow'; }), go(function () { sleep(0.05); return 'fast'; })]), "\n";
});
