<?php
require __DIR__ . '/vendor/autoload.php';
use function async\{run, go, await_all};

$dsn = getenv('FI_PG') ?: 'host=127.0.0.1 port=15432 user=fi password=fi dbname=fi';
$t = microtime(true);
$rows = run(fn() => await_all(array_map(
    fn($id) => go(function () use ($id, $dsn) {
        $c = async\pg\connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        $r = async\pg\query_params($c, 'SELECT $1::int AS id, pg_sleep(0.3)', [$id]);
        return $r === false ? pg_last_error($c) : pg_fetch_assoc($r);
    }),
    [1, 2, 3],
)));
echo json_encode(array_column($rows, 'id')), ' in ', round(microtime(true) - $t, 1), " s\n";
