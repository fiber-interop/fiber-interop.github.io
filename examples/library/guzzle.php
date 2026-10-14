<?php
require __DIR__ . '/bootstrap.php';

$port = 19500 + getmypid() % 400;
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/server.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $p,
    null, ['PHP_CLI_SERVER_WORKERS' => '8']);
register_shutdown_function(fn() => proc_terminate($server));
for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(20_000);
}
$base = "http://127.0.0.1:$port";

$time = function (string $label, callable $f) {
    $t = microtime(true);
    $bodies = $f();
    printf("%-44s %.2f s  %s\n", $label, microtime(true) - $t, implode(' | ', $bodies));
};

$stock = new GuzzleHttp\Client(['base_uri' => $base]);
$fiber = async\guzzle\client(['base_uri' => $base]);

foreach (['stock Guzzle' => $stock, 'async\guzzle\client()' => $fiber] as $name => $client) {
    $time("$name, outside run():", fn() => [
        (string) $client->get('/a')->getBody(),
        (string) $client->get('/b')->getBody(),
        (string) $client->get('/c')->getBody(),
    ]);
}

$time('stock Guzzle, three tasks in run():', fn() => async\run(fn() => async\await_all([
    async\go(fn() => (string) $stock->get('/a')->getBody()),
    async\go(fn() => (string) $stock->get('/b')->getBody()),
    async\go(fn() => (string) $stock->get('/c')->getBody()),
])));

$time('async\guzzle\client(), three tasks in run():', fn() => async\run(fn() => async\await_all([
    async\go(fn() => (string) $fiber->get('/a')->getBody()),
    async\go(fn() => (string) $fiber->get('/b')->getBody()),
    async\go(fn() => (string) $fiber->get('/c')->getBody()),
])));
