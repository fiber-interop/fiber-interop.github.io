<?php
require __DIR__ . '/vendor/autoload.php';
use function async\{run, service, sleep};
run(function () {
    service(function () { while (true) { sleep(0.05); } }); // does not hold up run()
    sleep(0.1);
    echo "run() ends although the service loops\n";
});
