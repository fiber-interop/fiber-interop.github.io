<?php
require __DIR__ . '/vendor/autoload.php';
use function async\{run, go, sleep};
$r = run(function () {
    go(function () { sleep(0.1); echo "child finished\n"; }); // nobody awaits it
    return 'body returned';
});
echo $r, "\n"; // printed after "child finished": run() drains its tasks
try {
    async\sleep(0.01); // outside run()
} catch (async\NotInRunException) {
    echo "NotInRunException outside run()\n";
}
