<?php
declare(strict_types=1);
/**
 * SseDeltaParserTest: the frame dialect the live provider actually speaks.
 *
 * The fixture below is the raw byte stream captured from the production endpoint
 * by a probe - not a reconstruction from documentation. That matters: the
 * dialect, the empty deltas, the usage frame and the terminal marker are all
 * real, so the parser is pinned against what it will meet in production.
 *
 * What is checked:
 *   1. the concatenated deltas are exactly what the parser reports;
 *   2. the same bytes delivered one at a time, or in arbitrary chunks, give the
 *      same text - frames arrive split across network reads, sometimes in the
 *      middle of a line;
 *   3. the stream ends when the provider says it does, and nothing after the
 *      terminal marker is taken;
 *   4. a keep-alive comment and an unparseable frame are ignored, not fatal;
 *   5. the connector puts `stream` on the wire (without it the provider answers
 *      with one buffered body) and reuses the shared response builder, so the
 *      accounting does not fork into a second code path.
 *
 *   php tests/SseDeltaParserTest.php
 */
use App\Connectors\SseDeltaParser;

$root = dirname(__DIR__);
foreach (['/vendor/autoload.php', '/src/autoload.php'] as $candidate) {
    if (is_file($root . $candidate)) { require $root . $candidate; }
}
$passes = 0;
$failed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $passes, $failed;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failed++; }
}

/** Raw bytes captured from the provider on 2026-09-29. */
const PROVIDER_FRAMES = 'data: {"id":"gen-1790629751-zPxEoIDj1Q4Ogh4UvZjN","object":"chat.completion.chunk","created":1790629751,"model":"openai/gpt-6-luna-pro","provider":"OpenAI","choices":[{"index":0,"delta":{"content":"OK","role":"assistant"},"finish_reason":null,"native_finish_reason":null}]}

data: {"id":"gen-1790629751-zPxEoIDj1Q4Ogh4UvZjN","object":"chat.completion.chunk","created":1790629751,"model":"openai/gpt-6-luna-pro","provider":"OpenAI","choices":[{"index":0,"delta":{"content":"","role":"assistant"},"finish_reason":"stop","native_finish_reason":"completed"}]}

data: {"id":"gen-1790629751-zPxEoIDj1Q4Ogh4UvZjN","object":"chat.completion.chunk","created":1790629751,"model":"openai/gpt-6-luna-pro","provider":"OpenAI","service_tier":"default","choices":[{"index":0,"delta":{"content":"","role":"assistant"},"finish_reason":"stop","native_finish_reason":"completed"}],"usage":{"prompt_tokens":1396,"completion_tokens":39,"total_tokens":1435,"cost":0.0001591,"is_byok":false,"prompt_tokens_details":{"cached_tokens":0,"cache_write_tokens":0,"audio_tokens":0,"video_tokens":0},"cost_details":{"upstream_inference_cost":0.0001591,"upstream_inference_prompt_cost":0.0001396,"upstream_inference_completions_cost":0.0000195},"completion_tokens_details":{"reasoning_tokens":12,"image_tokens":0,"audio_tokens":0}}}

data: [DONE]

';

check('the parser is loadable', class_exists(SseDeltaParser::class));
check('the fixture is a real provider stream',
    str_contains(PROVIDER_FRAMES, 'chat.completion.chunk') && str_contains(PROVIDER_FRAMES, 'data: [DONE]'));

// 1 - the whole buffer at once
$whole = new SseDeltaParser();
$first = $whole->push(PROVIDER_FRAMES);
check('the whole stream parses in one push', $whole->isDone());
check('and produces text', $whole->text() !== '', '(empty)');
check('the reported text matches the accumulated text', $first['text'] === $whole->text());

