# Get started examples

Runnable sources of the code on `index.html` and `get-started.html`. The composer project uses path
repositories to the sibling checkouts (`../../../api` etc.), so clone the fiber-interop repositories
next to this one first.

```
composer install
PHP_CLI_SERVER_WORKERS=16 php -S 127.0.0.1:8080 server.php &   # every request takes 0.3 s
php sequential.php                                             # plain Guzzle, one after another
FI_RUNTIME=phasync php guzzle.php                              # phasync | revolt | amp | react
FI_RUNTIME=revolt php words.php
FI_RUNTIME=amp php blocks.php
```

Several adapters are installed here to compare them, so `runtime.php` picks one from `FI_RUNTIME`.
An application installs one adapter and needs no such file.
