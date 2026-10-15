<?php
/**
 * SemanticConsensusEngine: component-aware agreement scoring across
 * normalized LLM responses.
 *
 * Why this exists (v1.8.17):
 *   The previous `ConsensusEngine` computed pairwise Jaccard over flat
 *   token bags collected from per-claim text. That worked when models
 *   answered tersely with overlapping wording, but produced absurdly
 *   low scores (≈ 0.07 in one captured case — see paste.txt 2026-05-12)
 *   when several models proposed the same idea using different surface
 *   words (e.g. four Russian answers about a bottomless cup all saying
 *   "use it as decor" / "drink through the handle" / "turn it over" /
 *   "smash it") because the Jaccard intersection over raw tokens
 *   missed the semantic overlap.
 *
 * Strategy:
 *   1) Each model answer is split into semantic *components* — one per
 *      claim/sentence/bullet — and turned into a fingerprint:
 *      normalized keyword tokens (Unicode-aware, stop-word filtered),
 *      character 3-grams (catches morphological variants across
 *      Russian/English), and numeric/entity hints (years, percentages,
 *      capitalized phrases).
 *   2) Components are clustered greedily across models: two components
 *      from different models join the same cluster when their soft
 *      similarity exceeds `SIMILARITY_THRESHOLD`. A cluster covering N
 *      different models contributes weight ∝ N to the shared semantic
 *      content of the consensus.
 *   3) The final score is the fraction of cross-model semantic content
 *      that is covered by shared clusters — bounded in [0,1] with a
 *      bonus for clusters that span every participant and a penalty
 *      for direct numeric contradictions.
 *   4) Status thresholds remain compatible with `ConsensusResult`:
 *      agreement ≥ 0.85, partial ≥ 0.55, conflict otherwise.
 *
 * The implementation is fully local — no embeddings or external API
 * calls — so the engine ships without new dependencies and runs on
 * hosts without network access.
 *
 * Reference reading bundled with this release:
 *   - arXiv 2601.07245  https://arxiv.org/abs/2601.07245  semantic
 *                      agreement + clustering features (Kallem).
 *   - arXiv 2504.02128  https://arxiv.org/abs/2504.02128  multi-agent
 *                      deliberation + graded consensus (Pokharel et al.).
 *   - arXiv 2411.06535  https://arxiv.org/abs/2411.06535  probabilistic
 *                      consensus through ensemble validation (Naik).
 *   - arXiv 2312.07622  https://arxiv.org/abs/2312.07622  reasoning
 *                      taxonomy / CoT background (Liu et al.).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-12
 */
declare(strict_types=1);

namespace App\Consensus;

use App\Helpers\Utf8Helper;

final class SemanticConsensusEngine
{
    /** Similarity threshold above which two components join the same cluster. */
    private const SIMILARITY_THRESHOLD = 0.18;

    /** Minimum cluster coverage (fraction of models present) treated as shared. */
    private const MIN_CLUSTER_COVERAGE = 2;

    /** Penalty applied to the score when an outright numeric contradiction is detected. */
    private const NUMERIC_CONTRADICTION_PENALTY = 0.15;

    /** Bonus applied when at least one cluster spans every participant. */
    private const UNANIMOUS_BONUS = 0.05;

    /**
     * Stop-word set covering English and the most-frequent Russian
     * function words. Filtered before tokens enter the keyword vector.
     *
     * @var array<string,bool>
     */
    private array $stopWords;

    public function __construct()
    {
        $stop = [
            // English
            'a','an','the','of','to','and','or','for','with','is','are','be','in','on','at','it','by','i','this',
            'that','as','from','we','you','our','your','their','they','was','were','will','would','could','should',
            'have','has','had','do','does','did','not','no','if','but','so','than','then','when','where','what',
            'who','which','how','about','into','out','up','down','over','under','more','most','some','any','all',
            // Russian (high-frequency)
            'и','в','во','не','что','он','на','я','с','со','как','а','то','все','она','так','его','но','да','ты',
            'к','у','же','вы','за','бы','по','только','ее','мне','было','вот','от','меня','еще','нет','о','из',
            'ему','теперь','когда','даже','ну','вдруг','ли','если','уже','или','ни','быть','был','него','до',
            'вас','нибудь','опять','уж','вам','ведь','там','потом','себя','ничего','ей','может','они','тут',
            'где','есть','надо','ней','для','мы','тебя','их','чем','была','сам','чтоб','без','будто','чего',
            'раз','тоже','себе','под','будет','ж','тогда','кто','этот','того','потому','этого','какой','совсем',
            'ним','здесь','этом','один','почти','мой','тем','чтобы','нее','сейчас','были','куда','зачем','всех',
            'никогда','можно','при','наконец','два','об','другой','хоть','после','над','больше','тот','через',
            'эти','нас','про','всего','них','какая','много','разве','три','эту','моя','впрочем','свою','этой',
            'перед','иногда','лучше','чуть','том','нельзя','такой','им','более','всегда','конечно','между',
            'это','эта','эти','этих','этому','этой','тем','теми','тех','том','той','тою','здесь','туда',
        ];
        $this->stopWords = array_fill_keys($stop, true);
    }

