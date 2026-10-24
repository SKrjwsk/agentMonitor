<?php
/**
 * Legenda operacji na plikach (rozwijana) — wspólna dla paneli szczegółów.
 *
 * @var array<string, string> $opLabels
 */

use Viz\Web\App;

?>
<details class="op-legend">
    <summary>what do read / context / search / listing / edit / write / exec mean?</summary>
    <dl class="facts">
        <?php foreach (App::OP_HELP as $op => $help): ?>
            <dt viz-op="<?= h($op) ?>"><?= h($opLabels[$op] ?? $op) ?></dt>
            <dd class="explain"><?= h($help) ?></dd>
        <?php endforeach ?>
    </dl>
</details>
