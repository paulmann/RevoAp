#Requires -Version 7.6
#Requires -PSEdition Core
<#
.SYNOPSIS
    Прогоняет набор standalone-тестов RevoAp и печатает по одной строке итога на тест.

.DESCRIPTION
    Тесты в проекте — не PHPUnit: это автономные скрипты с собственным check() и
    итогом «Passes: N  Failures: M». Прогонять их по одному и читать полный вывод
    дорого: на десяток тестов уходит несколько минут и тысячи строк, из которых
    нужны только итоги и метки падений.

    Скрипт запускает выбранные тесты (или весь набор), разбирает итог каждого и
    печатает компактную таблицу. Падения выводятся отдельными строками с метками —
    этого достаточно, чтобы отличить регрессию от известного долга.

    Коды выхода:
      0 — ни в одном тесте, который удалось запустить, нет падений
      1 — есть падения (в т.ч. baseline-долг SmokeTest)
      2 — часть тестов не удалось запустить или разобрать

.PARAMETER Tests
    Конкретные файлы тестов (пути от корня проекта). Без параметра берётся набор
    по умолчанию: регламентные из docs/roadmap/R1_run_transparency.md плюс те,
    что чаще всего задевает правки в src/ и assets/.

.PARAMETER Baseline
    Скрипт Compare-SmokeBaseline, а не прогон: сравнивает набор падений SmokeTest.

