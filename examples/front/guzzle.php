<?php
require __DIR__ . '/vendor/autoload.php';
use function async\{run, go, await_all};

$client = async\guzzle\client(['base_uri' => getenv('FI_BASE') ?: 'http://127.0.0.1:18099']);

$t = microtime(true);
[$orders, $customer, $stock] = run(fn() => await_all([
    go(fn() => json_decode((string) $client->get('/orders/42')->getBody(), true)),
    go(fn() => json_decode((string) $client->get('/customers/7')->getBody(), true)),
    go(fn() => json_decode((string) $client->get('/stock/A-100')->getBody(), true)),
]));
echo json_encode([$orders['path'], $customer['path'], $stock['path']]), ' in ', round(microtime(true) - $t, 1), " s\n";
