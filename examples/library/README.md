# Samples for tutorial-library.html

Every code sample on the "Making a library fiber-friendly" page comes from these files.
They use path repositories to the sibling package checkouts (`../../../api`, `../../../guzzle`, ...),
so clone the fiber-interop repositories side by side first.

```
composer install
FI_RUNTIME=phasync php retry.php
FI_RUNTIME=revolt  php stream-handler.php
FI_RUNTIME=amp     php guzzle.php
```

`FI_RUNTIME` (phasync, revolt or amp; default phasync) picks the adapter in `bootstrap.php`, the
same way the fiber-interop packages' CI does. `retry.php` and `StreamHandler.php` contain the library
code and never pick an adapter. `guzzle.php` starts `php -S` with `server.php` on a local port.
