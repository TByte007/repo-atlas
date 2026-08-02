<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/stats_view.php';
require_once __DIR__ . '/lib/sync.php';

$cache = atlas_load_portfolio_stats();
if ($cache === null || empty($cache['by_repo'])) {
    echo '<!doctype html><meta charset="utf-8"><title>repo-atlas</title>';
    echo '<p>No compare data yet. Run <a href="index.php">Sync</a> first.</p>';
    exit;
}

$n = count($cache['by_repo']);
$at = (string)($cache['computed_at'] ?? '—');
$skipN = count($cache['skipped'] ?? []);
$sub = 'Activity by repo · <strong>' . h((string)$n) . '</strong> mirrored'
    . ' · as of <code>' . h($at) . '</code>'
    . ' · coldest last-commit first';
if ($skipN > 0) $sub .= ' · <span style="color:#9aa7b5">' . h((string)$skipN) . ' skipped</span>';

stats_render_compare(
    ['title' => 'Activity compare', 'subtitle' => $sub],
    $cache
);
