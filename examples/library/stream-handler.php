<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/StreamHandler.php';

// A slow reader in a child process, on the other end of a pipe: 8 KiB, then 2 ms pause, until
// EOF. It prints the byte count and a CRC32 of what it received.
function slowReader(&$pipes)
{
    $code = '$all = ""; while (($d = fread(STDIN, 8192)) !== "" && $d !== false) { $all .= $d; usleep(2000); }'
          . ' echo strlen($all), " ", hash("crc32b", $all);';
    return proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], STDERR], $pipes);
}

function logLines(StreamHandler $log, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        // Mostly short lines; every 50th carries a 20 KB context dump, larger than the pipe
        // has room for, so fwrite() accepts only part of it.
        $log->handle('INFO', "line $i " . str_repeat($i % 50 ? 'x' : 'trace ', $i % 50 ? 200 : 3400));
    }
}

function report(string $label, StreamHandler $log, $pipes, $proc, float $t, string $extra = ''): void
{
    fclose($pipes[0]);
    $received = stream_get_contents($pipes[1]);
    $received .= ' (' . ($received === $GLOBALS['expected'] ? 'intact' : 'MISMATCH') . ')';
    proc_close($proc);
    printf("%s %.2f s, reader got %s, partial writes %d, waits %d%s\n",
        $label, microtime(true) - $t, $received, $log->partialWrites, $log->waits, $extra);
}

$lines = 5000;
// What the reader should get, built in memory: length and CRC32.
$all = '';
for ($i = 0; $i < $lines; $i++) {
    $all .= "INFO: line $i " . str_repeat($i % 50 ? 'x' : 'trace ', $i % 50 ? 200 : 3400) . "\n";
}
$expected = strlen($all) . ' ' . hash('crc32b', $all);

$t = microtime(true);
$proc = slowReader($pipes);
$log = new StreamHandler($pipes[0]);
logLines($log, $lines);
report('outside run():', $log, $pipes, $proc, $t);

$t = microtime(true);
$proc = slowReader($pipes);
$log = new StreamHandler($pipes[0]);
$ticks = async\run(function () use ($log, $lines) {
    $done = false;
    $ticker = async\go(function () use (&$done) {
        $n = 0;
        while (!$done) {
            async\sleep(0.01);
            $n++;
        }
        return $n;
    });
    logLines($log, $lines);
    $done = true;
    return async\await($ticker);
});
report('inside run(): ', $log, $pipes, $proc, $t, ", other task ticked $ticks times meanwhile");
