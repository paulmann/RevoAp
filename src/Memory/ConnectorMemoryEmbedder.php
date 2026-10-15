<?php
/**
 * ConnectorMemoryEmbedder: Real embedding via the connector registry.
 *
 * Wraps an App\Connectors connector behind MemoryEmbedderInterface.
 * Provider resolution order:
 *  1. Constructor-supplied provider name;
 *  2. config `memory.embed_provider`;
 *  3. `bothub`, then `openrouter`, then the first available connector.
 * Model resolution: constructor model, else `memory.embed_model`, else
 * a model from listModels() whose id contains "embed".
 *
 * Availability is cached per process. embed() never throws; transport
 * failures degrade to [] ("no memory" behaviour) with a single warning
 * entry in the structured log.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

namespace App\Memory;

use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use App\Core\Config;
use App\Core\Logger;
use App\Storage\VectorStore;
use Throwable;

final class ConnectorMemoryEmbedder implements MemoryEmbedderInterface
{
    /** @var array<string,bool> provider|model -> availability cache */
    private static array $availability = [];

    /** @var array<string,bool> one warning per key per process */
    private static array $warned = [];

    private ?ConnectorInterface $connector = null;
    private ?string $resolvedProvider = null;
    private ?string $resolvedModel = null;

    public function __construct(
        private readonly ?ConnectorRegistry $registry = null,
        private readonly string $provider = '',
        private readonly string $model = '',
    ) {}

    public function isAvailable(): bool
    {
        $key = $this->provider . '|' . $this->model;
        if (isset(self::$availability[$key])) {
            return self::$availability[$key];
        }
        $connector = $this->resolveConnector();
        $model     = $this->resolveModel($connector);
        $this->connector       = $connector;
        $this->resolvedProvider = $connector !== null ? $connector->getProviderName() : null;
        $this->resolvedModel    = $model;
        $available = $connector !== null && $model !== null && $model !== '';
        if (!$available && !isset(self::$warned[$key])) {
            self::$warned[$key] = true;
            Logger::warn('memory.embedder.unavailable', [
                'provider' => $this->resolvedProvider,
                'model'    => $this->resolvedModel,
            ]);
        }
        self::$availability[$key] = $available;
        return $available;
    }

    /**
     * @inheritDoc
     */
    public function embed(string $text): array
    {
        $connector = $this->connector ?? $this->resolveConnector();
        $model     = $this->resolvedModel ?? $this->resolveModel($connector);
        if ($connector === null || $model === null || $model === '' || trim($text) === '') {
            return [];
        }
        try {
            $response = $connector->embed($model, $text);
            $vector   = VectorStore::normalizeVector($response->vector());
            return $vector;
        } catch (Throwable $e) {
            Logger::warn('memory.embedder.embed_failed', [
                'provider' => $connector->getProviderName(),
                'model'    => $model,
                'error'    => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return [];
        }
    }

    /** Selected provider after resolution (or null). */
    public function resolvedProvider(): ?string
    {
        return $this->resolvedProvider;
    }

    /** Selected embedding model after resolution (or null). */
    public function resolvedModel(): ?string
    {
        return $this->resolvedModel;
    }

    private function resolveConnector(): ?ConnectorInterface
    {
        if ($this->registry === null) {
            return null;
        }
        $registry = $this->registry;
        $wanted = trim($this->provider) !== '' ? [$this->provider] : [];
        if ($wanted === []) {
            $configured = (string)Config::get('memory.embed_provider', '');
            if ($configured !== '') {
                $wanted[] = $configured;
            }
        }
        if ($wanted === []) {
            $wanted = ['bothub', 'openrouter'];
        }
        foreach ($wanted as $name) {
            $instance = $registry->getByProvider($name);
            if ($instance !== null) {
                return $instance;
            }
        }
        $all = $registry->all();
        return $all[0] ?? null;
    }

    private function resolveModel(?ConnectorInterface $connector): ?string
    {
        if ($connector === null) {
            return null;
        }
        $wanted = trim($this->model) !== '' ? $this->model : (string)Config::get('memory.embed_model', '');
        if ($wanted !== '') {
            return $wanted;
        }
        try {
            $models = $connector->listModels();
        } catch (Throwable) {
            return null;
        }
        foreach ($models as $id) {
            $lower = strtolower((string)$id);
            if (str_contains($lower, 'embed')) {
                return (string)$id;
            }
        }
        // Providers with a single model expose it directly.
        return count($models) === 1 ? (string)($models[0] ?? '') : null;
    }
}
