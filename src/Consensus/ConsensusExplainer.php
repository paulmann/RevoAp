<?php
/**
 * ConsensusExplainer: Builds a human-readable explanation of an L3
 * consensus result so the pipeline UI no longer surfaces a bare phrase
 * such as "Significant disagreement between roles." that left users
 * unsure what was decided and what the result actually means.
 *
 * The explainer answers three questions in plain language:
 *   1. WHAT was evaluated   — normalized claims from L1 roles.
 *   2. WHO participated     — list of role keys + count, when known.
 *   3. WHAT WAS DECIDED     — consensus / partial / conflict, score,
 *                             pair count, and a closing conclusion
 *                             (consensus reached, partial agreement,
 *                             or treated as non-consensus).
 *
 * Structured output is also returned so the API and frontend can
 * render the same information consistently for live and loaded
 * sessions, while preserving the raw machine-readable consensus
 * payload for the Raw tab.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Consensus;

final class ConsensusExplainer
{
    /**
     * Build a structured explanation of a ConsensusResult.
     *
     * @param  ConsensusResult $result   Raw scoring output.
     * @param  array<int,string> $roleKeys  Optional role keys that
     *         participated in L3 (the order matches the L2 input).
     * @return array{
     *   summary:string,
     *   text:string,
     *   what:string,
     *   participants:array{count:int,roles:array<int,string>},
     *   decision:array{status:string,score:float,score_pct:int,pairs:int,label:string},
     *   conclusion:string,
     *   raw:array<string,mixed>
     * }
     */
    public function explain(ConsensusResult $result, array $roleKeys = []): array
    {
        $score   = $result->score;
        $scorePct = (int)round($score * 100);
        $status  = $result->status;
        $meta    = $result->meta;
        $nRoles  = isset($meta['n_roles']) ? (int)$meta['n_roles'] : count($roleKeys);
        $pairs   = isset($meta['pairs']) ? (int)$meta['pairs'] : 0;

        $label = match ($status) {
            'agreement' => 'consensus reached',
            'partial'   => 'partial agreement',
            'conflict'  => 'no consensus (treat L4 output as fallback synthesis)',
            default     => $status,
        };

        $scoring = isset($meta['scoring']) && is_string($meta['scoring'])
            ? (string)$meta['scoring']
            : 'lexical_jaccard';
        $clusters = isset($meta['clusters']) ? (int)$meta['clusters'] : 0;
        $sharedClusters = isset($meta['shared_clusters']) ? (int)$meta['shared_clusters'] : 0;
        $previewShared = is_array($meta['preview_shared'] ?? null) ? $meta['preview_shared'] : [];
        $hasSemanticMeta = $scoring === 'semantic_components' && $clusters > 0;
        if ($hasSemanticMeta) {
            $what = 'Extracted semantic components from each L1 role answer (claims / bullets / '
                  . 'sentences), clustered them across models using keyword stems, character n-gram '
                  . 'similarity and shared numeric/entity hints, then scored consensus by the fraction '
                  . 'of cross-model semantic content covered by shared clusters.';
        } else {
            $what = 'Compared normalized claims produced by L2 from each L1 role to '
                  . 'measure how much their key statements overlap.';
        }

        $rolesList = $roleKeys === []
            ? 'roles not recorded'
            : implode(', ', array_map(static fn(string $k): string => '"' . $k . '"', $roleKeys));

        $participantsLine = $nRoles > 0
            ? sprintf('Participants: %d role%s (%s).', $nRoles, $nRoles === 1 ? '' : 's', $rolesList)
            : 'Participants: none recorded.';

        if ($hasSemanticMeta) {
            $decisionLine = sprintf(
                'Decision: %s — score %s (%d%%) over %d semantic cluster%s (%d shared across models out of %d total).',
                $status,
                number_format($score, 3),
                $scorePct,
                $sharedClusters,
                $sharedClusters === 1 ? '' : 's',
                $sharedClusters,
                $clusters,
            );
        } else {
            $decisionLine = sprintf(
                'Decision: %s — score %s (%d%%) over %d pair%s.',
                $status,
                number_format($score, 3),
                $scorePct,
                $pairs,
                $pairs === 1 ? '' : 's',
            );
        }
        if ($previewShared !== []) {
            $decisionLine .= ' Shared topics: ' . implode('; ', array_slice($previewShared, 0, 4)) . '.';
        }

        $conclusion = match ($status) {
            'agreement' => 'Result: roles converged on the same key claims; the synthesized answer reflects a stable consensus.',
            'partial'   => 'Result: roles agree on a substantial subset of claims but diverge on the rest; treat the synthesis as a balanced compromise rather than a unanimous answer.',
            'conflict'  => 'Result: no consensus — roles produced largely disjoint claims, so the L4 synthesis should be read as a best-effort summary, not a confirmed answer.',
            default     => 'Result: ' . $result->summary,
        };

        $text = implode("\n", [
            'What was evaluated: ' . $what,
            $participantsLine,
            $decisionLine,
            $conclusion,
        ]);

        return [
            'summary'      => $result->summary,
            'text'         => $text,
            'what'         => $what,
            'participants' => [
                'count' => $nRoles,
                'roles' => array_values($roleKeys),
            ],
            'decision'     => [
                'status'           => $status,
                'score'            => $score,
                'score_pct'        => $scorePct,
                'pairs'            => $pairs,
                'label'            => $label,
                'scoring'          => $scoring,
                'clusters'         => $clusters,
                'shared_clusters'  => $sharedClusters,
                'preview_shared'   => $previewShared,
            ],
            'conclusion'   => $conclusion,
            'raw'          => $result->toArray(),
        ];
    }
}
