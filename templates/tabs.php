<?php
/**
 * Zakładki wspólne dla obu zakresów, jeden rząd o jednakowym wyglądzie. W zakresie „all” widoki wymagające pojedynczych wywołań są wyłączone.
 * Licznik tylko tam, gdzie widok go ma (`$view['counts']`).
 *
 * @var array $view @var array $p @var bool $all @var array<string, string> $tabs @var callable $tab
 */

use Viz\Web\App;

$off = static fn (string $v): bool => $all && !in_array($v, App::ALL_VIEWS, true);
?>
<nav class="tabs">
    <?php foreach ($tabs as $v => $label): ?>
        <?php if ($off($v)) { continue; } ?>
        <?php /* Log: licznik = wywołania narzędzi jak „wszystkie wywołania” w selekcie statusu; pliki wczytane przez Claude Code osobno w podpowiedzi */ ?>
        <a href="<?= h($tab($v)) ?>" <?= $p['view'] === $v ? 'viz-current' : '' ?><?= $v === 'log' && ($view['logContext'] ?? 0) > 0 ? ' title="' . th('{n} tool calls + {c} files loaded into context by Claude Code (also listed in the log)', ['n' => $view['counts']['log'], 'c' => $view['logContext']]) . '"' : '' ?>><?= th($label) ?><?= isset($view['counts'][$v]) ? ' <span class="n">' . h($view['counts'][$v]) . '</span>' : '' ?></a>
    <?php endforeach ?>
</nav>
