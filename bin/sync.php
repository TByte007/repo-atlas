#!/usr/local/bin/php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/sync.php';

try {
    $out = atlas_sync_all();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($out['results'] as $r) {
    $flag = $r['ok'] ? 'OK' : 'FAIL';
    echo $flag . "\t" . $r['action'] . "\t" . $r['repo'] . "\t" . $r['message'] . "\n";
}
if (!empty($out['error'])) {
    fwrite(STDERR, 'ERROR: ' . $out['error'] . "\n");
    exit(1);
}
echo 'catalog: ' . atlas_config()['cache_dir'] . "/catalog.json\n";
exit($out['ok'] ? 0 : 2);
