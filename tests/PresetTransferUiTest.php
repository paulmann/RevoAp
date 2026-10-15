<?php
declare(strict_types=1);
/**
 * PresetTransferUiTest: the Models tab offers the two controls, wired right.
 *
 * The API half is covered by DomainPresetTransferTest; this pins the interface
 * half so a refactor of models.js cannot quietly drop it: both controls exist,
 * the export downloads the document instead of navigating, the import posts a
 * dry run first and only writes after a confirmation, and a declined
 * confirmation writes nothing.
 *
 *   php tests/PresetTransferUiTest.php
 */
$root = dirname(__DIR__);
$js = (string)file_get_contents($root . '/assets/js/models.js');
$css = (string)file_get_contents($root . '/assets/css/components/models.css');
$passes = 0; $failed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $passes, $failed;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failed++; }
}

check('the bar is built', str_contains($js, 'function presetTransferBar('));
check('an export control exists', str_contains($js, "id: 'oc-presets-export'"));
check('an import control exists', str_contains($js, "id: 'oc-presets-import'"));
check('a file picker is present and hidden', str_contains($js, "id: 'oc-presets-import-file'") && str_contains($js, "style: 'display:none'"));
check('a status line is announced politely', str_contains($js, "'aria-live': 'polite'"));
check('the bar is added to the preset section', str_contains($js, 'root.prepend(presetTransferBar(root))'));

check('the export asks the export route', str_contains($js, "'/api/domain-presets/export'"));
check('the import asks the import route', str_contains($js, "'/api/domain-presets/import'"));
check('the export downloads rather than navigates',
    str_contains($js, 'URL.createObjectURL') && str_contains($js, "setAttribute('download'"));
check('the import reads the file the operator picked', str_contains($js, 'fileInput.files && fileInput.files[0]'));
check('the first import call is a dry run', str_contains($js, 'postPresetDocument(document_, false)'));
check('the write only happens after a confirmation', str_contains($js, 'window.confirm(question)'));
check('a declined confirmation writes nothing',
    preg_match('/window\.confirm\(question\)\)[\s\S]{0,220}return;/', $js) === 1);
check('the changes are shown before applying', str_contains($js, "'Apply these changes?"));
check('unknown models are surfaced, not hidden', str_contains($js, 'Unknown models (will not be written)'));
check('an export failure is reported, not swallowed', str_contains($js, "'Export failed: '"));
check('an import failure is reported too', str_contains($js, "'Import failed: '"));
check('the dry run never asks the server to write',
    str_contains($js, "Object.assign({}, document_, { apply: apply === true })"));

check('the bar has styling', str_contains($css, '.oc-presets-transfer'));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