    /**
     * Compute consensus across normalized responses.
     *
     * @param array<int,array<string,mixed>> $normalizedResponses Output of NormalizationService::normalize() per role.
     */
    public function compute(array $normalizedResponses): ConsensusResult
    {
        $perModel = [];
        foreach ($normalizedResponses as $idx => $resp) {
            $components = $this->extractComponents($resp);
            if ($components !== []) {
                $perModel[] = ['idx' => $idx, 'components' => $components];
            }
        }
        $n = count($perModel);
        if ($n === 0) {
            return new ConsensusResult(0.0, 'conflict',
                'No comparable components produced by any role.',
                ['scoring' => 'semantic_components', 'n_roles' => 0, 'clusters' => 0]);
        }
        if ($n === 1) {
            return new ConsensusResult(1.0, 'agreement',
                'Single role responded; trivial consensus.',
                [
                    'scoring'         => 'semantic_components',
                    'n_roles'         => 1,
                    'clusters'        => count($perModel[0]['components']),
                    'shared_clusters' => 0,
                ]);
        }

        $clusters = $this->clusterAcrossModels($perModel);
        $sharedClusters = 0;
        $unanimousClusters = 0;
        $coverageNumerator = 0.0;
        $coverageDenominator = 0.0;
        $previewShared = [];

            // R9 extension - per-participant contribution.
            //
            // `weight` is everything this model said (its components' quality
            // weights); `shared` is the part of it that landed in clusters
            // confirmed across models, i.e. what the others actually agreed
            // with. `ratio` is therefore "how much of this answer was
            // corroborated", and `share` is its slice of the total
            // corroboration in this run - the number the operator sees under
            // the final answer.
            //
            // Deliberately a share of *agreement*, not of volume: a model that
            // says a lot on its own scores low here, which is the honest
            // reading of "contributed to the decision".
            $memberWeight = [];
            $sharedCredit = [];
        foreach ($clusters as $cluster) {
            $modelsInCluster = count($cluster['models']);
            // Cross-model semantic weight = sum of per-member quality
            // weights, capped at 1.0 per model so a verbose answer
            // cannot dominate the score by listing many tiny claims.
            $weight = 0.0;
            $perModelWeight = [];
            foreach ($cluster['members'] as $member) {
                $mIdx = $member['model_idx'];
                $w = $member['weight'];
                $perModelWeight[$mIdx] = max($perModelWeight[$mIdx] ?? 0.0, $w);
                $memberWeight[$mIdx] = ($memberWeight[$mIdx] ?? 0.0) + $w;
            }
            $weight = array_sum($perModelWeight);
            $coverageDenominator += $weight;
            if ($modelsInCluster >= self::MIN_CLUSTER_COVERAGE) {
                $sharedClusters++;
                foreach ($perModelWeight as $mIdx => $w) {
                    $sharedCredit[$mIdx] = ($sharedCredit[$mIdx] ?? 0.0) + $w;
                }
                // Coverage credit: fraction of models covered by this cluster.
                $credit = $weight * ($modelsInCluster / $n);
                $coverageNumerator += $credit;
                if (count($previewShared) < 6) {
                    $previewShared[] = $this->previewLabel($cluster['members']);
                }
                if ($modelsInCluster === $n) {
                    $unanimousClusters++;
                }
            }
        }

        $perModelBreakdown = [];
        $sharedTotal = array_sum($sharedCredit);
        foreach ($memberWeight as $mIdx => $total) {
            $shared = $sharedCredit[$mIdx] ?? 0.0;
            $perModelBreakdown[] = [
                'idx'    => $mIdx,
                'weight' => round($total, 6),
                'shared' => round($shared, 6),
                'ratio'  => $total > 0 ? round($shared / $total, 4) : 0.0,
                'share'  => $sharedTotal > 0 ? round($shared / $sharedTotal, 4) : 0.0,
            ];
        }
        $rawScore = $coverageDenominator > 0.0 ? ($coverageNumerator / $coverageDenominator) : 0.0;
        if ($unanimousClusters > 0) {
            $rawScore = min(1.0, $rawScore + self::UNANIMOUS_BONUS);
        }
        $rawScore -= $this->numericContradictionPenalty($perModel);
        $score = max(0.0, min(1.0, $rawScore));

        $status = match (true) {
            $score >= 0.85 => 'agreement',
            $score >= 0.55 => 'partial',
            default        => 'conflict',
        };
        $summary = match ($status) {
            'agreement' => 'Strong semantic agreement across roles — shared components dominate.',
            'partial'   => 'Partial semantic overlap — roles agree on some components but diverge on others.',
            'conflict'  => 'Significant semantic divergence — few cross-model components are shared.',
        };
        $pairs = $n > 1 ? ($n * ($n - 1) / 2) : 0;
        return new ConsensusResult($score, $status, $summary, [
            'scoring'           => 'semantic_components',
            'n_roles'           => $n,
            'pairs'             => $pairs,
            'clusters'          => count($clusters),
            'shared_clusters'   => $sharedClusters,
            'unanimous_clusters'=> $unanimousClusters,
            'preview_shared'    => $previewShared,
            'per_item'             => $perModelBreakdown,
            'similarity_threshold' => self::SIMILARITY_THRESHOLD,
        ]);
    }

