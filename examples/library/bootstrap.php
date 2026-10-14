<?php
// Application side: pick the adapter. Library code (retry.php, StreamHandler.php) never does this.
require __DIR__ . '/vendor/autoload.php';

$runtime = getenv('FI_RUNTIME') ?: 'phasync';
$class = 'FiberInterop\\' . ucfirst($runtime) . '\\Adapter';
async\setup(new $class());
if (async\curl\Registry::$candidates === []) {
    async\curl\setup(new async\curl\MultiDriver());
}
fwrite(STDERR, "runtime=$runtime\n");
