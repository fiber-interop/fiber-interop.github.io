<?php
// Application side: pick the adapter. Library code (retry.php, StreamHandler.php) never does this.
require __DIR__ . '/vendor/autoload.php';

$runtime = getenv('FI_RUNTIME') ?: 'phasync';
$class = 'FiberInterop\\' . ucfirst($runtime) . '\\Adapter';
fiber\setup(new $class());
if (fiber\curl\Registry::$candidates === []) {
    fiber\curl\setup(new fiber\curl\MultiDriver());
}
fwrite(STDERR, "runtime=$runtime\n");
