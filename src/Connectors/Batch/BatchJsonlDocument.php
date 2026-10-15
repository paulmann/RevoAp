<?php
/**
 * BatchJsonlDocument: a rendered input file plus what was refused from it.
 *
 * The Batch API validates the upload as a whole: a single malformed line,
 * a duplicate `custom_id` or a bare `custom_id` outside the documented
 * grammar turns the entire batch `failed` with no per-line explanation and
 * no work done. There is no partial acceptance to fall back on.
 *
 * That makes "validate locally first" not an optimisation but the only
 * way to get a useful error message. So the builder reports both halves:
 * the bytes that would be uploaded, and every entry it refused, each with
 * the reason. A caller that ignores `rejected` would be uploading a file
 * it already knows is short.
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

final readonly class BatchJsonlDocument
{
    /**
     * @param string $content  The JSONL bytes (each line ends with "\n").
     * @param int    $lineCount Number of lines actually written.
     * @param list<array{custom_id:string,reason:string}> $rejected Entries
     *        dropped before rendering, with the reason, in input order.
     */
    public function __construct(
        public string $content,
        public int    $lineCount,
        public array  $rejected = [],
    ) {}

    /** Nothing usable to upload — the batch must not be submitted. */
    public function isEmpty(): bool
    {
        return $this->lineCount === 0;
    }

    /** Did every supplied entry survive validation? */
    public function isComplete(): bool
    {
        return $this->rejected === [];
    }

    /** Byte size, for the operator-facing "uploading N KB" detail. */
    public function byteSize(): int
    {
        return strlen($this->content);
    }

    /**
     * `custom_id`s present in the rendered document, in line order. Used
     * by the client to record what was asked for before the batch is
     * submitted, so the parser can later report which of them never came
     * back.
     *
     * @return list<string>
     */
    public function customIds(): array
    {
        $ids = [];
        foreach (explode("\n", $this->content) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $row = json_decode($line, true);
            $id  = is_array($row) ? ($row['custom_id'] ?? null) : null;
            if (is_string($id) && $id !== '') { $ids[] = $id; }
        }
        return $ids;
    }
}
