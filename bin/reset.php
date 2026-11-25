<?php

declare(strict_types=1);

/*
 * Resets the application to its post-install state: clears var/ (var/summary aggregates, hook records in var/events, session exports, hook error log).
 * Does not touch Claude Code transcripts (~/.claude) or .env. Without --yes it only shows what would be removed.
 * Run: php bin/reset.php [--yes]
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
    // a symbolic link is deleted as a link; its target is never entered
    if (is_link($path) || !is_dir($path)) {
        return [$path];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $out[] = $f->getPathname();
    }

    return [...$out, $path];
};

// sessions whose transcript Claude Code has already deleted — var/summary is their only copy
$archivedOnly = 0;
foreach (glob($var . '/summary/*.json') ?: [] as $summary) {
    $archivedOnly += TranscriptReader::locate(basename($summary, '.json')) === null ? 1 : 0;
}

echo "Will delete from var/:\n";
foreach ($entries as $entry) {
    $n = count(array_filter($files($var . '/' . $entry), 'is_file'));
    echo '  ' . $entry . (is_dir($var . '/' . $entry) && !is_link($var . '/' . $entry) ? "/ ($n files)" : '') . "\n";
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
