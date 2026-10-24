<?php
/**
 * Fragment podmieniany przy odświeżaniu na żywo (Hx, outerHTML).
 *
 * Sygnatura saradom:
 * < viz-session viz-version viz-version-url viz-pick? viz-pev? viz-pcmd? viz-all? viz-live? viz-st viz-se hx-get hx-vals-query="() => Viz.Query(this)">
 *     # viz-all = zakres „wszystkie sesje” (s=all): panel szczegółów dla pliku (pick) albo programu (pcmd)
 *     < input onchange="Viz.LiveToggle(this)">
 *     < select onchange="Viz.Go(this)">          # filtry agent / tura / pochodzenie; wartość opcji = URL budowany przez serwer
 *     < viz-pane="list">                         # lista bieżącego widoku (read / changed / commands / messages / log)
 *         < viz-file="<hash>" onclick="Viz.Open(this, event)" viz-picked?>
 *         < viz-ev="<toolUseId>" onclick="Viz.Open(this, event)" viz-picked?>
 *         < viz-cmd="<program>" onclick="Viz.OpenCmd(this, event)" viz-picked?>   # tylko viz-all
 *     < viz-pane="drawer">                       # panel szczegółów, tylko gdy jest pick albo pev
 *         < button onclick="Viz.Close(this)">
 *     < s-init run="(el) => Viz.Init(el)">
 *
 * @var array $view
 */

use Viz\Clock;
use Viz\Web\App;

if ($view['missing']) {
    ?>
    <section viz-session>
        <p class="empty">No data from source “<?= h($view['p']['src']) ?>” for session <?= h($view['sid']) ?>.
            <?php if ($view['hookAvailable']): ?><a href="/?<?= h(App::query($view, ['src' => 'hook'])) ?>">Show hook data</a><?php endif ?>
        </p>
    </section>
    <?php
    return;
}

$p = $view['p'];
$all = $view['allSessions'];
$cwd = $all ? null : $view['session']->cwd;
$opLabels = App::OP_LABELS;
// panel szczegółów: plik albo wywołanie (sesja), plik albo program (wszystkie sesje)
$drawer = $all
    ? in_array($p['view'], ['read', 'changed', 'commands'], true) && ($p['pick'] !== '' || $p['pcmd'] !== '')
    : in_array($p['view'], ['read', 'changed', 'commands', 'blocked', 'log'], true) && ($p['pick'] !== '' || $p['pev'] !== '');
$tab = static fn (string $v): string => '/?' . App::query($view, ['view' => $v === 'read' ? '' : $v, 'sort' => '', 'pcmd' => '', 'pev' => '', 'pick' => '']);
$tabs = ['read' => 'Files read', 'changed' => 'Files changed', 'commands' => 'Commands', 'blocked' => 'Blocked', 'messages' => 'Messages'];
?>
<section viz-session
         viz-version="<?= h($view['version']) ?>"
         viz-version-url="/version?<?= h(App::query($view)) ?>"
         <?= $p['pick'] !== '' ? 'viz-pick="' . h($p['pick']) . '"' : '' ?>
         <?= $p['pev'] !== '' ? 'viz-pev="' . h($p['pev']) . '"' : '' ?>
         <?= $p['pcmd'] !== '' ? 'viz-pcmd="' . h($p['pcmd']) . '"' : '' ?>
         <?= $all ? 'viz-all' : '' ?>
         <?= $view['live'] ? 'viz-live' : '' ?>
         viz-st="<?= h($p['st']) ?>" viz-se="<?= h($p['se']) ?>"
         viz-sx="<?= h($p['sx']) ?>" viz-sy="<?= h($p['sy']) ?>"
         hx-get="/session?<?= h(App::query($view)) ?>" hx-target="this" hx-swap="outerHTML"
         hx-vals-query="() => Viz.Query(this)">
    <header class="head">
        <?php if ($all): ?>
            <?php require __DIR__ . '/head-all.php' ?>
        <?php else: ?>
            <?php require __DIR__ . '/head-session.php' ?>
        <?php endif ?>
    </header>

    <?php if ($all && !in_array($p['view'], App::ALL_VIEWS, true)): ?>
        <p class="empty">This view needs single calls, messages and times — open one session from the list.</p>
    <?php elseif ($p['view'] === 'graph'): ?>
        <?php require __DIR__ . '/graph.php' ?>
    <?php elseif ($p['view'] === 'timeline'): ?>
        <?php $tl = $view['timeline']; require __DIR__ . '/timeline.php' ?>
    <?php else: ?>
        <div class="body" <?= $drawer ? 'viz-drawer' : '' ?>>
            <div class="list" viz-pane="list">
                <?php match ($p['view']) {
                    'commands' => require __DIR__ . '/commands.php',
                    'blocked' => require __DIR__ . '/blocked.php',
                    'messages' => require __DIR__ . '/messages.php',
                    'log' => require __DIR__ . '/log.php',
                    default => require __DIR__ . '/files.php',
                } ?>
            </div>
            <?php if ($drawer): ?>
                <?php require __DIR__ . ($all ? '/drawer-all.php' : '/drawer.php') ?>
            <?php endif ?>
        </div>
    <?php endif ?>
    <s-init run="(el) => Viz.Init(el)"></s-init>
</section>
