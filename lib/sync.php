<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/github.php';
require_once __DIR__ . '/stats.php';

/**
 * @return array{repo:string,action:string,ok:bool,message:string,path:string}
 */
function atlas_sync_repo(string $fullName): array {
    atlas_ensure_dirs();
    $path = mirror_path($fullName);
    $token = atlas_token();
    $authUrl = 'https://x-access-token:' . rawurlencode($token) . '@github.com/' . $fullName . '.git';
    $cleanUrl = 'https://github.com/' . $fullName . '.git';
    $env = ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C'];
    $err = '';

    if (is_dir($path . '/.git')) {
        git_run(['remote', 'set-url', 'origin', $authUrl], $path, $err, $env);
        $out = git_run(['fetch', '--all', '--prune'], $path, $err, $env);
        if ($err !== '') {
            git_run(['remote', 'set-url', 'origin', $cleanUrl], $path, $err, $env);
            return ['repo' => $fullName, 'action' => 'fetch', 'ok' => false, 'message' => $err, 'path' => $path];
        }
        $sym = trim(git_run(['symbolic-ref', 'refs/remotes/origin/HEAD'], $path, $err, $env));
        $branch = (preg_match('~refs/remotes/origin/(.+)$~', $sym, $m)) ? $m[1] : 'HEAD';
        git_run(['reset', '--hard', 'origin/' . $branch], $path, $err, $env);
        git_run(['remote', 'set-url', 'origin', $cleanUrl], $path, $err, $env);
        return ['repo' => $fullName, 'action' => 'fetch', 'ok' => true, 'message' => trim($out) ?: 'updated', 'path' => $path];
    }

    if (is_dir($path)) {
        return ['repo' => $fullName, 'action' => 'clone', 'ok' => false, 'message' => 'path exists but is not a git repo', 'path' => $path];
    }

    $out = git_run(['clone', $authUrl, $path], atlas_config()['mirrors_dir'], $err, $env);
    if ($err !== '' || !is_dir($path . '/.git')) {
        return ['repo' => $fullName, 'action' => 'clone', 'ok' => false, 'message' => $err ?: 'clone failed', 'path' => $path];
    }
    git_run(['remote', 'set-url', 'origin', $cleanUrl], $path, $err, $env);
    return ['repo' => $fullName, 'action' => 'clone', 'ok' => true, 'message' => trim($out) ?: 'cloned', 'path' => $path];
}

/**
 * @return array{ok:bool,catalog:array,results:list<array>,error?:string}
 */
function atlas_sync_all(): array {
    atlas_ensure_dirs();
    $tracked = atlas_tracked_repos();
    $results = [];
    foreach ($tracked as $name) {
        $results[] = atlas_sync_repo($name);
    }

    try {
        $all = github_list_owner_repos();
    } catch (Throwable $e) {
        return ['ok' => false, 'catalog' => [], 'results' => $results, 'error' => $e->getMessage()];
    }

    $catalog = ['synced_at' => gmdate('c'), 'tracked' => [], 'available' => []];
    foreach ($tracked as $name) {
        $meta = $all[$name] ?? null;
        if ($meta === null) {
            $catalog['tracked'][] = ['full_name' => $name, 'error' => 'not visible to this token'];
            continue;
        }
        $mirror = mirror_path($name);
        $catalog['tracked'][] = [
            'full_name'   => $name,
            'private'     => (bool)($meta['private'] ?? false),
            'description' => (string)($meta['description'] ?? ''),
            'pushed_at'   => (string)($meta['pushed_at'] ?? ''),
            'html_url'    => (string)($meta['html_url'] ?? ''),
            'language'    => (string)($meta['language'] ?? ''),
            'mirrored'    => is_dir($mirror . '/.git'),
        ];
    }
    foreach ($all as $name => $meta) {
        $catalog['available'][] = [
            'full_name' => $name,
            'private'   => (bool)($meta['private'] ?? false),
            'pushed_at' => (string)($meta['pushed_at'] ?? ''),
            'language'  => (string)($meta['language'] ?? ''),
            'tracked'   => in_array($name, $tracked, true),
        ];
    }

    $cacheFile = atlas_config()['cache_dir'] . '/catalog.json';
    $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($cacheFile, $json) === false) {
        return ['ok' => false, 'catalog' => $catalog, 'results' => $results, 'error' => 'failed writing cache'];
    }

    $roots = [];
    foreach ($tracked as $name) {
        $roots[$name] = mirror_path($name);
    }
    @set_time_limit(600);
    $portfolio = stats_portfolio_aggregate($roots);
    $pFile = atlas_config()['cache_dir'] . '/portfolio_stats.json';
    $pJson = json_encode($portfolio, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($pJson === false || file_put_contents($pFile, $pJson) === false) {
        return ['ok' => false, 'catalog' => $catalog, 'results' => $results, 'error' => 'failed writing portfolio stats'];
    }

    $ok = true;
    foreach ($results as $r) {
        if (!$r['ok']) { $ok = false; break; }
    }
    return ['ok' => $ok, 'catalog' => $catalog, 'results' => $results];
}

/** @return array<string,mixed>|null */
function atlas_load_catalog(): ?array {
    $file = atlas_config()['cache_dir'] . '/catalog.json';
    if (!is_readable($file)) return null;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/** @return array<string,mixed>|null */
function atlas_load_portfolio_stats(): ?array {
    $file = atlas_config()['cache_dir'] . '/portfolio_stats.json';
    if (!is_readable($file)) return null;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