.PARAMETER IncludeScenarios
    Добавить к набору по умолчанию tests/scenarios/*Test.php (их запуск дороже).

.EXAMPLE
    pwsh -NoLogo -NoProfile -NonInteractive -File .\tools\Run-RevoApTests.ps1

.EXAMPLE
    pwsh -NoLogo -NoProfile -NonInteractive -File .\tools\Run-RevoApTests.ps1 -Tests tests/SmokeTest.php,tests/CssTokensTest.php

.NOTES
    Ничего не меняет: тесты читают репозиторий и работают без БД и сети.
    DOM-стенды требуют node; без node соответствующие раннеры печатают SKIP.
#>
[CmdletBinding()]
param(
    [string[]] $Tests = @(),
    [switch]   $IncludeScenarios
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot

# Вызов через `pwsh -File` передаёт `-Tests a,b,c` ОДНОЙ строкой, а не массивом:
# аккуратно разворачиваем её в список, чтобы оба способа вызова работали одинаково.
if ($Tests.Count -eq 1 -and $Tests[0].Contains(',')) {
    $Tests = @($Tests[0] -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
}

# Набор по умолчанию: регламент R1 + тесты, которые чаще всего ломаются от правок
# в src/** и assets/** (они читают исходники через str_contains).
$defaultTests = @(
    'tests/SmokeTest.php'
    'tests/CssTokensTest.php'
    'tests/TerminalStatusTest.php'
    'tests/ChatStreamDomTest.php'
    'tests/ChatAttachmentsTest.php'
    'tests/FormFieldIdsTest.php'
    'tests/SseEventContractTest.php'
    'tests/HistoryCompletionTest.php'
    'tests/PipelinePlanTest.php'
    'tests/JobSessionReuseTest.php'
    'tests/BatchPlanTest.php'
    'tests/ApiLaunchTest.php'
    'tests/MigrationBannerTest.php'
)
if ($Tests.Count -eq 0) { $Tests = $defaultTests }
if ($IncludeScenarios) {
    $scenarios = Get-ChildItem -Path (Join-Path $projectRoot 'tests/scenarios') -Filter '*Test.php' -File -ErrorAction SilentlyContinue
    foreach ($s in $scenarios) {
        $Tests += ('tests/scenarios/' + $s.Name)
    }
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $php) {
    Write-Error 'php не найден в PATH — прогон выполнить нельзя.'
    exit 2
}

$results  = [System.Collections.Generic.List[object]]::new()
$unparsed = 0
$missing  = 0

foreach ($rel in $Tests) {
    $path = Join-Path $projectRoot $rel
    $name = Split-Path $rel -Leaf
    if (-not (Test-Path -LiteralPath $path)) {
        Write-Host ("{0,-34} MISSING" -f $name) -ForegroundColor Yellow
        $missing++
        continue
    }

    $sw   = [Diagnostics.Stopwatch]::StartNew()
    $raw  = & php $path 2>&1 | Out-String
    $sw.Stop()
    $text = $raw -replace "`r`n", "`n"

    $m = [regex]::Match($text, 'Passes:\s*(?<p>\d+)\s+Failures:\s*(?<f>\d+)')
    if (-not $m.Success) {
        $m = [regex]::Match($text, 'passed:\s*(?<p>\d+)\s+failed:\s*(?<f>\d+)')
    }
    if (-not $m.Success) {
        # Третий формат отчёта в проекте: "ALL OK: N passed, M failed".
        $m = [regex]::Match($text, 'ALL OK:\s*(?<p>\d+)\s+passed,\s*(?<f>\d+)\s+failed')
    }

    if (-not $m.Success) {
        Write-Host ("{0,-34} НЕ РАЗОБРАН ({1:N1}s)" -f $name, $sw.Elapsed.TotalSeconds) -ForegroundColor Yellow
        Write-Host ($text | Select-Object -Last 6)
        $unparsed++
        $results.Add([pscustomobject]@{ Name = $name; Passes = $null; Failures = $null; Seconds = $sw.Elapsed.TotalSeconds })
        continue
    }

    $p = [int]$m.Groups['p'].Value
    $f = [int]$m.Groups['f'].Value
    $label = ($text -split "`n" | Where-Object { $_ -match 'skip' } | Measure-Object).Count

    $colour = if ($f -gt 0) { 'Red' } else { 'Green' }
    $skipNote = if ($label -gt 0) { ' (есть SKIP)' } else { '' }
    Write-Host ("{0,-34} {1,5} / {2,-4} {3,6:N1}s{4}" -f $name, $p, $f, $sw.Elapsed.TotalSeconds, $skipNote) -ForegroundColor $colour

    if ($f -gt 0) {
        foreach ($line in ($text -split "`n")) {
            $fm = [regex]::Match($line, '^\s*FAIL\s+(?<label>.+?)\s*$')
            if ($fm.Success) { Write-Host ("      FAIL {0}" -f $fm.Groups['label'].Value) -ForegroundColor DarkRed }
        }
    }

    $results.Add([pscustomobject]@{ Name = $name; Passes = $p; Failures = $f; Seconds = $sw.Elapsed.TotalSeconds })
}

Write-Host ('-' * 72)
$withFailures = @($results | Where-Object { $null -ne $_.Failures -and $_.Failures -gt 0 })
# Суммируем вручную: под Set-StrictMode `(... | Measure-Object -Sum).Sum` падает,
# когда набор пуст (свойство Sum у $null отсутствует).
$totalPasses = 0
$totalFails  = 0
foreach ($r in $results) {
    if ($null -ne $r.Passes)   { $totalPasses += [int]$r.Passes }
    if ($null -ne $r.Failures) { $totalFails  += [int]$r.Failures }
}

$summaryLine = "тестов: {0}   Passes: {1}   Failures: {2}   не разобрано: {3}   отсутствует: {4}" -f `
    $results.Count, $totalPasses, $totalFails, $unparsed, $missing
Write-Host $summaryLine
if ($withFailures.Count -gt 0) {
    Write-Host ("с падениями: " + (($withFailures | ForEach-Object { $_.Name }) -join ', ')) -ForegroundColor Red
    Write-Host 'Для SmokeTest сверяйте набор с baseline: tools/Compare-SmokeBaseline.ps1'
}

if ($unparsed -gt 0 -or $missing -gt 0) { exit 2 }
exit ($(if ($totalFails -gt 0) { 1 } else { 0 }))
