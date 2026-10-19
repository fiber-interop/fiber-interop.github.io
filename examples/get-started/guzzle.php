<?php
require __DIR__ . '/vendor/autoload.php';

use function fiber\{run, go, await_all};

$client = fiber\guzzle\client(['base_uri' => 'http://127.0.0.1:8080']);

$t = microtime(true);
$bodies = run(fn() => await_all(array_map(
    fn($i) => go(fn() => (string) $client->get("/item/$i")->getBody()),
    range(1, 10),
)));
printf("%d responses in %.2f s\n", count($bodies), microtime(true) - $t);
