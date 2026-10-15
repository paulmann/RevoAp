<?php
/**
 * ConsensusEngine: Computes an agreement score across normalized responses.
 *
 * Two scoring backends are available (selectable via
 * `pipeline.consensus.scoring` in config.php or per-request runtime
 * options):
 *
 *   - `semantic_components` (default, v1.8.17+): delegates to
 *     {@see SemanticConsensusEngine}, which extracts per-answer
 *     semantic components, clusters them across models with
 *     keyword/character-ngram/numeric/entity similarity, and scores
 *     consensus by the fraction of cross-model semantic content
 *     covered by shared clusters.
 *
 *   - `lexical_jaccard` (legacy v1.0..1.8.16): pairwise Jaccard over
 *     token bags collected from each response's claims. Preserved for
 *     backwards compatibility and so operators can compare scoring
 *     strategies side by side.
 *
 * The selection is honoured *per call* — pipelines can pass a custom
 * mode through {@see ConsensusEngine::computeWithMode()} so a
 * user-selected "consensus skill" can override the default for one run
 * without mutating global config.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  2.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Consensus;

use App\Core\Config;
use App\Helpers\Utf8Helper;

final class ConsensusEngine
{
    public const MODE_SEMANTIC = 'semantic_components';
    public const MODE_LEXICAL  = 'lexical_jaccard';

    private SemanticConsensusEngine $semantic;

    public function __construct(?SemanticConsensusEngine $semantic = null)
    {
        $this->semantic = $semantic ?? new SemanticConsensusEngine();
    }

    /**
     * @param array<int,array<string,mixed>> $normalizedResponses Result of NormalizationService::normalize() per role.
     */
    public function compute(array $normalizedResponses): ConsensusResult
    {
        $mode = $this->defaultMode();
        return $this->computeWithMode($normalizedResponses, $mode);
    }

    /**
     * @param array<int,array<string,mixed>> $normalizedResponses
     * @param string $mode One of self::MODE_* constants.
     */
    public function computeWithMode(array $normalizedResponses, string $mode): ConsensusResult
    {
        $mode = strtolower(trim($mode));
        if ($mode === '' || ($mode !== self::MODE_LEXICAL && $mode !== self::MODE_SEMANTIC)) {
            $mode = self::MODE_SEMANTIC;
        }
        if ($mode === self::MODE_LEXICAL) {
            return $this->computeLexical($normalizedResponses);
        }
        return $this->semantic->compute($normalizedResponses);
    }

    /**
     * Resolve the active default mode. Reads `pipeline.consensus.scoring`
     * from Config; falls back to semantic when unset / unrecognised.
     */
    public function defaultMode(): string
    {
        try {
            $cfg = Config::get('pipeline.consensus.scoring', self::MODE_SEMANTIC);
        } catch (\Throwable) {
            // Config not loaded yet (e.g. tests instantiate ConsensusEngine
            // directly). Fall back to the semantic default.
            return self::MODE_SEMANTIC;
        }
        if (!is_string($cfg)) { return self::MODE_SEMANTIC; }
        $cfg = strtolower(trim($cfg));
        return ($cfg === self::MODE_LEXICAL) ? self::MODE_LEXICAL : self::MODE_SEMANTIC;
    }

    /**
     * Legacy token-bag Jaccard scoring. Preserved verbatim so operators
     * who depended on the v1.0 numbers can still opt back in.
     *
     * @param array<int,array<string,mixed>> $normalizedResponses
     */
    private function computeLexical(array $normalizedResponses): ConsensusResult
    {
        $bags = [];
        foreach ($normalizedResponses as $resp) {
            $claims = $resp['claims'] ?? [];
            if (!is_array($claims) || $claims === []) { continue; }
            $bags[] = $this->bagOfTokens(implode(' ', array_map('strval', $claims)));
        }

        if ($bags === []) {
            return new ConsensusResult(0.0, 'conflict',
                'No comparable claims produced by any role.',
                ['scoring' => self::MODE_LEXICAL]);
        }
        if (count($bags) === 1) {
            return new ConsensusResult(1.0, 'agreement',
                'Single role responded; trivial consensus.',
                ['scoring' => self::MODE_LEXICAL, 'n_roles' => 1]);
        }

        $n     = count($bags);
        $pairs = 0;
        $sum   = 0.0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $sum += $this->jaccard($bags[$i], $bags[$j]);
                $pairs++;
            }
        }
        $score  = $pairs > 0 ? $sum / $pairs : 0.0;
        $status = match (true) {
            $score >= 0.85 => 'agreement',
            $score >= 0.55 => 'partial',
            default        => 'conflict',
        };
        $summary = match ($status) {
            'agreement' => 'Strong agreement across roles.',
            'partial'   => 'Partial overlap with notable divergence.',
            'conflict'  => 'Significant disagreement between roles.',
        };
        return new ConsensusResult($score, $status, $summary, [
            'scoring' => self::MODE_LEXICAL,
            'pairs'   => $pairs,
            'n_roles' => $n,
        ]);
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private function jaccard(array $a, array $b): float
    {
        $sa = array_unique($a);
        $sb = array_unique($b);
        $inter = count(array_intersect($sa, $sb));
        $union = count(array_unique(array_merge($sa, $sb)));
        return $union === 0 ? 0.0 : $inter / $union;
    }

    /** @return string[] */
    private function bagOfTokens(string $text): array
    {
        $text = strtolower(Utf8Helper::toUtf8($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $stop  = ['a','an','the','of','to','and','or','for','with','is','are','be','in','on','at','it','by','i','this','that','as','from','we','you','our','your','their','they'];
        return array_values(array_filter(
            $parts,
            static fn(string $w): bool => mb_strlen($w) >= 3 && !in_array($w, $stop, true)
        ));
    }
}