    /**
     * Public entry point exposed for unit tests + diagnostic tooling.
     * Returns the same component fingerprint that `compute()` consumes.
     *
     * @param  array<string,mixed> $normalized Result of NormalizationService::normalize.
     * @return array<int,array<string,mixed>>
     */
    public function extractComponents(array $normalized): array
    {
        $claims = $normalized['claims'] ?? [];
        if (!is_array($claims)) { $claims = []; }
        $data = $normalized['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $additional = $this->splitMarkdownBullets($data);
            foreach ($additional as $line) {
                $claims[] = $line;
            }
        }
        $components = [];
        $seenFingerprints = [];
        foreach ($claims as $raw) {
            if (!is_string($raw)) { continue; }
            $text = trim($raw);
            if ($text === '') { continue; }
            $tokens   = $this->tokenize($text);
            $keywords = $this->keywords($tokens);
            if ($keywords === []) { continue; }
            $charNgrams = $this->charNgrams($tokens);
            $numbers    = $this->extractNumbers($text);
            $entities   = $this->extractEntities($text);
            $fingerprint = md5(implode(' ', $keywords));
            if (isset($seenFingerprints[$fingerprint])) { continue; }
            $seenFingerprints[$fingerprint] = true;
            $weight = max(0.2, min(1.0, count($keywords) / 8.0));
            $components[] = [
                'text'        => $text,
                'keywords'    => $keywords,
                'char_ngrams' => $charNgrams,
                'numbers'     => $numbers,
                'entities'    => $entities,
                'weight'      => $weight,
            ];
        }
        return $components;
    }

    /**
     * Compute the soft similarity between two components. The score is a
     * weighted blend of:
     *   - keyword Jaccard (over morphologically-light tokens),
     *   - char-3-gram Jaccard (catches case/inflection/typos),
     *   - shared-numbers bonus,
     *   - shared-entities bonus.
     *
     * @param  array<string,mixed> $a
     * @param  array<string,mixed> $b
     */
    public function similarity(array $a, array $b): float
    {
        $kw = $this->jaccard($a['keywords'] ?? [], $b['keywords'] ?? []);
        $ng = $this->jaccard($a['char_ngrams'] ?? [], $b['char_ngrams'] ?? []);
        // Soft-OR blend so the strongest channel can carry a paraphrased
        // pair across the threshold. The previous additive blend pinned
        // the max effective similarity for char-ngrams alone to 0.35,
        // which left Russian morphological variants below 0.42 and
        // collapsed every cluster.
        $base = max($kw, $ng * 0.85);
        $base += min(0.15, $kw * 0.25);
        $numA = $a['numbers'] ?? [];
        $numB = $b['numbers'] ?? [];
        if ($numA !== [] && $numB !== []) {
            $sharedNums = array_intersect($numA, $numB);
            if ($sharedNums !== []) {
                $base += $this->jaccard($numA, $numB) * 0.08;
            } else {
                // Direct numeric disagreement when keywords already
                // overlap → the components are talking about the same
                // entity with different magnitudes. Suppress the match
                // so the cluster does not absorb them as "shared".
                $kwShared = array_intersect($a['keywords'] ?? [], $b['keywords'] ?? []);
                if (count($kwShared) >= 2) {
                    // Direct numeric disagreement under shared context
                    // disqualifies the cluster — components are about
                    // the same thing with conflicting magnitudes.
                    return 0.0;
                }
            }
        }
        $entA = $a['entities'] ?? [];
        $entB = $b['entities'] ?? [];
        if ($entA !== [] && $entB !== []) {
            $base += $this->jaccard($entA, $entB) * 0.08;
        }
        return max(0.0, min(1.0, $base));
    }

