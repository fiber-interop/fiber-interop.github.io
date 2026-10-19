<?php
// Runs the api's conformance runner against every adapter and writes conformance.json.
// Usage: php build/conformance.php [SRC_DIR=..] [OUT=conformance.json] [only=openswoole|trueasync]
// With only=openswoole just the swoole adapter runs, recorded as runtime "openswoole" (CI loads ext-openswoole
// instead of ext-swoole for that run); the other runtimes are skipped. Without it, openswoole is not run.
// With only=trueasync just the trueasync adapter runs (CI runs it inside the trueasync/php-true-async image).
// Each adapter is a sibling checkout (api, phasync, revolt, ...) with composer already installed.
// A crashing adapter (no "ok"/"FAIL" lines) gets every example marked fail plus its error text.

$src = realpath($argv[1] ?? __DIR__ . '/../..');
$out = $argv[2] ?? __DIR__ . '/../conformance.json';
$only = $argv[3] ?? null;
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
foreach (['phasync', 'revolt', 'amp', 'react', 'swoole', 'trueasync'] as $n) {
    $runtimes[$n] = ['dir' => "$src/$n", 'bootstrap' => "$src/$n/tests/bootstrap.php"];
}

if ($only === 'openswoole') {
    $runtimes = ['openswoole' => $runtimes['swoole']];
} elseif ($only === 'trueasync') {
    $runtimes = ['trueasync' => $runtimes['trueasync']];
} else {
    unset($runtimes['trueasync']); // needs PHP 8.6 with TrueAsync: its own CI step
}

// scope probes: probes/scope-{api,loop,raw-fiber}.php in the adapter checkout, each prints "ok" and exits 0.
// Run once with the polyfill, once more with the ext-scope extension loaded (EXT_SCOPE_SO, else $src/ext-scope/modules/scope.so).
$extSo = getenv('EXT_SCOPE_SO') ?: "$src/ext-scope/modules/scope.so";
$runProbes = function (array $rt, string $env, ?string $ext): array {
    $r = [];
    foreach (['api' => 'scope-api', 'loop' => 'scope-loop', 'raw_fiber' => 'scope-raw-fiber'] as $k => $f) {
        $file = $rt['dir'] . "/probes/$f.php";
        if (!is_file($file)) { $r[$k] = 'not run'; continue; }
        $x = $ext !== null ? ' -d extension=' . escapeshellarg($ext) : '';
        $o = (string) shell_exec('cd ' . escapeshellarg($rt['dir']) . " && {$env}timeout 30 " . escapeshellarg(PHP_BINARY) . "$x " . escapeshellarg($file) . ' 2>&1; echo "exit:$?"');
        preg_match('/exit:(\d+)\s*$/', $o, $m);
        $code = (int) ($m[1] ?? 1);
        $o = trim(preg_replace('/\s*exit:\d+\s*$/', '', $o));
        if ($code === 0 && $o === 'ok') { $r[$k] = 'ok'; continue; }
        $r[$k] = 'fail';
        $r[$k . '_line'] = $code === 124 ? 'timed out (30s)' : substr(strtok($o, "\n") ?: "exit $code, no output", 0, 200);
    }
    return $r;
};

// stress tier: api/bin/fiber-interop-stress <bootstrap>, results read from a PHPUnit JUnit log
$runStress = function (array $rt, string $env, string $api): array {
    $stress = "$api/bin/fiber-interop-stress";
    $none = ['passed' => 0, 'failed' => 0, 'tests' => []];
    if (!is_file($stress)) return $none + ['status' => 'not run'];
    $log = tempnam(sys_get_temp_dir(), 'fi-stress-');
    $iter = getenv('FIBER_INTEROP_STRESS_ITERATIONS') !== false ? '' : 'FIBER_INTEROP_STRESS_ITERATIONS=100 ';
    $o = (string) shell_exec('cd ' . escapeshellarg($rt['dir']) . " && {$env}{$iter}timeout 600 " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stress) . ' ' . escapeshellarg($rt['bootstrap']) . ' --log-junit ' . escapeshellarg($log) . ' 2>&1; echo "exit:$?"');
    $xml = @simplexml_load_file($log);
    @unlink($log);
    if (!$xml) return $none + ['status' => 'fail', 'error' => substr(trim(strtok(preg_replace('/\s*exit:\d+\s*$/', '', trim($o)) ?: 'no output', "\n")), 0, 200)];
    $r = ['passed' => 0, 'failed' => 0, 'tests' => []];
    foreach ($xml->xpath('//testcase') as $tc) {
        $n = (string) $tc['name'];
        $bad = isset($tc->failure) ? $tc->failure : (isset($tc->error) ? $tc->error : null);
        if ($bad === null) { $r['tests'][$n] = 'ok'; $r['passed']++; continue; }
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $bad)), fn($l) => $l !== '' && !str_ends_with($l, $n)));
        $r['tests'][$n] = 'fail (' . substr($lines[0] ?? 'failed', 0, 200) . ')';
        $r['failed']++;
    }
    return $r;
};

