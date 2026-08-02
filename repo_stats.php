<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/stats_view.php';

$repo = trim((string)($_GET['repo'] ?? ''));
if ($repo === '' || !preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repo)) {
    header('Location: index.php');
    exit;
}
if (!in_array($repo, atlas_tracked_repos(), true)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>repo-atlas</title>';
    echo '<p>Repo not in tracked list: <code>' . h($repo) . '</code>. <a href="index.php">Back</a></p>';
    exit;
}

$root = mirror_path($repo);
$gitErr = '';
if (!is_dir($root . '/.git') || !stats_git_ok($root, $gitErr)) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>repo-atlas</title>';
    echo '<p>No mirror for <code>' . h($repo) . '</code>. Sync from the <a href="index.php">index</a> first.</p>';
    if ($gitErr !== '') echo '<p><code>' . h($gitErr) . '</code></p>';
    exit;
}

@set_time_limit(300);
$repoPath = realpath($root) ?: $root;
stats_render_page(
    [
        'title' => $repo,
        'subtitle' => 'Mirror: <code>' . h($repoPath) . '</code> · Excludes: <code>' . h(implode(' ', STATS_EXCLUDES)) . '</code>',
    ],
    stats_collect_repo($root)
);
