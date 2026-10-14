<?php
// Test endpoint: every request takes 0.3 s. Run with: PHP_CLI_SERVER_WORKERS=16 php -S 127.0.0.1:8080 server.php
usleep(300_000);
echo 'ok ', $_SERVER['REQUEST_URI'], "\n";
