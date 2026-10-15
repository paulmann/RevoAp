<?php
declare(strict_types=1);

namespace App\Models;

/**
 * R9 extension - what the runs say about each model's usefulness.
 *
 * The engine records, for every consensus round, how much of each answer the
 * other participants corroborated (`share` = its slice of the corroborated
 * total, `ratio` = the part of its own answer that was corroborated). This class
 * folds those records across runs into something an operator can act on.
 *
 * Two rules shape the fold, and both exist to keep the number honest:
 *
 *   1. **A non-answer is not a bad answer.** A model that timed out, was rate
 *      limited, refused by the provider, or dropped by the call breaker
 *      contributes nothing to the negative side. Those cases are counted
 *      separately as `non_answers` with their reason, because "the network was
 *      down" and "this model's answers are consistently not corroborated" are
 *      different facts about a model, and only the second one is about the
 *      model at all.
 *   2. **Positive and negative are absolute, not zero-sum.** `strongly` counts
 *      rounds where most of the model's content was corroborated; `weakly`
 *      counts rounds where almost none of it was. A round can be neither, and a
 *      model is not "negative" merely because another model was corroborated
 *      more - a share of agreement would otherwise always average out.
 *
 * Pure: it folds arrays in, and never queries. The reading side lives in the
 * controller that hands it rows.
 */
final class ModelUsefulness
{
    /** A ratio at or above this counts as "its answer was largely corroborated". */
    public const STRONG_RATIO = 0.5;

    /** A ratio at or below this counts as "almost none of it was corroborated". */
    public const WEAK_RATIO = 0.2;

    /**
     * Fold consensus-round contributions.
     *
     * @param array<int,array<string,mixed>> $rounds
     *        Each row is the `contribution` object recorded on an L3 step:
     *        `{scoring, sum, models: [{model, provider, role, share, ratio}]}`.
     * @return array<string,array{model:string,provider:string,runs:int,strongly:int,weakly:int,neutral:int,avg_share:float,avg_ratio:float,last_role:string}>
     */
    public static function fromRounds(array $rounds): array
    {
        $out = [];
        foreach ($rounds as $round) {
            $models = is_array($round['models'] ?? null) ? $round['models'] : [];
            foreach ($models as $row) {
                if (!is_array($row)) { continue; }
                $model = trim((string)($row['model'] ?? ''));
                if ($model === '') { continue; }
                $share = (float)($row['share'] ?? 0.0);
                $ratio = (float)($row['ratio'] ?? 0.0);

                $entry = $out[$model] ?? [
                    'model'     => $model,
                    'provider'  => '',
                    'runs'      => 0,
                    'strongly'  => 0,
                    'weakly'    => 0,
                    'neutral'   => 0,
                    'avg_share' => 0.0,
                    'avg_ratio' => 0.0,
                    'last_role' => '',
                ];
                $entry['runs']++;
                $entry['avg_share'] += $share;
                $entry['avg_ratio'] += $ratio;
                if ((string)($entry['provider']) === '' && (string)($row['provider'] ?? '') !== '') {
                    $entry['provider'] = (string)$row['provider'];
                }
                if ((string)($row['role'] ?? '') !== '') {
                    $entry['last_role'] = (string)$row['role'];
                }
                if ($ratio >= self::STRONG_RATIO) {
                    $entry['strongly']++;
                } elseif ($ratio <= self::WEAK_RATIO) {
                    $entry['weakly']++;
                } else {
                    $entry['neutral']++;
                }
                $out[$model] = $entry;
            }
        }

        foreach ($out as $model => $entry) {
            $runs = max(1, (int)$entry['runs']);
            $out[$model]['avg_share'] = round($entry['avg_share'] / $runs, 4);
            $out[$model]['avg_ratio'] = round($entry['avg_ratio'] / $runs, 4);
        }

        return $out;
    }

    /**
     * Fold the calls a model did not answer, with their reasons.
     *
     * Kept apart from `fromRounds` on purpose: these rows are about the
     * connection, the provider or the key, not about the model's output.
     *
     * @param array<int,array{model:string,reason:string}> $failures
     * @return array<string,array{model:string,non_answers:int,reasons:array<string,int>}>
     */
    public static function fromNonAnswers(array $failures): array
    {
        $out = [];
        foreach ($failures as $row) {
            $model = trim((string)($row['model'] ?? ''));
            if ($model === '') { continue; }
            $reason = trim((string)($row['reason'] ?? ''));
            if ($reason === '') { $reason = 'not reported'; }
            $out[$model] ??= ['model' => $model, 'non_answers' => 0, 'reasons' => []];
            $out[$model]['non_answers']++;
            $out[$model]['reasons'][$reason] = ($out[$model]['reasons'][$reason] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * One record per model, for the operator: the useful numbers and the
     * non-answers side by side, never mixed into a single score.
     *
     * @param array<int,array<string,mixed>> $rounds
     * @param array<int,array{model:string,reason:string}> $failures
     * @return array<int,array<string,mixed>>
     */
    public static function combine(array $rounds, array $failures): array
    {
        $corroboration = self::fromRounds($rounds);
        $nonAnswers = self::fromNonAnswers($failures);
        $models = array_keys($corroboration + $nonAnswers);
        sort($models);

        $out = [];
        foreach ($models as $model) {
            $c = $corroboration[$model] ?? null;
            $n = $nonAnswers[$model] ?? null;
            $runs = (int)($c['runs'] ?? 0);
            $nonAnswerCount = (int)($n['non_answers'] ?? 0);
            $out[] = [
                'model'          => $model,
                'provider'       => (string)($c['provider'] ?? ''),
                'runs'           => $runs,
                'avg_share'      => (float)($c['avg_share'] ?? 0.0),
                'avg_ratio'      => (float)($c['avg_ratio'] ?? 0.0),
                'strongly'       => (int)($c['strongly'] ?? 0),
                'weakly'         => (int)($c['weakly'] ?? 0),
                'neutral'        => (int)($c['neutral'] ?? 0),
                'non_answers'    => $nonAnswerCount,
                'non_answer_reasons' => $n['reasons'] ?? [],
                // Said in words, because a reader must not have to infer that
                // "weakly" and "non_answers" are different things.
                'note'           => self::note($runs, (int)($c['strongly'] ?? 0), (int)($c['weakly'] ?? 0), $nonAnswerCount),
            ];
        }

        return $out;
    }

    private static function note(int $runs, int $strongly, int $weakly, int $nonAnswers): string
    {
        $parts = [];
        if ($runs > 0) {
            $parts[] = sprintf('%d round(s) answered, %d with most of the content corroborated, %d with almost none.', $runs, $strongly, $weakly);
        } else {
            $parts[] = 'No consensus round has measured this model yet.';
        }
        if ($nonAnswers > 0) {
            $parts[] = sprintf('%d call(s) not answered for transport or provider reasons - counted separately, not as a negative contribution.', $nonAnswers);
        }

        return implode(' ', $parts);
    }
}
