<?php

declare(strict_types=1);

/*
 * Hook Claude Code: PostToolUse, PostToolUseFailure, Notification, PermissionRequest, StopFailure, SessionEnd, Stop, SubagentStart, SubagentStop,
 * InstructionsLoaded, PreCompact, PostCompact (recording: HookRecorder).
 * On PermissionRequest, no output = no decision: Claude Code shows the regular permission dialog. On Stop / SubagentStop / PreCompact, no output
 * = does not block the end of the turn or compaction and adds no instructions to the summary.
 * Never interrupts the agent's work: always exit 0, prints nothing; errors → var/hook-errors.log.
 */

try {
    require __DIR__ . '/../src/autoload.php';
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    if (is_array($payload)) {
        (new Viz\Source\HookRecorder())->record($payload);
    }
} catch (Throwable $e) {
    $errorLog = dirname(__DIR__) . '/var/hook-errors.log';
    @file_put_contents($errorLog, gmdate('c') . ' ' . $e::class . ': ' . $e->getMessage() . "\n", FILE_APPEND);
    @chmod($errorLog, 0600);
}
exit(0);
