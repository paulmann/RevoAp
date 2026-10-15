<?php
/**
 * DocumentShareController: publish one block of text as a document.
 *
 * The Share button next to every Copy / Copy MD pair posts the block's topic
 * and its Markdown here. The endpoint writes `share/<slug>.md` next to
 * index.php - the same directory History's export uses, and the directory
 * MD.Viewer lists by default (BROWSE_DIR = /share/) - and answers with the
 * link the operator should hand to someone else:
 *
 *   - viewer installed     -> <base>/extensions/<product>/md.php?file=<name>
 *                             (MD.Scribe since v2.1.0; a legacy MD.Viewer
 *                             install keeps its own path — the root md.php
 *                             answers the identical ?file= contract)
 *   - not installed        -> <base>/share/<name>
 *
 * Both URLs come back in every answer (`url` is the one to copy, `viewer_url`
 * is absent when there is no viewer), so the client never has to know how the
 * deployment is laid out.
 *
 * The topic reaches a path only through {@see DocumentSlug}, which emits pure
 * ASCII from a whitelist that cannot carry a separator, a `..` or a control
 * character; the resolved directory is compared against realpath() again
 * before the write, so a symlink inside share/ cannot redirect it either.
 *
 * No database, no session and no History tab are involved: sharing a block
 * works on any deployment that can run the app.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-27
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Logger;
use App\Extensions\MdViewerInstaller;
use App\Helpers\JsonHelper;
use App\History\DocumentSlug;
use App\Http\Response;

final class DocumentShareController
{
    /** Route this controller answers on; assets/js/copy-buttons.js posts here. */
    public const ENDPOINT = '/api/share/document';

    /** Largest document accepted, in bytes. A 45 KB answer is typical; 2 MiB is generous. */
    private const MAX_BYTES = 2097152;

    /** Longest topic accepted, in characters. The slug is capped separately. */
    private const MAX_TOPIC_CHARS = 500;

    /** Directory under the app root that holds published documents. */
    private const SHARE_DIR = 'share';

    public function __construct(
        private readonly string $rootDir,
        private readonly MdViewerInstaller $viewer,
    ) {}

    /**
     * POST /api/share/document
     *
     * Body: {"title": "Final answer", "markdown": "# Final answer\n\n…"}
     * Response: {"ok": true, "file": "final-answer.md", "bytes": 1234,
     *            "installed": true, "url": "https://host/extensions/md-viewer/md.php?file=final-answer.md",
     *            "viewer_url": "https://host/extensions/md-viewer/md.php?file=final-answer.md",
     *            "file_url": "https://host/share/final-answer.md"}
     *
     * Status codes: 200 written; 400 no usable topic or empty document;
     * 405 wrong verb; 413 document over the size cap; 500 share/ could not be
     * created, is not writable, or resolved outside the app root.
     */
    public function create(): string
    {
        $payload = $this->readJsonBody();

        $topic    = isset($payload['title']) && is_string($payload['title']) ? $payload['title'] : '';
        $markdown = isset($payload['markdown']) && is_string($payload['markdown']) ? $payload['markdown'] : '';

        $result = $this->publish($topic, $markdown);
        $http   = (int)($result['http'] ?? 500);
        unset($result['http']);
        return Response::json($result, $http);
    }

    /**
     * Publish markdown under share/ and answer with the same shape create()
     * uses. Public on purpose: the Skills tab opens a skill description
     * through exactly this path, so the write checks, the size limits and
     * the MD.Viewer URL can never drift into a second copy.
     *
     * @return array<string,mixed> the response payload plus an `http` hint
     *   the callers strip before answering
     */
    public function publish(string $topic, string $markdown): array
    {
        $topic = trim($topic);
        if (function_exists('mb_substr') && mb_strlen($topic, 'UTF-8') > self::MAX_TOPIC_CHARS) {
            $topic = mb_substr($topic, 0, self::MAX_TOPIC_CHARS, 'UTF-8');
        }
        if (trim($markdown) === '') {
            return [
                'http'    => 400,
                'ok'      => false,
                'error'   => 'empty_document',
                'message' => 'There is nothing to share: markdown is required and must not be empty.',
            ];
        }
        if (strlen($markdown) > self::MAX_BYTES) {
            return [
                'http'    => 413,
                'ok'      => false,
                'error'   => 'document_too_large',
                'message' => 'The document is larger than the ' . self::MAX_BYTES . '-byte limit.',
                'limit'   => self::MAX_BYTES,
                'bytes'   => strlen($markdown),
            ];
        }

        $slug = DocumentSlug::make($topic);
        if ($slug === '') {
            return [
                'http'    => 400,
                'ok'      => false,
                'error'   => 'invalid_title',
                'message' => 'The block has no topic that can name a document.',
            ];
        }
        // Defense in depth: DocumentSlug cannot emit these, so reaching this
        // branch means the invariant was broken elsewhere.
        if (str_contains($slug, '/') || str_contains($slug, '\\')) {
            Logger::warn('share.document.slug_rejected', ['slug' => Logger::sanitizeScalar($slug)]);
            return [
                'http'    => 400,
                'ok'      => false,
                'error'   => 'invalid_title',
                'message' => 'The block topic could not be turned into a safe file name.',
            ];
        }

        $dir = $this->shareDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [
                'http'    => 500,
                'ok'      => false,
                'error'   => 'share_not_writable',
                'message' => 'Could not create the share/ directory next to index.php.',
            ];
        }
        $realDir = realpath($dir);
        if ($realDir === false) {
            return [
                'http'    => 500,
                'ok'      => false,
                'error'   => 'share_not_writable',
                'message' => 'The share/ directory could not be resolved.',
            ];
        }

        $name = $slug . '.md';
        $path = $realDir . DIRECTORY_SEPARATOR . $name;
        $realParent = realpath(dirname($path));
        if ($realParent === false || $realParent !== $realDir) {
            Logger::warn('share.document.outside_share_dir', [
                'file'   => $name,
                'parent' => Logger::sanitizeScalar((string)$realParent),
            ]);
            return [
                'http'    => 500,
                'ok'      => false,
                'error'   => 'share_not_writable',
                'message' => 'The document would not land inside share/.',
            ];
        }

        $bytes = @file_put_contents($path, $markdown, LOCK_EX);
        if ($bytes === false) {
            return [
                'http'    => 500,
                'ok'      => false,
                'error'   => 'write_failed',
                'message' => 'The share/ directory is not writable by the web process.',
            ];
        }

        // v2.1.0 (T4): the link follows whichever viewer is actually
        // installed (MD.Scribe preferred over a legacy MD.Viewer), instead
        // of hardcoding one product's directory.
        $viewerDir = $this->viewerInstalledDir();
        $installed = $viewerDir !== null;
        $base      = $this->baseUrl();
        $fileUrl   = $base . '/' . self::SHARE_DIR . '/' . rawurlencode($name);
        $viewerUrl = $installed
            ? $base . '/' . $viewerDir . '/md.php?file=' . rawurlencode($name)
            : '';

        Logger::info('share.document.written', [
            'file'      => $name,
            'bytes'     => $bytes,
            'installed' => $installed,
        ]);

        return [
            'http'       => 200,
            'ok'         => true,
            'file'       => $name,
            'bytes'      => $bytes,
            'installed'  => $installed,
            'url'        => $installed ? $viewerUrl : $fileUrl,
            'viewer_url' => $viewerUrl,
            'file_url'   => $fileUrl,
        ];
    }

    /**
     * GET /api/share/document - publishing is a POST; say so precisely
     * instead of letting the router answer 404.
     */
    public function methodNotAllowed(): string
    {
        if (!headers_sent()) {
            header('Allow: POST');
        }
        return Response::json([
            'ok'      => false,
            'error'   => 'method_not_allowed',
            'message' => 'Use POST ' . self::ENDPOINT . ' with {"title": "…", "markdown": "…"}.',
            'allow'   => 'POST',
        ], 405);
    }

    private function shareDir(): string
    {
        return rtrim($this->rootDir, '/\\') . '/' . self::SHARE_DIR;
    }

    /**
     * Is MD.Viewer installed next to the app? Its own installer owns the
     * answer - the same probe the Extensions tab uses.
     */
    /**
     * Relative directory of the installed viewer (md-scribe preferred), or
     * null. Probe failures answer "not installed" — a share link that 404s
     * because a probe threw is worse than the plain file link.
     */
    private function viewerInstalledDir(): ?string
    {
        try {
            return $this->viewer->installedViewerDir();
        } catch (\Throwable $e) {
            Logger::warn('share.document.viewer_probe_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return null;
        }
    }

    /**
     * Absolute base of this deployment, without a trailing slash. Mirrors
     * ShareController::buildShareUrl(): the configured app.url when set, the
     * request otherwise, so a link pasted elsewhere still resolves.
     */
    private function baseUrl(): string
    {
        // Config::get() throws before Config::load() has run (a CLI or test
        // context). The document is already on disk at this point, so a
        // missing config must degrade to the request host, not to a 500.
        $base = '';
        try {
            $base = trim((string)Config::get('app.url', ''));
        } catch (\Throwable) {
            $base = '';
        }
        if ($base !== '') {
            return rtrim($base, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = 'localhost';
        }
        return $scheme . '://' . $host;
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return $_POST;
        }
        $parsed = JsonHelper::tryDecode($raw);
        return is_array($parsed) ? $parsed : [];
    }
}
