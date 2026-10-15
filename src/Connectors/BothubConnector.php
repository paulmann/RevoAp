<?php
/**
 * BothubConnector: BotHub.chat OpenAI-compatible API connector.
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
use App\Connectors\PromptCache\Policy;

final class BothubConnector extends AbstractConnector
{
    public function __construct()
    {
        parent::__construct(
            providerSlug:        'bothub',
            // BotHub exposes OpenAI-compatible endpoints; their docs use
            // baseURL https://bothub.chat/api/v2/openai/v1, so /chat/completions
            // and /embeddings sit underneath this base.
            defaultBaseUrl:      'https://bothub.chat/api/v2/openai/v1',
            defaultTimeoutChat:  120,
            defaultTimeoutEmbed: 30,
            defaultMaxRetries:   3,
        );
    }

    /**
     * Bothub is the only provider this project talks to that documents
     * prompt caching, so it is the only one that opts in.
     *
     * The returned policy drives two independent things and it is worth
     * keeping them apart:
     *
     *   - `cache_control` breakpoints, which only the families that need
     *     them get ({@see \App\Connectors\PromptCache\Capabilities}) — the
     *     decision itself lives in the planner, not here;
     *   - `usage: {include: true}`, which asks Bothub to report cache
     *     tokens at the end of the response and is what makes the
     *     automatic families measurable at all.
     *
     * Returning null here (the OpenRouter behaviour, inherited) leaves the
     * request body byte-identical to what it was before this feature.
     */
    protected function promptCachePolicy(): ?Policy
    {
        return Policy::fromConfig();
    }

    /**
     * @return string[]
     */
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
}
