<?php
// Runs the api's conformance runner against every adapter and writes conformance.json.
// Usage: php build/conformance.php [SRC_DIR=..] [OUT=conformance.json]
// Each adapter is a sibling checkout (api, phasync, revolt, ...) with composer already installed.
// A crashing adapter (no "ok"/"FAIL" lines) gets every example marked fail plus its error text.

$src = realpath($argv[1] ?? __DIR__ . '/../..');
$out = $argv[2] ?? __DIR__ . '/../conformance.json';
$api = "$src/api";
$runner = "$api/bin/fiber-interop-conformance";

$examples = array_map(fn($f) => basename($f, '.php'), glob("$api/examples/*.php"));
sort($examples);
// one-line purpose: the first non-"Run:" // comment in the example, else the humanised file name
$purposes = [];
foreach ($examples as $e) {
    $purposes[$e] = ucfirst(str_replace('-', ' ', preg_replace('/^\d+-/', '', $e)));
    foreach (file("$api/examples/$e.php") as $l) {
        if (preg_match('~^\s*//\s*+(?!Run:)(.+)$~', $l, $m)) { $purposes[$e] = trim($m[1]); break; }
    }
}

$git = fn(string $dir) => trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' rev-parse --short HEAD 2>/dev/null')) ?: null;
$runtimes = ['reference' => ['dir' => $api, 'bootstrap' => "$api/tests/bootstrap-reference.php"]];
foreach (['phasync', 'revolt', 'amp', 'react', 'swoole'] as $n) {
    $runtimes[$n] = ['dir' => "$src/$n", 'bootstrap' => "$src/$n/tests/bootstrap.php"];
}

$result = ['generated' => gmdate('c'), 'php' => PHP_VERSION, 'examples' => $examples, 'purposes' => $purposes, 'runtimes' => []];
foreach ($runtimes as $name => $rt) {
    $entry = ['commit' => $git($rt['dir']), 'api_commit' => $git($api), 'php' => PHP_VERSION, 'run_at' => gmdate('c'), 'results' => [], 'note' => null];
    if (!is_dir($rt['dir']) || !is_file($rt['bootstrap'])) {
        $entry['note'] = 'adapter checkout or bootstrap not present';
        $entry['results'] = array_fill_keys($examples, 'skipped');
    } else {
        $php = PHP_BINARY;
        // locally, SWOOLE_SO=/path/swoole.so loads the extension for the child php processes too
        $env = '';
        if ($name === 'swoole' && ($so = getenv('SWOOLE_SO'))) {
            $d = sys_get_temp_dir() . '/fi-swoole-ini';
            @mkdir($d);
            file_put_contents("$d/swoole.ini", "extension=$so\n");
            $env = 'PHP_INI_SCAN_DIR=' . escapeshellarg(':' . $d) . ' ';
        }
        // one runner process per example, so a hanging example only fails itself
        $crash = null;
        $timedOut = [];
        foreach ($examples as $e) {
            $cmd = 'cd ' . escapeshellarg($rt['dir']) . " && {$env}timeout 30 " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($rt['bootstrap']) . ' ' . escapeshellarg($e) . ' 2>&1; echo "exit:$?"';
            $o = (string) shell_exec($cmd);
            if (preg_match("/^ok\s+$e\$/m", $o)) { $entry['results'][$e] = 'ok'; continue; }
            $entry['results'][$e] = 'fail';
            if (str_contains($o, 'exit:124')) $timedOut[] = $e;
            elseif (!preg_match("/^FAIL\s+$e\$/m", $o)) $crash ??= substr(trim($o), 0, 600);
        }
        $notes = [];
        if ($timedOut) $notes[] = 'timed out (30s): ' . implode(', ', $timedOut);
        if ($crash !== null) $notes[] = 'runner crashed: ' . $crash;
        $bad = array_keys(array_filter($entry['results'], fn($r) => $r !== 'ok'));
        if ($bad && !$notes) $notes[] = 'failing: ' . implode(', ', $bad);
        $entry['note'] = $notes ? implode('; ', $notes) : null;
    }
    $result['runtimes'][$name] = $entry;
    fwrite(STDERR, sprintf("%-10s %d ok / %d\n", $name, count(array_keys($entry['results'], 'ok')), count($examples)));
}
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
