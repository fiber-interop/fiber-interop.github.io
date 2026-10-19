<?php
require __DIR__ . '/vendor/autoload.php';
use function fiber\{run, go, await_all};

$client = fiber\guzzle\client(['base_uri' => getenv('FI_BASE') ?: 'http://127.0.0.1:18099']);

$t = microtime(true);
[$orders, $customer, $stock] = run(function () use ($client) {
    $orders = go(fn() => (string) $client->get('/orders/42')->getBody());
    $customer = go(fn() => (string) $client->get('/customers/7')->getBody());
    $stock = go(fn() => (string) $client->get('/stock/A-100')->getBody());
    return await_all([$orders, $customer, $stock]);
});

$orders = json_decode($orders, true);
$customer = json_decode($customer, true);
$stock = json_decode($stock, true);
echo json_encode([$orders['path'], $customer['path'], $stock['path']]), ' in ', round(microtime(true) - $t, 1), " s\n";
