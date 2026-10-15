<?php
declare(strict_types=1);
/**
 * ResearchTest: the arXiv searcher and the analysis prompt.
 *
 * The feed below is a real answer from export.arxiv.org, captured by the probe -
 * Atom with two entries, the wrapping the API actually uses, and the entity
 * escapes it actually emits. The parser is pinned against that, not against a
 * reconstruction, and the prompt builder is pinned so a change to the analysis
 * question is a visible change.
 *
 *   php tests/ResearchTest.php
 */
use App\Research\ArxivAtomParser;
use App\Research\ArxivClient;
use App\Research\PaperAnalyst;

$root = dirname(__DIR__);
foreach (['/vendor/autoload.php', '/src/autoload.php'] as $candidate) {
    if (is_file($root . $candidate)) { require $root . $candidate; }
}
$passes = 0;
$failed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $passes, $failed;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failed++; }
}

/** A real feed from export.arxiv.org, captured 2026-09-29. */
const ARXIV_FEED = '<?xml version=\'1.0\' encoding=\'UTF-8\'?>
<feed xmlns:opensearch="http://a9.com/-/spec/opensearch/1.1/" xmlns:arxiv="http://arxiv.org/schemas/atom" xmlns="http://www.w3.org/2005/Atom">
  <id>https://arxiv.org/api/WJL44Sxj8pFBrqt4D8xz50b2bu4</id>
  <title>arXiv Query: search_query=all:consensus AND all:large AND all:language AND all:models&amp;id_list=&amp;start=0&amp;max_results=2</title>
  <updated>2026-09-28T22:21:52Z</updated>
  <link href="https://arxiv.org/api/query?search_query=all:consensus+AND+(all:large+AND+(all:language+AND+all:models))&amp;start=0&amp;max_results=2&amp;id_list=" type="application/atom+xml"/>
  <opensearch:itemsPerPage>2</opensearch:itemsPerPage>
  <opensearch:totalResults>773</opensearch:totalResults>
  <opensearch:startIndex>0</opensearch:startIndex>
  <entry>
    <id>http://arxiv.org/abs/2310.20151v2</id>
    <title>Multi-Agent Consensus Seeking via Large Language Models</title>
    <updated>2025-01-21T06:26:43Z</updated>
    <link href="https://arxiv.org/abs/2310.20151v2" rel="alternate" type="text/html"/>
    <link href="https://arxiv.org/pdf/2310.20151v2" rel="related" type="application/pdf" title="pdf"/>
    <summary>Multi-agent systems driven by large language models (LLMs) have shown promising abilities for solving complex tasks in a collaborative manner. This work considers a fundamental problem in multi-agent collaboration: consensus seeking. When multiple agents work together, we are interested in how they can reach a consensus through inter-agent negotiation. To that end, this work studies a consensus-seeking task where the state of each agent is a numerical value and they negotiate with each other to reach a consensus value. It is revealed that when not explicitly directed on which strategy should be adopted, the LLM-driven agents primarily use the average strategy for consensus seeking although they may occasionally use some other strategies. Moreover, this work analyzes the impact of the agent number, agent personality, and network topology on the negotiation process. The findings reported in this work can potentially lay the foundations for understanding the behaviors of LLM-driven multi-agent systems for solving more complex tasks. Furthermore, LLM-driven consensus seeking is applied to a multi-robot aggregation task. This application demonstrates the potential of LLM-driven agents to achieve zero-shot autonomous planning for multi-robot collaboration tasks. Project website: windylab.github.io/ConsensusLLM/.</summary>
    <category term="cs.CL" scheme="http://arxiv.org/schemas/atom"/>
    <category term="cs.RO" scheme="http://arxiv.org/schemas/atom"/>
    <category term="eess.SY" scheme="http://arxiv.org/schemas/atom"/>
    <published>2023-10-31T03:37:11Z</published>
    <arxiv:primary_category term="cs.CL"/>
    <author>
      <name>Huaben Chen</name>
    </author>
    <author>
      <name>Wenkang Ji</name>
    </author>
    <author>
      <name>Lufeng Xu</name>
    </author>
    <author>
      <name>Shiyu Zhao</name>
    </author>
  </entry>
  <entry>
    <id>http://arxiv.org/abs/2502.19064v2</id>
    <title>Can Large Language Models Outperform Non-Experts in Poetry Evaluation? A Comparative Study Using the Consensual Assessment Technique</title>
    <updated>2025-10-04T09:24:24Z</updated>
    <link href="https://arxiv.org/abs/2502.19064v2" rel="alternate" type="text/html"/>
    <link href="https://arxiv.org/pdf/2502.19064v2" rel="related" type="application/pdf" title="pdf"/>
    <summary>This study adapts the Consensual Assessment Technique (CAT) for Large Language Models (LLMs), introducing a novel methodology for poetry evaluation. Using a 90-poem dataset with a ground truth based on publication venue, we demonstrate that this approach allows LLMs to significantly surpass the performance of non-expert human judges. Our method, which leverages forced-choice ranking within small, randomized batches, enabled Claude-3-Opus to achieve a Spearman\'s Rank Correlation of 0.87 with the ground truth, dramatically outperforming the best human non-expert evaluation (SRC = 0.38). The LLM assessments also exhibited high inter-rater reliability, underscoring the methodology\'s robustness. These findings establish that LLMs, when guided by a comparative framework, can be effective and reliable tools for assessing poetry, paving the way for their broader application in other creative domains.</summary>
    <category term="cs.CL" scheme="http://arxiv.org/schemas/atom"/>
    <published>2025-02-26T11:43:25Z</published>
    <arxiv:comment>18 pages, 3 figures. Accepted for publication at the 2025 Conference on Empirical Methods in Natural Language Processing (EMNLP)</arxiv:comment>
    <arxiv:primary_category term="cs.CL"/>
    <author>
      <name>Piotr Sawicki</name>
    </author>
    <author>
      <name>Marek Grześ</name>
    </author>
    <author>
      <name>Dan Brown</name>
    </author>
    <author>
      <name>Fabrício Góes</name>
    </author>
  </entry>
