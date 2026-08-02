<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

/** Default path excludes for git pathspecs / ls-files filters. */
const STATS_EXCLUDES = ['vendor', 'node_modules', 'phaser.min.js', 'devassets', 'fonts'];

/** Extensions skipped for line-based "largest files" (case-insensitive). */
const STATS_BINARY_EXTS = [
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'tif', 'tiff', 'avif', 'heic',
    'svg', 'woff', 'woff2', 'ttf', 'otf', 'eot',
    'mp3', 'wav', 'ogg', 'mp4', 'webm', 'mov', 'avi',
    'pdf', 'zip', 'gz', '7z', 'rar', 'wasm', 'bin', 'exe', 'dll', 'so', 'dylib',
    'parquet', 'sqlite', 'db',
];

/** @return string[] */
function stats_pathspec_excludes(): array {
    $out = [];
    foreach (STATS_EXCLUDES as $e) {
        $out[] = ':(exclude)' . $e;
    }
    return $out;
}

function stats_exclude_regex(): string {
    $parts = [];
    foreach (STATS_EXCLUDES as $e) {
        $parts[] = str_contains($e, '.') ? preg_quote($e, '~') : (preg_quote($e, '~') . '/');
    }
    return '~(' . implode('|', $parts) . ')~';
}

/** True if path looks binary by extension or a NUL in the first 8 KiB. */
function stats_is_binaryish(string $relPath, string $absPath): bool {
    $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, STATS_BINARY_EXTS, true)) return true;
    $fp = @fopen($absPath, 'rb');
    if (!$fp) return true;
    $chunk = fread($fp, 8192);
    fclose($fp);
    return $chunk === false || str_contains($chunk, "\0");
}

/** @param string[] $args */
function stats_git(array $args, string $root, ?string &$err = null): string {
    return git_run($args, $root, $err);
}

function stats_git_ok(string $root, ?string &$err = null): bool {
    return trim(stats_git(['rev-parse', '--is-inside-work-tree'], $root, $err)) === 'true';
}

function stats_overview(string $root): array {
    $first = trim(stats_git(['log', '--reverse', '--pretty=format:%ad', '--date=short'], $root));
    $first = $first !== '' ? strtok($first, "\n") : '—';
    $last = trim(stats_git(['log', '-1', '--pretty=format:%ad', '--date=short'], $root));
    $count = (int)trim(stats_git(['rev-list', '--count', 'HEAD'], $root));
    $tracked = 0;
    $re = stats_exclude_regex();
    foreach (preg_split('/\R/', stats_git(['ls-files'], $root), -1, PREG_SPLIT_NO_EMPTY) as $f) {
        if (!preg_match($re, $f)) $tracked++;
    }
    return [
        'first_commit'  => $first ?: '—',
        'last_commit'   => $last !== '' ? $last : '—',
        'total_commits' => $count,
        'tracked_files' => $tracked,
    ];
}

/** @return array{commits_30d:int,commits_year:int} */
function stats_commit_windows(string $root): array {
    return [
        'commits_30d'  => (int)trim(stats_git(['rev-list', '--count', '--since=30 days ago', 'HEAD'], $root)),
        'commits_year' => (int)trim(stats_git(['rev-list', '--count', '--since=1 year ago', 'HEAD'], $root)),
    ];
}

function stats_line_deltas(string $root): array {
    $windows = ['Week' => '1 week ago', 'Month' => '1 month ago', '6 mo' => '6 months ago', 'Year' => '1 year ago'];
    $excludes = stats_pathspec_excludes();
    $out = [];
    foreach ($windows as $label => $since) {
        $raw = stats_git(array_merge(['log', '--since=' . $since, '--pretty=tformat:', '--numstat', '--', '.'], $excludes), $root);
        $add = 0;
        $rem = 0;
        foreach (preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $parts = preg_split('/\s+/', $line, 3);
            if (!$parts || count($parts) < 2) continue;
            if (ctype_digit($parts[0]) && ctype_digit($parts[1])) {
                $add += (int)$parts[0];
                $rem += (int)$parts[1];
            }
        }
        $out[] = ['label' => $label, 'add' => $add, 'rem' => $rem, 'net' => $add - $rem];
    }
    return $out;
}

function stats_commits_by_weekday(string $root): array {
    $raw = stats_git(['log', '--pretty=format:%ad', '--date=format:%u'], $root);
    $counts = array_fill(1, 7, 0);
    $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    foreach (preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        if (!preg_match('/^[1-7]$/', $line)) continue;
        $counts[(int)$line]++;
    }
    $out = [];
    foreach ($counts as $i => $c) {
        $out[] = ['label' => $labels[$i], 'count' => $c];
    }
    return $out;
}

