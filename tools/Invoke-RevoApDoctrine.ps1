#Requires -Version 7.6
#Requires -PSEdition Core

<#
.SYNOPSIS
    Checks the RevoAp coding doctrine and schema conventions without Composer or PHPUnit.

.DESCRIPTION
    RevoAp has no CI, no .editorconfig and no PHPUnit installation; its coding
    standard lives in SKILL.md and its schema convention in db/schema.sql.
    This script turns the machine-checkable part of those rules into a single
    read-only pass, so a change can be judged before it reaches the host.

    Checks performed:
      1. declare(strict_types=1) in every src/**/*.php file.
      2. No eval() and no variable variables in src/ (comments are stripped first).
      3. No die()/exit() in src/ class code.
      4. Every src/**/*.php file carries the @project RevoAp header.
      5. Every src/ subdirectory has its companion [Directory].md file.
      6. Every file in src/ and bin/ passes `php -l` (skipped without php).
      7. Every CREATE TABLE in db/schema.sql carries @table-version, and every
         table created by a migration is declared in db/schema.sql.
      8. Migration file names follow NNN_snake_case.sql, numbers are unique and
         contiguous, and the schema version for a table is not older than the
         newest migration version for that table.
      9. db/schema.sql @schema-version equals config.php app.version.
     10. .gitignore still covers secret material (and warns about .httpauth).

    Exit code 0 when only warnings remain, 1 when at least one error is found.
    Use -Strict to treat warnings as errors.

.PARAMETER RepoRoot
    Repository root. Defaults to the parent directory of this script.

.PARAMETER MaxExamples
    How many examples to print per failed check. Defaults to 10.

.PARAMETER Strict
    Treat warnings as errors.

.PARAMETER SkipPhpLint
    Skip the `php -l` sweep (useful when php is not on PATH).

.EXAMPLE
    pwsh -NoLogo -NoProfile -NonInteractive -File .\tools\Invoke-RevoApDoctrine.ps1

.EXAMPLE
    pwsh -NoLogo -NoProfile -NonInteractive -File .\tools\Invoke-RevoApDoctrine.ps1 -Strict -SkipPhpLint

.NOTES
    Read-only: nothing in the repository is modified.
#>

[CmdletBinding()]
param(
    [string] $RepoRoot = (Split-Path -Parent $PSScriptRoot),
    [int] $MaxExamples = 10,
    [switch] $Strict,
    [switch] $SkipPhpLint
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = [System.IO.Path]::GetFullPath($RepoRoot)

$composerPath = Join-Path $RepoRoot 'composer.json'
$schemaPath = Join-Path $RepoRoot 'db\schema.sql'
if (-not (Test-Path -LiteralPath $composerPath -PathType Leaf) -or
    -not (Test-Path -LiteralPath $schemaPath -PathType Leaf)) {
    throw "Not a RevoAp repository root (composer.json / db/schema.sql not found): $RepoRoot"
}

function Get-RelativePath {
    param([Parameter(Mandatory)][string] $FullPath)

    return ([System.IO.Path]::GetRelativePath($RepoRoot, $FullPath)) -replace '\\', '/'
}

function Get-PhpFiles {
    param(
        [Parameter(Mandatory)][string] $TopDirectory,
        [switch] $Recurse
    )

    $root = Join-Path $RepoRoot $TopDirectory
    if (-not (Test-Path -LiteralPath $root -PathType Container)) { return @() }

    $items = if ($Recurse) { Get-ChildItem -LiteralPath $root -Recurse -File -Filter '*.php' }
             else { Get-ChildItem -LiteralPath $root -File -Filter '*.php' }

    return @(
        $items | Where-Object { $_.FullName -notmatch '\\(vendor|node_modules|\.git)\\' } | Sort-Object FullName
    )
}

function Remove-PhpComments {
    <#
        Removes /* */ comments and // line comments so that prose mentions of a
        forbidden API are not reported as usage. "#" is deliberately not treated
        as a comment marker here: it is rare in this codebase and stripping it
        would hide shell-style pragma lines.
    #>
    param([Parameter(Mandatory)][string] $Text)

    $withoutBlock = [regex]::Replace($Text, '/\*.*?\*/', ' ', 'Singleline')
    return [regex]::Replace($withoutBlock, '(?m)(^|\s)//[^\n]*', '$1 ')
}

# Known, documented deviations from the doctrine. Each entry needs a reason:
# the point of the register is visibility, not silence. Anything outside this
# list is a failure.
$script:KnownEvalExceptions = @{
    'src/Core/Config.php' = 'evalTrustedReturn(): evaluates a root-only <?php return [...]; payload from .config.php/.sql.php, never web input (Config.php:876-888)'
}

function New-Result {
    param(
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][bool] $Ok,
        [string[]] $Details = @(),
        [switch] $Warning
    )

    [pscustomobject]@{
        Name    = $Name
        Ok      = $Ok
        Details = $Details
        Warning = [bool]$Warning
    }
}

