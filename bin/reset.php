<?php

declare(strict_types=1);

/*
 * Reset aplikacji do stanu po instalacji: czyści var/ (agregaty var/summary, zapis hooka var/events, zrzuty sesji, log błędów hooka).
 * Nie dotyka transkryptów Claude Code (~/.claude) ani .env. Bez --yes tylko pokazuje, co zostałoby usunięte.
 * Uruchomienie: php bin/reset.php [--yes]
 */

use Viz\Source\TranscriptReader;

require __DIR__ . '/../src/autoload.php';

$var = dirname(__DIR__) . '/var';
$entries = is_dir($var) ? array_values(array_diff((array) scandir($var), ['.', '..'])) : [];
if ($entries === []) {
    echo "Nothing to reset: var/ is empty.\n";
    exit(0);
}

$files = static function (string $path): array {
    if (!is_dir($path)) {
        return [$path];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $out[] = $f->getPathname();
    }

    return [...$out, $path];
};

// sesje, których transkrypt Claude Code już usunął — var/summary to ich jedyna kopia
$archivedOnly = 0;
foreach (glob($var . '/summary/*.json') ?: [] as $summary) {
    $archivedOnly += TranscriptReader::locate(basename($summary, '.json')) === null ? 1 : 0;
}

echo "Will delete from var/:\n";
foreach ($entries as $entry) {
    $n = count(array_filter($files($var . '/' . $entry), 'is_file'));
    echo '  ' . $entry . (is_dir($var . '/' . $entry) ? "/ ($n files)" : '') . "\n";
}
if ($archivedOnly > 0) {
    echo "\n$archivedOnly sessions exist only in var/summary (Claude Code already removed their transcripts) — they will be gone for good.\n";
}
echo "Not touched: Claude Code transcripts (~/.claude), .env.\n";

if (!in_array('--yes', array_slice($argv, 1), true)) {
    echo "\nDry run. To delete, run: php bin/reset.php --yes\n";
    exit(0);
}

$deleted = 0;
foreach ($entries as $entry) {
    foreach ($files($var . '/' . $entry) as $path) {
        is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        $deleted++;
    }
}
echo "Deleted $deleted entries. The next page load rebuilds aggregates from the transcripts that still exist.\n";
