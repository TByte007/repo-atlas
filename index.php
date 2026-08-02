<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/sync.php';

$flash = null;
$syncOut = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $repo = trim((string)($_POST['repo'] ?? ''));
    try {
        if ($action === 'sync') {
            @set_time_limit(600);
            $syncOut = atlas_sync_all();
            $flash = $syncOut['ok']
                ? 'Sync finished.'
                : ('Sync finished with errors' . (!empty($syncOut['error']) ? ': ' . $syncOut['error'] : '.'));
        } elseif ($action === 'track') {
            atlas_track_repo($repo);
            $flash = 'Tracked ' . $repo . '. Run Sync to mirror it.';
        } elseif ($action === 'untrack') {
            atlas_untrack_repo($repo);
            $flash = 'Untracked ' . $repo . ' (mirror left on disk).';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        if ($action === 'sync') $syncOut = ['ok' => false, 'results' => []];
    }
}

$catalog = atlas_load_catalog();
$tokenOk = is_readable(atlas_config()['token_path']);
$trackedNames = atlas_tracked_repos();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>repo-atlas</title>
    <style>
        :root {
            --bg: #1a1f24; --surface: #232a31; --text: #e6ebf0; --muted: #9aa7b5;
            --accent: #4a9fd8; --ok: #6fbf7a; --bad: #d97a6c; --border: #34404c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; color: var(--text);
            font: 15px/1.45 "IBM Plex Sans", "Segoe UI", sans-serif;
            background: radial-gradient(900px 420px at 10% -10%, #2b3a44, transparent 60%), var(--bg);
        }
        main { max-width: 1024px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
        h1 { font: 700 2rem/1.1 "IBM Plex Serif", Georgia, serif; margin: 0 0 .35rem; }
        .lead { color: var(--muted); margin: 0 0 1.5rem; max-width: 42rem; }
        .row { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-bottom: 1.25rem; }
        button, a.btn {
            border: 1px solid var(--border); background: var(--accent); color: #0c1a24;
            font: 600 13px/1 "IBM Plex Sans", sans-serif; padding: .55rem .85rem; cursor: pointer;
            text-decoration: none; display: inline-block;
        }
        button.danger { background: var(--bad); color: #1a1010; }
        button.inline, a.btn.inline { padding: .35rem .55rem; font-size: 12px; }
        form.inline { display: inline; margin: 0; }
        .meta, .hint { color: var(--muted); font-size: .9rem; }
        .hint { margin-top: .75rem; font-size: .85rem; }
        .flash { padding: .75rem 1rem; margin-bottom: 1rem; border: 1px solid var(--border); background: var(--surface); }
        table { width: 100%; border-collapse: collapse; background: var(--surface); }
        th, td { text-align: left; padding: .55rem .65rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
        code { font-family: "IBM Plex Mono", ui-monospace, monospace; font-size: .88em; }
        .pill { display: inline-block; padding: .1rem .4rem; border: 1px solid var(--border); color: var(--muted); font-size: .75rem; }
        .ok { color: var(--ok); } .bad { color: var(--bad); }
        .section { margin-top: 2rem; }
        .section h2 { font-size: 1.1rem; margin: 0 0 .75rem; }
        .actions { white-space: nowrap; }
        .actions > a.btn, .actions > form { margin-left: .35rem; vertical-align: middle; }
        .actions > :first-child { margin-left: 0; }
    </style>
</head>
<body>
<main>
    <h1>repo-atlas</h1>
    <p class="lead">GitHub catalog + local mirrors. Track/untrack here (writes <code><?php echo h(basename(atlas_config()['tracked_path'])); ?></code>), then Sync.</p>

    <div class="row">
        <form method="post">
            <input type="hidden" name="action" value="sync">
            <button type="submit">Sync tracked repos</button>
        </form>
        <span class="meta">
            Token: <?php echo $tokenOk ? '<span class="ok">readable</span>' : '<span class="bad">missing</span>'; ?>
            <?php if ($catalog): ?>
                · Last catalog: <code><?php echo h((string)($catalog['synced_at'] ?? '—')); ?></code>
            <?php endif; ?>
        </span>
    </div>

    <?php if ($flash !== null): ?>
        <div class="flash"><?php echo h($flash); ?></div>
    <?php endif; ?>

    <?php if ($syncOut && !empty($syncOut['results'])): ?>
        <div class="section">
            <h2>Sync results</h2>
            <table>
                <thead><tr><th>Repo</th><th>Action</th><th>Status</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($syncOut['results'] as $r): ?>
                    <tr>
                        <td><code><?php echo h($r['repo']); ?></code></td>
                        <td><?php echo h($r['action']); ?></td>
                        <td class="<?php echo $r['ok'] ? 'ok' : 'bad'; ?>"><?php echo $r['ok'] ? 'ok' : 'fail'; ?></td>
                        <td><?php echo h($r['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="section">
        <h2>Tracked <?php if ($trackedNames): ?><a class="btn inline" href="portfolio_stats.php" style="margin-left:.5rem;vertical-align:middle">Portfolio stats</a> <a class="btn inline" href="compare.php" style="margin-left:.35rem;vertical-align:middle">Compare</a><?php endif; ?></h2>
        <?php
        $rows = $catalog['tracked'] ?? [];
        if (!$rows && $trackedNames):
            $rows = array_map(static fn($n) => ['full_name' => $n, 'mirrored' => is_dir(mirror_path($n) . '/.git')], $trackedNames);
        endif;
        if (!$rows): ?>
            <p class="meta">Nothing tracked yet — use Track in the list below (Sync first if empty).</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Repo</th><th>Visibility</th><th>Language</th><th>Pushed</th><th>Mirror</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $name = (string)($r['full_name'] ?? ''); ?>
                    <tr>
                        <td>
                            <code><?php echo h($name); ?></code>
                            <?php if (!empty($r['description'])): ?><div class="meta"><?php echo h($r['description']); ?></div><?php endif; ?>
                            <?php if (!empty($r['error'])): ?><div class="bad"><?php echo h($r['error']); ?></div><?php endif; ?>
                        </td>
                        <td><?php if (isset($r['private'])): ?><span class="pill"><?php echo !empty($r['private']) ? 'private' : 'public'; ?></span><?php endif; ?></td>
                        <td><?php echo h((string)($r['language'] ?? '')); ?></td>
                        <td class="meta"><?php echo h((string)($r['pushed_at'] ?? '')); ?></td>
                        <td class="<?php echo !empty($r['mirrored']) ? 'ok' : 'bad'; ?>"><?php echo !empty($r['mirrored']) ? 'yes' : 'no'; ?></td>
                        <td class="actions">
                            <?php if (!empty($r['mirrored'])): ?>
                                <a class="btn inline" href="repo_stats.php?repo=<?php echo h(rawurlencode($name)); ?>">Stats</a>
                            <?php endif; ?>
                            <?php if (!empty($r['html_url'])): ?>
                                <a class="btn inline" href="<?php echo h($r['html_url']); ?>" rel="noopener">GitHub</a>
                            <?php endif; ?>
                            <form class="inline" method="post">
                                <input type="hidden" name="action" value="untrack">
                                <input type="hidden" name="repo" value="<?php echo h($name); ?>">
                                <button class="danger inline" type="submit">Untrack</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if (!empty($catalog['available'])): ?>
        <div class="section">
            <h2>All visible on this token</h2>
            <table>
                <thead><tr><th>Repo</th><th>Language</th><th>Pushed</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($catalog['available'] as $r):
                    $name = (string)$r['full_name'];
                    $isTracked = !empty($r['tracked']) || in_array($name, $trackedNames, true); ?>
                    <tr>
                        <td><code><?php echo h($name); ?></code></td>
                        <td><?php echo h((string)($r['language'] ?? '')); ?></td>
                        <td class="meta"><?php echo h((string)($r['pushed_at'] ?? '')); ?></td>
                        <td class="actions">
                            <?php if ($isTracked): ?>
                                <form class="inline" method="post">
                                    <input type="hidden" name="action" value="untrack">
                                    <input type="hidden" name="repo" value="<?php echo h($name); ?>">
                                    <button class="danger inline" type="submit">Untrack</button>
                                </form>
                            <?php else: ?>
                                <form class="inline" method="post">
                                    <input type="hidden" name="action" value="track">
                                    <input type="hidden" name="repo" value="<?php echo h($name); ?>">
                                    <button class="inline" type="submit">Track</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="hint">Track adds to the list only; Sync clones/updates mirrors.</p>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