    /**
     * Greedy cross-model clustering. Iterates over (model A, model B)
     * pairs and attaches each candidate component to the best matching
     * existing cluster (or seeds a fresh cluster) above the similarity
     * threshold. Components from the same model are kept in distinct
     * clusters — we measure cross-model agreement only.
     *
     * @param  array<int,array{idx:int,components:array<int,array<string,mixed>>}> $perModel
     * @return array<int,array{
     *   members:array<int,array{model_idx:int,text:string,weight:float,keywords:array<int,string>}>,
     *   models:array<int,bool>,
     * }>
     */
    private function clusterAcrossModels(array $perModel): array
    {
        $clusters = [];
        foreach ($perModel as $entry) {
            $mIdx = $entry['idx'];
            foreach ($entry['components'] as $component) {
                $best = -1;
                $bestSim = self::SIMILARITY_THRESHOLD;
                foreach ($clusters as $ci => $cluster) {
                    // Don't merge with a cluster that already includes this
                    // model — we want cross-model agreement, not within-model.
                    if (isset($cluster['models'][$mIdx])) { continue; }
                    foreach ($cluster['members'] as $member) {
                        $sim = $this->similarity($component, $member);
                        if ($sim > $bestSim) {
                            $bestSim = $sim;
                            $best = $ci;
                        }
                    }
                }
                if ($best >= 0) {
                    $clusters[$best]['members'][] = [
                        'model_idx' => $mIdx,
                        'text'      => $component['text'],
                        'weight'    => $component['weight'],
                        'keywords'  => $component['keywords'],
                        'char_ngrams' => $component['char_ngrams'],
                        'numbers'   => $component['numbers'],
                        'entities'  => $component['entities'],
                    ];
                    $clusters[$best]['models'][$mIdx] = true;
                } else {
                    $clusters[] = [
                        'members' => [[
                            'model_idx' => $mIdx,
                            'text'      => $component['text'],
                            'weight'    => $component['weight'],
                            'keywords'  => $component['keywords'],
                            'char_ngrams' => $component['char_ngrams'],
                            'numbers'   => $component['numbers'],
                            'entities'  => $component['entities'],
                        ]],
                        'models'  => [$mIdx => true],
                    ];
                }
            }
        }
        return $clusters;
    }

