<?php
// Builds public/*.html from the READMEs (single source of truth) and conformance.json.
// Usage: php build/build.php [SRC_DIR|remote]
//   SRC_DIR  directory holding sibling checkouts (api, phasync, ...); default: parent of this repo
//   remote   fetch every README from raw.githubusercontent.com (used in CI)
require __DIR__ . '/../vendor/autoload.php';

use League\CommonMark\GithubFlavoredMarkdownConverter;

$arg = $argv[1] ?? dirname(__DIR__, 2) ?: '';
$root = dirname(__DIR__);
// Where "the Scope RFC" links point. Switch to 'https://wiki.php.net/rfc/scope' once the RFC is published there.
const RFC_URL = 'rfc-scope.html';
$conv = new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);

/** [repo, path] => markdown */
function source(string $arg, string $repo, string $path): string
{
    if ($arg === 'remote') {
        $url = "https://raw.githubusercontent.com/fiber-interop/$repo/main/$path";
        $md = @file_get_contents($url);
        if ($md === false) { fwrite(STDERR, "cannot fetch $url\n"); exit(1); }
        return $md;
    }
    $dir = $repo === '.github' ? "$arg/.github" : "$arg/$repo";
    $md = @file_get_contents("$dir/$path");
    if ($md === false) { fwrite(STDERR, "cannot read $dir/$path\n"); exit(1); }
    return $md;
}

/** Render a README; demote headings by $demote levels; make relative links point at GitHub. */
function render(GithubFlavoredMarkdownConverter $conv, string $md, string $repo, int $demote = 0, bool $dropH1 = false): string
{
    $html = (string) $conv->convert($md);
    if ($dropH1) $html = preg_replace('~<h1>.*?</h1>\s*~s', '', $html, 1);
    if ($demote) {
        $html = preg_replace_callback('~<(/?)h([1-6])>~', fn($m) => '<' . $m[1] . 'h' . min(6, $m[2] + $demote) . '>', $html);
    }
    $gh = 'https://github.com/fiber-interop/';
    return preg_replace_callback('~href="([^"]*)"~', function ($m) use ($gh, $repo) {
        $h = $m[1];
        if ($h === '' || $h[0] === '#' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $h)) return $m[0];
        if (preg_match('~^\.\./([^/]+)(/.*)?$~', $h, $x)) return 'href="' . $gh . $x[1] . (isset($x[2]) ? '/blob/main' . $x[2] : '') . '"';
        return 'href="' . $gh . $repo . '/blob/main/' . ltrim($h, './') . '"';
    }, $html);
}

@mkdir("$root/public", 0777, true);
copy("$root/assets/style.css", "$root/public/style.css");

$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);

function page(string $file, string $title, string $body, string $desc, bool $wide = false): void
{
    global $root;
    $nav = ['index.html' => 'Overview', 'spec.html' => 'Specification', 'adapters.html' => 'Adapters', 'scope.html' => 'Scope', 'rfc-scope.html' => 'RFC', 'conformance.html' => 'Conformance'];
    $links = '';
    foreach ($nav as $f => $t) $links .= '<a href="' . $f . '"' . ($f === $file ? ' aria-current="page"' : '') . '>' . $t . '</a>';
    $full = $file === 'index.html' ? 'fiber-interop' : "$title - fiber-interop";
    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>$full</title>
<meta name="description" content="$desc">
<link rel="stylesheet" href="style.css">
</head>
<body>
<header><div class="wrap"><a class="mark" href="index.html">fiber-interop</a><nav>$links</nav></div></header>
<main class="wrap%WIDE%">
$body
</main>
<footer><div class="wrap">fiber-interop is an open project. <a href="https://github.com/fiber-interop">github.com/fiber-interop</a>. This site is generated from the READMEs of the repositories; edit them there. Built %DATE%.</div></footer>
</body>
</html>
HTML;
    $html = str_replace(['%WIDE%', '%DATE%'], [$wide ? ' wide' : '', gmdate('Y-m-d H:i') . ' UTC'], $html);
    file_put_contents("$root/public/$file", $html);
    echo "built $file\n";
}

$adapters = [
    'phasync' => ['phasync', 'phasync'],
    'revolt' => ['Revolt', 'revolt'],
    'amp' => ['AMPHP', 'amp'],
    'react' => ['ReactPHP', 'react'],
    'swoole' => ['Swoole', 'swoole'],
];
$conf = is_file("$root/conformance.json") ? json_decode(file_get_contents("$root/conformance.json"), true) : null;

