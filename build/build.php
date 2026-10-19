<?php
// Builds public/*.html from the READMEs (single source of truth), content/*.md and conformance.json.
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
copy("$root/assets/favicon.svg", "$root/public/favicon.svg");
@mkdir("$root/public/assets/logos", 0777, true); foreach (glob("$root/assets/logos/*") as $lf) { copy($lf, "$root/public/assets/logos/" . basename($lf)); }

$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);

const FI_API_FUNCS = ['setup','register','adapter','go','await','await_all','await_any','run','service','sleep','readable','writable'];
const FI_RUNTIMES = ['revolt'=>['Revolt'],'amp'=>['AMPHP'],'react'=>['ReactPHP'],'swoole'=>['Swoole'],'openswoole'=>['OpenSwoole'],'phasync'=>['phasync']];

/** Highlight the ```php blocks of a page at build time with PHP's own tokenizer (no client-side JS). */
function highlight_php_blocks(string $html): string
{
    // Explicit ```php fences, and unlabelled fences whose content is evidently PHP (a README's
    // ``` without a language); shell, ini and other labelled fences are left alone.
    return preg_replace_callback('~<pre><code( class="language-php")?>(.*?)</code></pre>~s', function ($m) {
        $src = htmlspecialchars_decode($m[2], ENT_QUOTES);
        if ($m[1] === '' && !preg_match('~^\s*<\?php|\$[A-Za-z_]|->|::|\b(function|use function|namespace|return|new)\b~', $src)) {
            return $m[0];
        }
        $open = !str_contains($src, '<?php');
        $toks = token_get_all(($open ? '<?php ' : '') . $src);
        $kw = [T_FUNCTION, T_FN, T_RETURN, T_USE, T_NEW, T_CLASS, T_TRY, T_CATCH, T_FOREACH, T_FOR, T_IF, T_ELSE, T_STATIC, T_NAMESPACE, T_THROW, T_AS, T_ECHO, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_CONST, T_EXTENDS, T_IMPLEMENTS, T_INTERFACE, T_FINALLY, T_MATCH, T_WHILE, T_ABSTRACT, T_FINAL, T_INSTANCEOF, T_CLONE, T_YIELD, T_CONTINUE, T_BREAK, T_FOREACH];
        $out = '';
        $prev = null;
        foreach ($toks as $i => $t) {
            if (!is_array($t)) { $out .= '<span class="tk-op">' . htmlspecialchars($t) . '</span>'; continue; }
            [$id, $txt] = $t;
            $e = htmlspecialchars($txt, ENT_QUOTES);
            if ($id === T_OPEN_TAG) { if (!$open) $out .= $e; continue; }
            $cls = match (true) {
                $id === T_VARIABLE => 'var',
                in_array($id, [T_COMMENT, T_DOC_COMMENT], true) => 'cmt',
                in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) => 'str',
                in_array($id, [T_LNUMBER, T_DNUMBER], true) => 'num',
                in_array($id, $kw, true) => 'kw',
                $id === T_STRING => (function () use ($toks, $i) {
                    for ($j = $i + 1; isset($toks[$j]) && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE; $j++);
                    return ($toks[$j] ?? null) === '(' ? 'fn' : null;
                })(),
                in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) => (($toks[$i + 1] ?? null) === '(' ? 'fn' : null),
                default => null,
            };
            $out .= $cls ? "<span class=\"tk-$cls\">$e</span>" : $e;
        }
        return '<pre><code class="language-php">' . $out . '</code></pre>';
    }, $html);
}

