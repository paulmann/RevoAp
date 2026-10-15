<?php
/**
 * PaperAnalyst: hand the papers to a model and ask for something usable.
 *
 * The point of the searcher is not a list of links - it is an answer to "what
 * does the literature say about this, and what should we change?". So the
 * prompt asks for exactly that: a synthesis of what the papers agree on, where
 * they disagree, what is clearly established against what is speculative, and
 * concrete changes for this system, each tied to the paper it came from. The
 * brief is told to close with a "Changes to consider" section, which is what
 * turns the prose into a change plan (AnalysisProposals).
 *
 * The model is the research model (research.provider / research.model), which
 * inherits the synthesis model when unset - the same inheritance the role
 * generator and the actualize analyzer use.
 *
 * @project  RevoAp
 * @version  1.1.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Research;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\ConnectorException;
use App\Core\Config;
use App\Core\Logger;

final class PaperAnalyst
{
    /** Characters of an abstract kept in the prompt; enough to judge, not a wall. */
    public const ABSTRACT_LIMIT = 1200;

    public function __construct(private readonly ConnectorRegistry $connectors) {}

    /** The model that analyses research: its own setting, else the synthesis one. */
    public function target(): array
    {
        $provider = trim((string)Config::get('research.provider', ''));
        $model    = trim((string)Config::get('research.model', ''));
        if ($provider !== '' && $model !== '') {
            return ['provider' => $provider, 'model' => $model];
        }
        return [
            'provider' => (string)Config::get('pipeline.synthesis_provider', 'bothub'),
            'model'    => (string)Config::get('pipeline.synthesis_model', 'gpt-6-luna-pro'),
        ];
    }

    /** The prompt, built from the topic and the papers. Pure and testable. */
    public static function messages(string $topic, array $papers): array
    {
        $lines = [];
        foreach ($papers as $index => $paper) {
            $authors = implode(', ', array_map('strval', (array)($paper['authors'] ?? [])));
            $summary = (string)($paper['summary'] ?? '');
            if (mb_strlen($summary) > self::ABSTRACT_LIMIT) {
                $summary = mb_substr($summary, 0, self::ABSTRACT_LIMIT) . ' ...';
            }
            $lines[] = sprintf(
                "[%d] %s\nAuthors: %s\nPublished: %s\nURL: %s\nAbstract: %s",
                $index + 1,
                (string)($paper['title'] ?? ''),
                $authors !== '' ? $authors : 'unknown',
                (string)($paper['published'] ?? ''),
                (string)($paper['url'] ?? ''),
                $summary,
            );
        }
        $body = "Topic: {$topic}\n\nPapers found on arXiv:\n\n" . implode("\n\n", $lines);

        return [
            ['role' => 'system', 'content' =>
                'You are a research analyst for a software team. You read the supplied arXiv papers and write an '
                . 'evidence-bound brief. Rules: never invent a fact that is not in the abstracts; mark anything you '
                . 'infer as inference; prefer the specific over the general. Structure: (1) what the papers agree on, '
                . '(2) where they disagree or qualify each other, (3) what is established vs speculative, (4) concrete '
                . 'changes for our system, each citing the paper number it comes from, (5) what is still unknown and what '
                . 'would settle it. Cite as [n]. Write in the language of the topic. Finish with a section headed "Changes to '
                . 'consider" whose bullets list the concrete changes, each starting with "- ".'],
            ['role' => 'user', 'content' => $body],
        ];
    }

    /**
     * @return array{ok:bool,analysis:string,model:string,provider:string,tokens:int,error:?string}
     */
    public function analyse(string $topic, array $papers): array
    {
        $target = $this->target();
        $connector = $this->connectors->getByProvider($target['provider']);
        if ($connector === null) {
            return ['ok' => false, 'analysis' => '', 'model' => $target['model'], 'provider' => $target['provider'],
                'tokens' => 0, 'error' => 'The provider ' . $target['provider'] . ' is not available.'];
        }
        try {
            $resp = $connector->chat($target['model'], self::messages($topic, $papers), ['temperature' => 0.2]);
        } catch (ConnectorException $e) {
            Logger::warn('research.analyse_failed', [
                'provider' => $target['provider'],
                'model'    => $target['model'],
                'message'  => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return ['ok' => false, 'analysis' => '', 'model' => $target['model'], 'provider' => $target['provider'],
                'tokens' => 0, 'error' => $e->getMessage()];
        }
        return [
            'ok'       => true,
            'analysis' => (string)$resp->content,
            'model'    => $target['model'],
            'provider' => $target['provider'],
            'tokens'   => (int)($resp->totalTokens ?? 0),
            'error'    => null,
        ];
    }
}