<?php
/**
 * ProviderFamily: derive the *base provider family* of a model id.
 *
 * Why this exists: a consensus pool is only worth what its independent
 * opinions are worth. Five rows of `openai/gpt-5.x` agree with each other
 * by construction, so "diverse" has to mean "different base vendors", not
 * "different strings". The actualization run needs that notion twice:
 *
 *   1. when telling the analyzer model how to fill the `default` preset
 *      ("strongest models, but from different base providers"), and
 *   2. when checking its answer, because a model reply is a suggestion
 *      and the diversification rule has to hold whether or not the model
 *      respected it (see ModelsActualizer::diversify()).
 *
 * The family is a heuristic over the identifier alone — no network call,
 * no vendor table to keep in sync. A live catalogue carries the vendor
 * either as an OpenRouter-style prefix (`anthropic/claude-sonnet-4.6`) or
 * inside the slug (`claude-sonnet-4.6`, `gpt-5.5`), so both are read.
 * When nothing matches, the vendor prefix is used as-is, and only a row
 * with no usable signal at all falls through to `unknown`.
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

final class ProviderFamily
{
    /** Family reported when the identifier carries no usable signal. */
    public const UNKNOWN = 'unknown';

    /**
     * Canonical family => human label, in the order the analyzer prompt
     * lists them. Ordering is editorial: the vendors an operator is most
     * likely to have keys for come first, so a truncated prompt keeps the
     * useful half.
     *
     * @var array<string,string>
     */
    private const FAMILIES = [
        'openai'    => 'OpenAI / ChatGPT',
        'anthropic' => 'Anthropic / Claude',
        'google'    => 'Google / Gemini',
        'deepseek'  => 'DeepSeek',
        'alibaba'   => 'Alibaba / Qwen',
        'xai'       => 'xAI / Grok',
        'meta'      => 'Meta / Llama',
        'mistral'   => 'Mistral',
        'cohere'    => 'Cohere',
        'zhipu'     => 'Zhipu / GLM',
        'moonshot'  => 'Moonshot / Kimi',
        'microsoft' => 'Microsoft / Phi',
        'amazon'    => 'Amazon / Nova',
        'nvidia'    => 'NVIDIA / Nemotron',
        'perplexity' => 'Perplexity / Sonar',
        'ai21'      => 'AI21 / Jamba',
        'baidu'     => 'Baidu / ERNIE',
        'bytedance' => 'ByteDance / Seed',
        'minimax'   => 'MiniMax',
        'tii'       => 'TII / Falcon',
        'reka'      => 'Reka',
        'upstage'   => 'Upstage / Solar',
        '01ai'      => '01.AI / Yi',
    ];

    /**
     * Vendor prefixes (`vendor/model`) that map onto a family.
     *
     * @var array<string,string>
     */
    private const VENDOR_ALIASES = [
        'openai'      => 'openai',
        'azure'       => 'openai',
        'azure-openai' => 'openai',
        'anthropic'   => 'anthropic',
        'google'      => 'google',
        'google-vertex' => 'google',
        'deepseek'    => 'deepseek',
        'qwen'        => 'alibaba',
        'alibaba'     => 'alibaba',
        'x-ai'        => 'xai',
        'xai'         => 'xai',
        'meta-llama'  => 'meta',
        'meta'        => 'meta',
        'mistralai'   => 'mistral',
        'mistral'     => 'mistral',
        'cohere'      => 'cohere',
        'z-ai'        => 'zhipu',
        'zhipu'       => 'zhipu',
        'zhipuai'     => 'zhipu',
        'thudm'       => 'zhipu',
        'moonshotai'  => 'moonshot',
        'moonshot'    => 'moonshot',
        'microsoft'   => 'microsoft',
        'amazon'      => 'amazon',
        'nvidia'      => 'nvidia',
        'perplexity'  => 'perplexity',
        'ai21'        => 'ai21',
        'baidu'       => 'baidu',
        'bytedance'   => 'bytedance',
        'minimax'     => 'minimax',
        'tiiuae'      => 'tii',
        'reka'        => 'reka',
        'rekaai'      => 'reka',
        'upstage'     => 'upstage',
        '01-ai'       => '01ai',
        '01ai'        => '01ai',
    ];

    /**
     * Slug-keyword => family. Checked against the slug (the segment after
     * the last `/`, with a leading `~` alias marker removed) when the
     * vendor prefix did not decide it.
     *
     * @var array<string,string>
     */
    private const SLUG_KEYWORDS = [
        'claude'     => 'anthropic',
        'gpt'        => 'openai',
        'chatgpt'    => 'openai',
        'davinci'    => 'openai',
        'text-embedding' => 'openai',
        'gemini'     => 'google',
        'gemma'      => 'google',
        'learnlm'    => 'google',
        'palm'       => 'google',
        'deepseek'   => 'deepseek',
        'qwen'       => 'alibaba',
        'qwq'        => 'alibaba',
        'qvq'        => 'alibaba',
        'grok'       => 'xai',
        'llama'      => 'meta',
        'codellama'  => 'meta',
        'mistral'    => 'mistral',
        'mixtral'    => 'mistral',
        'magistral'  => 'mistral',
        'devstral'   => 'mistral',
        'codestral'  => 'mistral',
        'pixtral'    => 'mistral',
        'ministral'  => 'mistral',
        'command'    => 'cohere',
        'aya'        => 'cohere',
        'glm'        => 'zhipu',
        'chatglm'    => 'zhipu',
        'kimi'       => 'moonshot',
        'phi'        => 'microsoft',
        'nova'       => 'amazon',
        'titan'      => 'amazon',
        'nemotron'   => 'nvidia',
        'sonar'      => 'perplexity',
        'jamba'      => 'ai21',
        'ernie'      => 'baidu',
        'seed'       => 'bytedance',
        'doubao'     => 'bytedance',
        'minimax'    => 'minimax',
        'abab'       => 'minimax',
        'falcon'     => 'tii',
        'reka'       => 'reka',
        'solar'      => 'upstage',
        'yi'         => '01ai',
    ];

    /**
     * Strip the provider prefix, the `~` alias marker and the mode suffix
     * from an id.
     *
     * `:batch` is removed here, at the single funnel both {@see self::of()}
     * and {@see ModelVersion} read the model part through, because it says
     * *how* the id is served and not *what* the model is. Left in place it
     * would become a token of its own: `gpt-4o:batch` would tokenise to the
     * line `gptobatch` while `gpt-4o` is `gpto`, so the two would look like
     * different models, become incomparable, and stop being versioned or
     * grouped together ({@see ModelId}).
     */
    public static function slug(string $model): string
    {
        $id = strtolower(trim($model));
        $slash = strrpos($id, '/');
        if ($slash !== false) {
            $id = substr($id, $slash + 1);
        }
        return ModelId::baseModelId(ltrim($id, '~'));
    }

    /** The `vendor/` prefix, or '' when the id carries none. */
    public static function vendor(string $model): string
    {
        $id = strtolower(trim($model));
        $slash = strrpos($id, '/');
        return $slash === false ? '' : substr($id, 0, $slash);
    }

    /**
     * Classify a model id into a base provider family.
     *
     * Resolution order: explicit `vendor/model` prefix → slug keyword →
     * the vendor prefix verbatim (an unrecognised but real vendor is
     * still its own family, which is what diversification needs) →
     * `unknown`.
     */
    public static function of(string $model): string
    {
        $vendor = self::vendor($model);
        if ($vendor !== '' && isset(self::VENDOR_ALIASES[$vendor])) {
            return self::VENDOR_ALIASES[$vendor];
        }

        $slug = self::slug($model);
        if ($slug !== '') {
            // Longest keyword first so `chatglm` is not decided by `glm`'s
            // position, and `codellama` not by `llama`.
            $keywords = array_keys(self::SLUG_KEYWORDS);
            usort($keywords, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($keywords as $keyword) {
                if (str_contains($slug, $keyword)) {
                    return self::SLUG_KEYWORDS[$keyword];
                }
            }
        }

        if ($vendor !== '') {
            return $vendor;
        }
        return self::UNKNOWN;
    }

    /** Human label for a family slug (falls back to the slug itself). */
    public static function label(string $family): string
    {
        return self::FAMILIES[$family] ?? $family;
    }

    /**
     * Position of a family in the editorial order above.
     *
     * v1.8.60 — used to rank a truncated catalogue: the families an
     * operator is most likely to have keys for come first, so a capped
     * catalogue leads with the vendors that matter. A family that is not in
     * the table sits after every family that is (its vendor prefix was real
     * but unrecognised, so it is neither hidden nor promoted), and the
     * aggregate {@see self::UNKNOWN} bucket goes last.
     *
     * @return int Lower sorts first; always >= 0.
     */
    public static function order(string $family): int
    {
        if ($family === self::UNKNOWN) {
            return count(self::FAMILIES) + 1;
        }
        $index = array_search($family, array_keys(self::FAMILIES), true);
        return $index === false ? count(self::FAMILIES) : (int)$index;
    }

    /**
     * Families the analyzer prompt names explicitly, so a model reply is
     * asked to spread across the vendors an operator is most likely to
     * have keys for.
     *
     * @return array<string,string>
     */
    public static function catalog(): array
    {
        return self::FAMILIES;
    }
}
