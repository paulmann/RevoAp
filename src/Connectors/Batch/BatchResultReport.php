<?php
/**
 * BatchResultReport: the full account of one downloaded result file.
 *
 * Holds the three collections a caller has to act on and nothing else:
 * the answers keyed by `custom_id`, the lines that could not be read, and
 * the requests whose answers never arrived. Everything is exposed as
 * iterators/arrays over the same internal maps, so the order a document
 * happened to use never leaks into the pipeline: participants are ordered
 * by the caller's own request list.
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

final readonly class BatchResultReport
{
    /**
     * @param array<string,BatchResultEntry>                $entries       keyed by custom_id
     * @param int                                          $lineCount     non-blank lines read
     * @param list<array{line:int,reason:string,excerpt:string}> $invalidLines
     * @param list<string>                                 $missingIds    requested, never answered
     * @param list<string>                                 $unexpectedIds answered, never requested
     * @param list<string>                                 $duplicateIds  appeared more than once
     */
    public function __construct(
        public array $entries,
        public int   $lineCount,
        public array $invalidLines = [],
        public array $missingIds = [],
        public array $unexpectedIds = [],
        public array $duplicateIds = [],
    ) {}

    /**
     * The entry for a `custom_id`, or null when the document carried none.
     * The single accessor the accept phase uses — a null here is exactly
     * the "no answer arrived" case that must not be silently dropped.
     */
    public function entry(string $customId): ?BatchResultEntry
    {
        return $this->entries[$customId] ?? null;
    }

    /** @return list<string> */
    public function customIds(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Ids whose row produced a usable chat completion.
     *
     * @return list<string>
     */
    public function successfulIds(): array
    {
        $out = [];
        foreach ($this->entries as $id => $entry) {
            if ($entry->isSuccess()) { $out[] = (string)$id; }
        }
        return $out;
    }

    /**
     * Rows the provider refused, each with its own cause.
     *
     * @return list<array{custom_id:string,status_code:int,reason:string}>
     */
    public function failures(): array
    {
        $out = [];
        foreach ($this->entries as $id => $entry) {
            $reason = $entry->failureReason();
            if ($reason === null) { continue; }
            $out[] = [
                'custom_id'   => (string)$id,
                'status_code' => $entry->statusCode,
                'reason'      => $reason,
            ];
        }
        return $out;
    }

    /**
     * Did anything at all go wrong with this document?
     *
     * Deliberately broad: an unexpected id or a duplicate means the
     * document is not the one this run produced, which the operator needs
     * to know even when every requested id happened to be answered.
     */
    public function hasProblems(): bool
    {
        return $this->invalidLines !== []
            || $this->missingIds !== []
            || $this->unexpectedIds !== []
            || $this->duplicateIds !== []
            || $this->failures() !== [];
    }

    /**
     * One-line summary for the job envelope and the log. Counts only —
     * every individual fact lives in the collections above, and the log
     * line that accompanies this carries them in full.
     */
    public function summary(): string
    {
        return sprintf(
            'rows=%d answered=%d failed=%d missing=%d invalid=%d unexpected=%d duplicate=%d',
            $this->lineCount,
            count($this->successfulIds()),
            count($this->failures()),
            count($this->missingIds),
            count($this->invalidLines),
            count($this->unexpectedIds),
            count($this->duplicateIds),
        );
    }

    /**
     * Compact, browser-safe form for the job result envelope, so the
     * batch card in the UI can list what was lost without a second parse.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'line_count'     => $this->lineCount,
            'answered'       => count($this->successfulIds()),
            'failed'         => $this->failures(),
            'missing_ids'    => $this->missingIds,
            'invalid_lines'  => $this->invalidLines,
            'unexpected_ids' => $this->unexpectedIds,
            'duplicate_ids'  => $this->duplicateIds,
        ];
    }
}
