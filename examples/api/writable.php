<?php
require __DIR__ . '/vendor/autoload.php';
use function fiber\{run, writable};
run(function () {
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($a, false);
    echo fwrite(writable($a, 1.0), "ping"), " bytes written\n";
});
