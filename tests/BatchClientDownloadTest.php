<?php
declare(strict_types=1);
/**
 * BatchClientDownloadTest: the result download, including the pointer dialect.
 *
 * bothub answers `GET /files/{id}` for a finished batch with a short JSON
 * string holding a signed object-storage URL - a pointer, not the document.
 * These checks drive the client through an offline stub transport and pin
 * the whole contract: a pointer is recognised and followed once (WITHOUT the
 * connector's credentials, because the storage host is not the provider), a
 * failed follow-up degrades to the `/content` route, and every other body -
 * real JSONL, a metadata object - behaves exactly as before.
 *
 *   php tests/BatchClientDownloadTest.php
 */
require __DIR__ . '/../src/autoload.php';

use App\Connectors\Batch\BatchHttpResponse;
use App\Connectors\Batch\BothubBatchClient;
use App\Connectors\BothubConnector;
use App\Core\Config;

$failures = 0;
$passes   = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failures++; }
}

Config::set([
    'app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false],
    'connectors' => [
        'bothub' => ['active' => true, 'api_key' => 'fixture-key', 'base_url' => 'https://provider.test/v1'],
    ],
    'models' => [],
    'pipeline' => [],
]);
$connector = new BothubConnector();

// ---- the detector ------------------------------------------------------------
check('a JSON-quoted URL is a pointer',
    BothubBatchClient::indirectDocumentUrl('"https://files.test/a.jsonl"') === 'https://files.test/a.jsonl');
check('a bare URL is a pointer',
    BothubBatchClient::indirectDocumentUrl("  https://files.test/a.jsonl \n") === 'https://files.test/a.jsonl');
check('a JSONL line is not a pointer', BothubBatchClient::indirectDocumentUrl('{"custom_id":"x"}') === null);
check('a quoted non-URL string is not a pointer', BothubBatchClient::indirectDocumentUrl('"hello"') === null);
check('an over-long body is never a pointer',
    BothubBatchClient::indirectDocumentUrl('"https://files.test/' . str_repeat('a', 2100) . '.jsonl"') === null);
check('an empty body is not a pointer', BothubBatchClient::indirectDocumentUrl('') === null);

// ---- a pointer is followed, without credentials ------------------------------
$calls = [];
$stub = function (string $method, string $url, array $headers, ?array $json = null, ?array $multipart = null, int $timeout = 60) use (&$calls): BatchHttpResponse {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
    if ($url === 'https://provider.test/v1/files/file-1') {
        return new BatchHttpResponse(200, '"https://files.test/out.jsonl"', $url, $method);
    }
    if ($url === 'https://files.test/out.jsonl') {
        return new BatchHttpResponse(200, "{\"custom_id\":\"a\"}\n", $url, $method);
    }
    return new BatchHttpResponse(404, 'not scripted', $url, $method);
};
$client = new BothubBatchClient($connector, $stub);
$download = $client->downloadResult('file-1');
check('the pointer is followed to the document',
    ($download['ok'] ?? false) === true && ($download['content'] ?? '') === "{\"custom_id\":\"a\"}\n",
    json_encode($download));
check('the result says where it really came from',
    ($download['indirect'] ?? false) === true && ($download['path'] ?? '') === 'https://files.test/out.jsonl');
check('exactly two requests were made', count($calls) === 2, (string)count($calls));
check('the provider call carries the connector credentials',
    str_contains(implode("\n", $calls[0]['headers'] ?? []), 'fixture-key'));
check('the storage host never sees the API key',
    !str_contains(implode("\n", $calls[1]['headers'] ?? []), 'fixture-key'),
    implode(' | ', $calls[1]['headers'] ?? []));
check('and the pointer request used GET',
    ($calls[1]['method'] ?? '') === 'GET');

// ---- a failed follow-up degrades to the /content route -----------------------
$calls2 = [];
$stub2 = function (string $method, string $url, array $headers, ?array $json = null, ?array $multipart = null, int $timeout = 60) use (&$calls2): BatchHttpResponse {
    $calls2[] = $url;
    if (str_contains($url, '/files/file-2/content')) {
        return new BatchHttpResponse(200, "{\"custom_id\":\"b\"}\n", $url, $method);
    }
    if (str_contains($url, '/files/file-2')) {
        return new BatchHttpResponse(200, '"https://files.test/gone.jsonl"', $url, $method);
    }
    if ($url === 'https://files.test/gone.jsonl') {
        return new BatchHttpResponse(500, 'storage exploded', $url, $method);
    }
    return new BatchHttpResponse(404, 'not scripted', $url, $method);
};
$client2 = new BothubBatchClient($connector, $stub2);
$download2 = $client2->downloadResult('file-2');
check('the /content route still supplies the document',
    ($download2['ok'] ?? false) === true && ($download2['content'] ?? '') === "{\"custom_id\":\"b\"}\n",
    json_encode($download2));
check('which took three requests (files, pointer attempt, content)', count($calls2) === 3, (string)count($calls2));

// ---- plain JSONL on the first route stays untouched --------------------------
$calls3 = [];
$stub3 = function (string $method, string $url, array $headers, ?array $json = null, ?array $multipart = null, int $timeout = 60) use (&$calls3): BatchHttpResponse {
    $calls3[] = $url;
    return new BatchHttpResponse(200, "{\"custom_id\":\"c\"}\n", $url, $method);
};
$client3 = new BothubBatchClient($connector, $stub3);
$download3 = $client3->downloadResult('file-3');
check('a real document is returned as-is',
    ($download3['ok'] ?? false) === true && ($download3['content'] ?? '') === "{\"custom_id\":\"c\"}\n"
    && !isset($download3['indirect']));
check('with no extra hop', count($calls3) === 1, (string)count($calls3));

// ---- the metadata-object fallback is unchanged -------------------------------
$calls4 = [];
$stub4 = function (string $method, string $url, array $headers, ?array $json = null, ?array $multipart = null, int $timeout = 60) use (&$calls4): BatchHttpResponse {
    $calls4[] = $url;
    if (str_contains($url, '/content')) {
        return new BatchHttpResponse(200, "{\"custom_id\":\"d\"}\n", $url, $method);
    }
    return new BatchHttpResponse(200, '{"object":"file","id":"file-4","bytes":1,"filename":"x","purpose":"batch"}', $url, $method);
};
$client4 = new BothubBatchClient($connector, $stub4);
$download4 = $client4->downloadResult('file-4');
check('a metadata object still falls back to /content',
    ($download4['ok'] ?? false) === true && ($download4['content'] ?? '') === "{\"custom_id\":\"d\"}\n"
    && count($calls4) === 2);

echo "\n----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
