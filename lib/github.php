<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

/** @return array{ok:bool,status:int,body:mixed,error?:string} */
function github_request(string $method, string $path, ?array $query = null): array {
    $url = 'https://api.github.com' . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . atlas_token(),
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: repo-atlas',
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $cerr ?: 'curl failed'];
    }
    $body = json_decode($raw, true);
    $ok = $status >= 200 && $status < 300;
    return [
        'ok'     => $ok,
        'status' => $status,
        'body'   => $body,
        'error'  => $ok ? null : (is_array($body) ? (string)($body['message'] ?? $raw) : $raw),
    ];
}

/** @return array<string,array<string,mixed>> keyed by full_name */
function github_list_owner_repos(): array {
    $out = [];
    for ($page = 1; $page <= 20; $page++) {
        $res = github_request('GET', '/user/repos', [
            'per_page'    => 100,
            'page'        => $page,
            'affiliation' => 'owner,organization_member',
            'sort'        => 'updated',
        ]);
        if (!$res['ok'] || !is_array($res['body'])) {
            throw new RuntimeException('GitHub /user/repos failed: ' . ($res['error'] ?? 'unknown'));
        }
        if ($res['body'] === []) break;
        foreach ($res['body'] as $r) {
            if (!is_array($r) || empty($r['full_name'])) continue;
            $out[$r['full_name']] = $r;
        }
        if (count($res['body']) < 100) break;
    }
    return $out;
}
