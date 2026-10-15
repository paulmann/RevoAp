<?php
declare(strict_types=1);
/**
 * ChatAttachmentsTest: the wiring of composer attachments, end to end in code.
 *
 * The attachment feature lives in five files that must agree or it silently
 * does nothing: the composer template (the picker exists), app.js (files are
 * read and ride in the job payload; the legacy mode routes through the
 * worker; the limits mirror the server), the job service (validated at
 * enqueue; batch refused), the worker runner (re-validated before dispatch),
 * the engine (every user message carries the block) and the controller (the
 * synchronous path validates too). The checks pin exactly those joints.
 *
 *   php tests/ChatAttachmentsTest.php
 */
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

$html  = (string)file_get_contents($root . '/assets/templates/chat.html');
$app   = (string)file_get_contents($root . '/assets/js/app.js');
$css   = (string)file_get_contents($root . '/assets/css/components/chat.css');
$jobs  = (string)file_get_contents($root . '/src/Jobs/JobService.php');
$runner = (string)file_get_contents($root . '/src/Jobs/PipelineJobRunner.php');
$engine = (string)file_get_contents($root . '/src/Pipeline/PipelineEngine.php');
$ctrl  = (string)file_get_contents($root . '/src/Http/Controllers/CouncilController.php');
$historyJs  = (string)file_get_contents($root . '/assets/js/history.js');
$pipelineJs = (string)file_get_contents($root . '/assets/js/pipeline.js');
$historySvc = (string)file_get_contents($root . '/src/History/HistoryService.php');

// ---- the composer ------------------------------------------------------------
check('the attach input exists', str_contains($html, 'id="chat-attach-input"'));
check('the attach button exists', str_contains($html, 'id="chat-attach-button"'));
check('the chips host exists', str_contains($html, 'id="chat-attachments"'));
check('the file dialog filters to text formats', str_contains($html, 'accept=".txt,.md,') && str_contains($html, '.sql,'));

// ---- app.js ------------------------------------------------------------------
check('the picker is wired', str_contains($app, 'function initAttachments(') && str_contains($app, 'initAttachments();'));
check('files are read as text in the browser', str_contains($app, 'await file.text()'));
check('the job payload carries them', str_contains($app, 'body.attachments = attachments;'));
check('the composer no longer refuses batch attachments', !str_contains($app, 'not supported with the Batch API'));
check('a legacy-mode attachment run goes through the worker', str_contains($app, 'attachmentsQueued'));
check('the browser limits mirror the server', str_contains($app, 'ATTACHMENT_MAX_FILES = 6')
    && str_contains($app, 'ATTACHMENT_MAX_FILE_CHARS = 128000')
    && str_contains($app, 'ATTACHMENT_MAX_TOTAL_CHARS = 300000'));
check('chips are styled', str_contains($css, '.oc-attachment-chip'));

// ---- the server chain --------------------------------------------------------
check('the service validates at enqueue',
    str_contains($jobs, 'QueryAttachments::fromRequest') && str_contains($jobs, "'attachments'") && str_contains($jobs, '$attachments,'));
check('batch runs carry the attachments in their options',
    !str_contains($jobs, 'not supported with the Batch API') && str_contains($jobs, "options['attachments']"));
check('the worker re-validates before dispatch', str_contains($runner, 'QueryAttachments::fromRequest'));
check('the sync path validates too',
    str_contains($ctrl, 'QueryAttachments::fromRequest') && str_contains($ctrl, "'invalid_attachments'"));
check('every user message carries the block', substr_count($engine, '$this->prependAttachments(') === 5,
    (string)substr_count($engine, '$this->prependAttachments('));
check('the block renders through the validator', str_contains($engine, 'QueryAttachments::renderBlock'));
check('the batch planner shares the user-message builder',
    str_contains($engine, "\$messages = \$this->buildL1Messages(\$role, \$query);"));

// ---- the names, where a run is read ------------------------------------------
check('the enqueue keeps the attachment names in the session metadata',
    str_contains($jobs, 'buildSessionMetadata($input, $attachments)')
    && str_contains($jobs, 'buildSessionMetadata($input, $batchAttachments')
    && str_contains($jobs, "\$meta['attachments'] = array_values(array_map("));
check('the worker run writes the names into the session metadata too',
    str_contains($engine, "\$sessionMeta['attachments'] = \$attachmentNames;"));
check('the live query card can show the file names',
    str_contains($pipelineJs, 'renderQueryCard(query, info = null, files = null)')
    && str_contains($pipelineJs, "'Files: ' + files.map(String).join(', ')"));
check('and the composer passes them',
    str_contains($app, 'renderQueryCard(query, queryParticipantsInfo(), attachmentNamesForCard())'));
check('the History detail shows an Attached files block',
    str_contains($historyJs, "'Attached files'") && str_contains($historyJs, 's.options.attachments'));
check('the History list shows a files count', str_contains($historyJs, "'files: ' + item.files"));
check('the service counts them for the list',
    str_contains($historySvc, "'files'            =>") && str_contains($historySvc, "count(\$opts['attachments'])"));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