function stats_commits_by_hour(string $root): array {
    $raw = stats_git(['log', '--pretty=format:%ad', '--date=format:%H'], $root);
    $counts = array_fill(0, 24, 0);
    foreach (preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        if (preg_match('/^\d{2}$/', $line)) $counts[(int)$line]++;
    }
    $out = [];
    for ($h = 0; $h < 24; $h++) {
        $out[] = ['label' => sprintf('%02d:00', $h), 'count' => $counts[$h]];
    }
    return $out;
}

/** @return list<array{label:string,value:int}> */
function stats_lines_per_bucket(string $root, string $since, string $datePattern): array {
    $raw = stats_git(array_merge(
        ['log', '--since=' . $since, '--pretty=format:@@@ %ad', '--date=format:' . $datePattern, '--numstat', '--', '.'],
        stats_pathspec_excludes()
    ), $root);
    $totals = [];
    $order = [];
    $bucket = null;
    foreach (preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        if (strncmp($line, '@@@ ', 4) === 0) {
            $bucket = substr($line, 4);
            if (!isset($totals[$bucket])) {
                $totals[$bucket] = 0;
                $order[] = $bucket;
            }
            continue;
        }
        if ($bucket === null) continue;
        $parts = preg_split('/\s+/', $line, 3);
        if (!$parts || count($parts) < 2) continue;
        if (ctype_digit($parts[0]) && ctype_digit($parts[1])) $totals[$bucket] += (int)$parts[0] + (int)$parts[1];
    }
    $out = [];
    foreach (array_reverse($order) as $b) {
        $out[] = ['label' => $b, 'value' => $totals[$b]];
    }
    return $out;
}

function stats_hottest_files(string $root, int $limit = 15): array {
    $re = stats_exclude_regex();
    $counts = [];
    foreach (preg_split('/\R/', stats_git(['log', '--pretty=format:', '--name-only'], $root), -1, PREG_SPLIT_NO_EMPTY) as $f) {
        if ($f === '' || preg_match($re, $f)) continue;
        $counts[$f] = ($counts[$f] ?? 0) + 1;
    }
    arsort($counts);
    $out = [];
    foreach (array_slice($counts, 0, $limit, true) as $f => $c) {
        $out[] = ['file' => $f, 'count' => $c];
    }
    return $out;
}

function stats_largest_files(string $root, int $limit = 15): array {
    $files = stats_git(['ls-files'], $root);
    if ($files === '') return [];
    $re = stats_exclude_regex();
    $base = realpath($root) ?: $root;
    $rows = [];
    foreach (preg_split('/\R/', $files, -1, PREG_SPLIT_NO_EMPTY) as $f) {
        if (preg_match($re, $f)) continue;
        $abs = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f);
        if (!is_file($abs) || stats_is_binaryish($f, $abs)) continue;
        $lines = 0;
        $fp = @fopen($abs, 'rb');
        if ($fp) {
            while (!feof($fp)) {
                $chunk = fread($fp, 65536);
                if ($chunk === false) break;
                $lines += substr_count($chunk, "\n");
            }
            fclose($fp);
        }
        $rows[] = ['file' => $f, 'lines' => $lines];
    }
    usort($rows, static fn($a, $b) => $b['lines'] <=> $a['lines']);
    return array_slice($rows, 0, $limit);
}

function stats_biggest_commits(string $root, int $limit = 10): array {
    $raw = stats_git(array_merge(
        ['log', '--pretty=format:%h__%ad__%s', '--date=short', '--shortstat', '--', '.'],
        stats_pathspec_excludes()
    ), $root);
    $rows = [];
    $pending = null;
    foreach (preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        if (preg_match('/^[0-9a-f]{6,}__/', $line)) {
            $pending = $line;
            continue;
        }
        if (!preg_match('/files? changed/', $line) || !$pending) continue;
        $ins = preg_match('/(\d+)\s+insertion/', $line, $m) ? (int)$m[1] : 0;
        $del = preg_match('/(\d+)\s+deletion/', $line, $m) ? (int)$m[1] : 0;
        $parts = explode('__', $pending, 3);
        if (count($parts) === 3) {
            $rows[] = ['hash' => $parts[0], 'date' => $parts[1], 'msg' => $parts[2], 'lines' => $ins + $del];
        }
        $pending = null;
    }
    usort($rows, static fn($a, $b) => $b['lines'] <=> $a['lines']);
    return array_slice($rows, 0, $limit);
}

function stats_health_markers(string $root): array {
    $raw = stats_git(array_merge(['grep', '-InwE', '(TODO|FIXME|HACK|XXX)', '--'], stats_pathspec_excludes()), $root);
    $tally = ['TODO' => 0, 'FIXME' => 0, 'HACK' => 0, 'XXX' => 0];
    foreach (preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        foreach ($tally as $k => $_v) {
            if (preg_match('/\b' . $k . '\b/', $line)) $tally[$k]++;
        }
    }
    return $tally;
}

