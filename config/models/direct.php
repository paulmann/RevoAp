<?php
/**
 * Direct-provider model catalog (top 5 most popular).
 *
 * "Direct" means the connector talks to the vendor's own
 * OpenAI-compatible endpoint instead of routing through OpenRouter or
 * BotHub. Each row carries its provider slug so the connector layer
 * can look up `connectors.{provider}` for `api_key` / `base_url` and
 * so `Config::getConnectorKey()` can resolve keys from `.{provider}.key`
 * or the matching environment variable.
 *
 * Rows default to `active=false` and `default=false`: enabling a
 * direct provider is opt-in and requires both `connectors.{slug}.active`
 * = true AND a configured key.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-12
 */
declare(strict_types=1);

return [
    ['provider' => 'openai',    'model' => 'gpt-5.5',                  'label' => 'OpenAI GPT-5.5',          'rank' => 1, 'active' => false, 'default' => false, 'category' => 'flagship'],
    ['provider' => 'anthropic', 'model' => 'claude-sonnet-4.6',        'label' => 'Anthropic Claude 4.6',    'rank' => 2, 'active' => false, 'default' => false, 'category' => 'flagship'],
    ['provider' => 'gemini',    'model' => 'gemini-3.1-pro-preview',   'label' => 'Google Gemini 3.1 Pro',   'rank' => 3, 'active' => false, 'default' => false, 'category' => 'flagship'],
    ['provider' => 'deepseek',  'model' => 'deepseek-chat',            'label' => 'DeepSeek Chat',           'rank' => 4, 'active' => false, 'default' => false, 'category' => 'fast'],
    ['provider' => 'xai',       'model' => 'grok-4.20',                'label' => 'xAI Grok 4.20',           'rank' => 5, 'active' => false, 'default' => false, 'category' => 'flagship'],
];
