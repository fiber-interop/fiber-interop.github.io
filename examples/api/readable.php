<?php
require __DIR__ . '/vendor/autoload.php';
use function fiber\{run, go, await, sleep, readable};
use fiber\TimeoutException;
run(function () {
    [$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($r, false);
    try {
        readable($r, 0.05);
    } catch (TimeoutException) {
        echo "nothing to read yet\n";
    }
    go(function () use ($w) { sleep(0.05); fwrite($w, "hello"); });
    echo fread(readable($r, 1.0), 8192), "\n";
});