// ---- index
$body = render($conv, source($arg, '.github', 'profile/README.md'), '.github');
$body .= "<h2>Install</h2>\n<pre><code>composer require fiber-interop/api fiber-interop/&lt;adapter&gt;</code></pre>\n";
$body .= "<p>Replace <code>&lt;adapter&gt;</code> with the package for the loop you use.</p>\n<h2>Runtimes</h2>\n<ul class=\"runtimes\">\n";
foreach ($adapters as $key => [$label]) {
    $st = '';
    if ($conf && isset($conf['runtimes'][$key])) {
        $r = $conf['runtimes'][$key]['results'];
        $st = ' - ' . count(array_keys($r, 'ok')) . '/' . count($r) . ' conformance examples pass';
    }
    $body .= "<li><a href=\"adapters.html#$key\">$label</a> (<code>fiber-interop/$key</code>)$st</li>\n";
}
$body .= "<li>TrueAsync, when it ships</li>\n</ul>\n";
$body .= '<h2>Links</h2><ul><li><a href="spec.html">Specification</a></li><li><a href="conformance.html">Conformance matrix</a></li><li><a href="scope.html">Scope</a></li><li><a href="' . RFC_URL . '">the Scope RFC (draft)</a></li><li><a href="https://github.com/fiber-interop">GitHub organization</a></li></ul>';
page('index.html', 'Overview', $body, 'One way to write concurrent PHP that runs on every event loop.');

// ---- rfc (published copy of the author's draft; see content/rfc-scope.md)
$md = preg_replace('~\A<!--.*?-->\s*~s', '', file_get_contents("$root/content/rfc-scope.md"));
$body = '<div class="banner"><strong>Draft, version 0.5, 2026-10-13.</strong> This RFC is not yet published on wiki.php.net and is not under discussion. When it is, this page will point to <a href="https://wiki.php.net/rfc/scope">https://wiki.php.net/rfc/scope</a> and discussion happens on the php-internals mailing list, not here.</div>'
    . (string) $conv->convert($md);
page('rfc-scope.html', 'Scope RFC (draft)', $body, 'Draft PHP RFC: Scope, version 0.5. Not yet published on wiki.php.net.');

// ---- spec
$body = '<p class="lead">Generated from <a href="https://github.com/fiber-interop/api/blob/main/README.md">fiber-interop/api README.md</a>, the single source of truth.</p>'
    . render($conv, source($arg, 'api', 'README.md'), 'api');
page('spec.html', 'Specification', $body, 'The fiber-interop api specification.');

// ---- adapters
$toc = '<ul>';
$secs = '';
foreach ($adapters as $key => [$label]) {
    $toc .= "<li><a href=\"#$key\">$label</a></li>";
    $secs .= "<section id=\"$key\"><h2>$label <small><code>fiber-interop/$key</code></small></h2><p><a href=\"https://github.com/fiber-interop/$key\">Repository</a></p>"
        . render($conv, source($arg, $key, 'README.md'), $key, 2, true) . "</section>\n";
}
page('adapters.html', 'Adapters', "<h1>Adapters</h1><p class=\"lead\">One package per runtime, each certified by the same conformance suite. Sections below are generated from each adapter's README.</p>$toc</ul>$secs", 'fiber-interop adapters for phasync, Revolt, AMPHP, ReactPHP and Swoole.');

// ---- scope
$body = '<h1>Scope</h1><p class="lead">The identity of the operation code belongs to, an HTTP request or a job, preserved across fibers and generators.</p>'
    . '<p>Scope is proposed for PHP core in <a href="' . RFC_URL . '">the Scope RFC (draft)</a>. Today it is available as a native extension, <code>fiber-interop/ext-scope</code>, with a userland polyfill, <code>fiber-interop/scope</code>, for installs without the extension. The polyfill and the extension expose the same class; code written against one runs on the other.</p>'
    . '<h2>Install</h2><pre><code>composer require fiber-interop/scope</code></pre><p>Composer uses the extension when it is loaded and the polyfill otherwise.</p>'
    . '<h2>Polyfill (<code>fiber-interop/scope</code>)</h2>' . render($conv, source($arg, 'scope', 'README.md'), 'scope', 2, true)
    . '<h2>Extension (<code>fiber-interop/ext-scope</code>)</h2>' . render($conv, source($arg, 'ext-scope', 'README.md'), 'ext-scope', 2, true);
page('scope.html', 'Scope', $body, 'Scope: operation identity across fibers and generators, as extension or polyfill.');

