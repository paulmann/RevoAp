<?php
/**
 * ProviderTester: lightweight HTTP probes for OpenRouter / BotHub.
 *
 * Used by the Settings UI to validate API keys without invoking a full
 * chat completion. Each method returns a sanitized status struct that
 * the controller streams back as JSON.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Settings;

use App\Core\Logger;

final class ProviderTester
{
    /**
     * @return array{ok:bool,status:int,provider:string,base_url:string,message:string,models_count:int}
     */
    public static function testOpenRouter(string $apiKey, string $baseUrl = 'https://openrouter.ai/api/v1'): array
    {
        $apiKey  = trim($apiKey);
        $baseUrl = rtrim($baseUrl, '/');
        if ($apiKey === '') {
            return self::result(false, 0, 'openrouter', $baseUrl, 'API key is empty', 0);
        }
        // Prefer /key (cheap, returns budget/credits) when the key is set;
        // fall back to /models if /key is unavailable.
        [$status, $body] = self::http('GET', $baseUrl . '/key', [
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://github.com/paulmann/AI-RevoAp',
            'X-Title: RevoAp',
        ]);
        if ($status >= 200 && $status < 300) {
            $msg = 'Key accepted';
            $decoded = self::tryJson($body);
            if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                $info = $decoded['data'];
                $label = is_string($info['label'] ?? null) ? $info['label'] : '';
                if ($label !== '') {
                    $msg .= " ({$label})";
                }
            }
            // Fetch model count quickly
            [$ms, $mb] = self::http('GET', $baseUrl . '/models', []);
            $count = 0;
            if ($ms >= 200 && $ms < 300) {
                $j = self::tryJson($mb);
                if (is_array($j) && isset($j['data']) && is_array($j['data'])) {
                    $count = count($j['data']);
                }
            }
            return self::result(true, $status, 'openrouter', $baseUrl, $msg, $count);
        }
        if ($status === 401 || $status === 403) {
            return self::result(false, $status, 'openrouter', $baseUrl, 'Auth rejected (HTTP ' . $status . ')', 0);
        }
        // Fall back to /models with bearer
        [$status2, $body2] = self::http('GET', $baseUrl . '/models', [
            'Authorization: Bearer ' . $apiKey,
        ]);
        if ($status2 >= 200 && $status2 < 300) {
            $count = 0;
            $j = self::tryJson($body2);
            if (is_array($j) && isset($j['data']) && is_array($j['data'])) {
                $count = count($j['data']);
            }
            return self::result(true, $status2, 'openrouter', $baseUrl, '/models reachable', $count);
        }
        return self::result(
            false,
            $status2 ?: $status,
            'openrouter',
            $baseUrl,
            'HTTP ' . ($status2 ?: $status) . ' — ' . self::short($body2 ?: $body),
            0,
        );
    }

    /**
     * @return array{ok:bool,status:int,provider:string,base_url:string,message:string,models_count:int}
     */
    public static function testBotHub(string $apiKey, string $baseUrl = 'https://bothub.chat/api/v2/openai/v1'): array
    {
        $apiKey  = trim($apiKey);
        $baseUrl = rtrim($baseUrl, '/');
        if ($apiKey === '') {
            return self::result(false, 0, 'bothub', $baseUrl, 'API key is empty', 0);
        }
        [$status, $body] = self::http('GET', $baseUrl . '/models', [
            'Authorization: Bearer ' . $apiKey,
        ]);
        if ($status >= 200 && $status < 300) {
            $count = 0;
            $j = self::tryJson($body);
            if (is_array($j) && isset($j['data']) && is_array($j['data'])) {
                $count = count($j['data']);
            }
            return self::result(true, $status, 'bothub', $baseUrl, '/models reachable', $count);
        }
        return self::result(
            false,
            $status,
            'bothub',
            $baseUrl,
            'HTTP ' . $status . ' — ' . self::short($body),
            0,
        );
    }

    /**
     * Fetch the OpenRouter catalog (public, no auth required).
     *
     * @return array{ok:bool,status:int,models:array<int,array<string,mixed>>,error?:string}
     */
    public static function fetchOpenRouterModels(string $baseUrl = 'https://openrouter.ai/api/v1'): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        [$status, $body] = self::http('GET', $baseUrl . '/models', [
            'HTTP-Referer: https://github.com/paulmann/AI-RevoAp',
            'X-Title: RevoAp',
        ]);
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'models' => [], 'error' => 'HTTP ' . $status];
        }
        $j = self::tryJson($body);
        if (!is_array($j) || !isset($j['data']) || !is_array($j['data'])) {
            return ['ok' => false, 'status' => $status, 'models' => [], 'error' => 'invalid response'];
        }
        $models = [];
        foreach ($j['data'] as $entry) {
            if (!is_array($entry)) { continue; }
            $id = $entry['id'] ?? null;
            if (!is_string($id) || $id === '') { continue; }
            $models[] = [
                'id'             => $id,
                'name'           => is_string($entry['name'] ?? null) ? $entry['name'] : $id,
                'context_length' => (int)($entry['context_length'] ?? 0),
                'pricing'        => is_array($entry['pricing'] ?? null) ? $entry['pricing'] : null,
                'description'    => is_string($entry['description'] ?? null) ? mb_substr($entry['description'], 0, 240) : '',
            ];
        }
        return ['ok' => true, 'status' => $status, 'models' => $models];
    }

    /**
     * Fetch the BotHub catalog. Requires a valid API key — BotHub /models
     * rejects unauthenticated requests.
     *
     * @return array{ok:bool,status:int,models:array<int,array<string,mixed>>,error?:string}
     */
    public static function fetchBotHubModels(string $apiKey, string $baseUrl = 'https://bothub.chat/api/v2/openai/v1'): array
    {
        $apiKey  = trim($apiKey);
        $baseUrl = rtrim($baseUrl, '/');
        if ($apiKey === '') {
            return ['ok' => false, 'status' => 0, 'models' => [], 'error' => 'API key required'];
        }
        [$status, $body] = self::http('GET', $baseUrl . '/models', [
            'Authorization: Bearer ' . $apiKey,
        ]);
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'models' => [], 'error' => 'HTTP ' . $status];
        }
        $j = self::tryJson($body);
        if (!is_array($j) || !isset($j['data']) || !is_array($j['data'])) {
            return ['ok' => false, 'status' => $status, 'models' => [], 'error' => 'invalid response'];
        }
        $models = [];
        foreach ($j['data'] as $entry) {
            if (!is_array($entry)) { continue; }
            $id = $entry['id'] ?? null;
            if (!is_string($id) || $id === '') { continue; }
            $models[] = [
                'id'   => $id,
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : $id,
            ];
        }
        return ['ok' => true, 'status' => $status, 'models' => $models];
    }

    /**
     * @param string[] $headers
     * @return array{0:int,1:string}
     */
    private static function http(string $method, string $url, array $headers, ?string $body = null, int $timeout = 12): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return [0, ''];
        }
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $err !== '') {
            return [$code, ''];
        }
        return [$code, (string)$raw];
    }

    private static function tryJson(string $body): mixed
    {
        try {
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @return array{ok:bool,status:int,provider:string,base_url:string,message:string,models_count:int}
     */
    private static function result(bool $ok, int $status, string $provider, string $baseUrl, string $message, int $count): array
    {
        return [
            'ok'           => $ok,
            'status'       => $status,
            'provider'     => $provider,
            'base_url'     => $baseUrl,
            'message'      => Logger::sanitizeScalar($message),
            'models_count' => $count,
        ];
    }

    private static function short(string $body): string
    {
        $body = trim($body);
        if ($body === '') { return ''; }
        return mb_substr($body, 0, 160);
    }
}