# ---------------------------------------------------------------- PHP doctrine

function Test-StrictTypes {
    $offenders = [System.Collections.Generic.List[string]]::new()
    foreach ($file in (Get-PhpFiles -TopDirectory 'src' -Recurse)) {
        if ([System.IO.File]::ReadAllText($file.FullName) -notmatch 'declare\s*\(\s*strict_types\s*=\s*1\s*\)') {
            $offenders.Add((Get-RelativePath $file.FullName))
        }
    }
    return New-Result -Name 'declare(strict_types=1) in every src/**/*.php' -Ok ($offenders.Count -eq 0) -Details $offenders
}

function Test-ForbiddenConstructs {
    $offenders = [System.Collections.Generic.List[string]]::new()

    foreach ($file in (Get-PhpFiles -TopDirectory 'src' -Recurse)) {
        $code = Remove-PhpComments ([System.IO.File]::ReadAllText($file.FullName))
        $relative = Get-RelativePath $file.FullName
        $lineNumber = 0
        foreach ($line in ($code -split "`n")) {
            $lineNumber++

            if ($line -match '(?<![\w>$])eval\s*\(') {
                if ($script:KnownEvalExceptions.ContainsKey($relative)) { continue }
                $offenders.Add("$relative`:$lineNumber eval() is forbidden")
            }
            if ($line -match '\$\$[A-Za-z_]') {
                $offenders.Add("$relative`:$lineNumber variable variable is forbidden")
            }
            if ($line -match '(?<![\w>$])(die|exit)\s*\(') {
                $offenders.Add("$relative`:$lineNumber die()/exit() in application code")
            }
        }
    }

    return New-Result -Name 'no eval() / $$var / die() / exit() outside documented deviations' -Ok ($offenders.Count -eq 0) -Details $offenders
}

function Test-KnownDeviations {
    # Informational: lists the exceptions that are deliberately tolerated so a
    # reader still sees them on every run.
    $found = [System.Collections.Generic.List[string]]::new()
    foreach ($file in (Get-PhpFiles -TopDirectory 'src' -Recurse)) {
        $relative = Get-RelativePath $file.FullName
        if (-not $script:KnownEvalExceptions.ContainsKey($relative)) { continue }
        $code = Remove-PhpComments ([System.IO.File]::ReadAllText($file.FullName))
        $lineNumber = 0
        foreach ($line in ($code -split "`n")) {
            $lineNumber++
            if ($line -match '(?<![\w>$])eval\s*\(') {
                $found.Add("$relative`:$lineNumber eval() - " + $script:KnownEvalExceptions[$relative])
            }
        }
    }

    $name = 'documented deviations in force (' + $found.Count + ' eval() exception)'
    return New-Result -Name $name -Ok $true -Details @() -Warning
}

function Test-FileHeaders {
    $offenders = [System.Collections.Generic.List[string]]::new()
    foreach ($file in (Get-PhpFiles -TopDirectory 'src' -Recurse)) {
        $text = [System.IO.File]::ReadAllText($file.FullName)
        $missing = [System.Collections.Generic.List[string]]::new()
        if ($text -notmatch '@project\s+RevoAp') { $missing.Add('@project RevoAp') }
        if ($text -notmatch '@version') { $missing.Add('@version') }
        if ($text -notmatch '@since') { $missing.Add('@since') }
        if ($missing.Count -gt 0) {
            $offenders.Add((Get-RelativePath $file.FullName) + ' -> missing ' + ($missing -join ', '))
        }
    }
    return New-Result -Name 'file header (@project/@version/@since) present' -Ok ($offenders.Count -eq 0) -Details $offenders -Warning
}

