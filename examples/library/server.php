<?php
// Test server for guzzle.php: every request takes 300 ms.
usleep(300_000);
echo 'hello from ', $_SERVER['REQUEST_URI'];