function stats_stalest_files(string $root, int $limit = 10): array {
    $files = stats_git(['ls-files'], $root);
    if ($files === '') return [];
    $re = stats_exclude_regex();
    $rows = [];
    foreach (preg_split('/\R/', $files, -1, PREG_SPLIT_NO_EMPTY) as $f) {
        if (preg_match($re, $f)) continue;
        $d = trim(stats_git(['log', '-1', '--pretty=format:%ad', '--date=short', '--', $f], $root));
        if ($d !== '') $rows[] = ['file' => $f, 'date' => $d];
    }
    usort($rows, static fn($a, $b) => strcmp($a['date'], $b['date']));
    return array_slice($rows, 0, $limit);
}

function stats_tokei(string $root): array {
    $bin = '/usr/local/bin/tokei';
    if (!is_executable($bin)) return [];
    $cmd = escapeshellarg($bin) . ' --output json';
    foreach (STATS_EXCLUDES as $e) {
        $cmd .= ' -e ' . escapeshellarg($e);
    }
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($proc)) return [];
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $data = is_string($out) ? json_decode($out, true) : null;
    if (!is_array($data)) return [];
    $rows = [];
    foreach ($data as $lang => $info) {
        if ($lang === 'Total' || !is_array($info)) continue;
        $code = (int)($info['code'] ?? 0);
        $comments = (int)($info['comments'] ?? 0);
        $blanks = (int)($info['blanks'] ?? 0);
        $files = is_array($info['reports'] ?? null) ? count($info['reports']) : 0;
        if ($code + $comments + $blanks === 0 && $files === 0) continue;
        $rows[] = ['lang' => $lang, 'files' => $files, 'code' => $code, 'comments' => $comments, 'blanks' => $blanks];
    }
    usort($rows, static fn($a, $b) => $b['code'] <=> $a['code']);
    return $rows;
}

/** Shared payload shape for repo_stats / portfolio_stats view. */
function stats_collect_repo(string $root): array {
    return [
        'overview'        => stats_overview($root),
        'line_deltas'     => stats_line_deltas($root),
        'weekday'         => stats_commits_by_weekday($root),
        'hourly'          => stats_commits_by_hour($root),
        'lines_per_day'   => stats_lines_per_bucket($root, '30 days ago', '%Y-%m-%d'),
        'lines_per_month' => stats_lines_per_bucket($root, '12 months ago', '%Y-%m'),
        'hottest'         => stats_hottest_files($root),
        'largest'         => stats_largest_files($root),
        'biggest'         => stats_biggest_commits($root),
        'markers'         => stats_health_markers($root),
        'stalest'         => stats_stalest_files($root),
        'tokei'           => stats_tokei($root),
    ];
}

