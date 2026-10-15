<?php
/**
 * OpenRouter model catalog (top 10 latest/best).
 *
 * One row per model. The 'active' flag controls whether the model
 * participates by default in the L1 consensus pool; the Settings UI
 * lists every row regardless of the flag and lets the operator toggle
 * each entry individually. The 'default' flag (optional) marks rows
 * that should be pre-selected for a NEW operator who has not yet saved
 * a `pipeline.default_selected_models` override — pipeline boot picks
 * up to four of these as the fresh-install defaults.
 *
 * Slugs verified against https://openrouter.ai/api/v1/models on the
 * date of this commit. Operators may add their own rows by dropping a
 * `.config.php` that supplies a `models` list; this file is merely the
 * shipped default and is replaced wholesale (not merged) by any list
 * the operator provides.
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
    // Auto-routed flagship chat model — OpenRouter resolves to the
    // latest OpenAI chat completion model behind the scenes.
    ['provider' => 'openrouter', 'model' => 'openai/gpt-chat-latest',          'label' => 'GPT (latest)',           'rank' => 1, 'active' => true,  'default' => true,  'category' => 'flagship'],
    ['provider' => 'openrouter', 'model' => 'openai/gpt-5.5',                  'label' => 'GPT-5.5',                'rank' => 2, 'active' => true,  'default' => false, 'category' => 'flagship'],
    ['provider' => 'openrouter', 'model' => 'openai/gpt-5.5-pro',              'label' => 'GPT-5.5 Pro',            'rank' => 3, 'active' => true,  'default' => false, 'category' => 'reasoning'],
    ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4.6',     'label' => 'Claude Sonnet 4.6',      'rank' => 4, 'active' => true,  'default' => true,  'category' => 'flagship'],
    ['provider' => 'openrouter', 'model' => '~anthropic/claude-sonnet-latest', 'label' => 'Claude Sonnet (latest)', 'rank' => 5, 'active' => true,  'default' => false, 'category' => 'flagship'],
    ['provider' => 'openrouter', 'model' => 'google/gemini-3.1-pro-preview',   'label' => 'Gemini 3.1 Pro (preview)','rank'=> 6, 'active' => true,  'default' => true,  'category' => 'flagship'],
    // v1.8.20: replaced the bogus `google/gemini-3.1-flash-latest` (no such
    // slug on OpenRouter — caused a "is not a valid model ID" HTTP 400 on
    // any L1 call) with `google/gemini-3.1-flash-lite`, the closest valid
    // 3.1 Flash variant present in /api/v1/models. Confirmed against the
    // live catalog (see CHANGELOG 1.8.20). If you want auto-routing to
    // whatever Google releases next, use `~google/gemini-flash-latest`.
    ['provider' => 'openrouter', 'model' => 'google/gemini-3.1-flash-lite',    'label' => 'Gemini 3.1 Flash Lite',  'rank' => 7, 'active' => true,  'default' => false, 'category' => 'fast'],
    ['provider' => 'openrouter', 'model' => 'deepseek/deepseek-v4-pro',        'label' => 'DeepSeek V4 Pro',        'rank' => 8, 'active' => true,  'default' => true,  'category' => 'reasoning'],
    ['provider' => 'openrouter', 'model' => 'deepseek/deepseek-v4-flash',      'label' => 'DeepSeek V4 Flash',      'rank' => 9, 'active' => false, 'default' => false, 'category' => 'fast'],
    ['provider' => 'openrouter', 'model' => 'x-ai/grok-4.20',                  'label' => 'Grok 4.20',              'rank' => 10,'active' => false, 'default' => false, 'category' => 'flagship'],
    // Bonus row — kept inactive so the curated catalog remains exactly
    // ten *active* picks while still surfacing a strong open-weights
    // option in the Settings UI.
    ['provider' => 'openrouter', 'model' => 'qwen/qwen3.6-max-preview',        'label' => 'Qwen 3.6 Max (preview)', 'rank' => 11,'active' => false, 'default' => false, 'category' => 'open'],
];