function Test-ModuleCompanionDocs {
    $offenders = [System.Collections.Generic.List[string]]::new()
    $srcRoot = Join-Path $RepoRoot 'src'
    foreach ($directory in (Get-ChildItem -LiteralPath $srcRoot -Directory | Sort-Object Name)) {
        $companion = Join-Path $directory.FullName ($directory.Name + '.md')
        if (-not (Test-Path -LiteralPath $companion -PathType Leaf)) {
            $offenders.Add('src/' + $directory.Name + '/' + $directory.Name + '.md is missing')
        }
    }
    return New-Result -Name 'every src/ module has its companion [Directory].md' -Ok ($offenders.Count -eq 0) -Details $offenders -Warning
}

function Test-SuppressionUsage {
    $offenders = [System.Collections.Generic.List[string]]::new()
    foreach ($file in (Get-PhpFiles -TopDirectory 'src' -Recurse)) {
        $code = Remove-PhpComments ([System.IO.File]::ReadAllText($file.FullName))
        $lineNumber = 0
        foreach ($line in ($code -split "`n")) {
            $lineNumber++
            if ($line -match '@\s*[A-Za-z_\\]+\s*\(' -or $line -match '@\s*\$') {
                $offenders.Add((Get-RelativePath $file.FullName) + ":$lineNumber " + $line.Trim())
            }
        }
    }
    # Informational: '@' before best-effort filesystem calls is an established
    # idiom in this codebase (RuntimeConfig, ModelResponseCache, LogsService).
    return New-Result -Name ("'@' error suppression usage (informational: " + $offenders.Count + ' sites)') -Ok $true -Details @() -Warning
}

function Test-PhpSyntax {
    $offenders = [System.Collections.Generic.List[string]]::new()
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) {
        return New-Result -Name 'php -l over src/ and bin/ (skipped: php not found)' -Ok $true -Details @() -Warning
    }

    $files = @(Get-PhpFiles -TopDirectory 'src' -Recurse) + @(Get-PhpFiles -TopDirectory 'bin')
    $checked = 0
    foreach ($file in $files) {
        $checked++
        $output = & $php.Source -l $file.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            $first = @($output | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -First 1)
            $offenders.Add((Get-RelativePath $file.FullName) + ' -> ' + ($first -join ' '))
        }
    }
    return New-Result -Name "php -l over $checked files in src/ and bin/" -Ok ($offenders.Count -eq 0) -Details $offenders
}

# -------------------------------------------------------------- Schema checks

function Get-SchemaTableVersions {
    param([Parameter(Mandatory)][string] $SchemaText)

    $map = [ordered]@{}
    foreach ($match in [regex]::Matches($SchemaText, '@table-version\s+(\w+)\s+(\d+\.\d+\.\d+)')) {
        $map[$match.Groups[1].Value] = $match.Groups[2].Value
    }
    return $map
}

function Get-SchemaTables {
    param([Parameter(Mandatory)][string] $SchemaText)

    $names = [System.Collections.Generic.List[string]]::new()
    # The opening paren is required so that prose such as "Every CREATE TABLE
    # block is preceded by ..." is not read as a table declaration.
    foreach ($match in [regex]::Matches($SchemaText, 'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(', 'IgnoreCase')) {
        $name = $match.Groups[1].Value
        if (-not $names.Contains($name)) { $names.Add($name) }
    }
    return $names
}

function Test-SchemaAnnotations {
    $schema = [System.IO.File]::ReadAllText($schemaPath)
    $tables = Get-SchemaTables -SchemaText $schema
    $annotated = Get-SchemaTableVersions -SchemaText $schema
    $offenders = [System.Collections.Generic.List[string]]::new()

    foreach ($table in $tables) {
        if (-not $annotated.Contains($table)) {
            $offenders.Add("table $table has no -- @table-version annotation")
        }
    }
    foreach ($table in $annotated.Keys) {
        if (-not $tables.Contains($table)) {
            $offenders.Add("annotation for $table has no CREATE TABLE in db/schema.sql")
        }
    }
    if ($schema -notmatch '@schema-version\s+\d+\.\d+\.\d+') {
        $offenders.Add('db/schema.sql has no -- @schema-version annotation')
    }

    return New-Result -Name ("schema annotations cover all $($tables.Count) tables") -Ok ($offenders.Count -eq 0) -Details $offenders
}