    /**
     * Detect direct numeric contradictions: same noun context, different
     * number. Heuristic — looks for at least one component pair from
     * different models that shares ≥ 1 keyword but disagrees on at least
     * one number. Returns a penalty in [0, NUMERIC_CONTRADICTION_PENALTY].
     *
     * @param  array<int,array{idx:int,components:array<int,array<string,mixed>>}> $perModel
     */
    private function numericContradictionPenalty(array $perModel): float
    {
        $total = 0.0;
        $count = count($perModel);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                foreach ($perModel[$i]['components'] as $ca) {
                    if (empty($ca['numbers'])) { continue; }
                    foreach ($perModel[$j]['components'] as $cb) {
                        if (empty($cb['numbers'])) { continue; }
                        $sharedKw = array_intersect($ca['keywords'], $cb['keywords']);
                        if (count($sharedKw) < 1) { continue; }
                        $shared = array_intersect($ca['numbers'], $cb['numbers']);
                        $union  = array_unique(array_merge($ca['numbers'], $cb['numbers']));
                        if ($shared !== [] || $union === []) { continue; }
                        $total += self::NUMERIC_CONTRADICTION_PENALTY / max(1, $count);
                        break 2;
                    }
                }
            }
        }
        return min(self::NUMERIC_CONTRADICTION_PENALTY, $total);
    }

    /**
     * @param  array<int,array{model_idx:int,text:string,weight:float,keywords:array<int,string>}> $members
     */
    private function previewLabel(array $members): string
    {
        $allKeywords = [];
        foreach ($members as $m) {
            foreach ($m['keywords'] as $kw) { $allKeywords[$kw] = ($allKeywords[$kw] ?? 0) + 1; }
        }
        arsort($allKeywords);
        $top = array_slice(array_keys($allKeywords), 0, 4);
        return implode(' / ', $top);
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $text): array
    {
        $text = strtolower(Utf8Helper::toUtf8($text));
        // Strip markdown markers + punctuation; keep Unicode letters + digits.
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = (string)$p;
            if ($p === '') { continue; }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * @param  array<int,string> $tokens
     * @return array<int,string>
     */
    private function keywords(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 3) { continue; }
            if (isset($this->stopWords[$t])) { continue; }
            $stem = $this->lightStem($t);
            $out[$stem] = true;
        }
        return array_keys($out);
    }

    /**
     * Lightweight morphology trimmer for Russian/English without external
     * dictionaries: drop common inflectional suffixes so morphological
     * variants (e.g. "кружка" / "кружку" / "кружкой") collapse onto the
     * same key, "cup"/"cups" do too. This is intentionally crude — the
     * char-ngram channel covers what we miss.
     */
    private function lightStem(string $token): string
    {
        $token = mb_strtolower($token, 'UTF-8');
        $len = mb_strlen($token);
        // Strip common Russian suffixes
        $suffixes = [
            'ыми','ями','ого','его','ому','ему','ыми','ыми','ами','ями','ев','ов','ой','ей','ий','ый','ая','яя',
            'ое','ее','ые','ие','ах','ях','ам','ям','ах','ах','ой','ою','ою','ью','ием','ует','уют','ишь','ите',
            'ете','ат','ят','ут','ют','ит','ил','ила','или','ить','еть','ать','ять','ться','ться','тся','лся',
            'лась','лось','ник','ниц','ость','тель','ями','ого','его','ыми','ые','ие','ью',
            // English
            'ies','ied','ing','tion','ment','ness','ous','ive','ity','ize','ise','ist','ism','able','ible',
            'ed','es','er','est','ly','s',
        ];
        usort($suffixes, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($suffixes as $s) {
            $sl = mb_strlen($s);
            if ($len > $sl + 2 && mb_substr($token, -$sl) === $s) {
                return mb_substr($token, 0, $len - $sl);
            }
        }
        return $token;
    }

    /**
     * @param  array<int,string> $tokens
     * @return array<int,string>
     */
    private function charNgrams(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            $t = mb_strtolower($t, 'UTF-8');
            if (mb_strlen($t) < 3) { continue; }
            $padded = '_' . $t . '_';
            $len = mb_strlen($padded);
            for ($i = 0; $i <= $len - 3; $i++) {
                $out[mb_substr($padded, $i, 3)] = true;
            }
        }
        return array_keys($out);
    }

    /** @return array<int,string> */
    private function extractNumbers(string $text): array
    {
        $out = [];
        if (preg_match_all('/-?\d+(?:[.,]\d+)?%?/', $text, $m) > 0) {
            foreach ($m[0] as $n) {
                $clean = str_replace(',', '.', (string)$n);
                $out[$clean] = true;
            }
        }
        return array_keys($out);
    }

    /** @return array<int,string> */
    private function extractEntities(string $text): array
    {
        $out = [];
        if (preg_match_all('/\b\p{Lu}\p{L}{2,}(?:\s+\p{Lu}\p{L}{2,})*/u', $text, $m) > 0) {
            foreach ($m[0] as $hit) {
                $hit = trim((string)$hit);
                if (mb_strlen($hit) >= 3) {
                    $out[mb_strtolower($hit, 'UTF-8')] = true;
                }
            }
        }
        return array_keys($out);
    }

    /** @return array<int,string> */
    private function splitMarkdownBullets(string $text): array
    {
        $lines = preg_split('/\r?\n/u', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') { continue; }
            $stripped = preg_replace('/^[\-\*\d\.\)\s•]+/u', '', $line) ?? $line;
            $stripped = trim((string)$stripped);
            if ($stripped !== '' && mb_strlen($stripped) >= 6) {
                $out[] = $stripped;
            }
        }
        return $out;
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] && $b === []) { return 0.0; }
        $sa = array_unique($a);
        $sb = array_unique($b);
        $inter = count(array_intersect($sa, $sb));
        $union = count(array_unique(array_merge($sa, $sb)));
        return $union === 0 ? 0.0 : $inter / $union;
    }
}
