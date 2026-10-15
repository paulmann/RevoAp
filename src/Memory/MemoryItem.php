<?php
/**
 * MemoryItem: Domain value object for the vector memory subsystem.
 *
 * Adapted (Task 5) from the PROTO prototype
 * Consensus-AI-main/src/Domain/Memory/MemoryItem.php to the project
 * style: readonly public properties instead of get* accessors, plain
 * string constants for the type taxonomy, and a fromRow() factory used
 * by PdoMemoryRepository.
 *
 * The backing `memory_items` table is declared in db/schema.sql and
 * shipped incrementally as the idempotent migration
 * db/migrations/014_memory_items.sql (identical DDL, so a fresh import
 * and an applied migration converge on the same shape). The PROTO
 * defect of referencing a table nothing creates is intentionally NOT
 * copied.
 *
 * A memory item stores one unit of remembered context (a fact, decision,
 * argument or raw text) that becomes searchable in the vector store
 * under the 'memory' collection.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

namespace App\Memory;

use App\Helpers\JsonHelper;

final readonly class MemoryItem
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_ARGUMENT = 'argument';
    public const TYPE_DECISION = 'decision';
    public const TYPE_CONTEXT  = 'context';
    public const TYPE_FACT     = 'fact';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_ARGUMENT,
        self::TYPE_DECISION,
        self::TYPE_CONTEXT,
        self::TYPE_FACT,
    ];

    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly ?int   $id,
        public readonly int    $sessionId,
        public readonly string $content,
        public readonly array  $metadata = [],
        public readonly string $type = self::TYPE_TEXT,
        public readonly ?string $createdAt = null,
    ) {}

    /**
     * Build an item from a `memory_items` database row.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $meta = JsonHelper::tryDecode((string)($row['metadata'] ?? '') ?: '{}', []);
        return new self(
            id:        isset($row['id']) ? (int)$row['id'] : null,
            sessionId: (int)($row['session_id'] ?? 0),
            content:   (string)($row['content'] ?? ''),
            metadata:  is_array($meta) ? $meta : [],
            type:      (string)($row['type'] ?? self::TYPE_TEXT),
            createdAt: isset($row['created_at']) ? (string)$row['created_at'] : null,
        );
    }

    /**
     * The exact text that gets embedded and searched. Subclasses of
     * context can enrich it via metadata instead.
     */
    public function searchPayload(): string
    {
        return trim($this->content);
    }

    /**
     * Attach the item's denormalised identity to arbitrary metadata
     * before it reaches the vector store, so a search hit can always
     * answer "which session and type produced this memory?" without a
     * join to memory_items.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function vectorMetadata(array $extra = []): array
    {
        return array_merge([
            'session_id' => $this->sessionId,
            'type'       => $this->type,
            'content'    => $this->truncateContent(),
        ], $extra);
    }

    /**
     * Cap the content carried inside vector metadata: vector payloads
     * must stay small even when the original text is long. The full
     * content remains in `memory_items.content`.
     */
    private function truncateContent(): string
    {
        $text = $this->searchPayload();
        return mb_strlen($text) > 400 ? mb_substr($text, 0, 399) . '…' : $text;
    }
}
