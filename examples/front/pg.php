<?php
require __DIR__ . '/vendor/autoload.php';
use function fiber\{run, go, await_all};

$dsn = getenv('FI_PG') ?: 'host=127.0.0.1 port=15432 user=fi password=fi dbname=fi';
$t = microtime(true);
$rows = run(function () use ($dsn) {
    $query = function ($id) use ($dsn) {
        $c = fiber\pg\connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        $r = fiber\pg\query_params($c, 'SELECT $1::int AS id, pg_sleep(0.3)', [$id]);
        return pg_fetch_assoc($r);
    };
    $a = go(fn() => $query(1));
    $b = go(fn() => $query(2));
    $c = go(fn() => $query(3));
    return await_all([$a, $b, $c]);
});
echo json_encode(array_column($rows, 'id')), ' in ', round(microtime(true) - $t, 1), " s\n";
