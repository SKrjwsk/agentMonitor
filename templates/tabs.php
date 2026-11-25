<?php
/**
 * Tabs shared by both scopes, one row with a uniform look. In the "all" scope views requiring single calls are disabled.
 * Counter only where the view has one (`$view['counts']`).
 *
 * @var array $view @var array $p @var bool $all @var array<string, string> $tabs @var callable $tab
 */

use Viz\Web\App;

$off = static fn (string $v): bool => $all && !in_array($v, App::ALL_VIEWS, true);
?>
<nav class="tabs">
    <?php foreach ($tabs as $v => $label): ?>
        <?php if ($off($v)) { continue; } ?>
        <?php /* Log: counter = tool calls like "all calls" in the status select; files loaded by Claude Code separately in the tooltip */ ?>
        <a href="<?= h($tab($v)) ?>" <?= $p['view'] === $v ? 'viz-current' : '' ?><?= $v === 'log' && ($view['logContext'] ?? 0) > 0 ? ' title="' . th('{n} tool calls + {c} files loaded into context by Claude Code (also listed in the log)', ['n' => $view['counts']['log'], 'c' => $view['logContext']]) . '"' : '' ?>><?= th($label) ?><?= isset($view['counts'][$v]) ? ' <span class="n">' . h($view['counts'][$v]) . '</span>' : '' ?></a>
    <?php endforeach ?>
</nav>