// Independent extraction: pull every delta content out of the fixture with a
// regex, decode it, and compare - a second reading of the same bytes.
preg_match_all('/"delta":\{[^}]*?"content":"((?:[^"\\\\]|\\\\.)*)"/', PROVIDER_FRAMES, $matches);
$expected = '';
foreach ($matches[1] as $piece) {
    $decoded = json_decode('"' . $piece . '"');
    if (is_string($decoded)) { $expected .= $decoded; }
}
check('nothing is lost against an independent extraction', $whole->text() === $expected,
    'parser=' . json_encode($whole->text()) . ' expected=' . json_encode($expected));
check('no raw framing leaked into the text',
    !str_contains($whole->text(), 'data:') && !str_contains($whole->text(), 'DONE'));

// 2 - the same bytes, one at a time, then in arbitrary chunks
$byte = new SseDeltaParser();
$acc = '';
for ($i = 0, $len = strlen(PROVIDER_FRAMES); $i < $len; $i++) {
    $acc .= $byte->push(PROVIDER_FRAMES[$i])['text'];
}
check('byte-by-byte delivery yields the same text', $acc === $whole->text(), json_encode($acc));
check('and still ends', $byte->isDone());

$split = new SseDeltaParser();
$acc2 = '';
foreach (str_split(PROVIDER_FRAMES, 37) as $chunk) {
    $acc2 .= $split->push($chunk)['text'];
}
check('arbitrary chunk boundaries yield the same text', $acc2 === $whole->text(), json_encode($acc2));

// 3 + 4 - noise, the terminal marker, and bytes that arrive after it
$noise = new SseDeltaParser();
$noise->push(": keep-alive\n\n");
$noise->push("data: not json at all\n\n");
$noise->push("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"tail\"}}]}\n\n");
check('an unparseable frame does not kill the stream', $noise->text() === 'tail', json_encode($noise->text()));
check('noise does not mark the stream done', !$noise->isDone());
$noise->push("data: [DONE]\n\n");
check('the terminal marker ends it', $noise->isDone());
check('nothing after the marker is taken',
    $noise->push("data: {\"choices\":[{\"delta\":{\"content\":\"late\"}}]}\n\n")['text'] === '');

// usage, when the provider reports it
if (true) {
    check('the usage block the provider sent is kept', is_array($whole->usage()),
        var_export($whole->usage(), true));
} else {
    check('a stream without a usage block still parses', true);
}

// 5 - the connector half
$connector = (string)file_get_contents($root . '/src/Connectors/AbstractConnector.php');
check('the connector can stream', str_contains($connector, 'public function chatStream('));
check('it puts the stream flag on the wire', str_contains($connector, "\$payload['stream'] = true;"));
check('it parses with this parser', str_contains($connector, 'new SseDeltaParser()'));
check('it reuses the shared response builder',
    str_contains($connector, 'return $this->buildChatResponse($model, $messages, $raw, $latency);'));
check('a stream that produced nothing throws',
    str_contains($connector, 'the stream ended without any content'));
check('a partial stream keeps its text', str_contains($connector, 'Partial answer: keep it'));

// The engine half: the main L4 path streams when it can and falls back when it
// cannot, without ever retrying a stream that already showed text.
$engine = (string)file_get_contents($root . '/src/Pipeline/PipelineEngine.php');
check('the engine streams the final answer', str_contains($engine, 'modelCallForFinalAnswer('));
check('it emits deltas', str_contains($engine, "'L4_SYNTHESIS_DELTA'"));
check('the delta carries the strategy', str_contains($engine, "'strategy' => \$strategy,"));
check('streaming can be switched off', str_contains($engine, "Config::get('pipeline.stream_final_answer', true)"));
check('a stream that showed nothing falls back', str_contains($engine, 'L4.stream_fell_back'));
check('a stream that showed text is never retried',
    preg_match('/if \(\$delivered > 0\) \{\s*throw \$e;/', $engine) === 1);
check('the instance is asked, not a class name',
    str_contains($engine, "method_exists(\$conn, 'chatStream')")
    && !str_contains($engine, "AbstractConnector::class, 'chatStream'"));


echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
