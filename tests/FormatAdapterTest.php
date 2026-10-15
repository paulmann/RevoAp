<?php
/**
 * FormatAdapterTest: offline unit checks for the external REST API
 * format adapter (Task: REST API, Step 3).
 *
 * Runs without PHPUnit and without network access:
 * `php tests/FormatAdapterTest.php`.
 *
 * Verified:
 *   - detectFormat heuristic (native query key, OpenAI messages,
 *     Anthropic messages + system) and hint override;
 *   - native request passes through untouched (minus transport flag)
 *     and its payload shape matches JobService::createJob() input;
 *   - OpenAI request mapping: query=last user message, model->
 *     selected_models [{provider,model}], conversation history and
 *     transport options preserved under request_meta (scalar-only,
 *     JSON-encoded where needed);
 *   - Anthropic request mapping incl. block-list content flattening,
 *     system prompt, max_tokens;
 *   - round-trip runResponse for native/OpenAI/Anthropic formats;
 *   - errorResponse shapes match the OpenAI/Anthropic SDK contracts;
 *   - invalid requests throw InvalidArgumentException.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Api\FormatAdapter;

$f = new FormatAdapter();
$failures = 0;
$passes   = 0;

function checkFormat(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failures++;
    }
}

// --- Format detection ---------------------------------
checkFormat('detect: native query key',
    $f->detectFormat(['query' => 'x']) === 'native');
checkFormat('detect: OpenAI messages',
    $f->detectFormat(['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'hi']]]) === 'openai');
checkFormat('detect: Anthropic messages + system',
    $f->detectFormat(['model' => 'claude', 'system' => 'be nice', 'messages' => [['role' => 'user', 'content' => 'hi']]]) === 'anthropic');
checkFormat('detect: hint wins',
    $f->detectFormat(['query' => 'x'], 'anthropic') === 'anthropic');
checkFormat('detect: invalid hint ignored',
    $f->detectFormat(['query' => 'x'], 'bogus') === 'native');

// --- Native request mapping ----------------------------
$nativeParsed = $f->parseRequest([
    'query'           => 'What is 2+2?',
    'stream'          => true,
    'selected_models' => [['provider' => 'openai', 'model' => 'gpt-4o-mini']],
    'reasoning'       => 'medium',
]);
checkFormat('native: format detected', $nativeParsed['format'] === 'native');
checkFormat('native: stream flag extracted', $nativeParsed['stream'] === true);
checkFormat('native: input passes through to createJob',
    isset($nativeParsed['input']['query'], $nativeParsed['input']['selected_models'])
    && !array_key_exists('stream', $nativeParsed['input'])
    && $nativeParsed['input']['query'] === 'What is 2+2?');
checkFormat('native: selected_models shape matches createJob',
    $nativeParsed['input']['selected_models'] === [
        ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
    ]);

// --- OpenAI request mapping ----------------------------
$openAiParsed = $f->parseRequest([
    'model'       => 'gpt-4o-mini',
    'stream'      => false,
    'temperature' => 0.3,
    'max_tokens'  => 500,
    'user'        => 'api-client',
    'messages'    => [
        ['role' => 'system', 'content' => 'You are precise.'],
        ['role' => 'user',   'content' => 'What is 2+2?'],
        ['role' => 'assistant', 'content' => 'Thinking...'],
        ['role' => 'user',   'content' => 'And 3+3?'],
    ],
]);
checkFormat('openai: format detected', $openAiParsed['format'] === 'openai');
checkFormat('openai: query is the last user message', $openAiParsed['input']['query'] === 'And 3+3?');
checkFormat('openai: model mapped to selected_models',
    $openAiParsed['input']['selected_models'] === [['provider' => 'openai', 'model' => 'gpt-4o-mini']]);
checkFormat('openai: history preserved in request_meta',
    isset($openAiParsed['input']['request_meta']['openai_history']));
$hist = json_decode($openAiParsed['input']['request_meta']['openai_history'], true);
checkFormat('openai: history contains system and prior turns',
    is_array($hist) && count($hist) === 3 && $hist[0]['role'] === 'system');
checkFormat('openai: temperature preserved',
    ($openAiParsed['input']['request_meta']['openai_temperature'] ?? null) === '0.3');
checkFormat('openai: max_tokens preserved',
    ($openAiParsed['input']['request_meta']['openai_max_tokens'] ?? null) === '500');
checkFormat('openai: user meta preserved',
    ($openAiParsed['input']['request_meta']['openai_user'] ?? null) === 'api-client');
checkFormat('openai: warning about history semantics', $openAiParsed['warnings'] !== []);

// Block-list content for OpenAI.
$openAiBlocks = $f->parseRequest([
    'model'    => 'gpt-4o',
    'messages' => [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Part one.'], ['type' => 'text', 'text' => 'Part two.']]],
    ],
]);
checkFormat('openai: block-list content flattened',
    str_contains($openAiBlocks['input']['query'], 'Part one')
    && str_contains($openAiBlocks['input']['query'], 'Part two'));

// --- Anthropic request mapping -------------------------
$anthropicParsed = $f->parseRequest([
    'model'      => 'claude-sonnet-4-5',
    'system'     => 'Answer concisely.',
    'max_tokens' => 1000,
    'metadata'   => ['client' => 'acme'],
    'messages'   => [
        ['role' => 'user', 'content' => 'Hello there.'],
        ['role' => 'assistant', 'content' => 'Hi!'],
        ['role' => 'user', 'content' => 'Explain recursion.'],
    ],
]);
checkFormat('anthropic: format detected', $anthropicParsed['format'] === 'anthropic');
checkFormat('anthropic: query is the last user message',
    $anthropicParsed['input']['query'] === 'Explain recursion.');
checkFormat('anthropic: model mapped to selected_models',
    $anthropicParsed['input']['selected_models'] === [['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5']]);
checkFormat('anthropic: system preserved in request_meta',
    ($anthropicParsed['input']['request_meta']['anthropic_system'] ?? null) === 'Answer concisely.');
checkFormat('anthropic: max_tokens preserved',
    ($anthropicParsed['input']['request_meta']['anthropic_max_tokens'] ?? null) === '1000');
checkFormat('anthropic: metadata preserved as JSON',
    ($anthropicParsed['input']['request_meta']['anthropic_metadata'] ?? null) === '{"client":"acme"}');
checkFormat('anthropic: history preserved',
    isset($anthropicParsed['input']['request_meta']['anthropic_history']));

// Anthropic block-list content.
$anthropicBlocks = $f->parseRequest([
    'model'    => 'claude-haiku',
    'messages' => [
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Block one.'],
            ['type' => 'text', 'text' => 'Block two.'],
        ]],
    ],
]);
checkFormat('anthropic: block-list content flattened',
    str_contains($anthropicBlocks['input']['query'], 'Block one')
    && str_contains($anthropicBlocks['input']['query'], 'Block two'));

// --- Run response rendering ----------------------------
$run = [
    'run_id'       => 'run_77',
    'session_id'   => 77,
    'job_id'       => 7,
    'status'       => 'completed',
    'created_at'   => '2026-09-19T10:00:00Z',
    'final_answer' => 'The answer is 42.',
    'consensus'    => ['score' => 0.98, 'status' => 'strong'],
    'model'        => 'openai:gpt-4o-mini',
    'usage'        => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150],
    'urls'         => [
        'events_url' => '/v1/runs/77/events',
        'result_url' => '/v1/runs/77/result',
        'cancel_url' => '/v1/runs/77/cancel',
    ],
];

$nativeResp = $f->runResponse('native', $run);
checkFormat('native response: ok + run_id', $nativeResp['ok'] === true && $nativeResp['run_id'] === 'run_77');
checkFormat('native response: urls', $nativeResp['events_url'] === '/v1/runs/77/events');
checkFormat('native response: final_answer + consensus + usage',
    $nativeResp['final_answer'] === 'The answer is 42.'
    && $nativeResp['consensus']['score'] === 0.98
    && $nativeResp['usage']['total_tokens'] === 150);

$openAiResp = $f->runResponse('openai', $run);
checkFormat('openai response: chat.completion object',
    $openAiResp['object'] === 'chat.completion' && str_starts_with($openAiResp['id'], 'chatcmpl_'));
checkFormat('openai response: assistant message',
    $openAiResp['choices'][0]['message']['role'] === 'assistant'
    && $openAiResp['choices'][0]['message']['content'] === 'The answer is 42.');
checkFormat('openai response: finish_reason stop',
    $openAiResp['choices'][0]['finish_reason'] === 'stop');
checkFormat('openai response: usage mapped',
    $openAiResp['usage']['prompt_tokens'] === 120
    && $openAiResp['usage']['completion_tokens'] === 30
    && $openAiResp['usage']['total_tokens'] === 150);
checkFormat('openai response: revoap extension block',
    $openAiResp['revoap']['run_id'] === 'run_77');

$anthropicResp = $f->runResponse('anthropic', $run);
checkFormat('anthropic response: message type',
    $anthropicResp['type'] === 'message' && $anthropicResp['role'] === 'assistant');
checkFormat('anthropic response: text block content',
    $anthropicResp['content'][0]['type'] === 'text'
    && $anthropicResp['content'][0]['text'] === 'The answer is 42.');
checkFormat('anthropic response: usage mapped',
    $anthropicResp['usage']['input_tokens'] === 120
    && $anthropicResp['usage']['output_tokens'] === 30);
checkFormat('anthropic response: stop_reason end_turn',
    $anthropicResp['stop_reason'] === 'end_turn');

// --- Error response shapes -----------------------------
$openAiErr = $f->errorResponse('openai', 401, 'unauthorized', 'Bad key');
checkFormat('openai error: error.type authentication_error',
    $openAiErr['error']['type'] === 'authentication_error' && $openAiErr['error']['message'] === 'Bad key');
$anthropicErr = $f->errorResponse('anthropic', 429, 'rate_limited', 'Slow down');
checkFormat('anthropic error: error.type rate_limit_error',
    $anthropicErr['error']['type'] === 'rate_limit_error' && $anthropicErr['error']['message'] === 'Slow down');
$nativeErr = $f->errorResponse('native', 429, 'rate_limited', 'Slow down');
checkFormat('native error: ok=false + error code',
    $nativeErr['ok'] === false && $nativeErr['error'] === 'rate_limited');

// --- Invalid input -------------------------------------
$threw = [];
try { $f->parseRequest(['messages' => [['role' => 'system', 'content' => 'no users']]]); } catch (InvalidArgumentException $e) { $threw[] = $e->getMessage(); }
try { $f->parseRequest(['query' => '']); } catch (InvalidArgumentException $e) { $threw[] = $e->getMessage(); }
checkFormat('invalid: no user message throws', in_array('at least one user message is required', $threw, true));
checkFormat('invalid: empty query throws', in_array('query is required', $threw, true));

echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
