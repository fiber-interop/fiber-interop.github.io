<?php
require __DIR__ . '/vendor/autoload.php';
// runtime.php (autoloaded here) calls fiber\setup() with the adapter named by FI_RUNTIME.
echo get_class(fiber\adapter()), "\n";
