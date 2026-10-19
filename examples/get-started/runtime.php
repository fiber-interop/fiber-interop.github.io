<?php
// Only needed because this example project installs several adapters to compare them.
// An application installs one adapter and needs nothing like this.
$runtime = getenv('FI_RUNTIME') ?: 'phasync';
$class = 'FiberInterop\\' . ucfirst($runtime) . '\\Adapter';
fiber\setup(new $class());
