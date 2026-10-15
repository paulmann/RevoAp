<?php
/**
 * ModelImporter: fetch a provider's model catalogue and merge it into the
 * persisted `models[]` list.
 *
 * This is the one place that knows *how* a catalogue is fetched. Two
 * callers need exactly the same thing and used to be two implementations
 * waiting to drift apart:
 *
 *   1. `POST /api/settings/models/import` — the Models tab's "Import
 *      models" button, which answers the browser immediately
 *      ({@see \App\Http\Controllers\SettingsController::importModels()}).
 *   2. The first stage of an actualization run, which runs in the worker
 *      and therefore cannot be a browser request
 *      ({@see ModelsActualizer} → `importing_models`). A live catalogue
 *      fetch takes seconds to tens of seconds; holding an HTTP request
 *      open for it is what the worker queue exists to avoid.
 *
 * What is deliberately shared: the source list, the URL/key resolution,
 * the `ProviderTester` call, the "no ext-curl" refusal and the merge
 * report. What stays with each caller: what it does with the result — the
 * controller persists immediately and answers JSON, the worker folds the
 * merged rows into the run's catalogue so the whole run stays
 * all-or-nothing.
 *
 * `ModelCatalogMerge` remains the merge *rule* (never duplicate a row,
 * never rewrite the flags of a row that already exists); this class only
 * wraps it with the counters a report needs.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Logger;
use App\Settings\PopularModels;
use App\Settings\ProviderTester;

final class ModelImporter
{
    /** BotHub: OpenAI-compatible endpoint, key required. */
    public const SOURCE_BOTHUB = 'bothub';

    /** OpenRouter: public catalogue, no key needed to list models. */
    public const SOURCE_OPENROUTER = 'openrouter';

    /**
     * The import sources an operator may choose from, in the order the UI
     * offers them. Anything else is refused rather than guessed at — a
     * typo must not silently import from a provider nobody picked.
     */
    public const SOURCES = [self::SOURCE_BOTHUB, self::SOURCE_OPENROUTER];

    /** Shipped endpoints, used when config.php carries no override. */
    public const BOTHUB_BASE_URL     = 'https://bothub.chat/api/v2/openai/v1';
    public const OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1';

    /**
     * Why an import cannot run at all, when the transport is missing.
     * Named once so the controller and the worker say the same sentence.
     */
    public const NO_TRANSPORT_REASON = 'the PHP curl extension is not loaded, so no provider catalogue can be '
        . 'fetched from here. Enable ext-curl in the PHP configuration that runs the worker.';

    /**
     * Test seam: called with the provider slug and expected to return
     * `{ok, status, models, error?}`. Null means "use ProviderTester",
     * which is the only production path — the HTTP tests cannot reach a
     * real provider (and this host has no ext-curl), so they inject a
     * canned answer instead of asserting on a network that is not there.
     *
     * @var null|callable(string):array<string,mixed>
     */
    private $fetcher;

    /** @param null|callable(string):array<string,mixed> $fetcher */
    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher;
    }

    /**
     * Is this a source we accept? Case-insensitive and trimmed, because
     * the value arrives from a select, a job payload and a hand-written
     * request body.
     */
    public static function normaliseSource(mixed $source): ?string
    {
        if (!is_string($source)) {
            return null;
        }
        $source = strtolower(trim($source));
        return in_array($source, self::SOURCES, true) ? $source : null;
    }

    /**
     * The source a run uses when the operator did not choose one.
     *
     * BotHub when a key is configured — it is the shipped default
     * connector and the catalogue it serves is the one the pipeline
     * calls. OpenRouter otherwise: its catalogue is public, so an
     * install without a BotHub key still gets a working first step
     * instead of a run that fails on a key it was never asked for.
     */
    public static function defaultSource(): string
    {
        try {
            if (trim(Config::getConnectorKey(self::SOURCE_BOTHUB)) !== '') {
                return self::SOURCE_BOTHUB;
            }
        } catch (\Throwable) {
            // An unreadable key is "no key": the OpenRouter catalogue
            // needs none, so the fallback below still names a real source.
        }
        return self::SOURCE_OPENROUTER;
    }

    /** Can this host perform a catalogue fetch at all? */
    public static function transportAvailable(): bool
    {
        return extension_loaded('curl') && function_exists('curl_init');
    }

    /**
     * Fetch one provider's catalogue.
     *
     * Never throws: a transport fault, a rejected key, an HTTP error and
     * a malformed body all come back as `ok=false` with the provider's
     * own words in `error`, because both callers have to *report* the
     * failure rather than die inside it — the Models tab paints it, the
     * actualization run refuses loudly with it.
     *
     * @return array{ok:bool,provider:string,status:int,models:array<int,array<string,mixed>>|array<int,string>,popular:array<int,array<string,mixed>>,error:?string}
     */
    public function fetch(string $provider): array
    {
        $source = self::normaliseSource($provider);
        if ($source === null) {
            return self::failure((string)$provider, 'unknown import source "' . $provider . '"; expected one of '
                . implode(', ', self::SOURCES) . '.');
        }
        if ($this->fetcher !== null) {
            try {
                $result = ($this->fetcher)($source);
            } catch (\Throwable $e) {
                return self::failure($source, Logger::sanitizeScalar($e->getMessage()));
            }
            return self::normaliseFetch($source, $result);
        }
        // A fatal `Call to undefined function curl_init()` inside
        // ProviderTester would escape as an Error with no provider name on
        // it. Refusing here is the difference between "the import failed
        // because ext-curl is missing" and a stack trace.
        if (!self::transportAvailable()) {
            return self::failure($source, self::NO_TRANSPORT_REASON);
        }
        try {
            return $this->fetchFromProvider($source);
        } catch (\Throwable $e) {
            Logger::warn('models.import.fetch_failed', [
                'provider' => $source,
                'error'    => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return self::failure($source, Logger::sanitizeScalar($e->getMessage()));
        }
    }

    /**
     * The merge half, with the counters a report needs.
     *
     * `fetched` is what the provider sent, `added` what the catalogue did
     * not have yet, `duplicates` what it already had, `truncated` what
     * did not fit under the row cap and `unusable` entries that carried
     * no model id at all. Those five numbers are the whole import report:
     * nothing may be dropped without being counted.
     *
     * @param  array<int,mixed> $existing Rows currently in effect.
     * @param  array<int,mixed> $catalog  Provider rows (`{id,name}` or bare ids).
     * @return array{source:string,rows:array<int,array<string,mixed>>,added:array<int,string>,batch:array<int,string>,duplicates:int,present:int,truncated:int,unusable:int,limit:int,fetched:int}
     */
    public static function mergeReport(
        array $existing,
        string $provider,
        array $catalog,
        int $limit = ModelCatalogMerge::MAX_ROWS,
    ): array {
        $provider = self::normaliseSource($provider) ?? $provider;
        $merge = ModelCatalogMerge::mergeImported($existing, $catalog, $provider, $limit);
        $accounted = count($merge['added']) + $merge['duplicates'] + $merge['truncated'];
        return [
            // The source travels with the report: "fetched 412, added 53" is
            // only actionable next to "from openrouter".
            'source'     => $provider,
            'rows'       => $merge['rows'],
            'added'      => $merge['added'],
            'batch'      => $merge['batch'],
            'duplicates' => $merge['duplicates'],
            'present'    => $merge['present'],
            'truncated'  => $merge['truncated'],
            // `mergeImported()` skips an entry it cannot read an id out of.
            // Deriving that count here is what keeps "fetched" and
            // "accounted for" equal, instead of quietly losing rows.
            'unusable'   => max(0, count($catalog) - $accounted),
            'limit'      => $merge['limit'],
            'fetched'    => count($catalog),
        ];
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * The production fetch: exactly the two calls the Import button made
     * before this class existed, including the OpenRouter "popular"
     * annotation only OpenRouter needs.
     *
     * @return array{ok:bool,provider:string,status:int,models:array<int,array<string,mixed>>|array<int,string>,popular:array<int,array<string,mixed>>,error:?string}
     */
    private function fetchFromProvider(string $provider): array
    {
        if ($provider === self::SOURCE_OPENROUTER) {
            $baseUrl = (string)Config::get('connectors.openrouter.base_url', self::OPENROUTER_BASE_URL);
            $result  = ProviderTester::fetchOpenRouterModels($baseUrl === '' ? self::OPENROUTER_BASE_URL : $baseUrl);
            return self::normaliseFetch($provider, [
                'ok'      => (bool)($result['ok'] ?? false),
                'status'  => (int)($result['status'] ?? 0),
                'models'  => (array)($result['models'] ?? []),
                'error'   => $result['error'] ?? null,
                'popular' => ($result['ok'] ?? false) === true
                    ? PopularModels::annotateOpenRouter((array)($result['models'] ?? []))
                    : [],
            ]);
        }

        $baseUrl = (string)Config::get('connectors.bothub.base_url', self::BOTHUB_BASE_URL);
        $key     = '';
        try {
            $key = Config::getConnectorKey(self::SOURCE_BOTHUB);
        } catch (\Throwable) {
            $key = '';
        }
        $result = ProviderTester::fetchBotHubModels($key, $baseUrl === '' ? self::BOTHUB_BASE_URL : $baseUrl);
        return self::normaliseFetch($provider, [
            'ok'     => (bool)($result['ok'] ?? false),
            'status' => (int)($result['status'] ?? 0),
            'models' => (array)($result['models'] ?? []),
            // BotHub's own words first: "API key required" and "HTTP 401"
            // are the two failures an operator actually has to act on.
            'error'  => $result['error'] ?? null,
        ]);
    }

    /**
     * @param  mixed $result anything the fetcher returned
     * @return array{ok:bool,provider:string,status:int,models:array<int,array<string,mixed>>|array<int,string>,popular:array<int,array<string,mixed>>,error:?string}
     */
    private static function normaliseFetch(string $provider, mixed $result): array
    {
        if (!is_array($result)) {
            return self::failure($provider, 'the fetch returned no result.');
        }
        $ok = ($result['ok'] ?? false) === true;
        $error = $result['error'] ?? null;
        if (!$ok && (!is_string($error) || trim($error) === '')) {
            $error = 'the provider did not answer with a usable model list.';
        }
        $models = [];
        foreach ((array)($result['models'] ?? []) as $row) {
            // `{id,name}` rows from ProviderTester, and bare ids from a
            // caller that already has the slugs: `ModelCatalogMerge` reads
            // both, so refusing one shape here would silently import
            // nothing.
            if (is_array($row) || is_string($row)) { $models[] = $row; }
        }
        $popular = [];
        foreach ((array)($result['popular'] ?? []) as $row) {
            if (is_array($row)) { $popular[] = $row; }
        }
        return [
            'ok'       => $ok,
            'provider' => $provider,
            'status'   => (int)($result['status'] ?? 0),
            'models'   => $models,
            'popular'  => $popular,
            'error'    => $ok ? null : Logger::sanitizeScalar((string)$error),
        ];
    }

    /**
     * @return array{ok:bool,provider:string,status:int,models:array<int,array<string,mixed>>|array<int,string>,popular:array<int,array<string,mixed>>,error:?string}
     */
    private static function failure(string $provider, string $error): array
    {
        return [
            'ok'       => false,
            'provider' => $provider,
            'status'   => 0,
            'models'   => [],
            'popular'  => [],
            'error'    => Logger::sanitizeScalar($error),
        ];
    }
}
