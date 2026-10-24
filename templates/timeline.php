<?php
/**
 * Oś czasu: tory agentów + prompty + rozmiar kontekstu + raster plik × czas na wspólnej osi X (SVG renderowane przez serwer).
 *
 * Sygnatura:
 * < viz-tl style="--tl-label-w">              # szerokość kolumny etykiet (localStorage viz-tl-label-w)
 *     < viz-pane="tl">                         # przewijanie (x, y) przechodzi przez odświeżenie (Viz.Query: sx, sy)
 *         < viz-tl-labels>                     # kolumna etykiet, sticky
 *             < svg.tl-labels>
 *             < viz-tl-resize onpointerdown/move/up="VizTl.*(this, event)" ondblclick="VizTl.Fit(this)">
 *     < s-init run="(el) => VizTl.Init(el)">
 *         < svg.tl-main>
 *             < rect viz-kind="read|search|list|edit|exec|agent|context|other" viz-failed? viz-blind?>
 *             < circle viz-op="…">            # raster
 *
 * @var array $view
 * @var array $tl
 */

use Viz\Web\App;

$labelW = 230;
$H = $tl['height'];
$W = $tl['width'];
$p = $view['p'];
$kinds = ['read' => 'read', 'search' => 'search', 'list' => 'listing', 'edit' => 'edit', 'exec' => 'Bash without files / program', 'agent' => 'subagent start', 'context' => 'context'];
?>
<div class="tl" viz-tl>
    <div class="tl-bar">
        <?php foreach ($kinds as $kind => $label): ?>
            <span><svg width="14" height="10"><rect width="14" height="10" rx="2" viz-kind="<?= h($kind) ?>"/></svg><?= h($label) ?></span>
        <?php endforeach ?>
        <span><svg width="12" height="12"><circle cx="6" cy="6" r="5" class="prompt"/></svg>prompt</span>
        <span><svg width="12" height="12"><polygon points="6,1 11,11 1,11" class="notify"/></svg>background task</span>
        <span><svg width="12" height="12"><polygon points="6,1 11,11 1,11" class="peer"/></svg>message from agent</span>
        <span class="sep"></span>
        raster rows:
        <?php foreach (['' => 'files', 'dirs' => 'folders', 'commands' => 'commands'] as $rowsKey => $rowsLabel): ?>
            <?php if (($p['rows'] === 'files' ? '' : $p['rows']) === $rowsKey): ?>
                <strong><?= h($rowsLabel) ?></strong>
            <?php else: ?>
                <a href="/?<?= h(App::query($view, ['rows' => $rowsKey])) ?>"><?= h($rowsLabel) ?></a>
            <?php endif ?>
        <?php endforeach ?>
        <span class="muted">· name column width: drag the edge, double-click = fit</span>
    </div>
    <?php if ($tl['empty']): ?>
        <p class="empty">No events.</p>
    <?php else: ?>
    <div class="tl-scroll" viz-pane="tl">
        <div class="tl-grid">
            <div class="tl-labels-wrap" viz-tl-labels>
            <svg class="tl-labels" width="<?= $labelW ?>" height="<?= h($H) ?>">
                <text x="8" y="12" class="axis">time →</text>
                <text x="8" y="28" class="muted-text">prompts</text>
                <?php foreach ($tl['lanes'] as $lane): ?>
                    <circle cx="12" cy="<?= h($lane['y'] + $tl['laneH'] / 2) ?>" r="4" class="ag" viz-agent-color="<?= h($lane['color']) ?>"/>
                    <text x="22" y="<?= h($lane['y'] + $tl['laneH'] / 2 + 4) ?>"><title><?= h($lane['label']) ?></title><?= h(mb_strimwidth($lane['label'], 0, 90, '…')) ?></text>
                <?php endforeach ?>
                <?php if ($tl['context'] !== null): ?>
                    <text x="8" y="<?= h($tl['context']['y'] + 10) ?>">context (tokens)</text>
                    <text x="8" y="<?= h($tl['context']['y'] + 24) ?>" class="muted-text">max <?= h(number_format($tl['context']['max'], 0, ',', ' ')) ?></text>
                <?php endif ?>
                <text x="8" y="<?= h($tl['raster']['top'] - 6) ?>"><?= ($tl['raster']['kind'] ?? '') === 'commands' ? 'commands × time' : 'files × time' ?> (<?= h($tl['raster']['count']) ?>)</text>
                <?php foreach ($tl['raster']['rows'] as $row): ?>
                    <?php if ($row['hash'] !== null): ?>
                        <a href="/?<?= h(App::query($view, ['view' => '', 'pick' => $row['hash']])) ?>">
                            <text x="20" y="<?= h($row['y'] + 10.5) ?>" class="row-file"><title><?= h($row['title']) ?></title><?= h(mb_strimwidth($row['label'], 0, 160, '…')) ?></text>
                        </a>
                    <?php else: ?>
                        <text x="8" y="<?= h($row['y'] + ($row['header'] ? 13 : 10.5)) ?>" class="<?= $row['header'] ? 'row-head' : 'row-dir' ?>"><title><?= h($row['title']) ?></title><?= h(mb_strimwidth($row['label'], 0, 160, '…')) ?></text>
                    <?php endif ?>
                <?php endforeach ?>
            </svg>
            <div class="tl-resize" viz-tl-resize title="drag to resize · double-click = fit to names"
                 onpointerdown="VizTl.Down(this, event)" onpointermove="VizTl.Move(this, event)"
                 onpointerup="VizTl.Up(this, event)" ondblclick="VizTl.Fit(this)"></div>
            </div>
            <svg class="tl-main" width="<?= h($W) ?>" height="<?= h($H) ?>">
                <?php foreach ($tl['axis'] as $tick): ?>
                    <line x1="<?= h($tick['x']) ?>" x2="<?= h($tick['x']) ?>" y1="16" y2="<?= h($H) ?>" class="grid"/>
                    <text x="<?= h($tick['x'] + 3) ?>" y="12" class="axis"><?= h($tick['label']) ?></text>
                <?php endforeach ?>
                <?php foreach ($tl['gaps'] as $gap): ?>
                    <rect x="<?= h($gap['x'] - 9) ?>" y="16" width="18" height="<?= h($H - 16) ?>" class="gap"><title><?= h($gap['label']) ?></title></rect>
                    <text x="<?= h($gap['x']) ?>" y="12" class="gap-label">⋯</text>
                <?php endforeach ?>
                <?php foreach ($tl['lanes'] as $i => $lane): ?>
                    <rect x="0" y="<?= h($lane['y']) ?>" width="<?= h($W) ?>" height="<?= h($tl['laneH']) ?>" class="<?= $i % 2 ? 'stripe' : 'stripe alt' ?>"/>
                <?php endforeach ?>
                <?php foreach ($tl['prompts'] as $pr): ?>
                    <line x1="<?= h($pr['x']) ?>" x2="<?= h($pr['x']) ?>" y1="30" y2="<?= h($H) ?>" class="prompt-line" viz-prompt-kind="<?= h($pr['kind']) ?>"/>
                    <?php if ($pr['kind'] === 'prompt'): ?>
                        <a href="/?<?= h(App::query($view, ['turn' => $view['turn'] === $pr['n'] ? '' : (string) $pr['n']])) ?>">
                            <g class="prompt-mark" <?= $view['turn'] === $pr['n'] ? 'viz-current' : '' ?>><title><?= h('Turn ' . $pr['n'] . ' (click = filter) · ' . $pr['title']) ?></title>
                                <circle cx="<?= h($pr['x']) ?>" cy="24" r="7" class="prompt"/>
                                <text x="<?= h($pr['x']) ?>" y="27.5" class="prompt-n"><?= h($pr['n']) ?></text>
                            </g>
                        </a>
                    <?php elseif ($pr['kind'] === 'compact'): ?>
                        <rect x="<?= h($pr['x'] - 5) ?>" y="19" width="10" height="10" class="compact" transform="rotate(45 <?= h($pr['x']) ?> 24)"><title><?= h($pr['title']) ?></title></rect>
                    <?php else: ?>
                        <polygon points="<?= h(($pr['x'] - 5) . ',29 ' . $pr['x'] . ',19 ' . ($pr['x'] + 5) . ',29') ?>" class="<?= $pr['kind'] === 'peer' ? 'peer' : 'notify' ?>"><title><?= h($pr['title']) ?></title></polygon>
                    <?php endif ?>
                <?php endforeach ?>
                <?php foreach ($tl['spawns'] as $s): ?>
                    <?php $mid = ($s['y0'] + $s['y1']) / 2 ?>
                    <path d="M<?= h($s['x0']) ?>,<?= h($s['y0']) ?> C<?= h($s['x0']) ?>,<?= h($mid) ?> <?= h($s['x1']) ?>,<?= h($mid) ?> <?= h($s['x1']) ?>,<?= h($s['y1']) ?>" class="spawn" viz-agent-color="<?= h($s['color']) ?>"/>
                <?php endforeach ?>
                <?php foreach ($tl['lanes'] as $lane): ?>
                    <?php foreach ($lane['bars'] as $bar): ?>
                        <rect x="<?= h($bar['x']) ?>" y="<?= h($lane['y'] + 4) ?>" width="<?= h($bar['w']) ?>" height="<?= h($tl['laneH'] - 8) ?>" rx="1.5"
                              viz-kind="<?= h($bar['kind']) ?>" <?= $bar['failed'] ? 'viz-failed' : '' ?> <?= $bar['blind'] ? 'viz-blind' : '' ?> <?= $bar['problem'] ? 'viz-problem' : '' ?>><title><?= h($bar['title']) ?></title></rect>
                    <?php endforeach ?>
                <?php endforeach ?>
                <?php if ($tl['context'] !== null): ?>
                    <?php $c = $tl['context'] ?>
                    <rect x="0" y="<?= h($c['y']) ?>" width="<?= h($W) ?>" height="<?= h($c['h']) ?>" class="stripe alt"/>
                    <?php foreach ($c['series'] as $series): ?>
                        <polyline points="<?= h($series['points']) ?>" class="ctx" viz-agent-color="<?= h($series['color']) ?>"/>
                    <?php endforeach ?>
                <?php endif ?>
                <?php foreach ($tl['raster']['rows'] as $i => $row): ?>
                    <?php if (!$row['header'] && $i % 2): ?>
                        <rect x="0" y="<?= h($row['y']) ?>" width="<?= h($W) ?>" height="<?= h($tl['rowH']) ?>" class="stripe alt"/>
                    <?php endif ?>
                    <?php foreach ($row['dots'] as $dot): ?>
                        <circle cx="<?= h($dot['x']) ?>" cy="<?= h($row['y'] + $tl['rowH'] / 2) ?>" r="2.8" viz-op="<?= h($dot['op']) ?>"><title><?= h($dot['title']) ?></title></circle>
                    <?php endforeach ?>
                <?php endforeach ?>
            </svg>
        </div>
    </div>
    <?php endif ?>
    <s-init run="(el) => VizTl.Init(el)"></s-init>
</div>
