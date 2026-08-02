<?php

declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function atlas_root(): string {
    return dirname(__DIR__);
}

/**
 * Prefer sibling ../repo-atlas-data (outside the web tree).
 *
 * @return array{token_path:string,tracked_path:string,mirrors_dir:string,cache_dir:string,git_bin:string}
 */
function atlas_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $root = atlas_root();
    $data = dirname($root) . '/repo-atlas-data';
    if (!is_dir($data)) $data = $root;
    $cfg = [
        'token_path'   => $data . '/config/github.token',
        'tracked_path' => $data . '/config/tracked_repos.txt',
        'mirrors_dir'  => $data . '/mirrors',
        'cache_dir'    => $data . '/cache',
        'git_bin'      => '/usr/local/bin/git',
    ];
    $local = $root . '/config/local.php';
    if (is_file($local)) {
        $override = require $local;
        if (is_array($override)) $cfg = array_merge($cfg, $override);
    }
    return $cfg;
}

function atlas_ensure_dirs(): void {
    $c = atlas_config();
    foreach ([$c['mirrors_dir'], $c['cache_dir']] as $d) {
        if (!is_dir($d) && !@mkdir($d, 02775, true)) {
            throw new RuntimeException('Cannot create directory: ' . $d);
        }
    }
}

/** @return string[] owner/name */
function atlas_tracked_repos(): array {
    $path = atlas_config()['tracked_path'];
    if (!is_readable($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $line)) continue;
        $out[$line] = $line;
    }
    return array_values($out);
}

function atlas_repo_name_ok(string $fullName): bool {
    return (bool)preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $fullName);
}

/** @param string[] $repos */
function atlas_write_tracked_repos(array $repos): void {
    $path = atlas_config()['tracked_path'];
    $uniq = [];
    foreach ($repos as $r) {
        $r = trim($r);
        if (!atlas_repo_name_ok($r)) continue;
        $uniq[$r] = $r;
    }
    ksort($uniq, SORT_STRING);
    $body = "# One owner/name per line. Edited by the repo-atlas UI or by hand.\n\n";
    foreach ($uniq as $r) {
        $body .= $r . "\n";
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException('Cannot create config dir: ' . $dir);
    }
    if (file_put_contents($path, $body) === false) {
        throw new RuntimeException('Cannot write tracked list: ' . $path);
    }
}

function atlas_track_repo(string $fullName): void {
    if (!atlas_repo_name_ok($fullName)) throw new InvalidArgumentException('Invalid repo name');
    $list = atlas_tracked_repos();
    if (!in_array($fullName, $list, true)) $list[] = $fullName;
    atlas_write_tracked_repos($list);
    atlas_patch_catalog_tracked();
}

function atlas_untrack_repo(string $fullName): void {
    if (!atlas_repo_name_ok($fullName)) throw new InvalidArgumentException('Invalid repo name');
    atlas_write_tracked_repos(array_values(array_filter(
        atlas_tracked_repos(),
        static fn($r) => $r !== $fullName
    )));
    atlas_patch_catalog_tracked();
}

/** Refresh catalog.json tracked flags after list edits (no GitHub/git I/O). */
function atlas_patch_catalog_tracked(): void {
    $file = atlas_config()['cache_dir'] . '/catalog.json';
    if (!is_readable($file)) return;
    $c = json_decode((string)file_get_contents($file), true);
    if (!is_array($c)) return;
    $tracked = atlas_tracked_repos();
    $set = array_fill_keys($tracked, true);
    $availBy = [];
    foreach ($c['available'] ?? [] as &$a) {
        if (!is_array($a) || empty($a['full_name'])) continue;
        $a['tracked'] = isset($set[$a['full_name']]);
        $availBy[$a['full_name']] = $a;
    }
    unset($a);
    $oldBy = [];
    foreach ($c['tracked'] ?? [] as $t) {
        if (is_array($t) && !empty($t['full_name'])) $oldBy[$t['full_name']] = $t;
    }
    $newTracked = [];
    foreach ($tracked as $name) {
        if (isset($oldBy[$name])) {
            $newTracked[] = $oldBy[$name];
            continue;
        }
        $a = $availBy[$name] ?? null;
        $newTracked[] = [
            'full_name'   => $name,
            'private'     => (bool)($a['private'] ?? false),
            'description' => '',
            'pushed_at'   => (string)($a['pushed_at'] ?? ''),
            'html_url'    => 'https://github.com/' . $name,
            'language'    => (string)($a['language'] ?? ''),
            'mirrored'    => is_dir(mirror_path($name) . '/.git'),
        ];
    }
    $c['tracked'] = $newTracked;
    $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json !== false) @file_put_contents($file, $json);
}

function atlas_token(): string {
    $path = atlas_config()['token_path'];
    if (!is_readable($path)) {
        throw new RuntimeException('Missing or unreadable token file: ' . $path);
    }
    $tok = trim((string)file_get_contents($path));
    if ($tok === '') throw new RuntimeException('Token file is empty: ' . $path);
    return $tok;
}

function git_bin(): string {
    $p = atlas_config()['git_bin'];
    return is_executable($p) ? $p : 'git';
}

/**
 * @param string[] $args
 * @param array<string,string>|null $env
 */
function git_run(array $args, string $cwd, ?string &$err = null, ?array $env = null): string {
    $err = '';
    $cmd = escapeshellarg(git_bin()) . ' -c ' . escapeshellarg('safe.directory=' . $cwd);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        $err = 'proc_open failed';
        return '';
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $errO = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) $err = trim((string)$errO) ?: ('exit code ' . $code);
    return is_string($out) ? $out : '';
}

function mirror_path(string $fullName): string {
    return atlas_config()['mirrors_dir'] . '/' . str_replace('/', '__', $fullName);
}
