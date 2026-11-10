<?php

declare(strict_types=1);

/*
 * Hook Claude Code: PostToolUse, PostToolUseFailure, Notification, PermissionRequest, StopFailure, SessionEnd, Stop, SubagentStart, SubagentStop,
 * InstructionsLoaded, PreCompact, PostCompact (zapis: HookRecorder).
 * Przy PermissionRequest brak wyjścia = brak decyzji: Claude Code pokazuje zwykłe okno zgody. Przy Stop / SubagentStop / PreCompact brak wyjścia
 * = nie blokuje zakończenia tury ani kompaktowania i nie dodaje instrukcji do streszczenia.
 * Nigdy nie przerywa pracy agenta: zawsze exit 0, nic nie wypisuje; błędy → var/hook-errors.log.
 */

try {
    require __DIR__ . '/../src/autoload.php';
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    if (is_array($payload)) {
        (new Viz\Source\HookRecorder())->record($payload);
    }
} catch (Throwable $e) {
    @file_put_contents(
        dirname(__DIR__) . '/var/hook-errors.log',
        gmdate('c') . ' ' . $e::class . ': ' . $e->getMessage() . "\n",
        FILE_APPEND,
    );
}
exit(0);
