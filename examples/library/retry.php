<?php
require __DIR__ . '/bootstrap.php';

// --- library code -------------------------------------------------------------------------
function pause(float $seconds): void
{
    if (\fiber\Registry::$active === []) {
        \usleep((int) ($seconds * 1e6));   // no run(): behave as before
    } else {
        \fiber\sleep($seconds);            // inside run(): suspend this task only
    }
}

function retry(callable $op, int $tries = 3, float $delay = 0.2): mixed
{
    for ($i = 1; ; $i++) {
        try {
            return $op();
        } catch (\RuntimeException $e) {
            if ($i === $tries) {
                throw $e;
            }
            pause($delay);
        }
    }
}

// --- demo: three jobs, each fails twice before it succeeds -----------------------------------
function job(string $name): string
{
    $failures = 2;
    return retry(function () use ($name, &$failures) {
        if ($failures-- > 0) {
            throw new \RuntimeException("$name: not yet");
        }
        return "$name ok";
    });
}

$t = microtime(true);
foreach (['a', 'b', 'c'] as $n) {
    $r[] = job($n);
}
printf("outside run(): %s in %.2f s\n", implode(', ', $r), microtime(true) - $t);

$t = microtime(true);
$r = fiber\run(fn() => fiber\await_all([
    fiber\go(job(...), 'a'), fiber\go(job(...), 'b'), fiber\go(job(...), 'c'),
]));
printf("inside run():  %s in %.2f s\n", implode(', ', $r), microtime(true) - $t);
