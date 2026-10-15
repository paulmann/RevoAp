<?php
/**
 * ResearchController: search arXiv, let a chosen model read the result, and
 * keep the run in the journal.
 *
 * The searcher answers a question that arXiv itself cannot: not "which papers
 * exist" but "what do they say, and what should we change". Searching is one
 * public API call and needs no account; the analysis is one model call against
 * the research model (research.provider / research.model, inheriting the
 * synthesis model when unset - the same inheritance the role generator and the
 * actualize analyzer use).
 *
 *   GET  /api/research/settings       the analysis model and where it comes from
 *   POST /api/research/settings       choose it, or reset to inherit
 *   POST /api/research/search         {"topic": "...", "max": 5, "only_new": false}
 *   POST /api/research/analyse        {"topic": "...", "max": 5} -> papers + analysis + plan
 *   GET  /api/research/history        the journal, newest first
 *   GET  /api/research/run/{id}       one saved run, ready to paint
 *   POST /api/research/run/delete     {"id": N} - forget one run
 *   POST /api/research/history/clear  {} - forget everything, seen memory included
 *   POST /api/research/plan/status    {"id": N, "status": "draft|active|done|dropped"}
 *   POST /api/research/plan/check     {"id": N, "checked": [true, false, ...]}
 *
 * The analysis ends in a change plan: the model is asked to close with a
 * "Changes to consider" section, the proposals are extracted from it, and the
 * answer carries a ready markdown plan the operator can copy or download.
 *
 * Every search and analysis is recorded in the research journal
 * (App\Research\ResearchJournal over research_runs / research_seen): what was
 * shown, what the model answered, and which papers the operator has seen
 * before - that memory is what "Only new" filters against. Writes are
 * best-effort: an unavailable journal never fails a search - the answer says
 * `saved: false` and the card explains.
 *
 * @project  RevoAp
 * @version  1.2.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\RuntimeConfig;
use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Research\AnalysisProposals;
use App\Research\ArxivClient;
use App\Research\PaperAnalyst;
use App\Research\PaperRanker;
use App\Research\ResearchJournal;

final class ResearchController
{
    /** Longest topic accepted, in characters. */
    private const MAX_TOPIC_CHARS = 400;

    public function __construct(
        private readonly PaperAnalyst $analyst,
        private readonly ?ResearchJournal $journal = null
    ) {}

    /** GET /api/research/settings */
    public function settings(): string
    {
        $provider = trim((string)Config::get('research.provider', ''));
        $model    = trim((string)Config::get('research.model', ''));
        $target   = $this->analyst->target();
        $own      = $provider !== '' && $model !== '';

        return Response::json([
            'ok'            => true,
            'provider'      => $provider,
            'model'         => $model,
            'source'        => $own ? 'section' : 'inherited',
            'source_key'    => 'research.provider / research.model',
            'inherited_key' => 'pipeline.synthesis_provider / pipeline.synthesis_model',
            'inherited'     => $own ? null : $target,
            'effective'     => $target,
        ]);
    }

    /** POST /api/research/settings */
    public function saveSettings(): string
    {
        $payload = $this->readJsonBody();

        if (($payload['reset'] ?? false) === true) {
            RuntimeConfig::update([
                'research.provider' => '',
                'research.model'    => '',
            ]);
            return Response::json(['ok' => true, 'message' => 'The research model inherits the synthesis model again.']);
        }

        $provider = isset($payload['provider']) && is_string($payload['provider']) ? trim($payload['provider']) : '';
        $model    = isset($payload['model']) && is_string($payload['model']) ? trim($payload['model']) : '';
        if ($provider === '' || $model === '') {
            return Response::json([
                'ok'      => false,
                'error'   => 'provider_and_model_required',
                'message' => 'Send {"provider": "...", "model": "..."} or {"reset": true}.',
            ], 400);
        }
        if (str_contains($model, ':batch')) {
            return Response::json([
                'ok'      => false,
                'error'   => 'model_not_synchronous',
                'message' => 'A batch-only model cannot analyse research: the call has to answer while the operator waits.',
            ], 400);
        }
        $known = false;
        foreach (Config::getActiveModels() as $row) {
            if (is_array($row)
                && (string)($row['provider'] ?? '') === $provider
                && (string)($row['model'] ?? '') === $model) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            return Response::json([
                'ok'      => false,
                'error'   => 'model_not_in_catalogue',
                'message' => 'The active catalogue does not serve ' . $provider . ' / ' . $model . '.',
            ], 400);
        }

        RuntimeConfig::update([
            'research.provider' => mb_substr($provider, 0, 64),
            'research.model'    => mb_substr($model, 0, 191),
        ]);

        return Response::json(['ok' => true, 'message' => 'The research model is ' . $provider . ' / ' . $model . '.']);
    }

    /** POST /api/research/search */
    public function search(): string
    {
        $topic = $this->topic();
        if ($topic === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'topic_required',
                'message' => 'Describe what to look for on arXiv.',
            ], 400);
        }
        $max = $this->max();
        $result = ArxivClient::search($topic, $max);
        if ($result['error'] !== null && $result['papers'] === []) {
            return Response::json([
                'ok'      => false,
                'error'   => 'arxiv_unavailable',
                'message' => 'arXiv did not answer: ' . $result['error'],
                'query'   => $result['query'],
            ], 502);
        }
        [$papers, $hidden, $window] = $this->selectPapers($topic, $result['papers'], $max, $this->onlyNew());
        [$runId, $saved] = $this->recordRun('search', $topic, $result['query'], count($result['papers']), $papers, $window, null, [], null);
        return Response::json([
            'ok'          => true,
            'query'       => $result['query'],
            'count'       => count($papers),
            'candidates'  => count($result['papers']),
            'seen_hidden' => $hidden,
            'run_id'      => $runId,
            'saved'       => $saved,
            'papers'      => $papers,
        ]);
    }

    /** POST /api/research/analyse */
    public function analyse(): string
    {
        $topic = $this->topic();
        if ($topic === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'topic_required',
                'message' => 'Describe what to look for on arXiv.',
            ], 400);
        }
        $max = $this->max();
        $result = ArxivClient::search($topic, $max);
        if ($result['papers'] === []) {
            return Response::json([
                'ok'      => false,
                'error'   => $result['error'] !== null ? 'arxiv_unavailable' : 'no_papers',
                'message' => $result['error'] !== null
                    ? 'arXiv did not answer: ' . $result['error']
                    : 'arXiv found nothing for this topic. Try broader words.',
                'query'   => $result['query'],
            ], $result['error'] !== null ? 502 : 404);
        }

        [$papers, $hidden, $window] = $this->selectPapers($topic, $result['papers'], $max, $this->onlyNew());
        $analysis = $this->analyst->analyse($topic, $papers);
        if ($analysis['ok'] !== true) {
            return Response::json([
                'ok'      => false,
                'error'   => 'analysis_failed',
                'message' => (string)($analysis['error'] ?? 'The analysis model did not answer.'),
                'query'   => $result['query'],
                'papers'  => $papers,
            ], 502);
        }

        $proposals = AnalysisProposals::extract($analysis['analysis']);
        $plan = AnalysisProposals::planMarkdown(
            $topic,
            (string)$analysis['provider'],
            (string)$analysis['model'],
            $papers,
            $proposals,
        );
        [$runId, $saved] = $this->recordRun(
            'analyse',
            $topic,
            $result['query'],
            count($result['papers']),
            $papers,
            $window,
            $analysis,
            $proposals,
            $plan,
        );

        return Response::json([
            'ok'            => true,
            'query'         => $result['query'],
            'topic'         => $topic,
            'count'         => count($papers),
            'candidates'    => count($result['papers']),
            'seen_hidden'   => $hidden,
            'run_id'        => $runId,
            'saved'         => $saved,
            'papers'        => $papers,
            'analysis'      => $analysis['analysis'],
            'model'         => $analysis['model'],
            'provider'      => $analysis['provider'],
            'tokens'        => $analysis['tokens'],
            'proposals'     => $proposals,
            'plan_markdown' => $plan,
            'plan_status'   => ResearchJournal::DEFAULT_STATUS,
            'plan_checked'  => $proposals === [] ? [] : array_fill(0, count($proposals), false),
        ]);
    }

    /** GET /api/research/history */
    public function history(): string
    {
        $available = $this->journal !== null && $this->journal->available();
        $runs = $available ? $this->journal->recent(30) : [];
        return Response::json(['ok' => true, 'available' => $available, 'runs' => $runs]);
    }

    /** GET /api/research/run/{id} */
    public function run(int $id): string
    {
        $run = $this->journal?->find($id);
        if ($run === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'run_not_found',
                'message' => 'No such run in the research journal.',
            ], 404);
        }
        return Response::json(['ok' => true, 'run' => $this->runPayload($run)]);
    }

    /** POST /api/research/run/delete */
    public function deleteRun(): string
    {
        $payload = $this->readJsonBody();
        $id = isset($payload['id']) && is_numeric($payload['id']) ? (int)$payload['id'] : 0;
        if ($id <= 0) {
            return Response::json([
                'ok'      => false,
                'error'   => 'run_id_required',
                'message' => 'Send {"id": N}.',
            ], 400);
        }
        if ($this->journal === null || !$this->journal->delete($id)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'run_not_found',
                'message' => 'No such run in the research journal.',
            ], 404);
        }
        return Response::json(['ok' => true, 'id' => $id]);
    }

    /** POST /api/research/history/clear */
    public function clearHistory(): string
    {
        if ($this->journal === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'journal_unavailable',
                'message' => 'The research journal is not available on this server.',
            ], 503);
        }
        return Response::json(['ok' => true, 'deleted' => $this->journal->clear()]);
    }

    /** POST /api/research/plan/status */
    public function planStatus(): string
    {
        $payload = $this->readJsonBody();
        $id = isset($payload['id']) && is_numeric($payload['id']) ? (int)$payload['id'] : 0;
        $status = isset($payload['status']) && is_string($payload['status']) ? strtolower(trim($payload['status'])) : '';
        if ($id <= 0 || !in_array($status, ResearchJournal::STATUSES, true)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'plan_status_invalid',
                'message' => 'Send {"id": N, "status": "draft|active|done|dropped"}.',
            ], 400);
        }
        if ($this->journal === null || !$this->journal->setPlanStatus($id, $status)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'run_not_found',
                'message' => 'No such run in the research journal.',
            ], 404);
        }
        return Response::json(['ok' => true, 'id' => $id, 'status' => $status]);
    }

    /** POST /api/research/plan/check */
    public function planCheck(): string
    {
        $payload = $this->readJsonBody();
        $id = isset($payload['id']) && is_numeric($payload['id']) ? (int)$payload['id'] : 0;
        $checked = $payload['checked'] ?? null;
        if ($id <= 0 || !is_array($checked)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'plan_check_invalid',
                'message' => 'Send {"id": N, "checked": [true, false, ...]}.',
            ], 400);
        }
        if ($this->journal === null || !$this->journal->setPlanChecked($id, $checked)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'run_not_found',
                'message' => 'No such run in the research journal.',
            ], 404);
        }
        $plan = '';
        $run = $this->journal->find($id);
        if ($run !== null) {
            $payloadOut = $this->runPayload($run);
            $plan = (string)($payloadOut['plan_markdown'] ?? '');
        }
        return Response::json(['ok' => true, 'id' => $id, 'plan_markdown' => $plan]);
    }

    /**
     * Rank the window for this topic, mark what was seen before, and - when
     * "only new" is on - drop the seen papers.
     *
     * @param array<int,array<string,mixed>> $window
     * @return array{0:array<int,array<string,mixed>>,1:int,2:array<int,array<string,mixed>>} papers for this answer,
     *   how many seen ones were hidden, and the whole ranked window behind them
     */
    private function selectPapers(string $topic, array $window, int $max, bool $onlyNew): array
    {
        $rankedAll = $this->annotateSeen(PaperRanker::rank($window, ArxivClient::topicTerms($topic), count($window)));
        $ranked = $rankedAll;
        $hidden = 0;
        if ($onlyNew) {
            $kept = [];
            foreach ($ranked as $paper) {
                if (($paper['seen_first'] ?? '') !== '') {
                    $hidden++;
                    continue;
                }
                $kept[] = $paper;
            }
            $ranked = $kept;
        }
        return [array_slice($ranked, 0, $max), $hidden, $rankedAll];
    }

    /**
     * Every paper carries `seen_first` - the date it was first shown, empty
     * when it is new. Missing data reads as "new" honestly: an unavailable
     * journal must not fake a memory.
     *
     * @param array<int,array<string,mixed>> $papers
     * @return array<int,array<string,mixed>>
     */
    private function annotateSeen(array $papers): array
    {
        foreach ($papers as $i => $paper) {
            $papers[$i]['seen_first'] = '';
        }
        if ($this->journal === null || !$this->journal->available() || $papers === []) {
            return $papers;
        }
        $keys = [];
        foreach ($papers as $paper) {
            $keys[] = (string)($paper['id'] ?? ($paper['url'] ?? ''));
        }
        $seen = $this->journal->seenAt($keys);
        foreach ($papers as $i => $paper) {
            $key = ResearchJournal::paperKey((string)($paper['id'] ?? ($paper['url'] ?? '')));
            if ($key !== '' && isset($seen[$key])) {
                $papers[$i]['seen_first'] = $seen[$key];
            }
        }
        return $papers;
    }

    /**
     * Record the run - best-effort, never fails the request.
     *
     * @param array<int,array<string,mixed>> $papers
     * @param array<string,mixed>|null $analysis
     * @param array<int,string> $proposals
     * @return array{0:?int,1:bool} the run id, and whether it was saved
     */
    private function recordRun(
        string $kind,
        string $topic,
        string $query,
        int $candidates,
        array $papers,
        array $window,
        ?array $analysis,
        array $proposals,
        ?string $plan
    ): array {
        if ($this->journal === null) {
            return [null, false];
        }
        $id = $this->journal->record([
            'kind' => $kind,
            'topic' => $topic,
            'query' => $query,
            'candidates' => $candidates,
            'papers' => $papers,
            'window' => $window,
            'provider' => (string)($analysis['provider'] ?? ''),
            'model' => (string)($analysis['model'] ?? ''),
            'tokens' => (int)($analysis['tokens'] ?? 0),
            'analysis' => $analysis['analysis'] ?? null,
            'proposals' => $proposals,
            'plan' => $plan,
        ]);
        return [$id, $id !== null];
    }

    /** The journal row, shaped for the card. */
    private function runPayload(array $run): array
    {
        $papers = is_array($run['papers'] ?? null) ? $run['papers'] : [];
        $proposals = is_array($run['proposals'] ?? null) ? $run['proposals'] : [];
        $checked = is_array($run['plan_checked'] ?? null) ? $run['plan_checked'] : [];
        $plan = AnalysisProposals::planMarkdown(
            (string)($run['topic'] ?? ''),
            (string)($run['provider'] ?? ''),
            (string)($run['model'] ?? ''),
            $papers,
            $proposals,
            substr((string)($run['created_at'] ?? ''), 0, 10) ?: null,
            $checked,
        );
        return [
            'run_id'        => (int)($run['id'] ?? 0),
            'created_at'    => (string)($run['created_at'] ?? ''),
            'kind'          => (string)($run['kind'] ?? ''),
            'topic'         => (string)($run['topic'] ?? ''),
            'query'         => (string)($run['query'] ?? ''),
            'candidates'    => (int)($run['candidates'] ?? 0),
            'count'         => count($papers),
            'found'         => is_array($run['candidates'] ?? null) ? count($run['candidates']) : 0,
            'papers'        => $papers,
            'analysis'      => (string)($run['analysis'] ?? ''),
            'proposals'     => $proposals,
            'plan_markdown' => $plan,
            'plan_status'   => (string)($run['plan_status'] ?? ResearchJournal::DEFAULT_STATUS),
            'plan_checked'  => $checked,
            'provider'      => (string)($run['provider'] ?? ''),
            'model'         => (string)($run['model'] ?? ''),
            'tokens'        => (int)($run['tokens'] ?? 0),
            'saved'         => true,
        ];
    }

    /** The topic, trimmed and capped, or null when there is none. */
    private function topic(): ?string
    {
        $payload = $this->readJsonBody();
        $topic = isset($payload['topic']) && is_string($payload['topic']) ? trim($payload['topic']) : '';
        if ($topic === '') {
            return null;
        }
        return mb_strlen($topic) > self::MAX_TOPIC_CHARS ? mb_substr($topic, 0, self::MAX_TOPIC_CHARS) : $topic;
    }

    /** The "only new" switch from the request body. */
    private function onlyNew(): bool
    {
        $payload = $this->readJsonBody();
        $flag = $payload['only_new'] ?? null;
        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
    }

    private function max(): int
    {
        $payload = $this->readJsonBody();
        $max = isset($payload['max']) && is_numeric($payload['max']) ? (int)$payload['max'] : ArxivClient::DEFAULT_MAX;
        return max(1, min(ArxivClient::MAX_RESULTS, $max));
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return $_POST;
        }
        $parsed = JsonHelper::tryDecode($raw);
        return is_array($parsed) ? $parsed : [];
    }
}
