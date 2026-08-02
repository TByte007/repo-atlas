# repo-atlas

Personal multi-repo statistics dashboard: catalog every GitHub repo you own (read-only), keep local working copies updated, and run deep per-repo analysis (commits, line deltas, tokei, TODO markers, etc.).

## Status (v1 bootstrap)

**Data lives outside the web tree** (do not put secrets under the docroot):

| Path | Purpose |
|---|---|
| `../repo-atlas-data/config/github.token` | fine-grained PAT (not in git) |
| `../repo-atlas-data/config/tracked_repos.txt` | which repos to track (`owner/name` per line) |
| `../repo-atlas-data/mirrors/` | local clones |
| `../repo-atlas-data/cache/catalog.json` | last sync catalog |

In-tree `config/` is only examples / placeholders. Override paths via `config/local.php` if needed.

- `index.php` — catalog UI + **Sync tracked repos**
- `bin/sync.php` — same sync from CLI
- `repo_stats.php?repo=owner/name` — deep stats for a tracked mirror (`lib/stats.php`)

Auth: protect the app at the reverse proxy / vhost (e.g. LAN allowlist and/or HTTP basic auth). Do not rely on `.htaccess` if the vhost has `AllowOverride None`.

**Rotate the GitHub PAT** if it was ever pasted into chat or briefly web-served; put the new token only in `../repo-atlas-data/config/github.token`.

## Goal

A **standalone** project that:

1. **Catalogs** all repos on the user’s GitHub account via API (read-only PAT or GitHub App: metadata + contents read). No need to clone just to list repos, stars, languages summary, last push, etc.
2. **Mirrors / updates** local checkouts for repos that need deep stats (`tokei`, TODO/FIXME greps, file sizes, commit rhythm, line deltas). Prefer `git fetch` / shallow update over re-downloading from scratch.
3. **Computes + caches** stats on a schedule or TTL — do not recompute every page load across the whole account.
4. **Serves** a UI that can show:
   - account / portfolio overview (API-backed)
   - per-repo deep stats (mirror-backed)

### Explicit non-goals (for v1)

- Do not require downloading every blob via the Contents API instead of git clones — shallow/full mirrors are the intended path for `tokei`-class tools.
- Do not invent write access to GitHub; read-only is enough.

### Suggested architecture (guidance, not law)

```
repo-atlas/
  README.md                 (this file)
  repo_stats.php            (deep stats view)
  config/                   (token path, exclude lists — secrets not in git)
  mirrors/                  (gitignored local clones)
  cache/ or data/           (gitignored computed JSON / DB)
  sync.php or bin/sync      (API catalog + git fetch worker)
  public/ or index          (web UI)
```

| Layer | Source | Examples |
|---|---|---|
| Portfolio index | GitHub REST/GraphQL | list repos, visibility, pushed_at, size, `/languages` |
| Tree / composition | Local shallow clone + update | `tokei`, largest files, TODO markers |
| History charts | Deeper or full history as needed | commits by hour/weekday, line deltas, hottest files |

### Auth note

Store a fine-grained PAT (or App credentials) **outside the web root**; scopes: repository metadata + contents read. Document rotation in config comments, never commit the token.

## Current collectors (what the PHP already knows how to do — for one local repo)

- Overview: first/last commit, commit count, tracked file count  
- Line deltas (week / month / 6mo / year) with pathspec excludes  
- Commits by weekday / hour  
- Lines changed per day/month buckets  
- Hottest files, largest files, biggest commits, stalest files  
- TODO / FIXME / HACK / XXX counts  
- Optional `tokei` language breakdown + Chart.js UI  

Next work: multi-repo catalog polish, mirrors, cache, then wire UI to cached results.