function Test-MigrationConventions {
    $offenders = [System.Collections.Generic.List[string]]::new()
    $migrationsDirectory = Join-Path $RepoRoot 'db\migrations'
    if (-not (Test-Path -LiteralPath $migrationsDirectory -PathType Container)) {
        return New-Result -Name 'db/migrations conventions' -Ok $false -Details @('db/migrations is missing')
    }

    $schema = [System.IO.File]::ReadAllText($schemaPath)
    $schemaVersions = Get-SchemaTableVersions -SchemaText $schema
    $numbers = [System.Collections.Generic.List[int]]::new()
    $maxByTable = [ordered]@{}

    foreach ($file in (Get-ChildItem -LiteralPath $migrationsDirectory -File -Filter '*.sql' | Sort-Object Name)) {
        $nameMatch = [regex]::Match($file.Name, '^(\d{3})_[a-z0-9_]+\.sql$')
        if (-not $nameMatch.Success) {
            $offenders.Add("$($file.Name) does not match NNN_snake_case.sql")
            continue
        }
        $numbers.Add([int]$nameMatch.Groups[1].Value)

        $raw = [System.IO.File]::ReadAllText($file.FullName)
        $annotations = @([regex]::Matches($raw, '@table-version\s+(\w+)\s+(\d+\.\d+\.\d+)'))
        if ($annotations.Count -eq 0) {
            $offenders.Add("$($file.Name) carries no -- @table-version annotation")
        }

        $touched = [System.Collections.Generic.List[string]]::new()
        foreach ($annotation in $annotations) {
            $table = $annotation.Groups[1].Value
            $version = $annotation.Groups[2].Value
            if (-not $touched.Contains($table)) { $touched.Add($table) }

            if (-not $schemaVersions.Contains($table)) {
                $offenders.Add("$($file.Name): table $table is not declared in db/schema.sql")
            }
            elseif ([version]$schemaVersions[$table] -lt [version]$version) {
                $offenders.Add("$($file.Name): schema.sql has $table at $($schemaVersions[$table]), migration expects $version")
            }

            if (-not $maxByTable.Contains($table)) { $maxByTable[$table] = $version }
            elseif ([version]$maxByTable[$table] -lt [version]$version) { $maxByTable[$table] = $version }
        }

        # a migration may only touch tables it annotates
        foreach ($match in [regex]::Matches($raw, '(?m)^\s*(?:ALTER\s+TABLE|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+`?(\w+)`?', 'IgnoreCase')) {
            $touchedTable = $match.Groups[1].Value
            if (-not $touched.Contains($touchedTable)) {
                $offenders.Add("$($file.Name): statement on $touchedTable without a matching @table-version")
            }
        }
    }

    $sorted = @($numbers | Sort-Object)
    if ($sorted.Count -gt 0) {
        for ($i = 1; $i -lt $sorted.Count; $i++) {
            if ($sorted[$i] -ne $sorted[$i - 1] + 1) {
                $offenders.Add("migration numbers are not contiguous between $($sorted[$i - 1]) and $($sorted[$i])")
            }
        }
        $nextFree = '{0:D3}' -f ($sorted[-1] + 1)
    }
    else {
        $nextFree = '001'
    }

    return New-Result -Name ("migration conventions (last " + (@($sorted)[-1]) + ", next free $nextFree)") -Ok ($offenders.Count -eq 0) -Details $offenders
}

