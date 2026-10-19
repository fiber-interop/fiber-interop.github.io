<?php
require __DIR__ . '/vendor/autoload.php';

use function fiber\{run, go, await_all};

// Outside run(): fiber\guzzle\client() is an ordinary blocking Guzzle client.
$client = fiber\guzzle\client(['base_uri' => 'http://127.0.0.1:8080']);
$t = microtime(true);
$client->get('/a');
$client->get('/b');
printf("outside run, 2 Guzzle requests:  %.2f s\n", microtime(true) - $t);

// Inside run(): calls that wait inside C hold up every task.
$t = microtime(true);
run(fn() => await_all([go(fn() => \usleep(300_000)), go(fn() => \usleep(300_000))]));
printf("inside run, 2 x native usleep(0.3):          %.2f s\n", microtime(true) - $t);

$t = microtime(true);
run(fn() => await_all([
    go(fn() => file_get_contents('http://127.0.0.1:8080/a')),
    go(fn() => file_get_contents('http://127.0.0.1:8080/b')),
]));
printf("inside run, 2 x file_get_contents('http://'): %.2f s\n", microtime(true) - $t);

$t = microtime(true);
run(fn() => await_all([go(fn() => fiber\sleep(0.3)), go(fn() => fiber\sleep(0.3))]));
printf("inside run, 2 x fiber\\sleep(0.3):            %.2f s\n", microtime(true) - $t);
