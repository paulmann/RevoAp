<?php
/**
 * MemoryEmbedderInterface: Abstraction for text→vector embedding used
 * by the vector memory subsystem.
 *
 * Implementations:
 *   - App\Memory\ConnectorMemoryEmbedder - resolves an available
 *     connector's embed() endpoint (config keys `memory.embed_provider`
 *     / `memory.embed_model`) and reports availability honestly;
 *   - test doubles (anonymous classes) with deterministic semantic
 *     vectors for offline verification.
 *
 * Contract: embed() NEVER throws - any failure degrades to []. An
 * empty vector means "embedding unavailable"; callers then treat the
 * operation as a no-op ("no memory" behaviour).
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

interface MemoryEmbedderInterface
{
    /**
     * Embed a text into a unit-norm vector.
     *
     * @return list<float> Non-empty vector, or [] when the embedding
     *                     backend is unavailable / the call failed.
     */
    public function embed(string $text): array;

    /**
     * Whether real (model-backed) embeddings can currently be produced.
     * When false, consumers may skip embedding entirely instead of
     * emitting low-quality fallback vectors.
     */
    public function isAvailable(): bool;
}
