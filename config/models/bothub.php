<?php
/**
 * BotHub model catalog (top 10 latest/high-capability text models).
 *
 * Model identifiers must be verified against the BotHub `/v1/models`
 * endpoint before enabling them in production. BotHub uses an
 * OpenAI-compatible API, so model identifiers are provider-specific slugs.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    Mikhail@Deynekin.com
 * @version  2.0.0
 * @since    2026-09-21
 */

declare(strict_types=1);

return [
    [
        'provider' => 'bothub',
        'model' => 'gpt-5.6-sol',
        'label' => 'GPT-5.6 Sol',
        'rank' => 1,
        'active' => false,
        'default' => true,
        'category' => 'flagship',
    ],
    [
        'provider' => 'bothub',
        'model' => 'claude-opus-5',
        'label' => 'Claude Opus 5',
        'rank' => 2,
        'active' => false,
        'default' => false,
        'category' => 'flagship',
    ],
    [
        'provider' => 'bothub',
        'model' => 'gemini-3.1-pro-preview',
        'label' => 'Gemini 3.1 Pro Preview',
        'rank' => 3,
        'active' => false,
        'default' => false,
        'category' => 'reasoning',
    ],
    [
        'provider' => 'bothub',
        'model' => 'deepseek-v4-pro',
        'label' => 'DeepSeek V4 Pro',
        'rank' => 4,
        'active' => false,
        'default' => false,
        'category' => 'reasoning',
    ],
    [
        'provider' => 'bothub',
        'model' => 'grok-4',
        'label' => 'Grok 4',
        'rank' => 5,
        'active' => false,
        'default' => false,
        'category' => 'flagship',
    ],
    [
        'provider' => 'bothub',
        'model' => 'qwen-3',
        'label' => 'Qwen 3',
        'rank' => 6,
        'active' => false,
        'default' => false,
        'category' => 'reasoning',
    ],
    [
        'provider' => 'bothub',
        'model' => 'glm-5.3-flashx',
        'label' => 'GLM-5.3-FlashX',
        'rank' => 7,
        'active' => false,
        'default' => false,
        'category' => 'fast',
    ],
    [
        'provider' => 'bothub',
        'model' => 'gemini-3.6-flash',
        'label' => 'Gemini 3.6 Flash',
        'rank' => 8,
        'active' => false,
        'default' => false,
        'category' => 'fast',
    ],
    [
        'provider' => 'bothub',
        'model' => 'claude-sonnet-5',
        'label' => 'Claude Sonnet 5',
        'rank' => 9,
        'active' => false,
        'default' => false,
        'category' => 'flagship',
    ],
    [
        'provider' => 'bothub',
        'model' => 'chatgpt-5.2',
        'label' => 'ChatGPT 5.2',
        'rank' => 10,
        'active' => false,
        'default' => false,
        'category' => 'flagship',
    ],
];