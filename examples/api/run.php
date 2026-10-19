<?php
require __DIR__ . '/vendor/autoload.php';
use function fiber\{run, go, sleep};
$r = run(function () {
    go(function () { sleep(0.1); echo "child finished\n"; }); // nobody awaits it
    return 'body returned';
});
echo $r, "\n"; // printed after "child finished": run() drains its tasks
try {
    fiber\sleep(0.01); // outside run()
} catch (fiber\NotInRunException) {
    echo "NotInRunException outside run()\n";
}
