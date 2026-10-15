<?php
/**
 * OpenRouterConnector: OpenRouter.ai OpenAI-compatible API connector.
 *
 * Adds the required HTTP-Referer and X-Title headers per OpenRouter spec.
 * Embeddings are not supported by OpenRouter and throw UnsupportedOperationException.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\UnsupportedOperationException;

final class OpenRouterConnector extends AbstractConnector
{
    public function __construct()
    {
        parent::__construct(
            providerSlug:        'openrouter',
            defaultBaseUrl:      'https://openrouter.ai/api/v1',
            defaultTimeoutChat:  120,
            defaultTimeoutEmbed: 30,
            defaultMaxRetries:   3,
        );
    }

    public function embed(string $model, string $text): EmbedResponse
    {
        throw new UnsupportedOperationException('OpenRouter does not expose an embeddings endpoint');
    }

    /** @return string[] */
    public function listModels(): array
    {
        try {
            $raw = $this->httpJson('GET', rtrim($this->baseUrl(), '/') . '/models', null, $this->timeoutEmbed());
        } catch (ConnectorException) {
            return [];
        }
        $models = [];
        if (isset($raw['data']) && is_array($raw['data'])) {
            foreach ($raw['data'] as $m) {
                $id = is_array($m) ? ($m['id'] ?? null) : null;
                if (is_string($id) && $id !== '') {
                    $models[] = $id;
                }
            }
        }
        return $models;
    }

    /** @return string[] */
    protected function headers(): array
    {
        return array_merge(parent::headers(), [
            'HTTP-Referer: https://github.com/paulmann/AI-RevoAp',
            'X-Title: RevoAp',
        ]);
    }
}
