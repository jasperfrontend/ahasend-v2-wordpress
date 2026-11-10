<?php
/**
 * Compiles every xolution-maildelivery/languages/*.po into the files WordPress loads: .mo, and .l10n.php
 * (preferred by WordPress 6.5 and newer).
 *
 *   php bin/compile-translations.php           write them
 *   php bin/compile-translations.php --check   exit 1 if any compiled file is out of date (release workflow)
 *
 * Dependency-free stand-in for `msgfmt` / `wp i18n make-mo` / `wp i18n make-php`. Handles msgctxt and
 * multi-line strings; this plugin has no plural strings, so plurals are not supported.
 */

declare(strict_types=1);

$dir   = dirname(__DIR__) . '/xolution-maildelivery/languages';
$check = in_array('--check', $argv, true);
$stale = [];

foreach (glob($dir . '/*.po') ?: [] as $poFile) {
    [$headers, $messages] = parsePo((string) file_get_contents($poFile));
    $base = substr($poFile, 0, -3);

    // Untranslated strings fall back to English, so they don't block anything; just say so.
    $pot = $dir . '/xolution-maildelivery.pot';
    if (is_file($pot)) {
        [, $template] = parsePo(preg_replace('/^msgstr ""$/m', 'msgstr "x"', (string) file_get_contents($pot)));
        $missing = array_diff_key($template, $messages);
        if ($missing !== []) {
            echo basename($poFile) . ': ' . count($missing) . " string(s) not translated yet
";
        }
    }

    $outputs = [
        $base . '.mo'       => buildMo($headers, $messages),
        $base . '.l10n.php' => buildL10nPhp($headers, $messages),
    ];
    foreach ($outputs as $file => $content) {
        $current = is_file($file) ? (string) file_get_contents($file) : null;
        if (str_ends_with($file, '.php') && $current !== null) {
            $current = str_replace("\r\n", "\n", $current); // Git may check it out with CRLF on Windows
        }
        if ($current === $content) {
            continue;
        }
        if ($check) {
            $stale[] = basename($file);
        } else {
            file_put_contents($file, $content);
            echo 'Wrote ' . basename($file) . ' (' . count($messages) . " strings)\n";
        }
    }
}

if ($check) {
    if ($stale !== []) {
        fwrite(STDERR, 'Out of date: ' . implode(', ', $stale) . ". Run php bin/compile-translations.php and commit.\n");
        exit(1);
    }
    echo "Compiled translations up to date.\n";
}

/**
 * @return array{0: string, 1: array<string, string>} header block, and original => translation where the
 *                                                    original is "context\x04msgid" when there is a context
 */
function parsePo(string $po): array
{
    $unquote = static fn (string $s): string => stripcslashes(substr($s, 1, -1));
    $entries = [];
    $current = [];
    $field   = null;

    foreach (preg_split('/\r?\n/', $po) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            if ($line === '' && $current !== []) {
                $entries[] = $current;
                $current   = [];
                $field     = null;
            }
            continue;
        }
        if (preg_match('/^(msgctxt|msgid|msgstr)\s+(".*")$/', $line, $m)) {
            $field             = $m[1];
            $current[$field]   = $unquote($m[2]);
        } elseif ($line[0] === '"' && $field !== null) {
            $current[$field] .= $unquote($line);
        }
    }
    if ($current !== []) {
        $entries[] = $current;
    }

    $headers  = '';
    $messages = [];
    foreach ($entries as $entry) {
        if (($entry['msgid'] ?? null) === '' && !isset($entry['msgctxt'])) {
            $headers = $entry['msgstr'] ?? '';
            continue;
        }
        if (!isset($entry['msgid']) || ($entry['msgstr'] ?? '') === '') {
            continue; // untranslated
        }
        $key            = isset($entry['msgctxt']) ? $entry['msgctxt'] . "\x04" . $entry['msgid'] : $entry['msgid'];
        $messages[$key] = $entry['msgstr'];
    }
    ksort($messages, SORT_STRING);

    return [$headers, $messages];
}

/**
 * GNU .mo, little-endian, no hash table.
 *
 * @param array<string, string> $messages
 */
function buildMo(string $headers, array $messages): string
{
    $all = ['' => $headers] + $messages;
    ksort($all, SORT_STRING);

    $count       = count($all);
    $originals   = '';
    $translation = '';
    $origTable   = [];
    $transTable  = [];
    $dataStart   = 28 + 16 * $count;

    foreach ($all as $original => $translated) {
        $origTable[] = [strlen((string) $original), strlen($originals)];
        $originals  .= $original . "\0";
    }
    foreach ($all as $translated) {
        $transTable[] = [strlen($translated), strlen($translation)];
        $translation .= $translated . "\0";
    }

    $mo = pack('V7', 0x950412de, 0, $count, 28, 28 + 8 * $count, 0, $dataStart);
    foreach ($origTable as [$length, $offset]) {
        $mo .= pack('V2', $length, $dataStart + $offset);
    }
    $transStart = $dataStart + strlen($originals);
    foreach ($transTable as [$length, $offset]) {
        $mo .= pack('V2', $length, $transStart + $offset);
    }

    return $mo . $originals . $translation;
}

/**
 * The PHP translation file format of WordPress 6.5+: a returned array with headers and messages.
 *
 * @param array<string, string> $messages
 */
function buildL10nPhp(string $headers, array $messages): string
{
    $data = [];
    foreach (preg_split('/\n/', trim($headers)) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $data[strtolower($name)] = $value;
        }
    }
    $data['messages'] = $messages;

    return "<?php\n// Generated by bin/compile-translations.php from the .po file next to it. Do not edit.\nreturn " . var_export($data, true) . ";\n";
}
