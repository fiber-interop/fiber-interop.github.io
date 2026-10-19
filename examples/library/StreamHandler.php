<?php
// --- library code: a tiny Monolog-style handler ----------------------------------------------

final class StreamHandler
{
    public int $partialWrites = 0;
    public int $waits = 0;

    /** @param resource $stream */
    public function __construct(private $stream)
    {
        \stream_set_blocking($this->stream, false);
    }

    public function handle(string $level, string $message): void
    {
        $this->write("$level: $message\n");
    }

    private function write(string $data): void
    {
        while ($data !== '') {
            $n = @\fwrite($this->stream, $data);
            if ($n === false) {
                throw new \RuntimeException('write failed');
            }
            if ($n > 0 && $n < \strlen($data)) {
                $this->partialWrites++;
            }
            $data = \substr($data, $n);
            if ($data !== '') {
                $this->waits++;
                $this->waitWritable();
            }
        }
    }

    private function waitWritable(): void
    {
        if (\fiber\Registry::$active === []) {
            // No run(): block until writable, as a blocking stream would.
            $r = $e = null;
            $w = [$this->stream];
            \stream_select($r, $w, $e, null);
        } else {
            // Inside run(): suspend this task; the others keep running.
            \fiber\writable($this->stream);
        }
    }
}
