<?php
/**
 * CreatorController: Renders the public author info page.
 *
 * Triggered by `?creator` on any URL — must NEVER expose configuration,
 * keys, paths, or other system data.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Helpers\Utf8Helper;
use App\Http\Response;

final class CreatorController
{
    public function show(): string
    {
        $version = Utf8Helper::escape((string)Config::get('app.version', '1.0.0'));
        $body = <<<HTML
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RevoAp — Creator</title>
<style>
body{font-family:ui-monospace,Menlo,Consolas,monospace;background:#0d1117;color:#e6edf3;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
pre{background:#161b22;padding:1.6rem 2rem;border-radius:8px;border:1px solid #30363d;line-height:1.6}
a{color:#58a6ff;text-decoration:none}
</style></head>
<body><pre>RevoAp — AI Consensus Platform
Author : Mikhail Deynekin
Site   : <a href="https://Deynekin.com">https://Deynekin.com</a>
Email  : mikhail@deynekin.com
GitHub : <a href="https://github.com/paulmann/AI-RevoAp">https://github.com/paulmann/AI-RevoAp</a>
Version: {$version}</pre></body></html>
HTML;
        return Response::html($body);
    }
}