</feed>
';

check('the parser is loadable', class_exists(ArxivAtomParser::class));
check('the client is loadable', class_exists(ArxivClient::class));
check('the analyst is loadable', class_exists(PaperAnalyst::class));
check('the fixture is a real feed', str_contains(ARXIV_FEED, '<feed') && str_contains(ARXIV_FEED, '<entry>'));

// ---- the feed becomes papers ------------------------------------------------
$papers = ArxivAtomParser::parse(ARXIV_FEED);
check('two entries give two papers', count($papers) === 2, (string)count($papers));
check('every paper has a title', ($papers[0]['title'] ?? '') !== '');
check('titles are one line, not the feed wrapping',
    !str_contains((string)$papers[0]['title'], "\n") && !str_contains((string)$papers[0]['title'], '  '),
    json_encode($papers[0]['title'] ?? null));
check('the id becomes an https abstract page',
    str_starts_with((string)$papers[0]['url'], 'https://arxiv.org/abs/'), (string)($papers[0]['url'] ?? ''));
check('the summary is kept', mb_strlen((string)$papers[0]['summary']) > 100);
check('the date is a date', preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$papers[0]['published']) === 1,
    (string)($papers[0]['published'] ?? ''));
check('authors are listed', is_array($papers[0]['authors']) && $papers[0]['authors'] !== []);
check('at most three authors plus et al', count($papers[0]['authors']) <= 4);

check('an empty feed gives no papers', ArxivAtomParser::parse('<feed></feed>') === []);
check('a broken entry is skipped, not fatal',
    ArxivAtomParser::parse('<feed><entry><title>No id here</title></entry></feed>') === []);
check('a bare id becomes a full url', ArxivAtomParser::idToUrl('2509.12345') === 'https://arxiv.org/abs/2509.12345');
check('an http id becomes https', ArxivAtomParser::idToUrl('http://arxiv.org/abs/2509.1') === 'https://arxiv.org/abs/2509.1');
check('four authors become three plus et al',
    ArxivAtomParser::trimAuthors(['a', 'b', 'c', 'd']) === ['a', 'b', 'c', 'et al.']);

// ---- the topic becomes a query ----------------------------------------------
check('a topic becomes AND-ed terms',
    ArxivClient::searchQuery('multi agent consensus') === 'all:multi AND all:agent AND all:consensus',
    ArxivClient::searchQuery('multi agent consensus'));
check('a quoted phrase stays a phrase',
    str_contains(ArxivClient::searchQuery('"large language models" survey'), 'all:"large language models"'),
    ArxivClient::searchQuery('"large language models" survey'));
check('single letters are dropped', !str_contains(ArxivClient::searchQuery('a study of x'), 'all:a'));
check('an empty topic gives an empty query', ArxivClient::searchQuery('   ') === '');
check('the query is capped at eight terms',
    substr_count(ArxivClient::searchQuery('one two three four five six seven eight nine ten'), ' AND ') === 7);

// ---- the prompt --------------------------------------------------------------
$messages = PaperAnalyst::messages('consensus of LLMs', $papers);
check('the prompt has a system and a user turn', count($messages) === 2
    && $messages[0]['role'] === 'system' && $messages[1]['role'] === 'user');
