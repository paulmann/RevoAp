<?php
/**
 * BatchJsonlBuilder: render the input JSONL for a Batch API run.
 *
 * One line per request, in the documented shape:
 *
 *     {"custom_id":"j42-r1-p0-1f3a9c20","method":"POST",
 *      "url":"/v1/chat/completions","body":{"model":"…","messages":[…]}}
 *
 * `body` is used verbatim as supplied. That matters: the production caller
 * builds it with {@see \App\Connectors\AbstractConnector::buildChatRequest()},
 * which is the *same* pre-flight the synchronous path uses, so a model id,
 * a reasoning object or a `max_tokens` override can never mean two
 * different things depending on which surface the run took. This class
 * only wraps that body in the envelope and validates the envelope.
 *
 * ## What is validated, and why here
 *
 * Everything below is a property the provider checks when it opens the
 * file, and every one of them fails the *whole* batch rather than the one
 * offending line. Checking them locally converts an opaque `failed` batch
 * — "the file is invalid", with no line number — into a precise reason
 * attached to a specific request, before anything is uploaded:
 *
 *   * `custom_id` present, and inside `^[A-Za-z0-9_-]{1,64}$`;
 *   * `custom_id` unique within the document;
 *   * `body` a non-empty object carrying a non-empty `model` string and a
 *     non-empty `messages` list.
 *
 * A rejected entry is *dropped*, not repaired: the missing answer is then
 * reported per-participant at accept time (see {@see BatchResultParser}),
 * which is the same place a provider-side per-model refusal surfaces. The
 * alternative — uploading a line whose `custom_id` was guessed or
 * mangled — would silently attach an answer to the wrong participant.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Connectors\Batch;

use App\Helpers\JsonHelper;

final class BatchJsonlBuilder
{
    /** Documented `method` for every batch line. */
    public const METHOD = 'POST';

    /** Documented `url` for a chat-completions batch. */
    public const CHAT_COMPLETIONS_URL = '/v1/chat/completions';

    /** Default filename. The provider only inspects the extension. */
    public const FILENAME = 'revoap-batch.jsonl';

    public const CONTENT_TYPE = 'application/jsonl';

    /**
     * Render the document.
     *
     * @param list<array{custom_id:string,body:array<string,mixed>}> $entries
     */
    public static function build(array $entries): BatchJsonlDocument
    {
        $lines    = [];
        $rejected = [];
        $seen     = [];

        foreach ($entries as $position => $entry) {
            $customId = is_array($entry) ? (string)($entry['custom_id'] ?? '') : '';
            $body     = is_array($entry) ? ($entry['body'] ?? null) : null;

            if ($customId === '' || !BatchCustomId::isValid($customId)) {
                $rejected[] = [
                    'custom_id' => $customId,
                    'reason'    => $customId === ''
                        ? sprintf('entry #%d has no custom_id', $position)
                        : sprintf(
                            'custom_id "%s" is outside the provider grammar %s',
                            $customId,
                            BatchCustomId::PATTERN,
                        ),
                ];
                continue;
            }
            if (isset($seen[$customId])) {
                // The second occurrence would merge two participants into
                // one answer slot at accept time. Dropping it here keeps
                // the mapping one-to-one, and the drop is reported.
                $rejected[] = [
                    'custom_id' => $customId,
                    'reason'    => sprintf('duplicate custom_id "%s" — the first occurrence was kept', $customId),
                ];
                continue;
            }
            if (!is_array($body) || $body === []) {
                $rejected[] = [
                    'custom_id' => $customId,
                    'reason'    => 'body is missing or empty',
                ];
                continue;
            }
            $model = $body['model'] ?? null;
            if (!is_string($model) || trim($model) === '') {
                $rejected[] = [
                    'custom_id' => $customId,
                    'reason'    => 'body.model is missing or empty',
                ];
                continue;
            }
            $messages = $body['messages'] ?? null;
            if (!is_array($messages) || $messages === []) {
                $rejected[] = [
                    'custom_id' => $customId,
                    'reason'    => 'body.messages is missing or empty',
                ];
                continue;
            }

            $line = JsonHelper::encode([
                'custom_id' => $customId,
                'method'    => self::METHOD,
                'url'       => self::CHAT_COMPLETIONS_URL,
                'body'      => $body,
            ]);
            // A JSON object can never contain a raw newline — json_encode
            // escapes them — but the guard is cheap and makes the
            // one-line-per-entry invariant explicit rather than implied.
            $line = str_replace(["\r\n", "\r", "\n"], ' ', $line);
            $lines[] = $line;
            $seen[$customId] = true;
        }

        $content = $lines === [] ? '' : (implode("\n", $lines) . "\n");
        return new BatchJsonlDocument($content, count($lines), $rejected);
    }

    /**
     * Build one entry envelope. The production caller pairs this with
     * `AbstractConnector::buildChatRequest()['payload']`.
     *
     * @param  array<string,mixed> $body
     * @return array{custom_id:string,body:array<string,mixed>}
     */
    public static function entry(string $customId, array $body): array
    {
        return ['custom_id' => $customId, 'body' => $body];
    }
}
