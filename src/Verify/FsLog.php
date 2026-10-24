<?php

declare(strict_types=1);

namespace Viz\Verify;

use Viz\Path;

/**
 * Parser logu systemowego: `fs_usage -w -f filesys` (macOS) albo `strace -f -tt -e trace=openat` (Linux).
 * Wynik: lista ['time' => 'H:i:s.u', 'op' => 'open'|'stat', 'path' => string, 'proc' => string].
 */
final class FsLog
{
    private const FS_USAGE_OPEN = '/^(open|openat|open_nocancel|open_dprotected_np|open_extended|RdData)/';
    private const FS_USAGE_STAT = '/^(stat|lstat|getattrlist|getdirentries|fstatat|access)/';

    /** @return list<array{time: string, op: string, path: string, proc: string}> */
    public static function parse(string $file): array
    {
        $out = [];
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open ' . $file);
        }
        while (($line = fgets($handle)) !== false) {
            $row = self::strace($line) ?? self::fsUsage($line);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        fclose($handle);

        return $out;
    }

    /** `12:34:56.123456  open  F=4  (R_____)  /ścieżka   0.000020   cat.12345` */
    private static function fsUsage(string $line): ?array
    {
        if (!preg_match('/^(\d{2}:\d{2}:\d{2}(?:\.\d+)?)\s+(\S+)\s+(.*)$/', rtrim($line), $m)) {
            return null;
        }
        $op = preg_match(self::FS_USAGE_OPEN, $m[2]) ? 'open' : (preg_match(self::FS_USAGE_STAT, $m[2]) ? 'stat' : null);
        if ($op === null) {
            return null;
        }
        // ścieżka: od pierwszego " /" do kolumny czasu trwania; proces: ostatni token "nazwa.pid"
        if (!preg_match('#(?:^|\s)(/\S.*?)\s{2,}\d+\.\d+(?:\s+W)?\s+(\S+)$#', $m[3], $p)) {
            return null;
        }
        // RdData/WrData: "/dev/disk3s1  /ścieżka" — bierzemy ostatnią ścieżkę
        $path = (string) preg_replace('#^.*\s{2,}(?=/)#', '', $p[1]);
        if (str_starts_with($path, '/dev/')) {
            return null;
        }

        return ['time' => $m[1], 'op' => $op, 'path' => Path::normalize($path), 'proc' => preg_replace('/\.\d+$/', '', $p[2])];
    }

    /** `12345 12:34:56.123456 openat(AT_FDCWD, "/ścieżka", O_RDONLY) = 3` */
    private static function strace(string $line): ?array
    {
        if (!preg_match('/^(?:\[pid\s+)?(\d+)\]?\s+(\d{2}:\d{2}:\d{2}\.\d+)\s+(openat|open|stat|lstat|newfstatat|statx)\((?:AT_FDCWD,\s*|\d+,\s*)?"((?:[^"\\\\]|\\\\.)*)"[^=]*=\s*(-?\d+)/', $line, $m)) {
            return null;
        }
        if ((int) $m[5] < 0) {
            return null;
        }
        $path = stripcslashes($m[4]);
        if (!str_starts_with($path, '/')) {
            return null;
        }

        return ['time' => $m[2], 'op' => str_starts_with($m[3], 'open') ? 'open' : 'stat', 'path' => Path::normalize($path), 'proc' => 'pid:' . $m[1]];
    }
}