check('the system turn demands citations', str_contains($messages[0]['content'], 'Cite as [n]'));
check('the system turn forbids inventing facts', str_contains($messages[0]['content'], 'never invent a fact'));
check('the topic is named', str_contains($messages[1]['content'], 'consensus of LLMs'));
check('every paper is numbered', str_contains($messages[1]['content'], '[1]') && str_contains($messages[1]['content'], '[2]'));
check('the abstracts are in the prompt', str_contains($messages[1]['content'], 'Abstract:'));
$long = $papers;
$long[0]['summary'] = str_repeat('x', PaperAnalyst::ABSTRACT_LIMIT + 500);
$longMessages = PaperAnalyst::messages('t', $long);
check('an over-long abstract is cut', !str_contains($longMessages[1]['content'], str_repeat('x', PaperAnalyst::ABSTRACT_LIMIT + 100)));
check('and says so', str_contains($longMessages[1]['content'], ' ...'));

// ---- the wiring --------------------------------------------------------------
$index = (string)file_get_contents($root . '/index.php');
check('the search route is registered', str_contains($index, "'/api/research/search'"));
check('the analyse route is registered', str_contains($index, "'/api/research/analyse'"));
check('the settings routes are registered', str_contains($index, "'/api/research/settings'"));
check('the controller is built with the analyst and the journal', str_contains($index, 'new ResearchController(new \App\Research\PaperAnalyst($connectors), new \App\Research\ResearchJournal($pdo))'));
check('external api keys are kept off it',
    str_contains((string)file_get_contents($root . '/src/Api/ApiAuth.php'), "'/api/research'"));
$controller = (string)file_get_contents($root . '/src/Http/Controllers/ResearchController.php');
check('a search failure is a 502, not a silent empty list', str_contains($controller, "'arxiv_unavailable'"));
check('an empty result is a 404 the operator can read', str_contains($controller, "'no_papers'"));
check('the research model reset writes empty values', str_contains($controller, "'research.provider' => ''"));
check('a batch model is refused with its reason', str_contains($controller, 'model_not_synchronous'));

// ---- ranking, the plan, and the card -----------------------------------------
check('terms are shared between the query and the ranking',
    ArxivClient::topicTerms('"Large Language Models" survey') === ['large language models', 'survey'],
    json_encode(ArxivClient::topicTerms('"Large Language Models" survey')));
check('and the plain words keep their order',
    ArxivClient::topicTerms('Multi Agent Consensus') === ['multi', 'agent', 'consensus']);
check('a candidate window is wider than the answer',
    ArxivClient::candidatesFor(5) === 25 && ArxivClient::candidatesFor(10) === 30 && ArxivClient::candidatesFor(1) === 10);
check('the search ranks before answering', str_contains($controller, 'PaperRanker::rank'));
check('the answer says how many candidates were considered',
    str_contains($controller, "'candidates'") && str_contains($controller, "=> count(\$result['papers']),"));
check('the analysis is told to end in a plan', str_contains($messages[0]['content'], 'Changes to consider'));
check('the controller extracts proposals', str_contains($controller, 'AnalysisProposals::extract'));
check('and returns the plan ready to use', str_contains($controller, "'plan_markdown' =>"));
$modelsJs = (string)file_get_contents($root . '/assets/js/models.js');
check('the card shows why a paper was kept', str_contains($modelsJs, 'relevance ${paper.score}'));
check('the card offers the change plan', str_contains($modelsJs, "'oc-plan-copy'") && str_contains($modelsJs, "'oc-plan-download'"));
check('and can build the plan file', str_contains($modelsJs, 'change-plan-${slug}.md'));
check('the journal routes are registered',
    str_contains($index, "'/api/research/history'")
    && str_contains($index, "'/api/research/run/{id}'")
    && str_contains($index, "'/api/research/run/delete'")
    && str_contains($index, "'/api/research/history/clear'")
    && str_contains($index, "'/api/research/plan/status'")
    && str_contains($index, "'/api/research/plan/check'"));
check('the journal backs the searcher', str_contains($index, 'new \App\Research\ResearchJournal($pdo)'));
check('seen papers are marked, never hidden silently',
    str_contains($controller, 'seen_first') && str_contains($controller, 'seen_hidden'));
check('the plan keeps live state', str_contains($controller, 'setPlanChecked') && str_contains($controller, 'plan_checked'));
check('recording is best-effort', str_contains($controller, '$id !== null'));
check('the card has only-new and history', str_contains($modelsJs, "'oc-research-only-new'") && str_contains($modelsJs, "'oc-research-history'"));
check('plans carry live state in the card', str_contains($modelsJs, "'oc-plan-status'") && str_contains($modelsJs, 'saveResearchPlanChecks'));
check('the nothing-is-stored note is gone', !str_contains($modelsJs, 'Nothing is stored.'));
check('the journal keeps the whole found window',
    str_contains($controller, "'window' => ") && str_contains($controller, "'found'"));
check('and the store has a column for it',
    str_contains((string)file_get_contents($root . '/src/Research/ResearchJournal.php'), 'candidates_json'));
check('the card tells shown of found', str_contains($modelsJs, 'of ${found} paper(s)'));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);