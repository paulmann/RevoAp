<?php
/**
 * RequestAnalyzer: optional pre-pipeline stage that classifies the
 * user query and proposes a domain preset, skill, expert persona and
 * confidence score before the council fires.
 *
 * The analyzer has two execution paths:
 *
 *   * Live LLM path — when enabled and a provider/model pair is
 *     reachable, the analyzer prompts a small frontier model for a
 *     structured JSON envelope. The configured default is
 *     `bothub / gpt-6-luna-pro`.
 *   * Heuristic fallback — when the live path is disabled, the model
 *     is unavailable, or the response cannot be parsed, the analyzer
 *     falls back to {@see DomainRouter}'s lexical classifier and
 *     synthesises a persona from the detected domain.
 *
 * Hardening:
 *   * JSON extraction is a deterministic linear scanner — the previous
 *     recursive `(?R)` pattern degenerated to catastrophic backtracking
 *     on long near-JSON prose.
 *   * The model-supplied persona is sanitized before it is published in
 *     the envelope (the council-persona channel), so a live response
 *     cannot smuggle instructions into the consumers of that persona.
 *   * The live call is bounded by `analyzer.timeout_seconds` (15 s)
 *     instead of inheriting `connectors.<provider>.timeout_chat` (120 s).
 *   * Every fallback reason is logged — as *metadata only*. The prompt
 *     text and the raw model response are never logged, and a truncated
 *     (`finish_reason: length`) response is rejected outright.
 *
 * Returned envelopes never leak the original prompt verbatim into the
 * application log; only the *metadata* (domain, persona, confidence,
 * signals) is recorded for debugging.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Analyzer;

use App\Connectors\ChatResponse;
use App\Connectors\ConnectorRegistry;
use App\Core\Config;
use App\Core\Logger;
use App\DomainPresets\DomainRouter;

final class RequestAnalyzer
{
    public const DEFAULT_PROVIDER = 'bothub';
    public const DEFAULT_MODEL    = 'gpt-6-luna-pro';

    /** Per-call budget for the live analyzer request, in seconds. */
    public const DEFAULT_TIMEOUT_SECONDS = 15;
    public const MIN_TIMEOUT_SECONDS     = 3;
    public const MAX_TIMEOUT_SECONDS     = 300;

    /** Completion-token budget for the live analyzer call. */
    private const MAX_COMPLETION_TOKENS = 400;

    /** Upper bound on a persona forwarded into council system prompts. */
    private const PERSONA_MAX_CHARS = 600;

    /**
     * Hard cap on the byte count the fallback JSON scanner inspects.
     * The analyzer asks for 400 completion tokens, so anything past this
     * is prose that will never parse into an envelope.
     */
    private const MAX_JSON_SCAN_BYTES = 200000;

    /** Non-overlapping scanner starts tried before giving up. */
    private const MAX_SCAN_CANDIDATES = 8;

    /** Markdown fences inspected before falling back to a whole-text scan. */
    private const MAX_FENCE_CANDIDATES = 4;

    /** Domain slugs the analyzer accepts from the model. */
    private const DOMAINS = ['default', 'mathematics', 'physics'];

    /**
     * Provider finish reasons that mean "the model was cut off mid
     * answer" — the envelope would be partial, so it is discarded.
     * `max_tokens` is the Anthropic-style spelling of the same signal.
     */
    private const TRUNCATED_FINISH_REASONS = ['length', 'max_tokens'];

    private ?DomainRouter $domainRouter = null;

    public function __construct(
        private readonly ConnectorRegistry $connectors,
    ) {}

    public function setDomainRouter(DomainRouter $r): void
    {
        $this->domainRouter = $r;
    }

    public function isEnabled(): bool
    {
        return (bool)Config::get('analyzer.enabled', true);
    }

    public function provider(): string
    {
        return (string)Config::get('analyzer.provider', self::DEFAULT_PROVIDER);
    }

    public function model(): string
    {
        return (string)Config::get('analyzer.model', self::DEFAULT_MODEL);
    }

    /**
     * Budget for the live analyzer request, clamped to
     * [{@see self::MIN_TIMEOUT_SECONDS}, {@see self::MAX_TIMEOUT_SECONDS}].
     *
     * Optional config key `analyzer.timeout_seconds`; a non-numeric or
     * absent value yields {@see self::DEFAULT_TIMEOUT_SECONDS}.
     */
    public function timeoutSeconds(): int
    {
        $raw = Config::get('analyzer.timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS);
        if (!is_numeric($raw)) {
            return self::DEFAULT_TIMEOUT_SECONDS;
        }
        $value = (int)$raw;
        if ($value < self::MIN_TIMEOUT_SECONDS) { return self::MIN_TIMEOUT_SECONDS; }
        if ($value > self::MAX_TIMEOUT_SECONDS) { return self::MAX_TIMEOUT_SECONDS; }
        return $value;
    }

    /**
     * Heuristic confidence at or above which the live call is skipped.
     *
     * Optional config key `analyzer.skip_live_confidence`, clamped to
     * [0,1]. The default 0.0 disables the short-circuit entirely, so the
     * pre-existing behaviour (always try the live path when enabled) is
     * preserved unless an operator opts in.
     */
    public function skipLiveConfidenceThreshold(): float
    {
        $raw = Config::get('analyzer.skip_live_confidence', 0.0);
        if (!is_numeric($raw)) { return 0.0; }
        $value = (float)$raw;
        if ($value <= 0.0) { return 0.0; }
        return $value > 1.0 ? 1.0 : $value;
    }

    /**
     * Analyze the query. Returns a structured envelope the pipeline
     * persists into session metadata. When the analyzer is disabled
     * the envelope still carries the heuristic classification so
     * downstream code (auto-routing, persona prefix) has something
     * useful to consume.
     *
     * @return array{
     *     domain:string,
     *     confidence:float,
     *     formula_expected:bool,
     *     persona:string,
     *     suggested_skill:?string,
     *     suggested_preset:string,
     *     signals:array<int,string>,
     *     source:'analyzer'|'heuristic'|'disabled',
     *     reason:string,
     *     model:?string,
     *     provider:?string,
     * }
     */
    public function analyze(string $query): array
    {
        $router = $this->domainRouter ?? new DomainRouter();
        $heuristic = $router->classify($query);

        if (!$this->isEnabled()) {
            return $this->fromHeuristic($heuristic, 'disabled', 'analyzer disabled in settings');
        }

        // Optional short-circuit: when the lexical classifier is already
        // above the configured threshold there is nothing the live model
        // can add, so the round-trip is skipped. Off by default (0.0).
        $skipThreshold = $this->skipLiveConfidenceThreshold();
        if ($skipThreshold > 0.0 && (float)$heuristic['confidence'] >= $skipThreshold) {
            return $this->fromHeuristic(
                $heuristic,
                'heuristic',
                'heuristic confidence above the skip threshold'
            );
        }

        $tryLive = $this->tryLiveAnalyzer($query, $heuristic);
        if ($tryLive !== null) {
            return $tryLive;
        }
        return $this->fromHeuristic($heuristic, 'heuristic', 'live analyzer unavailable — fell back to lexical classifier');
    }

    /**
     * @param array{domain:string,confidence:float,scores:array<string,float>,signals:array<int,string>,formula_expected:bool} $heuristic
     */
    private function fromHeuristic(array $heuristic, string $source, string $reason): array
    {
        $domain = (string)$heuristic['domain'];
        $persona = self::personaForDomain($domain, (bool)$heuristic['formula_expected']);
        $suggestedSkill = match ($domain) {
            'mathematics' => 'mathematics-reasoning',
            'physics'     => 'physics-reasoning',
            default       => null,
        };
        return [
            'domain'           => $domain,
            'confidence'       => (float)$heuristic['confidence'],
            'formula_expected' => (bool)$heuristic['formula_expected'],
            'persona'          => $persona,
            'suggested_skill'  => $suggestedSkill,
            'suggested_preset' => $domain,
            'signals'          => $heuristic['signals'],
            'source'           => $source,
            'reason'           => $reason,
            'model'            => null,
            'provider'         => null,
        ];
    }

    /**
     * Attempt to call the configured analyzer model. Returns null on
     * any failure so the caller falls back to the heuristic.
     *
     * @param array{domain:string,confidence:float,scores:array<string,float>,signals:array<int,string>,formula_expected:bool} $heuristic
     */
    private function tryLiveAnalyzer(string $query, array $heuristic): ?array
    {
        $provider = $this->provider();
        $model = $this->model();
        try {
            $connector = $this->connectors->getByProvider($provider);
        } catch (\Throwable $e) {
            self::logFallback('analyzer.connector_lookup_failed', $provider, $model, $e);
            return null;
        }
        if ($connector === null) {
            self::logFallback('analyzer.connector_missing', $provider, $model);
            return null;
        }

        $system = <<<TXT
You are RevoAp's request analyzer. Read the user's query and return a JSON object describing how the multi-LLM council should handle it.

Return JSON ONLY (no markdown). Fields:
- domain: one of "default", "mathematics", "physics"
- confidence: float in [0,1]
- formula_expected: bool — true if formulas are expected in the answer (math symbols, units, equations)
- persona: short single-line expert persona to prepend to every council role, e.g. "Senior software engineer with 20+ years of experience in distributed systems and modern UI methods in 2026"
- suggested_skill: one of "mathematics-reasoning", "physics-reasoning", "consensus-reasoning", or null
- reason: one short sentence justifying the classification (no PII, no full quote of the query)

Stay concise. Never include the user's full prompt in the JSON.
TXT;
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => mb_substr($query, 0, 4000)],
        ];
        try {
            $response = $connector->chat($model, $messages, [
                'temperature' => 0,
                'max_tokens'  => self::MAX_COMPLETION_TOKENS,
                // Per-call transport budget; AbstractConnector::buildChatRequest()
                // consumes it and keeps it out of the provider payload.
                'timeout'     => $this->timeoutSeconds(),
            ]);
        } catch (\Throwable $e) {
            self::logFallback('analyzer.chat_failed', $provider, $model, $e);
            return null;
        }

        $finishReason = self::finishReason($response);
        if ($finishReason !== null && in_array($finishReason, self::TRUNCATED_FINISH_REASONS, true)) {
            self::logFallback('analyzer.response_truncated', $provider, $model, null, [
                'finish_reason' => $finishReason,
            ]);
            return null;
        }

        $raw = (string)$response->content;
        $json = self::extractJson($raw);
        if ($json === null) {
            self::logFallback('analyzer.json_unparsable', $provider, $model);
            return null;
        }

        $domain = strtolower((string)($json['domain'] ?? $heuristic['domain']));
        if (!in_array($domain, self::DOMAINS, true)) {
            $domain = $heuristic['domain'];
        }
        $confidence = (float)($json['confidence'] ?? $heuristic['confidence']);
        if ($confidence < 0.0) { $confidence = 0.0; } elseif ($confidence > 1.0) { $confidence = 1.0; }
        $formula = (bool)($json['formula_expected'] ?? $heuristic['formula_expected']);

        $personaSource = isset($json['persona']) && is_string($json['persona']) ? $json['persona'] : '';
        $persona = self::normalizePersona($personaSource);
        if ($persona === '') {
            // Missing, empty, or sanitized away — the model's persona is
            // unusable either way, so the domain persona is substituted.
            self::logFallback('analyzer.persona_empty', $provider, $model);
            $persona = self::normalizePersona(self::personaForDomain($domain, $formula));
        }

        $suggestedSkill = isset($json['suggested_skill']) && is_string($json['suggested_skill'])
            ? trim($json['suggested_skill']) : null;
        if ($suggestedSkill === '' || $suggestedSkill === 'null') { $suggestedSkill = null; }
        $reason = isset($json['reason']) && is_string($json['reason']) ? mb_substr(trim($json['reason']), 0, 240) : 'analyzer classification';

        return [
            'domain'           => $domain,
            'confidence'       => $confidence,
            'formula_expected' => $formula,
            'persona'          => $persona,
            'suggested_skill'  => $suggestedSkill,
            'suggested_preset' => $domain,
            'signals'          => array_values(array_filter([
                ...$heuristic['signals'],
                'analyzer:' . $provider . '/' . $model,
            ], 'is_string')),
            'source'           => 'analyzer',
            'reason'           => $reason,
            'model'            => $model,
            'provider'         => $provider,
        ];
    }

    /**
     * Extract a JSON object from a model response.
     *
     * Attempt order is unchanged: direct `json_decode` of the whole
     * (trimmed) response → markdown-fenced object → first balanced
     * `{...}` block.
     *
     * The two fallback attempts are regex-free on purpose. The previous
     * pattern `/\{(?:[^{}]|(?R))*\}/s` is recursive and degenerates to
     * catastrophic backtracking on long near-JSON prose, letting a
     * verbose (or hostile) model burn unbounded CPU inside a request.
     * Both attempts now run through {@see self::decodeFirstObject()} —
     * a single forward pass per candidate, bounded by
     * {@see self::MAX_SCAN_CANDIDATES} candidates and a shared byte
     * budget, so the worst case is a small constant multiple of the
     * capped text length rather than exponential.
     *
     * @return array<string,mixed>|null
     */
    private static function extractJson(string $raw): ?array
    {
        $trim = trim($raw);
        if ($trim === '') { return null; }
        $decoded = json_decode($trim, true);
        if (is_array($decoded)) { return $decoded; }

        // The direct decode above still sees the full response; only the
        // fallback scan is capped, so the worst case stays bounded.
        if (strlen($trim) > self::MAX_JSON_SCAN_BYTES) {
            $trim = substr($trim, 0, self::MAX_JSON_SCAN_BYTES);
        }

        // Markdown fence: ```json { ... } ```
        $searchFrom = 0;
        for ($i = 0; $i < self::MAX_FENCE_CANDIDATES; $i++) {
            $fence = strpos($trim, '```', $searchFrom);
            if ($fence === false) { break; }
            $searchFrom = $fence + 3;
            $decoded = self::decodeFirstObject($trim, self::fenceContentOffset($trim, $fence));
            if ($decoded !== null) { return $decoded; }
        }

        // Last resort: first balanced `{...}` block anywhere in the text.
        return self::decodeFirstObject($trim, 0);
    }

    /**
     * Offset of the fenced payload: past the opening fence and its
     * optional info string (`json`, `JSON`, …) plus the newline after it.
     * Clamped to the end of $s when the fence is the last thing in it.
     */
    private static function fenceContentOffset(string $s, int $fence): int
    {
        $len = strlen($s);
        $i = $fence + 3;
        // Info string: a single word such as "json" / "json5".
        while ($i < $len && (ctype_alnum($s[$i]) || $s[$i] === '_' || $s[$i] === '-' || $s[$i] === '+')) {
            $i++;
        }
        while ($i < $len && ($s[$i] === ' ' || $s[$i] === "\t")) { $i++; }
        if ($i < $len && $s[$i] === "\r") { $i++; }
        if ($i < $len && $s[$i] === "\n") { $i++; }
        return $i;
    }

    /**
     * Decode the first parseable JSON object at or after $from.
     *
     * Candidates are tried left to right, each starting at the next
     * opening brace, so an object nested inside an unmatched outer brace
     * is still reachable. Progress is bounded twice over — by
     * {@see self::MAX_SCAN_CANDIDATES} candidates and by a
     * {@see self::MAX_JSON_SCAN_BYTES} byte budget shared across those
     * candidates — which keeps a pathological response from turning this
     * into a quadratic (let alone exponential) walk.
     *
     * @return array<string,mixed>|null
     */
    private static function decodeFirstObject(string $s, int $from): ?array
    {
        $budget = self::MAX_JSON_SCAN_BYTES;
        $cursor = $from;
        for ($i = 0; $i < self::MAX_SCAN_CANDIDATES; $i++) {
            $found = self::scanBalancedObject($s, $cursor, $budget);
            if ($found === null) { return null; }
            if ($found['text'] !== null) {
                $decoded = json_decode($found['text'], true);
                if (is_array($decoded)) { return $decoded; }
            }
            // Resume one byte past this opening brace — the budget above
            // is what keeps the retries linear, not the stride.
            $cursor = $found['start'] + 1;
        }
        return null;
    }

    /**
     * Walk from the first `{` at or after $from to its matching close.
     *
     * Braces inside JSON strings are ignored, and `\"` / `\\` escapes are
     * honoured, so `{"a":"}","b":{"c":1}}` yields the whole object. The
     * scan is a single forward pass over the bytes — `{`, `}`, `"` and
     * `\` are all ASCII, so UTF-8 continuation bytes can never be
     * mistaken for structure.
     *
     * $budget is decremented once per byte examined and is shared across
     * the candidates of one {@see self::decodeFirstObject()} call, which
     * keeps the total walk linear in the (already capped) text length.
     *
     * @param  int $budget Remaining byte budget; consumed by reference.
     * @return array{start:int,text:?string}|null null when there is no
     *         `{` left to try (or the budget is spent); `text` is null
     *         when that opening brace never closes.
     */
    private static function scanBalancedObject(string $s, int $from, int &$budget): ?array
    {
        if ($budget <= 0) { return null; }
        $len   = strlen($s);
        $start = strpos($s, '{', $from);
        if ($start === false) { return null; }

        $depth   = 0;
        $inStr   = false;
        $escaped = false;
        for ($i = $start; $i < $len && $budget > 0; $i++) {
            $budget--;
            $ch = $s[$i];
            if ($inStr) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inStr = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return ['start' => $start, 'text' => substr($s, $start, $i - $start + 1)];
                }
            }
        }
        return ['start' => $start, 'text' => null];
    }

    /**
     * Provider finish reason, read from the raw payload.
     *
     * {@see ChatResponse} does not model `finish_reason` as a typed
     * field; `rawResponse` carries the provider's payload verbatim and
     * every OpenAI-compatible endpoint (BotHub, OpenRouter) reports it at
     * `choices[0].finish_reason`. Absent/blank/non-string values are
     * reported as null (unknown) rather than guessed.
     */
    private static function finishReason(ChatResponse $response): ?string
    {
        $value = $response->rawResponse['choices'][0]['finish_reason'] ?? null;
        if (!is_string($value)) { return null; }
        $value = strtolower(trim($value));
        return $value === '' ? null : $value;
    }

    /**
     * Normalize a persona before it is published in the envelope.
     *
     * The value originates in an LLM response, so it is treated as
     * untrusted input — it is the council-persona channel (prefix for
     * every council role's system prompt, routing notice, session
     * metadata):
     *
     *   * every whitespace run — including `\n`, `\r`, `\t`, NBSP, the
     *     Unicode space block and the line/paragraph separators —
     *     collapses to a single space, so the result is always one line;
     *   * C0/C1 control characters and the zero-width code points
     *     U+200B..U+200D / U+FEFF are dropped;
     *   * markdown artefacts (``` fences and inline-code backticks) are
     *     stripped;
     *   * the result is truncated to {@see self::PERSONA_MAX_CHARS}
     *     characters.
     *
     * Returns '' when nothing survives; the caller then substitutes the
     * domain persona. `json_decode` guarantees valid UTF-8 here, because
     * PHP rejects malformed UTF-8 and lone surrogates outright — so the
     * `/u` patterns below cannot fail on the JSON path.
     */
    private static function normalizePersona(string $value): string
    {
        if ($value === '') { return ''; }
        // C0/C1 controls and zero-width marks become spaces first, so
        // "a\nb" collapses to "a b" rather than "ab".
        $s = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200D}\x{FEFF}]/u', ' ', $value) ?? '';
        // Backticks / fences are markdown artefacts, never persona text.
        $s = str_replace(['```', '`'], ' ', $s);
        // Collapse every whitespace run. PCRE's \s is ASCII-only without
        // UCP, so the common Unicode spaces are listed explicitly.
        $s = preg_replace(
            '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u',
            ' ',
            $s
        ) ?? '';
        $s = trim($s);
        if ($s === '') { return ''; }
        return trim(mb_substr($s, 0, self::PERSONA_MAX_CHARS));
    }

    /**
     * Log a fallback reason — METADATA ONLY.
     *
     * Event names: `analyzer.connector_lookup_failed`, `analyzer.connector_missing`,
     * `analyzer.chat_failed`, `analyzer.response_truncated`,
     * `analyzer.json_unparsable`, `analyzer.persona_empty`.
     *
     * Context is limited to the provider slug, the requested model, the
     * exception class and a short, body-free excerpt of the exception
     * message (see {@see self::shortError()}). The analyzer prompt text
     * and the raw model response are never passed in, and logging can
     * never throw into the pipeline.
     *
     * @param array<string,mixed> $extra
     */
    private static function logFallback(
        string $message,
        string $provider,
        string $model,
        ?\Throwable $error = null,
        array $extra = [],
    ): void {
        try {
            $context = ['provider' => $provider, 'model' => $model] + $extra;
            if ($error !== null) {
                $context['error_class']   = $error::class;
                $context['error_message'] = self::shortError($error->getMessage());
            }
            Logger::warn($message, $context);
        } catch (\Throwable) {
            // Diagnostics must never break request handling.
        }
    }

    /**
     * Reduce an exception message to a short single-line, body-free
     * excerpt for logging.
     *
     * Connector exceptions embed up to 500 bytes of the provider's raw
     * response after the status code ("… HTTP 400: {body}"). That body
     * is response data, not metadata — and could echo the request — so
     * the excerpt stops at the first status code and keeps only the
     * code itself. Messages without a status code are truncated to 160
     * characters.
     */
    private static function shortError(string $message): string
    {
        $line = mb_substr(explode("\n", str_replace("\r", ' ', $message), 2)[0], 0, 512);
        $line = preg_replace('/[\x00-\x1F\x7F]/u', '', $line) ?? '';
        if (preg_match('/HTTP\s+(\d{3})/i', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $prefix = trim(substr($line, 0, (int)$m[0][1]));
            $line = ($prefix === '' ? '' : mb_substr($prefix, 0, 120) . ' ') . 'HTTP ' . (string)$m[1][0];
        }
        return mb_substr($line, 0, 160);
    }

    private static function personaForDomain(string $domain, bool $formulaExpected): string
    {
        $core = match ($domain) {
            'mathematics' => 'Senior research mathematician (PhD, 20+ years) skilled in symbolic derivation, formal proofs, computer algebra, and rigorous error analysis (2026 frontier)',
            'physics'     => 'Senior theoretical and experimental physicist (PhD, 20+ years) skilled in dimensional analysis, conservation laws, limiting-case validation, and clean derivations (2026 frontier)',
            default       => 'Senior multidisciplinary expert (20+ years) skilled in rigorous analysis, decomposition, and clearly justified conclusions (2026 frontier)',
        };
        if ($formulaExpected) {
            $core .= '. Express formulas with KaTeX-compatible delimiters (\\(...\\) inline, \\[...\\] display) and check units / dimensions.';
        }
        return $core;
    }
}
