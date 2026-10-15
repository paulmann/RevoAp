#Requires -Version 7.6
#Requires -PSEdition Core
<#
.SYNOPSIS
    Запускает tests/SmokeTest.php и сравнивает набор падений с сохранённым baseline.

.DESCRIPTION
    Норма в этом проекте — не «всё зелёное», а «набор падений не вырос». Раньше это
    сравнивалось вручную: прогнать тест, скопировать список FAIL, собрать список
    до правки, сверить построчно. Ручной шаг легко ошибается (разные отступы в
    строке дают ложные «новые падения») и занимает два прогона теста.

    Скрипт делает то же детерминированно: нормализует метки падений, сравнивает с
    файлом baseline и печатает только разницу.

    Коды выхода:
      0 — новых падений нет (набор совпал или падений стало меньше)
      1 — есть новые падения (регрессия)
      2 — проверку выполнить не удалось (нет php, нет baseline при сравнении,
          вывод теста не разобран)

.PARAMETER BaselinePath
    Файл baseline. По умолчанию — tools/smoke-baseline.txt рядом со скриптом.

.PARAMETER TestFile
    Путь к тесту относительно корня проекта. По умолчанию tests/SmokeTest.php.

.PARAMETER Update
    Записать текущий набор падений как новый baseline (осознанное действие:
    только когда состав падений изменился намеренно).

.EXAMPLE
    pwsh -NoLogo -NoProfile -NonInteractive -File .\tools\Compare-SmokeBaseline.ps1

.EXAMPLE
    pwsh -NoLogo -NoProfile -NonInteractive -File .\tools\Compare-SmokeBaseline.ps1 -Update

.NOTES
    Только чтение состояния проекта: тест не меняет файлы, скрипт не трогает БД.
#>
[CmdletBinding()]
param(
    [string] $BaselinePath = '',
    [string] $TestFile     = 'tests/SmokeTest.php',
    [switch] $Update
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
if ($BaselinePath -eq '') {
    $BaselinePath = Join-Path $PSScriptRoot 'smoke-baseline.txt'
}
$testPath = Join-Path $projectRoot $TestFile

if (-not (Test-Path -LiteralPath $testPath)) {
    Write-Error "Тест не найден: $testPath"
    exit 2
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $php) {
    Write-Error 'php не найден в PATH — проверку выполнить нельзя.'
    exit 2
}

Write-Host "== $TestFile ==" -ForegroundColor Cyan
$raw  = & php $testPath 2>&1 | Out-String
$text = $raw -replace "`r`n", "`n"

# Метка падения: строка вида "  FAIL <label>" (отступы и хвостовые пробелы не значимы).
$current = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
foreach ($line in ($text -split "`n")) {
    $m = [regex]::Match($line, '^\s*FAIL\s+(?<label>.+?)\s*$')
    if ($m.Success) { [void]$current.Add($m.Groups['label'].Value) }
}

# Итог прогона берётся из собственного отчёта теста, а не пересчитывается.
$summary = [regex]::Match($text, 'Passes:\s*(?<p>\d+)\s+Failures:\s*(?<f>\d+)')
if (-not $summary.Success) {
    Write-Host 'Не удалось разобрать итог "Passes: N  Failures: M" — вывод теста:' -ForegroundColor Yellow
    Write-Host ($text | Select-Object -Last 25)
    exit 2
}
$passes   = [int]$summary.Groups['p'].Value
$failures = [int]$summary.Groups['f'].Value
Write-Host "Passes: $passes  Failures: $failures  (уникальных меток: $($current.Count))"
if ($failures -ne $current.Count) {
    # Не «глушим»: расхождение означает, что парсер увидел не все падения.
    Write-Host "Внимание: Failures=$failures, а разобрано меток $($current.Count) — разбор неполный." -ForegroundColor Yellow
}

$sortedCurrent = @($current) | Sort-Object

if ($Update) {
    # Baseline пишется в UTF-8 без BOM и с LF, как остальные текстовые файлы проекта.
    $body = ($sortedCurrent -join "`n")
    if ($sortedCurrent.Count -gt 0) { $body += "`n" }
    [IO.File]::WriteAllText($BaselinePath, $body, [System.Text.UTF8Encoding]::new($false))
    Write-Host "Baseline записан: $BaselinePath ($($sortedCurrent.Count) меток)" -ForegroundColor Green
    exit 0
}

if (-not (Test-Path -LiteralPath $BaselinePath)) {
    Write-Host "Baseline не найден: $BaselinePath" -ForegroundColor Yellow
    Write-Host 'Создайте его осознанно: тот же скрипт с -Update.' -ForegroundColor Yellow
    exit 2
}

$baseline = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
foreach ($line in ([IO.File]::ReadAllText($BaselinePath) -split "`n")) {
    $t = $line.Trim()
    if ($t -ne '') { [void]$baseline.Add($t) }
}

$newFailures = @($sortedCurrent | Where-Object { -not $baseline.Contains($_) })
$fixed       = @(@($baseline) | Sort-Object | Where-Object { -not $current.Contains($_) })

Write-Host ''
if ($newFailures.Count -gt 0) {
    Write-Host "НОВЫЕ ПАДЕНИЯ ($($newFailures.Count)):" -ForegroundColor Red
    $newFailures | ForEach-Object { Write-Host "  + $_" }
}
if ($fixed.Count -gt 0) {
    Write-Host "ИСЧЕЗЛИ ($($fixed.Count)) — убедитесь, что это ожидаемо:" -ForegroundColor Green
    $fixed | ForEach-Object { Write-Host "  - $_" }
}
if ($newFailures.Count -eq 0 -and $fixed.Count -eq 0) {
    Write-Host "Набор падений идентичен baseline ($($baseline.Count) меток)." -ForegroundColor Green
}

exit ($(if ($newFailures.Count -gt 0) { 1 } else { 0 }))
