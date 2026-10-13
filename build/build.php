<?php
// Builds public/*.html from the READMEs (single source of truth) and conformance.json.
// Usage: php build/build.php [SRC_DIR|remote]
//   SRC_DIR  directory holding sibling checkouts (api, phasync, ...); default: parent of this repo
//   remote   fetch every README from raw.githubusercontent.com (used in CI)
require __DIR__ . '/../vendor/autoload.php';

use League\CommonMark\GithubFlavoredMarkdownConverter;

$arg = $argv[1] ?? dirname(__DIR__, 2) ?: '';
$root = dirname(__DIR__);
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
    $nav = ['index.html' => 'Overview', 'spec.html' => 'Specification', 'adapters.html' => 'Adapters', 'scope.html' => 'Scope', 'conformance.html' => 'Conformance'];
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
$body .= '<h2>Links</h2><ul><li><a href="spec.html">Specification</a></li><li><a href="conformance.html">Conformance matrix</a></li><li><a href="scope.html">Scope</a></li><li><a href="https://github.com/fiber-interop">GitHub organization</a></li></ul>';
page('index.html', 'Overview', $body, 'One way to write concurrent PHP that runs on every event loop.');

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
    . '<p>Scope is proposed for PHP core in the <a href="https://wiki.php.net/rfc/scope">Scope RFC</a>. Today it is available as a native extension, <code>fiber-interop/ext-scope</code>, with a userland polyfill, <code>fiber-interop/scope</code>, for installs without the extension. The polyfill and the extension expose the same class; code written against one runs on the other.</p>'
    . '<h2>Install</h2><pre><code>composer require fiber-interop/scope</code></pre><p>Composer uses the extension when it is loaded and the polyfill otherwise.</p>'
    . '<h2>Polyfill (<code>fiber-interop/scope</code>)</h2>' . render($conv, source($arg, 'scope', 'README.md'), 'scope', 2, true)
    . '<h2>Extension (<code>fiber-interop/ext-scope</code>)</h2>' . render($conv, source($arg, 'ext-scope', 'README.md'), 'ext-scope', 2, true);
page('scope.html', 'Scope', $body, 'Scope: operation identity across fibers and generators, as extension or polyfill.');

// ---- conformance
if (!$conf) {
    page('conformance.html', 'Conformance', '<h1>Conformance</h1><p>No conformance.json was produced for this build.</p>', 'Conformance matrix.');
} else {
    $rts = array_keys($conf['runtimes']);
    $sym = ['ok' => ['ok', 'ok'], 'fail' => ['fail', 'FAIL'], 'skipped' => ['skipped', 'skipped']];
    $t = '<div class="scroll"><table class="matrix"><thead><tr><th>Example</th>';
    foreach ($rts as $r) $t .= "<th class=\"s\">$r</th>";
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
    $n = '<h2>Notes per runtime</h2><table class="summary"><thead><tr><th>Runtime</th><th>PHP</th><th>Commit</th><th>Run</th><th>Notes</th></tr></thead><tbody>';
    foreach ($conf['runtimes'] as $r => $d) {
        $n .= '<tr><td><code>' . $h($r) . '</code></td><td>' . $h($d['php'] ?? '') . '</td><td><code>' . $h($d['commit'] ?? 'n/a') . '</code></td><td>' . $h(str_replace('T', ' ', substr($d['run_at'] ?? '', 0, 16))) . ' UTC</td><td>' . $h($d['note'] ?? '') . '</td></tr>';
    }
    $n .= '</tbody></table><p>All runs used the api at commit <code>' . $h($conf['runtimes']['reference']['api_commit'] ?? 'n/a') . '</code>. <em>skipped</em> means the runtime could not be run in that environment.</p>';
    $body = '<h1>Conformance</h1><p class="lead">Every adapter runs the same ' . count($conf['examples']) . ' examples from the api and must print identical output. Rows are examples, columns are runtimes. Results are produced by CI from the current <code>main</code> of every repository, daily and on every push.</p>' . $t . $n
        . '<p>See <a href="spec.html">the specification</a> for what the conformance set does and does not prove.</p>';
    page('conformance.html', 'Conformance', $body, 'Conformance matrix of fiber-interop adapters.', true);
}