// ---- conformance
if (!$conf) {
    page('conformance.html', 'Conformance', '<h1>Conformance</h1><p>No conformance.json was produced for this build.</p>', 'Conformance matrix.');
} else {
    $names = ['amp' => 'AMPHP', 'openswoole' => 'OpenSwoole (swoole adapter)', 'phasync' => 'phasync', 'react' => 'ReactPHP', 'revolt' => 'Revolt', 'swoole' => 'Swoole', 'reference' => 'reference adapter (api test double)'];
    $nm = fn(string $r) => $names[$r] ?? $r;
    $rts = array_keys($conf['runtimes']);
    usort($rts, fn($a, $b) => ($a === 'reference') <=> ($b === 'reference') ?: strcasecmp($nm($a), $nm($b)));
    $short = fn(string $r) => $r === 'reference' ? 'reference' : $nm($r);
    $sym = ['ok' => ['ok', 'ok'], 'fail' => ['fail', 'FAIL'], 'skipped' => ['skipped', 'skipped']];
    // summary
    $sm = '<table class="summary"><thead><tr><th>Runtime</th><th>Library / extension version tested</th><th>PHP</th><th>Result</th><th>Scope state</th><th>Stress</th><th>Run at</th></tr></thead><tbody>';
    $exc = [];
    $pk = ['api' => 'api', 'loop' => 'loop', 'raw_fiber' => 'raw fiber'];
    $probeExc = [];
    foreach ($rts as $r) {
        $d = $conf['runtimes'][$r];
        $pr = $d['probes'] ?? [];
        $pv = fn(string $k) => $pr[$k] ?? 'not run';
        if ($pv('api') === 'not run' && $pv('loop') === 'not run' && $pv('raw_fiber') === 'not run') [$sc, $st] = ['skip', 'not run'];
        elseif ($pv('api') !== 'ok') [$sc, $st] = ['bad', 'none'];
        elseif ($pv('loop') === 'ok' && $pv('raw_fiber') === 'ok') [$sc, $st] = ['ok', 'full'];
        else [$sc, $st] = ['warn', 'api-driven'];
        $res = $d['results'] ?? [];
        $tot = count($conf['examples']);
        $pass = count(array_keys($res, 'ok'));
        foreach ($conf['examples'] as $e) {
            $v = $res[$e] ?? 'skipped';
            if ($v === 'fail' || !in_array($v, ['ok', 'skipped'], true)) $exc[] = [$r, $e, $v === 'fail' ? '' : (string)$v];
        }
        if (!$res || count(array_keys($res, 'skipped')) === count($res)) [$bc, $bt] = ['skip', 'not run'];
        elseif ($pass === $tot) [$bc, $bt] = ['ok', "all $tot pass"];
        else [$bc, $bt] = ['warn', "$pass of $tot pass"];
        $stt = $d['stress'] ?? null;
        $sn = $stt ? $stt['passed'] + $stt['failed'] : 0;
        if (!$sn) [$tc, $tt] = ['skip', 'not run'];
        else [$tc, $tt] = [$stt['failed'] ? 'warn' : 'ok', $stt['passed'] . '/' . $sn];
        foreach ($stt['tests'] ?? [] as $tn => $tv) if ($tv !== 'ok') $exc[] = [$r, "stress $tn", preg_replace('/^fail \((.*)\)$/s', '$1', $tv)];
        $note = $d['note'] ?? null;
        $ver = [];
        if (!empty($d['commit'])) $ver[] = 'adapter <code>' . $h($d['commit']) . '</code>';
        if (is_array($note)) {
            foreach ($note as $k => $v) $ver[] = $h(is_int($k) ? (string)$v : "$k $v");
        } elseif ($note) {
            $ver[] = $h((string)$note);
        }
        $run = !empty($d['run_at']) ? $h(str_replace('T', ' ', substr($d['run_at'], 0, 16))) . ' UTC' : 'n/a';
        $sm .= '<tr><td>' . $h($nm($r)) . '</td><td>' . ($ver ? implode(', ', $ver) : 'n/a') . '</td><td>' . $h($d['php'] ?? 'n/a') . "</td><td><span class=\"badge $bc\">$bt</span></td><td><span class=\"badge $sc\">$st</span></td><td><span class=\"badge $tc\">$tt</span></td><td>$run</td></tr>";
    }
    $sm .= '</tbody></table><p class="meta">All runs used the api at commit <code>' . $h($conf['runtimes']['reference']['api_commit'] ?? 'n/a') . '</code>.</p>';
    // scope probes table
    $probeCell = function (array $p, string $k) use ($h, &$probeExc, &$cur) {
        $v = $p[$k] ?? 'not run';
        $cls = ['ok' => 'ok', 'fail' => 'fail'][$v] ?? 'skipped';
        $title = '';
        if ($v === 'fail') {
            $line = $p[$k . '_line'] ?? '';
            $title = ' title="' . $h($line) . '"';
            $probeExc[] = [$cur[0], $cur[1], $k, $line];
        }
        return "<td class=\"s $cls\"$title>" . ($v === 'fail' ? 'FAIL' : $v) . '</td>';
    };
    $pt = '<div class="scroll"><table class="matrix probes"><thead><tr><th rowspan="2">Runtime</th>';
    foreach ($pk as $label) $pt .= '<th colspan="2">' . $h($label) . '</th>';
    $pt .= '</tr><tr>' . str_repeat('<th class="s">polyfill</th><th class="s">ext-scope</th>', 3) . '</tr></thead><tbody>';
    foreach ($rts as $r) {
        $d = $conf['runtimes'][$r];
        $pt .= '<tr><td class="n">' . $h($nm($r)) . '</td>';
        foreach (array_keys($pk) as $k) {
            $cur = [$r, 'polyfill'];
            $pt .= $probeCell($d['probes'] ?? [], $k);
            $cur = [$r, 'ext-scope'];
            $pt .= $probeCell($d['probes_ext'] ?? [], $k);
        }
        $pt .= '</tr>';
    }
    $pt .= '</tbody></table></div>';
    $sm .= '<h3>Scope probes</h3>'
        . '<p>Three standalone scripts per adapter show how far per-scope state (including superglobals) follows the code. <strong>api</strong> proves that code driven through the fiber-interop api keeps its scope; <strong>loop</strong> proves the same for code using the loop\'s own primitives; <strong>raw fiber</strong> proves that a plain <code>Fiber</code> keeps the scope of its creator.</p>'
        . '<p>A userland polyfill sees only the switches that pass through a hook it controls: everything under Revolt and AMPHP, callbacks but not <code>await()</code> under ReactPHP, nothing Swoole performs itself. Covering every switch needs the runtime: the engine for fibers (the case made in the <a href="' . RFC_URL . '">the Scope RFC (draft)</a>), and for Swoole the two switch hooks its core already has but does not expose to PHP. The ext-scope columns run the same probes with the native extension loaded. The extension provides the identity; per-scope superglobals are the RFC\'s secondary vote and are not in the extension yet, so <em>api</em> and <em>loop</em> fail there today and <em>raw fiber</em> passes. That is the measurement, not a defect of the extension.</p>'
        . $pt;
    // exceptions
    foreach ($probeExc as [$r, $mode, $k, $line]) $exc[] = [$r, "scope probe $k ($mode)", $line];
    if ($exc) {
        $ex = '<ul class="exceptions">';
        foreach ($exc as [$r, $e, $msg]) $ex .= '<li><strong>' . $h($nm($r)) . '</strong>: <code>' . $h($e) . '</code>' . ($msg !== '' ? ' - <code>' . $h($msg) . '</code>' : ' - failed (no error recorded)') . '</li>';
        $ex .= '</ul>';
    } else {
        $ex = '<p>No exceptions: every runtime passes every example.</p>';
    }
    // matrix
    $t = '<div class="scroll"><table class="matrix"><thead><tr><th>Example</th>';
    foreach ($rts as $r) $t .= '<th class="s">' . $h($short($r)) . '</th>';
    $t .= '</tr></thead><tbody>';
    foreach ($conf['examples'] as $e) {
        $t .= '<tr><td class="n"><code>' . $h($e) . '</code><small>' . $h($conf['purposes'][$e] ?? '') . '</small></td>';
        foreach ($rts as $r) {
            $v = $conf['runtimes'][$r]['results'][$e] ?? 'skipped';
            [$c, $l] = $sym[$v] ?? $sym['skipped'];
            $t .= "<td class=\"s $c\">$l</td>";
        }
        $t .= '</tr>';
    }
    $t .= '<tr><th>Passing</th>';
    foreach ($rts as $r) {
        $res = $conf['runtimes'][$r]['results'];
        $t .= '<th class="s">' . count(array_keys($res, 'ok')) . '/' . count($res) . '</th>';
    }
    $t .= '</tr></tbody></table></div>';
    $body = '<h1>Conformance</h1><p class="lead">Every adapter runs the same ' . count($conf['examples']) . ' examples from the api and must print identical output. Results are produced by CI from the current <code>main</code> of every repository, daily and on every push.</p>'
        . '<h2>Summary</h2>' . $sm
        . '<h2>Exceptions</h2>' . $ex
        . '<h2>Full matrix</h2><details class="matrixbox"><summary>Show the full matrix (examples by runtimes)</summary>' . $t . '<p class="meta"><em>skipped</em> means the runtime could not be run in that environment.</p></details>'
        . '<p>See <a href="spec.html">the specification</a> for what the conformance set does and does not prove.</p>';
    page('conformance.html', 'Conformance', $body, 'Conformance matrix of fiber-interop adapters.');
}