function page(string $file, string $title, string $body, string $desc, bool $wide = false): void
{
    global $root;
    // Two-level navigation: top-level groups, plus a sub-bar listing the pages of the active group.
    $groups = [
        'Home' => ['index.html' => 'Home'],
        'Get started' => ['get-started.html' => 'Get started'],
        'Tutorials' => ['tutorial-library.html' => 'Library authors'],
        'Integrations' => ['integrations.html' => 'Integrations'],
        'Specification' => ['adapters.html' => 'Adapters', 'spec.html' => 'Specification', 'conformance.html' => 'Conformance'],
        'Scope' => ['scope.html' => 'Scope', 'tutorial-container.html' => 'Scoped services', 'api-Scope.html' => 'Scope class', 'rfc-scope.html' => 'RFC'],
    ];
    $groups['API'] = ['api.html' => 'Overview'] + array_combine(array_map(fn($n) => "api-$n.html", FI_API_FUNCS), FI_API_FUNCS);
    $groups['Runtimes'] = ['runtimes.html' => 'Overview'] + array_combine(array_map(fn($n) => "runtime-$n.html", array_keys(FI_RUNTIMES)), array_map(fn($r) => $r[0], FI_RUNTIMES));
    // Leave out tutorial pages whose source is missing, so no link points at a page that was not built.
    foreach ($groups['Tutorials'] as $f => $t) {
        if (!is_file("$root/content/" . basename($f, '.html') . '.md')) unset($groups['Tutorials'][$f]);
    }
    $links = $sub = '';
    foreach (array_filter($groups) as $g => $pages) {
        $active = isset($pages[$file]);
        $links .= '<a href="' . array_key_first($pages) . '"' . ($active ? ' aria-current="' . ($pages === [$file => $pages[$file]] ? 'page' : 'true') . '"' : '') . ">$g</a>";
        if ($active && count($pages) > 1) {
            foreach ($pages as $f => $t) $sub .= '<a href="' . $f . '"' . ($f === $file ? ' aria-current="page"' : '') . ">$t</a>";
            $sub = '<nav class="sub" aria-label="' . $g . '"><div class="wrap">' . $sub . '</div></nav>';
        }
    }
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
<link rel="icon" href="favicon.svg" type="image/svg+xml">
</head>
<body>
<header><div class="wrap"><a class="mark" href="index.html">fiber-interop</a><nav aria-label="Site">$links</nav></div>$sub</header>
<main class="wrap%WIDE%">
$body
</main>
<footer><div class="wrap">fiber-interop is an open project. <a href="https://github.com/fiber-interop">github.com/fiber-interop</a>. This site is generated from the READMEs of the repositories; edit them there. Built %DATE%.</div></footer>
</body>
</html>
HTML;
    $html = highlight_php_blocks($html);
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
// Developer-facing front page from content/index.md; the runtime-author links and the RFC note follow it.
// content/index.md is our own file, so it may carry raw HTML (the bars, the adapter cards, the on-page nav).
$body = (string) (new GithubFlavoredMarkdownConverter(['allow_unsafe_links' => false]))->convert(file_get_contents("$root/content/index.md"));
$body .= "<h2>For runtime and framework authors</h2>\n<p>One adapter package per event loop, each checked by the same conformance suite:</p>\n<ul class=\"runtimes\">\n";
foreach ($adapters as $key => [$label]) {
    $st = '';
    if ($conf && isset($conf['runtimes'][$key])) {
        $r = $conf['runtimes'][$key]['results'];
        $st = ' - ' . count(array_keys($r, 'ok')) . '/' . count($r) . ' conformance examples pass';
    }
    $body .= "<li><a href=\"adapters.html#$key\">$label</a> (<code>fiber-interop/$key</code>)$st</li>\n";
}
$body .= "<li>TrueAsync, when it ships</li>\n</ul>\n";
$body .= '<p><a href="spec.html">Specification</a> · <a href="conformance.html">Conformance matrix</a> · <a href="scope.html">Scope</a> · <a href="https://github.com/fiber-interop">GitHub organization</a></p>';
page('index.html', 'Home', $body, 'Concurrent Guzzle, curl, PostgreSQL and MySQL in plain PHP, on phasync, Revolt, AMPHP, ReactPHP or Swoole.');

// ---- get started and tutorials (handwritten; a missing file is skipped so the build never breaks)
foreach (['get-started' => ['Get started', 'Install an adapter and run your first concurrent PHP program.'],
          'tutorial-library' => ['Making a library fiber-friendly', 'Make a PHP library cooperative on every fiber-interop runtime, with Guzzle as the case study.'],
          'tutorial-container' => ['Scoped lifetime in a service container', 'Per-request services in a service container that serves concurrent requests.']] as $name => [$title, $desc]) {
    if (!is_file("$root/content/$name.md")) {
        fwrite(STDERR, "warning: content/$name.md not found, $name.html not built\n");
        continue;
    }
    page("$name.html", $title, (string) $conv->convert(file_get_contents("$root/content/$name.md")), $desc);
}

// ---- integrations (handwritten summary; package READMEs are linked, see content/integrations.md)
page('integrations.html', 'Integrations', (string) $conv->convert(file_get_contents("$root/content/integrations.md")), 'Cooperative curl, Guzzle, pg, mysqli, process, predis and Doctrine DBAL on every fiber-interop adapter.');

// ---- rfc (published copy of the author's draft; see content/rfc-scope.md)
$md = preg_replace('~\A<!--.*?-->\s*~s', '', file_get_contents("$root/content/rfc-scope.md"));
$body = '<div class="banner"><strong>Draft, version 0.6, 2026-10-14.</strong> This RFC is not yet published on wiki.php.net and is not under discussion. When it is, this page will point to <a href="https://wiki.php.net/rfc/scope">https://wiki.php.net/rfc/scope</a> and discussion happens on the php-internals mailing list, not here.</div>'
    . (string) $conv->convert($md);
page('rfc-scope.html', 'PHP RFC: Scope (draft)', $body, 'Draft PHP RFC: Scope, version 0.6. Not yet published on wiki.php.net.');

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
    . '<p>Scope is what lets an application or framework keep services per request, per user or per job while fibers interleave. Run each request in <code>(new Scope)->run(...)</code> and anything keyed by <code>Scope::getCurrent()</code> (a <code>WeakMap</code> of services, a container\'s <code>Lifetime::Scoped</code>, locale, timezone) is per request, with per-scope superglobals, without a separate worker mode. Library authors never touch it. Every fiber-interop runtime provides a global <code>\\Scope</code>: the extension natively, else the polyfill (<code>fiber_interop\\scope\\IS_POLYFILL</code> says which). phasync keeps it switched itself; the other adapters do it in the adapter.</p>'
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

require __DIR__ . '/sections.php';
