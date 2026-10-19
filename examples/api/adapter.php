<?php
require __DIR__ . '/vendor/autoload.php';
use function async\{adapter, run};
// adapter() works outside run(); it returns the configured Adapter.
$a = adapter();
echo $a === async\adapter() ? "same adapter every time\n" : "different\n";
