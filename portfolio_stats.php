<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/stats_view.php';
require_once __DIR__ . '/lib/sync.php';

$cache = atlas_load_portfolio_stats();
if ($cache === null) {
    echo '<!doctype html><meta charset="utf-8"><title>repo-atlas</title>';
    echo '<p>No portfolio stats yet. Run <a href="index.php">Sync</a> first.</p>';
    exit;
}

$n = count($cache['repos'] ?? []);
$at = (string)($cache['computed_at'] ?? '—');
$skipN = count($cache['skipped'] ?? []);
$sub = 'Totals across <strong>' . h((string)$n) . '</strong> mirrored tracked repo'
    . ($n === 1 ? '' : 's')
    . ' · as of <code>' . h($at) . '</code>'
    . ' · Excludes: <code>' . h(implode(' ', STATS_EXCLUDES)) . '</code>';
if ($skipN > 0) $sub .= ' · <span class="empty">' . h((string)$skipN) . ' skipped</span>';

stats_render_page(
    ['title' => 'All tracked repos', 'subtitle' => $sub],
    $cache
);
