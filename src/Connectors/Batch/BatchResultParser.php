<?php
/**
 * BatchResultParser: turn the output JSONL into a per-`custom_id` report.
 *
 * This is where the "nothing is lost silently" requirement is actually
 * enforced. A batch run can degrade in five distinct ways, and each one
 * has to survive the trip back to the operator as a named, per-request
 * fact rather than as a quiet absence:
 *
 *   1. **Out-of-order lines.** The documentation states the result lines
 *      do not follow the input order. The parser therefore builds a map
 *      keyed by `custom_id` and never returns a positional list; callers
 *      that need the pipeline's order re-order by their own request list.
 *   2. **Per-line HTTP failures.** `completed` describes the gateway only.
 *      Rate limits and moderation refusals arrive as `response.status_code`
 *      >= 400 inside otherwise successful lines, so every line is checked
 *      individually and its provider message is preserved.
 *   3. **Malformed lines.** A truncated document, a stray blank line or a
 *      line whose JSON is not an object. These are counted with their line
 *      number and an excerpt, and they never mask the rest of the file.
 *   4. **Requests with no answer at all.** An id that was submitted and
 *      never came back. This is the dangerous one — the whole point of
 *      mapping by `custom_id` is that a missing answer is *detectable*,
 *      and `missingIds()` is what makes it so.
 *   5. **Answers for requests we did not make.** A stale file id, a mixed
 *      upload, a provider bug. Reported rather than ignored, because a
 *      surprise id usually means the wrong file was downloaded.
 *
 * Duplicates are the sixth case: the same `custom_id` twice. The first
 * occurrence wins (deterministic, and the provider's own order), and the
 * duplicate is recorded so a caller can see the document was abnormal.
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

use App\Core\Logger;

final class BatchResultParser
{
    /** Characters of a broken line kept in the report. */
    public const EXCERPT_CHARS = 200;

    /**
     * Parse an output document.
     *
     * @param string       $jsonl            Raw body of the output file.
     * @param list<string> $expectedCustomIds Every `custom_id` that was
     *        submitted. Drives the missing/unexpected split; pass an empty
     *        list to skip that analysis entirely.
     */
    public static function parse(string $jsonl, array $expectedCustomIds = []): BatchResultReport
    {
        $expected = [];
        foreach ($expectedCustomIds as $id) {
            if (is_string($id) && $id !== '') {
                $expected[$id] = true;
            }
        }

        /** @var array<string,BatchResultEntry> $entries */
        $entries     = [];
        $invalidLines = [];
        $duplicates   = [];
        $lineCount    = 0;
        $sequence     = 0;

        // A trailing newline is the normal shape of a JSONL file, so the
        // split is on "\n" and every line is trimmed. Splitting on the
        // line terminator and dropping empties is what makes a blank line
        // *not* an error while a line of garbage is.
        foreach (explode("\n", $jsonl) as $offset => $rawLine) {
            $lineNumber = $offset + 1;
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }
            $lineCount++;

            $decoded = json_decode($line, true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                $invalidLines[] = [
                    'line'    => $lineNumber,
                    'reason'  => 'line is not a JSON object',
                    'excerpt' => self::excerpt($line),
                ];
                continue;
            }
            $customId = $decoded['custom_id'] ?? null;
            if (!is_string($customId) || trim($customId) === '') {
                $invalidLines[] = [
                    'line'    => $lineNumber,
                    'reason'  => 'line has no custom_id, so it cannot be matched to a request',
                    'excerpt' => self::excerpt($line),
                ];
                continue;
            }
            $customId = trim($customId);
            if (isset($entries[$customId])) {
                $duplicates[] = $customId;
                continue;
            }
            $entries[$customId] = self::entry($customId, $decoded, $sequence++);
        }

        $missing    = [];
        $unexpected = [];
        if ($expected !== []) {
            foreach (array_keys($expected) as $id) {
                if (!isset($entries[$id])) {
                    $missing[] = $id;
                }
            }
            foreach (array_keys($entries) as $id) {
                if (!isset($expected[$id])) {
                    $unexpected[] = $id;
                }
            }
        }

        return new BatchResultReport(
            entries:     $entries,
            lineCount:   $lineCount,
            invalidLines: $invalidLines,
            missingIds:  $missing,
            unexpectedIds: $unexpected,
            duplicateIds: array_values(array_unique($duplicates)),
        );
    }

    /**
     * Normalize one decoded line into a {@see BatchResultEntry}.
     *
     * Both envelope generations are accepted. The documented one nests the
     * status under `response`; a flattened `status_code`/`body` at the top
     * level is tolerated as well, because a provider that returns the
     * inner object directly would otherwise produce a report in which
     * every row looks like a missing answer.
     *
     * @param array<string,mixed> $row
     */
    private static function entry(string $customId, array $row, int $sequence): BatchResultEntry
    {
        $response = is_array($row['response'] ?? null) ? $row['response'] : [];
        $status   = $response['status_code'] ?? $row['status_code'] ?? 0;
        $status   = is_numeric($status) ? (int)$status : 0;

        $body = $response['body'] ?? $row['body'] ?? null;
        $body = is_array($body) ? $body : null;

        // The provider's cause can sit in the top-level `error` (the
        // request never ran) or inside the response body (the model was
        // called and refused). Either is a real per-line failure.
        $message = BatchHttpResponse::extractProviderMessage($row);
        if ($message === '' && $body !== null) {
            $message = BatchHttpResponse::extractProviderMessage($body);
        }
        if ($message === '' && $status === 0) {
            // No status and no message: the line is syntactically an
            // envelope but carries no outcome. Saying so beats treating it
            // as HTTP 0 "success".
            $message = 'the row carries neither a status_code nor an error message';
        }
        if ($message === '' && $body === null && $status >= 200 && $status < 300) {
            $message = 'the row carries no completion body';
        }

        $requestId = $response['request_id'] ?? $row['id'] ?? '';
        return new BatchResultEntry(
            customId:       $customId,
            statusCode:     $status,
            body:           $body,
            errorMessage:   $message === '' ? null : $message,
            batchRequestId: is_string($requestId) ? $requestId : '',
        );
    }

    private static function excerpt(string $line): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $line) ?? $line);
        if (mb_strlen($flat) > self::EXCERPT_CHARS) {
            $flat = mb_substr($flat, 0, self::EXCERPT_CHARS) . '…';
        }
        return Logger::sanitizeScalar($flat);
    }
}
