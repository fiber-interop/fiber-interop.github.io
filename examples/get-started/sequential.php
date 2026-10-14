<?php
require __DIR__ . '/vendor/autoload.php';

// The same ten requests with a plain Guzzle client, one after another.
$client = new GuzzleHttp\Client(['base_uri' => 'http://127.0.0.1:8080']);

$t = microtime(true);
$bodies = [];
foreach (range(1, 10) as $i) {
    $bodies[] = (string) $client->get("/item/$i")->getBody();
}
printf("%d responses in %.2f s\n", count($bodies), microtime(true) - $t);