/** @param array<string,string> $rootsByRepo full_name => mirror root */
function stats_portfolio_aggregate(array $rootsByRepo): array {
    $repos = [];
    $byRepo = [];
    $skipped = [];
    $overview = ['first_commit' => '—', 'last_commit' => '—', 'total_commits' => 0, 'tracked_files' => 0];
    $lineDeltas = [];
    $weekday = [];
    $hourly = [];
    $linesPerDay = [];
    $linesPerMonth = [];
    $hottest = [];
    $largest = [];
    $biggest = [];
    $markers = ['TODO' => 0, 'FIXME' => 0, 'HACK' => 0, 'XXX' => 0];
    $stalest = [];
    $tokeiByLang = [];

    foreach ($rootsByRepo as $name => $root) {
        $err = '';
        if (!is_dir($root . '/.git') || !stats_git_ok($root, $err)) {
            $skipped[] = ['repo' => $name, 'reason' => $err !== '' ? $err : 'no mirror'];
            continue;
        }
        try {
            $d = stats_collect_repo($root);
            $windows = stats_commit_windows($root);
        } catch (Throwable $e) {
            $skipped[] = ['repo' => $name, 'reason' => $e->getMessage()];
            continue;
        }
        $repos[] = $name;
        $o = $d['overview'];
        $overview['total_commits'] += (int)$o['total_commits'];
        $overview['tracked_files'] += (int)$o['tracked_files'];
        $fc = (string)$o['first_commit'];
        $lc = (string)$o['last_commit'];
        if ($fc !== '—' && ($overview['first_commit'] === '—' || $fc < $overview['first_commit']))
            $overview['first_commit'] = $fc;
        if ($lc !== '—' && ($overview['last_commit'] === '—' || $lc > $overview['last_commit']))
            $overview['last_commit'] = $lc;

        $byRepo[$name] = [
            'overview'        => $o,
            'commits_30d'     => $windows['commits_30d'],
            'commits_year'    => $windows['commits_year'],
            'line_deltas'     => $d['line_deltas'],
            'weekday'         => $d['weekday'],
            'hourly'          => $d['hourly'],
            'lines_per_day'   => $d['lines_per_day'],
            'lines_per_month' => $d['lines_per_month'],
        ];

        $lineDeltas = stats_merge_line_deltas($lineDeltas, $d['line_deltas']);
        $weekday = stats_merge_count_series($weekday, $d['weekday'], 'count');
        $hourly = stats_merge_count_series($hourly, $d['hourly'], 'count');
        $linesPerDay = stats_merge_bucket_series($linesPerDay, $d['lines_per_day']);
        $linesPerMonth = stats_merge_bucket_series($linesPerMonth, $d['lines_per_month']);

        foreach ($d['hottest'] as $row) {
            $hottest[] = ['file' => $name . ':' . $row['file'], 'count' => $row['count']];
        }
        foreach ($d['largest'] as $row) {
            $largest[] = ['file' => $name . ':' . $row['file'], 'lines' => $row['lines']];
        }
        foreach ($d['stalest'] as $row) {
            $stalest[] = ['file' => $name . ':' . $row['file'], 'date' => $row['date']];
        }
        foreach ($d['biggest'] as $row) {
            $biggest[] = [
                'repo' => $name,
                'hash' => $row['hash'],
                'date' => $row['date'],
                'msg' => $row['msg'],
                'lines' => $row['lines'],
            ];
        }
        foreach ($d['markers'] as $tag => $count) {
            $markers[$tag] = ($markers[$tag] ?? 0) + (int)$count;
        }
        foreach ($d['tokei'] as $row) {
            $lang = $row['lang'];
            if (!isset($tokeiByLang[$lang])) {
                $tokeiByLang[$lang] = ['lang' => $lang, 'files' => 0, 'code' => 0, 'comments' => 0, 'blanks' => 0];
            }
            $tokeiByLang[$lang]['files'] += (int)$row['files'];
            $tokeiByLang[$lang]['code'] += (int)$row['code'];
            $tokeiByLang[$lang]['comments'] += (int)$row['comments'];
            $tokeiByLang[$lang]['blanks'] += (int)$row['blanks'];
        }
    }

    usort($hottest, static fn($a, $b) => $b['count'] <=> $a['count']);
    usort($largest, static fn($a, $b) => $b['lines'] <=> $a['lines']);
    usort($biggest, static fn($a, $b) => $b['lines'] <=> $a['lines']);
    usort($stalest, static fn($a, $b) => strcmp($a['date'], $b['date']));
    $tokei = array_values($tokeiByLang);
    usort($tokei, static fn($a, $b) => $b['code'] <=> $a['code']);

    return [
        'computed_at'     => gmdate('c'),
        'repos'           => $repos,
        'by_repo'         => $byRepo,
        'skipped'         => $skipped,
        'overview'        => $overview,
        'line_deltas'     => $lineDeltas,
        'weekday'         => $weekday,
        'hourly'          => $hourly,
        'lines_per_day'   => $linesPerDay,
        'lines_per_month' => $linesPerMonth,
        'hottest'         => array_slice($hottest, 0, 15),
        'largest'         => array_slice($largest, 0, 15),
        'biggest'         => array_slice($biggest, 0, 10),
        'markers'         => $markers,
        'stalest'         => array_slice($stalest, 0, 10),
        'tokei'           => $tokei,
    ];
}

function stats_merge_line_deltas(array $acc, array $rows): array {
    if (!$acc) return $rows;
    foreach ($rows as $i => $r) {
        if (!isset($acc[$i])) {
            $acc[$i] = $r;
            continue;
        }
        $acc[$i]['add'] += (int)$r['add'];
        $acc[$i]['rem'] += (int)$r['rem'];
        $acc[$i]['net'] = $acc[$i]['add'] - $acc[$i]['rem'];
    }
    return $acc;
}

function stats_merge_count_series(array $acc, array $rows, string $valueKey): array {
    if (!$acc) return $rows;
    foreach ($rows as $i => $r) {
        if (!isset($acc[$i])) {
            $acc[$i] = $r;
            continue;
        }
        $acc[$i][$valueKey] = (int)$acc[$i][$valueKey] + (int)$r[$valueKey];
        if (($acc[$i]['label'] ?? '') === '' || ctype_digit((string)$acc[$i]['label']))
            $acc[$i]['label'] = $r['label'];
    }
    return $acc;
}

function stats_merge_bucket_series(array $acc, array $rows): array {
    $totals = [];
    foreach ($acc as $r) $totals[$r['label']] = (int)$r['value'];
    foreach ($rows as $r) $totals[$r['label']] = ($totals[$r['label']] ?? 0) + (int)$r['value'];
    ksort($totals, SORT_STRING);
    $out = [];
    foreach ($totals as $label => $value) {
        $out[] = ['label' => $label, 'value' => $value];
    }
    return $out;
}
