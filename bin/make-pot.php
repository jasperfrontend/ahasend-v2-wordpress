<?php
/**
 * Generates xolution-maildelivery/languages/xolution-maildelivery.pot from the plugin's source.
 *
 *   php bin/make-pot.php           write the .pot
 *   php bin/make-pot.php --check   exit 1 if the .pot is out of date (used by the release workflow)
 *
 * A dependency-free stand-in for `wp i18n make-pot`: finds __(), _e(), _x(), _n(), esc_html__(), esc_html_e(),
 * esc_attr__() and esc_attr_e() calls with literal strings in the 'xolution-maildelivery' domain, their
 * "translators:" comments, and the plugin header's Name and Description. The output has no creation date, so it
 * only changes when the strings do.
 */

declare(strict_types=1);

const DOMAIN = 'xolution-maildelivery';

$root   = dirname(__DIR__) . '/xolution-maildelivery';
$target = $root . '/languages/' . DOMAIN . '.pot';

/** function => [msgid arg, plural arg|null, context arg|null, domain arg] */
$functions = [
    '__'         => [0, null, null, 1],
    '_e'         => [0, null, null, 1],
    'esc_html__' => [0, null, null, 1],
    'esc_html_e' => [0, null, null, 1],
    'esc_attr__' => [0, null, null, 1],
    'esc_attr_e' => [0, null, null, 1],
    '_x'         => [0, null, 1, 2],
    '_n'         => [0, 1, null, 3],
];

/** @var array<string, array{msgid: string, plural: ?string, context: ?string, refs: list<string>, comments: list<string>}> */
$entries = [];

$add = static function (string $msgid, ?string $plural, ?string $context, string $ref, ?string $comment) use (&$entries): void {
    $key = ($context ?? '') . "\x04" . $msgid;
    $entries[$key] ??= ['msgid' => $msgid, 'plural' => $plural, 'context' => $context, 'refs' => [], 'comments' => []];
    $entries[$key]['refs'][] = $ref;
    if ($comment !== null && !in_array($comment, $entries[$key]['comments'], true)) {
        $entries[$key]['comments'][] = $comment;
    }
};

// Plugin header.
$main = file_get_contents($root . '/' . DOMAIN . '.php');
foreach (['Plugin Name' => 'Name of the plugin', 'Description' => 'Description of the plugin'] as $header => $note) {
    if (preg_match('/^\s*\*\s*' . preg_quote($header, '/') . ':\s*(.+)$/m', $main, $m)) {
        $add(trim($m[1]), null, null, DOMAIN . '.php', $note);
    }
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$paths = [];
foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        $paths[] = $file->getPathname();
    }
}
sort($paths);

foreach ($paths as $path) {
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $tokens   = token_get_all(file_get_contents($path));
    $count    = count($tokens);
    $comment  = null; // last "translators:" comment: [text, end line]

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            if (preg_match('/translators:\s*(.+?)\s*(?:\*\/)?$/s', $token[1], $m)) {
                $text    = trim(preg_replace('/\s+/', ' ', preg_replace('/^\s*\*\s?/m', '', $m[1])));
                $comment = ['translators: ' . $text, $token[2] + substr_count($token[1], "\n")];
            }
            continue;
        }
        if ($token[0] !== T_STRING || !isset($functions[$token[1]])) {
            continue;
        }
        // A function call, not a method, static call or definition.
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            $prev--;
        }
        if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        $next = $i + 1;
        while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }
        if (($tokens[$next] ?? null) !== '(') {
            continue;
        }

        // Arguments: a literal string per argument, or null when it's anything else.
        $args  = [];
        $depth = 0;
        $parts = [];
        for ($j = $next; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t === '(' || $t === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    $args[] = $parts;
                    break;
                }
            } elseif ($t === ',' && $depth === 1) {
                $args[] = $parts;
                $parts  = [];
                continue;
            }
            if (!(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT], true))) {
                $parts[] = $t;
            }
        }
        $literal = static function (?array $parts): ?string {
            if ($parts === null || count($parts) !== 1 || !is_array($parts[0]) || $parts[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $raw = $parts[0][1];

            return $raw[0] === "'"
                ? strtr(substr($raw, 1, -1), ['\\\\' => '\\', "\\'" => "'"])
                : stripcslashes(substr($raw, 1, -1));
        };

        [$msgidArg, $pluralArg, $contextArg, $domainArg] = $functions[$token[1]];
        $msgid  = $literal($args[$msgidArg] ?? null);
        $domain = $literal($args[$domainArg] ?? null);
        if ($msgid === null || $domain !== DOMAIN) {
            continue;
        }
        $line = $token[2];
        $note = $comment !== null && $line - $comment[1] <= 1 ? $comment[0] : null;
        $comment = null; // a translators comment belongs to the first call after it only
        $add(
            $msgid,
            $pluralArg === null ? null : $literal($args[$pluralArg] ?? null),
            $contextArg === null ? null : $literal($args[$contextArg] ?? null),
            $relative . ':' . $line,
            $note,
        );
    }
}

$po = static fn (string $s): string => '"' . strtr($s, ['\\' => '\\\\', '"' => '\\"', "\n" => '\n', "\t" => '\t']) . '"';

$out  = "# Copyright (C) Team Xolution\n# This file is distributed under the GPL-2.0-or-later.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: Xolution MailDelivery\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://github.com/jasperfrontend/ahasend-v2-wordpress/issues\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"X-Domain: " . DOMAIN . "\\n\"\n";

foreach ($entries as $entry) {
    $out .= "\n";
    foreach ($entry['comments'] as $c) {
        $out .= '#. ' . $c . "\n";
    }
    $out .= '#: ' . implode(' ', $entry['refs']) . "\n";
    if (str_contains($entry['msgid'], '%')) {
        $out .= "#, php-format\n";
    }
    if ($entry['context'] !== null) {
        $out .= 'msgctxt ' . $po($entry['context']) . "\n";
    }
    $out .= 'msgid ' . $po($entry['msgid']) . "\n";
    if ($entry['plural'] !== null) {
        $out .= 'msgid_plural ' . $po($entry['plural']) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
    } else {
        $out .= "msgstr \"\"\n";
    }
}

if (in_array('--check', $argv, true)) {
    // Compare the strings only: line endings (Git may use CRLF on Windows) and "#:" source references,
    // whose line numbers shift with every code change, don't make the template out of date.
    $strip = static fn (string $pot): string => (string) preg_replace('/^#: .*\n/m', '', str_replace("\r\n", "\n", $pot));
    if (!is_file($target) || $strip((string) file_get_contents($target)) !== $strip($out)) {
        fwrite(STDERR, "languages/" . DOMAIN . ".pot is out of date: run php bin/make-pot.php and commit it.\n");
        exit(1);
    }
    echo "POT up to date (" . count($entries) . " strings).\n";
    exit(0);
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0777, true);
}
file_put_contents($target, $out);
echo 'Wrote ' . count($entries) . ' strings to ' . str_replace('\\', '/', substr($target, strlen(dirname(__DIR__)) + 1)) . "\n";
