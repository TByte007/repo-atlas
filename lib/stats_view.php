<?php

declare(strict_types=1);

require_once __DIR__ . '/stats.php';

/**
 * @param array{title:string,subtitle:string} $meta
 * @param array<string,mixed> $data shared payload from stats_collect_repo / portfolio cache
 */
function stats_render_page(array $meta, array $data): void {
    $overview      = $data['overview'];
    $lineDeltas    = $data['line_deltas'];
    $weekday       = $data['weekday'];
    $hourly        = $data['hourly'];
    $linesPerDay   = $data['lines_per_day'];
    $linesPerMonth = $data['lines_per_month'];
    $hottest       = $data['hottest'];
    $largest       = $data['largest'];
    $biggest       = $data['biggest'];
    $markers       = $data['markers'];
    $stalest       = $data['stalest'];
    $tokei         = $data['tokei'];
    $showRepoCol   = $biggest && isset($biggest[0]['repo']);
    $linguistColors = json_decode((string)@file_get_contents(__DIR__ . '/linguist_colors.json'), true) ?: [];
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($meta['title']); ?> — stats</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #1a1f24; --surface: #232a31; --text: #e6ebf0; --muted: #9aa7b5;
            --accent: #6db3c9; --border: #34404c; --ok: #6fbf7a; --bad: #d97a6c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; color: var(--text);
            font: 15px/1.45 "IBM Plex Sans", "Segoe UI", sans-serif;
            background: radial-gradient(900px 420px at 10% -10%, #2b3a44, transparent 60%), var(--bg);
        }
        main { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
        h1 { font: 700 1.75rem/1.15 "IBM Plex Serif", Georgia, serif; margin: 0 0 .35rem; }
        h2 { font-size: 1.05rem; margin: 1.75rem 0 .75rem; }
        a.btn {
            border: 1px solid var(--border); background: transparent; color: var(--muted);
            font: 600 12px/1 "IBM Plex Sans", sans-serif; padding: .35rem .55rem;
            text-decoration: none; display: inline-block;
        }
        .meta { color: var(--muted); font-size: .9rem; margin: 0 0 1.25rem; }
        code { font-family: "IBM Plex Mono", ui-monospace, monospace; font-size: .88em; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .75rem; margin-bottom: 1rem; }
        .kpi { background: var(--surface); border: 1px solid var(--border); padding: .85rem 1rem; }
        .kpi b { display: block; font-size: 1.35rem; }
        .kpi span { color: var(--muted); font-size: .8rem; }
        table { width: 100%; border-collapse: collapse; background: var(--surface); margin-bottom: 1rem; }
        th, td { text-align: left; padding: .5rem .65rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem; margin: 1rem 0; }
        .card { background: var(--surface); border: 1px solid var(--border); padding: .85rem 1rem 1rem; }
        .card h3 { margin: 0 0 .65rem; font-size: .95rem; }
        .chart { position: relative; height: 260px; }
        .chart.tall { height: 320px; }
        .bars { max-width: 900px; }
        .bar { display: flex; align-items: center; gap: .5rem; margin: .35rem 0; }
        .bar label { flex: 0 0 16rem; font-family: "IBM Plex Mono", monospace; font-size: .8rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .bar i { flex: 1; height: 10px; background: #2a343e; display: block; }
        .bar i b { display: block; height: 100%; background: var(--accent); }
        .bar em { flex: 0 0 3rem; text-align: right; font-style: normal; color: var(--muted); font-size: .85rem; }
        .msg { max-width: 28rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .empty { color: var(--muted); }
    </style>
</head>
<body>
<main>
    <p class="meta"><a class="btn" href="index.php">&larr; repo-atlas</a></p>
    <h1><?php echo h($meta['title']); ?></h1>
    <p class="meta"><?php echo $meta['subtitle']; ?></p>

    <h2>Age &amp; activity</h2>
    <div class="kpis">
        <div class="kpi"><b><?php echo h(number_format($overview['total_commits'])); ?></b><span>Total commits</span></div>
        <div class="kpi"><b><?php echo h(number_format($overview['tracked_files'])); ?></b><span>Tracked files</span></div>
        <div class="kpi"><b><?php echo h($overview['first_commit']); ?></b><span>First commit</span></div>
        <div class="kpi"><b><?php echo h($overview['last_commit']); ?></b><span>Last commit</span></div>
    </div>

    <h2>Line deltas</h2>
    <table style="max-width:520px">
        <thead><tr><th>Window</th><th>Added</th><th>Removed</th><th>Net</th></tr></thead>
        <tbody>
        <?php foreach ($lineDeltas as $d): ?>
            <tr>
                <td><?php echo h($d['label']); ?></td>
                <td style="color:var(--ok)">+<?php echo h(number_format($d['add'])); ?></td>
                <td style="color:var(--bad)">-<?php echo h(number_format($d['rem'])); ?></td>
                <td><?php echo ($d['net'] >= 0 ? '+' : '') . h(number_format($d['net'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="grid">
        <div class="card"><h3>Lines changed / day (30d)</h3><div class="chart"><canvas id="chartDaily"></canvas></div></div>
        <div class="card"><h3>Lines changed / month (12mo)</h3><div class="chart"><canvas id="chartMonthly"></canvas></div></div>
        <div class="card"><h3>Commits by weekday</h3><div class="chart"><canvas id="chartWeekday"></canvas></div></div>
        <div class="card"><h3>Commits by hour</h3><div class="chart"><canvas id="chartHour"></canvas></div></div>
    </div>

    <?php if ($tokei): ?>
        <div class="grid">
            <div class="card"><h3>Code by language</h3><div class="chart tall"><canvas id="chartTokei"></canvas></div></div>
            <div class="card">
                <h3>Language details</h3>
                <table>
                    <thead><tr><th>Language</th><th>Files</th><th>Code</th><th>Comments</th><th>Blanks</th></tr></thead>
                    <tbody>
                    <?php foreach ($tokei as $row): ?>
                        <tr>
                            <td><?php echo h($row['lang']); ?></td>
                            <td><?php echo h(number_format($row['files'])); ?></td>
                            <td><?php echo h(number_format($row['code'])); ?></td>
                            <td><?php echo h(number_format($row['comments'])); ?></td>
                            <td><?php echo h(number_format($row['blanks'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <p class="empty"><em>tokei</em> not available — language breakdown skipped.</p>
    <?php endif; ?>

    <h2>Hottest files</h2>
    <?php if (!$hottest): ?>
        <p class="empty">No data.</p>
    <?php else:
        $maxHot = max(1, $hottest[0]['count']); ?>
        <div class="bars">
            <?php foreach ($hottest as $row): ?>
                <div class="bar">
                    <label title="<?php echo h($row['file']); ?>"><?php echo h($row['file']); ?></label>
                    <i><b style="width:<?php echo (int)round($row['count'] / $maxHot * 100); ?>%"></b></i>
                    <em><?php echo h(number_format($row['count'])); ?></em>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2>Largest files</h2>
    <table>
        <thead><tr><th>Lines</th><th>File</th></tr></thead>
        <tbody>
        <?php foreach ($largest as $row): ?>
            <tr><td><?php echo h(number_format($row['lines'])); ?></td><td><code><?php echo h($row['file']); ?></code></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Biggest commits</h2>
    <table>
        <thead><tr><th>Lines</th><?php if ($showRepoCol): ?><th>Repo</th><?php endif; ?><th>Hash</th><th>Date</th><th>Subject</th></tr></thead>
        <tbody>
        <?php foreach ($biggest as $row): ?>
            <tr>
                <td><?php echo h(number_format($row['lines'])); ?></td>
                <?php if ($showRepoCol): ?><td><code><?php echo h((string)$row['repo']); ?></code></td><?php endif; ?>
                <td><code><?php echo h($row['hash']); ?></code></td>
                <td><?php echo h($row['date']); ?></td>
                <td class="msg" title="<?php echo h($row['msg']); ?>"><?php echo h($row['msg']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Code-health markers</h2>
    <div class="kpis" style="max-width:640px">
        <?php foreach ($markers as $tag => $count): ?>
            <div class="kpi"><b><?php echo h(number_format($count)); ?></b><span><?php echo h($tag); ?></span></div>
        <?php endforeach; ?>
    </div>

    <h2>Stalest files</h2>
    <table>
        <thead><tr><th>Last change</th><th>File</th></tr></thead>
        <tbody>
        <?php foreach ($stalest as $row): ?>
            <tr><td><?php echo h($row['date']); ?></td><td><code><?php echo h($row['file']); ?></code></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
<script>
(function () {
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#6db3c9';
    const muted = getComputedStyle(document.documentElement).getPropertyValue('--muted').trim() || '#9aa7b5';
    const border = getComputedStyle(document.documentElement).getPropertyValue('--border').trim() || '#34404c';
    Chart.defaults.color = muted;
    Chart.defaults.borderColor = border;

    function lineChart(id, labels, values, label) {
        const el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'line',
            data: { labels, datasets: [{ label, data: values, fill: true, backgroundColor: accent + '33', borderColor: accent, pointRadius: 2, tension: .25 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } },
        });
    }
    function barChart(id, labels, values) {
        const el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'bar',
            data: { labels, datasets: [{ data: values, backgroundColor: accent, borderRadius: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } },
        });
    }
    const linguist = <?php echo json_encode($linguistColors, JSON_UNESCAPED_SLASHES); ?>;
    function langColor(name) {
        if (linguist[name]) return linguist[name];
        let h = 0;
        for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
        return 'hsl(' + (h % 360) + ' 45% 55%)';
    }
    function doughnut(id, labels, values) {
        const el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'doughnut',
            data: { labels, datasets: [{ data: values, backgroundColor: labels.map(langColor), borderWidth: 1 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12 } } } },
        });
    }

    const daily = <?php echo json_encode($linesPerDay, JSON_UNESCAPED_SLASHES); ?>;
    lineChart('chartDaily', daily.map(d => d.label), daily.map(d => d.value), 'Lines');
    const monthly = <?php echo json_encode($linesPerMonth, JSON_UNESCAPED_SLASHES); ?>;
    lineChart('chartMonthly', monthly.map(d => d.label), monthly.map(d => d.value), 'Lines');
    const weekday = <?php echo json_encode($weekday, JSON_UNESCAPED_SLASHES); ?>;
    barChart('chartWeekday', weekday.map(d => d.label), weekday.map(d => d.count));
    const hourly = <?php echo json_encode($hourly, JSON_UNESCAPED_SLASHES); ?>;
    barChart('chartHour', hourly.map(d => d.label), hourly.map(d => d.count));
    const tokei = <?php echo json_encode($tokei, JSON_UNESCAPED_SLASHES); ?>;
    if (tokei && tokei.length) doughnut('chartTokei', tokei.map(r => r.lang), tokei.map(r => r.code));
})();
</script>
</body>
</html>
<?php
}

/**
 * @param array{title:string,subtitle:string} $meta
 * @param array<string,mixed> $cache portfolio_stats payload with by_repo
 */
function stats_render_compare(array $meta, array $cache): void {
    $byRepo = $cache['by_repo'] ?? [];
    if (!is_array($byRepo) || $byRepo === []) {
        echo '<!doctype html><meta charset="utf-8"><title>repo-atlas</title>';
        echo '<p>No per-repo compare data yet. Run <a href="index.php">Sync</a> again.</p>';
        return;
    }

    $rows = [];
    foreach ($byRepo as $name => $slice) {
        if (!is_array($slice)) continue;
        $month = ['add' => 0, 'rem' => 0, 'net' => 0];
        $yearNet = 0;
        foreach ($slice['line_deltas'] ?? [] as $d) {
            if (($d['label'] ?? '') === 'Month') {
                $month = ['add' => (int)$d['add'], 'rem' => (int)$d['rem'], 'net' => (int)$d['net']];
            }
            if (($d['label'] ?? '') === 'Year') $yearNet = (int)$d['net'];
        }
        $rows[] = [
            'name'         => (string)$name,
            'last_commit'  => (string)($slice['overview']['last_commit'] ?? '—'),
            'commits_30d'  => (int)($slice['commits_30d'] ?? 0),
            'month'        => $month,
            'year_net'     => $yearNet,
            'weekday'      => $slice['weekday'] ?? [],
            'hourly'       => $slice['hourly'] ?? [],
            'lines_per_day'=> $slice['lines_per_day'] ?? [],
            'lines_per_month' => $slice['lines_per_month'] ?? [],
        ];
    }
    usort($rows, static function ($a, $b) {
        $da = $a['last_commit'] === '—' ? '0000-00-00' : $a['last_commit'];
        $db = $b['last_commit'] === '—' ? '0000-00-00' : $b['last_commit'];
        $c = strcmp($da, $db);
        return $c !== 0 ? $c : strcmp($a['name'], $b['name']);
    });

    $chartPayload = [];
    foreach ($rows as $r) {
        $short = str_contains($r['name'], '/') ? substr(strrchr($r['name'], '/'), 1) : $r['name'];
        $chartPayload[] = [
            'name'  => $r['name'],
            'short' => $short,
            'daily' => $r['lines_per_day'],
            'monthly' => $r['lines_per_month'],
            'weekday' => $r['weekday'],
            'hourly' => $r['hourly'],
        ];
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($meta['title']); ?> — compare</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #1a1f24; --surface: #232a31; --text: #e6ebf0; --muted: #9aa7b5;
            --accent: #6db3c9; --border: #34404c; --ok: #6fbf7a; --bad: #d97a6c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; color: var(--text);
            font: 15px/1.45 "IBM Plex Sans", "Segoe UI", sans-serif;
            background: radial-gradient(900px 420px at 10% -10%, #2b3a44, transparent 60%), var(--bg);
        }
        main { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
        h1 { font: 700 1.75rem/1.15 "IBM Plex Serif", Georgia, serif; margin: 0 0 .35rem; }
        h2 { font-size: 1.05rem; margin: 1.75rem 0 .75rem; }
        a.btn {
            border: 1px solid var(--border); background: transparent; color: var(--muted);
            font: 600 12px/1 "IBM Plex Sans", sans-serif; padding: .35rem .55rem;
            text-decoration: none; display: inline-block;
        }
        .meta { color: var(--muted); font-size: .9rem; margin: 0 0 1.25rem; }
        code { font-family: "IBM Plex Mono", ui-monospace, monospace; font-size: .88em; }
        table { width: 100%; border-collapse: collapse; background: var(--surface); margin-bottom: 1rem; }
        th, td { text-align: left; padding: .5rem .65rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
        .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem; margin: 1rem 0; }
        .card { background: var(--surface); border: 1px solid var(--border); padding: .85rem 1rem 1rem; }
        .card h3 { margin: 0 0 .65rem; font-size: .95rem; }
        .chart { position: relative; height: 280px; }
        .ok { color: var(--ok); } .bad { color: var(--bad); }
    </style>
</head>
<body>
<main>
    <p class="meta"><a class="btn" href="index.php">&larr; repo-atlas</a>
        <a class="btn" href="portfolio_stats.php" style="margin-left:.35rem">Portfolio stats</a></p>
    <h1><?php echo h($meta['title']); ?></h1>
    <p class="meta"><?php echo $meta['subtitle']; ?></p>

    <h2>Coldest first</h2>
    <table>
        <thead>
            <tr>
                <th>Repo</th>
                <th>Last commit</th>
                <th>Commits 30d</th>
                <th>Lines ± 30d</th>
                <th>Net year</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $m = $r['month'];
            $netYear = $r['year_net']; ?>
            <tr>
                <td><code><?php echo h($r['name']); ?></code></td>
                <td class="num"><?php echo h($r['last_commit']); ?></td>
                <td class="num"><?php echo h(number_format($r['commits_30d'])); ?></td>
                <td class="num">
                    <span class="ok">+<?php echo h(number_format($m['add'])); ?></span>
                    /
                    <span class="bad">-<?php echo h(number_format($m['rem'])); ?></span>
                    /
                    <?php echo ($m['net'] >= 0 ? '+' : '') . h(number_format($m['net'])); ?>
                </td>
                <td class="num"><?php echo ($netYear >= 0 ? '+' : '') . h(number_format($netYear)); ?></td>
                <td><a class="btn" href="repo_stats.php?repo=<?php echo h(rawurlencode($r['name'])); ?>">Stats</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="grid">
        <div class="card"><h3>Lines changed / day (30d)</h3><div class="chart"><canvas id="chartDaily"></canvas></div></div>
        <div class="card"><h3>Lines changed / month (12mo)</h3><div class="chart"><canvas id="chartMonthly"></canvas></div></div>
        <div class="card"><h3>Commits by weekday</h3><div class="chart"><canvas id="chartWeekday"></canvas></div></div>
        <div class="card"><h3>Commits by hour</h3><div class="chart"><canvas id="chartHour"></canvas></div></div>
    </div>
</main>
<script>
(function () {
    const muted = getComputedStyle(document.documentElement).getPropertyValue('--muted').trim() || '#9aa7b5';
    const border = getComputedStyle(document.documentElement).getPropertyValue('--border').trim() || '#34404c';
    Chart.defaults.color = muted;
    Chart.defaults.borderColor = border;

    // High-contrast categorical hues (dark bg); assigned by series index, not name hash.
    const palette = [
        '#4fc3f7', '#ff8a65', '#81c784', '#ce93d8',
        '#ffd54f', '#ef5350', '#26a69a', '#ffb74d',
        '#7986cb', '#aed581', '#f06292', '#4db6ac',
    ];
    function colorFor(_name, i) {
        return palette[i % palette.length];
    }

    function unionLabels(seriesList, key) {
        const set = {};
        seriesList.forEach(s => (s[key] || []).forEach(p => { set[p.label] = true; }));
        return Object.keys(set).sort();
    }
    function valuesOnAxis(points, labels, valueKey) {
        const map = {};
        (points || []).forEach(p => { map[p.label] = p[valueKey]; });
        return labels.map(l => map[l] ?? 0);
    }

    const repos = <?php echo json_encode($chartPayload, JSON_UNESCAPED_SLASHES); ?>;

    function multiLine(id, labels, valueKey, seriesKey) {
        const el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'line',
            data: {
                labels,
                datasets: repos.map((r, i) => ({
                    label: r.short,
                    data: valuesOnAxis(r[seriesKey], labels, valueKey),
                    borderColor: colorFor(r.name, i),
                    backgroundColor: 'transparent',
                    pointRadius: 1.5,
                    tension: .25,
                })),
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                scales: { y: { beginAtZero: true }, x: { grid: { display: false } } },
            },
        });
    }
    function multiBar(id, labels, valueKey, seriesKey) {
        const el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'bar',
            data: {
                labels,
                datasets: repos.map((r, i) => ({
                    label: r.short,
                    data: labels.map((_, idx) => {
                        const pts = r[seriesKey] || [];
                        return pts[idx] ? pts[idx][valueKey] : 0;
                    }),
                    backgroundColor: colorFor(r.name, i),
                    borderRadius: 2,
                })),
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                scales: { y: { beginAtZero: true }, x: { grid: { display: false } } },
            },
        });
    }

    const dayLabels = unionLabels(repos, 'daily');
    const monthLabels = unionLabels(repos, 'monthly');
    function seriesLabels(key) {
        for (const r of repos) {
            const pts = r[key] || [];
            if (pts.length && !/^\d+$/.test(String(pts[0].label))) return pts.map(d => d.label);
        }
        return unionLabels(repos, key);
    }
    const weekdayLabels = seriesLabels('weekday');
    const hourLabels = seriesLabels('hourly');

    multiLine('chartDaily', dayLabels, 'value', 'daily');
    multiLine('chartMonthly', monthLabels, 'value', 'monthly');
    multiBar('chartWeekday', weekdayLabels, 'count', 'weekday');
    multiBar('chartHour', hourLabels, 'count', 'hourly');
})();
</script>
</body>
</html>
<?php
}