function Test-SchemaVersionAgreement {
    $offenders = [System.Collections.Generic.List[string]]::new()
    $schema = [System.IO.File]::ReadAllText($schemaPath)
    $config = [System.IO.File]::ReadAllText((Join-Path $RepoRoot 'config.php'))

    $schemaVersion = [regex]::Match($schema, '@schema-version\s+(\d+\.\d+\.\d+)').Groups[1].Value
    $configVersion = [regex]::Match($config, "'version'\s*=>\s*'([^']+)'").Groups[1].Value

    if ($schemaVersion -eq '' -or $configVersion -eq '') {
        $offenders.Add("could not read both versions (schema='$schemaVersion', config='$configVersion')")
    }
    elseif ($schemaVersion -ne $configVersion) {
        $offenders.Add("db/schema.sql @schema-version $schemaVersion != config.php app.version $configVersion")
    }

    if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot 'CHANGELOG.md') -PathType Leaf)) {
        $offenders.Add('CHANGELOG.md is missing')
    }

    return New-Result -Name 'schema version agrees with config.php app.version' -Ok ($offenders.Count -eq 0) -Details $offenders
}

function Test-SecretHygiene {
    $offenders = [System.Collections.Generic.List[string]]::new()
    $gitignorePath = Join-Path $RepoRoot '.gitignore'
    if (-not (Test-Path -LiteralPath $gitignorePath -PathType Leaf)) {
        return New-Result -Name 'secret material stays out of git' -Ok $false -Details @('.gitignore is missing')
    }

    $gitignore = [System.IO.File]::ReadAllText($gitignorePath)
    foreach ($pattern in @('*.key', '.env')) {
        if ($gitignore -notmatch [regex]::Escape($pattern)) {
            $offenders.Add(".gitignore no longer covers $pattern")
        }
    }

    foreach ($secret in @('.httpauth', '.sql.php', '.url')) {
        $path = Join-Path $RepoRoot $secret
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            $matched = $gitignore -match [regex]::Escape($secret)
            if (-not $matched) {
                $offenders.Add("$secret exists but is not covered by .gitignore")
            }
        }
    }

    return New-Result -Name 'secret material stays out of git' -Ok ($offenders.Count -eq 0) -Details $offenders -Warning
}

# ------------------------------------------------------------------- run all

$checks = [System.Collections.Generic.List[object]]::new()
$checks.Add((Test-StrictTypes))
$checks.Add((Test-ForbiddenConstructs))
$checks.Add((Test-KnownDeviations))
$checks.Add((Test-FileHeaders))
$checks.Add((Test-ModuleCompanionDocs))
$checks.Add((Test-SuppressionUsage))
if (-not $SkipPhpLint) { $checks.Add((Test-PhpSyntax)) }
$checks.Add((Test-SchemaAnnotations))
$checks.Add((Test-MigrationConventions))
$checks.Add((Test-SchemaVersionAgreement))
$checks.Add((Test-SecretHygiene))

$padding = 0
foreach ($check in $checks) {
    if ($check.Name.Length -gt $padding) { $padding = $check.Name.Length }
}

$failedErrors = 0
$failedWarnings = 0

Write-Host 'RevoAp doctrine check'
Write-Host "repository: $RepoRoot"
Write-Host ''

foreach ($check in $checks) {
    if ($check.Ok) {
        Write-Host ('[PASS] ' + $check.Name.PadRight($padding)) -ForegroundColor Green
        continue
    }

    if ($check.Warning -and -not $Strict) {
        $failedWarnings++
        Write-Host ('[WARN] ' + $check.Name.PadRight($padding)) -ForegroundColor Yellow
    }
    else {
        $failedErrors++
        Write-Host ('[FAIL] ' + $check.Name.PadRight($padding)) -ForegroundColor Red
    }

    $shown = @($check.Details | Select-Object -First $MaxExamples)
    foreach ($detail in $shown) { Write-Host "         $detail" }
    if ($check.Details.Count -gt $shown.Count) {
        Write-Host "         ... and $($check.Details.Count - $shown.Count) more"
    }
}

Write-Host ''
Write-Host ("checks: $($checks.Count), errors: $failedErrors, warnings: $failedWarnings")

if ($failedErrors -gt 0) {
    Write-Host 'Result: FAIL - fix the errors above before committing or deploying.' -ForegroundColor Red
    exit 1
}

if ($failedWarnings -gt 0) {
    Write-Host 'Result: PASS with warnings - review them, they are not blocking.' -ForegroundColor Yellow
    exit 0
}

Write-Host 'Result: PASS' -ForegroundColor Green
exit 0
