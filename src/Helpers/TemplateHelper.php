<?php
/**
 * TemplateHelper: Renders HTML templates from assets/templates/.
 *
 * Templates are pure HTML with `{{varName}}` placeholders. All values are
 * HTML-escaped by default. Use `{{varName|raw}}` to inject a value verbatim
 * (only for pre-sanitized markup such as nested template fragments).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Helpers;

final class TemplateHelper
{
    private static ?string $templatesDir = null;

    public static function setTemplatesDir(string $dir): void
    {
        self::$templatesDir = rtrim($dir, '/');
    }

    /**
     * Render a template file with the given variables.
     *
     * @param  string               $template Filename (relative to templates dir, e.g. 'layout.html')
     * @param  array<string,mixed>  $vars     Replacement variables.
     * @return string Rendered HTML.
     * @throws \RuntimeException When the template file is missing.
     */
    public static function render(string $template, array $vars = []): string
    {
        $dir = self::$templatesDir ?? (dirname(__DIR__, 2) . '/assets/templates');
        $path = $dir . '/' . ltrim($template, '/');
        if (!is_file($path)) {
            throw new \RuntimeException("Template not found: {$template}");
        }
        $body = (string)file_get_contents($path);
        return self::interpolate($body, $vars);
    }

    /**
     * Render an inline template string. Same syntax as render().
     *
     * @param  array<string,mixed> $vars
     */
    public static function renderString(string $body, array $vars = []): string
    {
        return self::interpolate($body, $vars);
    }

    /**
     * @param array<string,mixed> $vars
     */
    private static function interpolate(string $body, array $vars): string
    {
        return preg_replace_callback(
            '#\{\{\s*([a-zA-Z0-9_\.]+)(\s*\|\s*raw)?\s*\}\}#',
            static function (array $m) use ($vars): string {
                $key = $m[1];
                $raw = isset($m[2]);
                $val = self::resolve($vars, $key);
                if ($val === null) {
                    return '';
                }
                $str = is_scalar($val) ? (string)$val : JsonHelper::encode($val, false);
                return $raw ? $str : Utf8Helper::escape($str);
            },
            $body
        ) ?? $body;
    }

    /**
     * @param array<string,mixed> $vars
     */
    private static function resolve(array $vars, string $key): mixed
    {
        if (!str_contains($key, '.')) {
            return $vars[$key] ?? null;
        }
        $cur = $vars;
        foreach (explode('.', $key) as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return null;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }
}
