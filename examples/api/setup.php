<?php
require __DIR__ . '/vendor/autoload.php';
// runtime.php (autoloaded here) calls async\setup() with the adapter named by FI_RUNTIME.
echo get_class(async\adapter()), "\n";
