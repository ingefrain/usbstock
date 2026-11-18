param([switch]$VerifyOnly)
$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$root = (Resolve-Path (Join-Path $project 'storage/app/private/development-import/october-2026')).Path
$expected = [IO.Path]::GetFullPath((Join-Path $project 'storage/app/private/development-import/october-2026'))
if ($root -ne $expected) { throw 'Unexpected evidence directory.' }
$broadSids = @('S-1-1-0','S-1-5-11','S-1-5-32-545')
function BroadGrants([string]$path) {
    @((Get-Acl -LiteralPath $path).Access | Where-Object {
        $_.AccessControlType -eq 'Allow' -and $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -in $broadSids
    })
}
$directories = @($root) + @(Get-ChildItem -LiteralPath $root -Directory | Where-Object {$_.Name -match '^backup-\d{8}_\d{6}$'} | ForEach-Object {$_.FullName})
foreach ($directory in $directories) {
    if ((Get-Item -LiteralPath $directory).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked evidence directories are not allowed.' }
}
if (-not $VerifyOnly) {
    foreach ($directory in $directories) {
        if (@(BroadGrants $directory).Count -eq 0) { continue }
        # Change only DACL grants, preserving owner, audit rules and named technical/user access.
        & icacls.exe $directory /inheritance:d | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Windows denied WRITE_DAC. Run this script from an elevated PowerShell; no ownership or credential changes are needed.' }
        & icacls.exe $directory /remove:g '*S-1-1-0' '*S-1-5-11' '*S-1-5-32-545' | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Windows could not restrict evidence access.' }
    }
}
$paths = @($root) + @(Get-ChildItem -LiteralPath $root -Recurse -Force | ForEach-Object {$_.FullName})
$exposed = @($paths | Where-Object {@(BroadGrants $_).Count -gt 0})
$report = [ordered]@{checked_at_utc=[DateTime]::UtcNow.ToString('o');scope='private/development-import/october-2026';verified=($exposed.Count -eq 0);paths_checked=$paths.Count;paths_with_broad_local_grants=$exposed.Count;verify_only=[bool]$VerifyOnly}
[IO.File]::WriteAllText((Join-Path $root 'permissions-verification.json'), ($report | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
$report | ConvertTo-Json
if ($exposed.Count -gt 0) { throw 'Broad local ACL grants remain. The application private directory is not served by HTTP, but Windows permission hardening is pending.' }