$result = ['generated' => gmdate('c'), 'php' => PHP_VERSION, 'examples' => $examples, 'purposes' => $purposes, 'runtimes' => []];
foreach ($runtimes as $name => $rt) {
    $entry = ['commit' => $git($rt['dir']), 'api_commit' => $git($api), 'php' => PHP_VERSION, 'run_at' => gmdate('c'), 'results' => [], 'note' => null];
    if (!is_dir($rt['dir']) || !is_file($rt['bootstrap'])) {
        $entry['note'] = 'adapter checkout or bootstrap not present';
        $entry['results'] = array_fill_keys($examples, 'skipped');
        $entry['stress'] = ['status' => 'not run', 'passed' => 0, 'failed' => 0, 'tests' => []];
        $entry['probes'] = $entry['probes_ext'] = ['api' => 'not run', 'loop' => 'not run', 'raw_fiber' => 'not run'];
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
            // the first stderr line, else the first "actual" line, else the start of the output
            $m = [];
            $first = preg_match('/^--- stderr\R(?:PHP )?(.+)$/m', $o, $m) || preg_match('/^--- actual\R(.+)$/m', $o, $m) ? $m[1] : preg_replace('/\s*exit:\d+\s*$/', '', $o);
            $entry['results'][$e] = substr(trim($first), 0, 200) ?: 'fail';
            if (str_contains($o, 'exit:124')) $timedOut[] = $e;
            elseif (!preg_match("/^FAIL\s+$e\$/m", $o)) $crash ??= substr(trim($o), 0, 600);
        }
        $entry['probes'] = $runProbes($rt, $env, null);
        $entry['stress'] = $runStress($rt, $env, $api);
        $entry['probes_ext'] = is_file($extSo) ? $runProbes($rt, $env, $extSo) : ['api' => 'not run', 'loop' => 'not run', 'raw_fiber' => 'not run'];
        $notes = [];
        if ($timedOut) $notes[] = 'timed out (30s): ' . implode(', ', $timedOut);
        if ($crash !== null) $notes[] = 'runner crashed: ' . $crash;
        $bad = array_keys(array_filter($entry['results'], fn($r) => $r !== 'ok'));
        if ($bad && !$notes) $notes[] = 'failing: ' . implode(', ', $bad);
        // versions actually tested: map of package/extension => version, plus problems under "problems"
        $pk = ['phasync' => ['phasync/phasync'], 'revolt' => ['revolt/event-loop'], 'amp' => ['amphp/amp'], 'react' => ['react/event-loop', 'react/async'], 'swoole' => [], 'openswoole' => []][$name] ?? [];
        $ver = [];
        $ci = json_decode((string) shell_exec('cd ' . escapeshellarg($rt['dir']) . ' && composer show --format=json 2>/dev/null'), true);
        foreach ($ci['installed'] ?? [] as $pkg) {
            if (in_array($pkg['name'], $pk, true)) $ver[$pkg['name']] = $pkg['version'];
        }
        if ($name === 'swoole' || $name === 'openswoole') {
            $ext = $name;
            $v = trim((string) shell_exec("{$env}" . escapeshellarg(PHP_BINARY) . " -r 'echo phpversion(\"$ext\");' 2>/dev/null"));
            $ver["ext-$ext"] = $v !== '' ? $v : 'not loaded';
        }
        if ($name === 'trueasync') {
            $v = phpversion('true_async');
            $ver['ext-true_async'] = $v !== false ? $v : 'not loaded';
        }
        $ver['php'] = PHP_VERSION;
        $ver['api'] = $entry['api_commit'];
        if ($notes) $ver['problems'] = implode('; ', $notes);
        $entry['note'] = $ver;
    }
    $result['runtimes'][$name] = $entry;
    fwrite(STDERR, sprintf("%-10s %d ok / %d\n", $name, count(array_keys($entry['results'], 'ok')), count($examples)));
}
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
