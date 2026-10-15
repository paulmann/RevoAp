<?php
/**
 * BatchModelCatalog: which models the provider says support background work.
 *
 * `GET /batches/models` is the documented authority on this. The whole
 * reason this class exists is the difference between its two negative
 * answers:
 *
 *   * **unknown** — the call failed, timed out, or answered something we
 *     could not read. We have learned nothing about any model.
 *   * **not listed** — the call succeeded and the id was not in it.
 *
 * Only the first one is ever produced by a failure. That is not a stylistic
 * choice; collapsing the two would mean a transient network error marks
 * every model in the catalogue as "does not support batch", and an operator
 * who then tries to fix their setup has no way to tell that the verdict came
 * from a dropped connection. So the class has no notion of "unsupported" at
 * all: absence from a *successful* response is reported as `unknown` too,
 * because the documented list is a catalogue of what works, not a
 * certificate of what does not — a model can support background processing
 * without appearing in it, and the list can legitimately be partial, paged,
 * or behind an entitlement the current key does not carry.
 *
 * The practical consequence, and the intended one: a batch run proceeds on
 * an `unknown` model and finds out from the provider. It is refused only
 * when the provider itself refuses it.
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

final class BatchModelCatalog
{
    /** The provider answered and the id is in the list. */
    public const CAPABILITY_SUPPORTED = 'supported';

    /**
     * We do not know: the call failed, or the id was not in a successful
     * answer. Never means "unsupported" — see the class docblock.
     */
    public const CAPABILITY_UNKNOWN = 'unknown';

    /**
     * @param array<string,bool> $ids Lower-cased id => true, from a
     *                                successful response only.
     * @param bool               $resolved Whether the provider answered at all.
     */
    private function __construct(
        private readonly array   $ids,
        private readonly bool    $resolved,
        private readonly string  $detail = '',
    ) {}

    /**
     * Build from a decoded `GET /batches/models` body.
     *
     * Three envelope shapes are accepted because the OpenAI-compatible
     * convention has settled on all three across providers: `{"data":[{"id":…}]}`,
     * `{"models":[…]}` and a bare list of string ids. Anything else yields
     * an `unknown` catalogue rather than an empty "nothing is supported"
     * one — the failure mode must be knowledge-free, not negative.
     *
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body): self
    {
        $rows = $body['data'] ?? $body['models'] ?? $body['ids'] ?? null;
        if (!is_array($rows)) {
            // A bare list arrives as a top-level JSON array, which decodes
            // to a list — accepted only when it really is a list.
            if (array_is_list($body) && $body !== []) {
                $rows = $body;
            } else {
                return self::unknown('GET /batches/models answered without a recognisable model list');
            }
        }
        $ids = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : $row;
            if (is_string($id) && trim($id) !== '') {
                $ids[strtolower(trim($id))] = true;
            }
        }
        if ($ids === []) {
            return self::unknown('GET /batches/models answered with an empty model list');
        }
        return new self($ids, true, sprintf('%d batch-capable id(s)', count($ids)));
    }

    /**
     * The "we could not find out" catalogue. Carries the cause so a job
     * report can say *why* the capability is unknown instead of implying
     * the provider has no batch models.
     */
    public static function unknown(string $detail = ''): self
    {
        return new self([], false, Logger::sanitizeScalar($detail));
    }

    /** Did the provider answer? */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /** How many ids the provider listed (0 when unresolved). */
    public function count(): int
    {
        return count($this->ids);
    }

    public function detail(): string
    {
        return $this->detail;
    }

    /**
     * Capability of one id: {@see self::CAPABILITY_SUPPORTED} or
     * {@see self::CAPABILITY_UNKNOWN}. Never `unsupported`.
     */
    public function capability(string $model): string
    {
        if (!$this->resolved) {
            return self::CAPABILITY_UNKNOWN;
        }
        return isset($this->ids[strtolower(trim($model))])
            ? self::CAPABILITY_SUPPORTED
            : self::CAPABILITY_UNKNOWN;
    }

    /** Convenience: is this id in a successful list? */
    public function isListed(string $model): bool
    {
        return $this->capability($model) === self::CAPABILITY_SUPPORTED;
    }

    /**
     * Every listed id, lower-cased, sorted. For diagnostics and for the
     * UI's "which models can run as a batch" hint.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $out = array_keys($this->ids);
        sort($out);
        return $out;
    }

    /**
     * Browser-safe snapshot. The UI needs the tri-state fact ("the list is
     * unknown") far more than it needs the ids themselves, so both are
     * present, but `resolved` is what the badge logic reads.
     *
     * @return array{resolved:bool,count:int,detail:string,ids:list<string>}
     */
    public function toArray(): array
    {
        return [
            'resolved' => $this->resolved,
            'count'    => count($this->ids),
            'detail'   => $this->detail,
            'ids'      => $this->ids(),
        ];
    }

    /** A catalogue holding exactly the supplied ids — the offline seam. */
    public static function of(array $models): self
    {
        $ids = [];
        foreach ($models as $model) {
            if (is_string($model) && trim($model) !== '') {
                $ids[strtolower(trim($model))] = true;
            }
        }
        return new self($ids, true, sprintf('%d batch-capable id(s)', count($ids)));
    }
}
