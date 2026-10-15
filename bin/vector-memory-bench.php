<?php
/**
 * vector-memory-bench.php - offline benchmark for the vector memory
 * layer (Task 5). Measures upsert/search throughput of the in-memory
 * VectorStore driver at 1k / 5k / 20k items without any database or
 * network access, so it runs on any host with PHP 8.4+.
 *
 * Usage: php bin/vector-memory-bench.php [sizes] [dim] [search-iters]
 *   sizes        - optional, comma-separated item counts (default 1000,5000,20000).
 *   dim          - optional vector dimension (default 64). Task-5 reference:
 *                  real embedding models produce 1536-dim vectors, e.g.
 *                  `php bin/vector-memory-bench.php 1000,5000,20000 1536 10`.
 *   search-iters - optional number of search probes per size (default 100).
 *                  Lower it for heavy D x N combinations (e.g. 20k x 1536).
 *
 * Exit code 0 on success, 1 on failure.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use App\Storage\VectorStore;

$EOL  = PHP_EOL;
$sizesArg = $argv[1] ?? '1000,5000,20000';
$sizes    = [];
foreach (explode(',', (string)$sizesArg) as $part) {
    $value = (int)trim($part);
    if ($value > 0) {
        $sizes[] = $value;
    }
}
if ($sizes === []) {
    fwrite(STDERR, 'usage: php bin/vector-memory-bench.php [sizes] [dim] [search-iters]' . $EOL);
    exit(1);
}

$dim         = isset($argv[2]) ? max(1, (int)$argv[2]) : 64;
$searchIters = isset($argv[3]) ? max(1, (int)$argv[3]) : 100;

function randomVector(int $dim): array
{
    $v = [];
    for ($i = 0; $i < $dim; $i++) {
        $v[] = ((float)mt_rand() / (float)mt_getrandmax()) * 2.0 - 1.0;
    }
    return VectorStore::normalizeVector($v);
}

$failed = false;
printf(
    '%-8s | %-14s | %-16s | %-12s | %-12s' . $EOL,
    'size', 'upsert', 'N searches', 'per-search ms', 'top1 precise'
);
printf('%s' . $EOL, str_repeat('-', 78));

foreach ($sizes as $size) {
    $store = new VectorStore(null);
    $t0 = microtime(true);
    for ($id = 1; $id <= $size; $id++) {
        $store->upsert('bench', $id, randomVector($dim), ['n' => $id]);
    }
    $upsertSeconds = microtime(true) - $t0;

    $t1 = microtime(true);
    $hitsCount = 0;
    for ($q = 0; $q < $searchIters; $q++) {
        $hits = $store->search('bench', randomVector($dim), 10);
        $hitsCount += count($hits);
    }
    $searchSeconds = microtime(true) - $t1;

    $precision = 0;
    for ($q = 0; $q < 20; $q++) {
        $probeId   = mt_rand(1, $size);
        $probe     = null;
        $reflection = new ReflectionClass($store);
        $prop      = $reflection->getProperty('memory');
        $prop->setAccessible(true);
        $memory    = $prop->getValue($store);
        if (isset($memory['bench'][$probeId]['vector'])) {
            $probe = $memory['bench'][$probeId]['vector'];
        }
        if ($probe !== null) {
            $hits = $store->search('bench', $probe, 1);
            if (isset($hits[0]['id']) && (int)$hits[0]['id'] === $probeId) {
                $precision++;
            }
        }
    }
    $precise = $precision >= 19;

    if ($hitsCount !== $searchIters * 10) {
        $failed = true;
    }

    printf(
        '%-8d | %10.4f s   | %8d      | %10.3f ms | %s' . $EOL,
        $size,
        $upsertSeconds,
        $searchIters,
        ($searchSeconds / $searchIters) * 1000.0,
        $precise ? 'yes' : 'NO'
    );

    if (!$precise) {
        $failed = true;
    }
}

echo $EOL . 'status: ' . ($failed ? 'FAILED' : 'OK') . $EOL;
exit($failed ? 1 : 0);
