<#
.SYNOPSIS
    Fills in the secrets that Dayflow needs in order to start.

.DESCRIPTION
    Generates cryptographically strong random values for the signing and
    encryption keys and the database passwords, and writes them into .env.

    Values already present are left alone unless -Force is given, so running
    this a second time is safe and will not invalidate everyone's sessions.

.PARAMETER AdminEmail
    Email address for the first administrator account.

.PARAMETER AdminPassword
    Password for that account. You are prompted if this is omitted.

.PARAMETER Force
    Replace secrets that already have a value. This signs out every user and
    makes existing password-reset links unusable.

.EXAMPLE
    powershell -File scripts\generate-secrets.ps1

.EXAMPLE
    powershell -File scripts\generate-secrets.ps1 -AdminEmail you@company.com
#>

[CmdletBinding()]
param(
    [string] $AdminEmail,
    [string] $AdminPassword,
    [switch] $Force
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $root '.env'
$examplePath = Join-Path $root '.env.example'

Write-Host ''
Write-Host '  Dayflow - configuration setup' -ForegroundColor Cyan
Write-Host '  -----------------------------' -ForegroundColor Cyan
Write-Host ''

if (-not (Test-Path $envPath)) {
    if (-not (Test-Path $examplePath)) {
        throw '.env.example is missing. Run this from inside the project folder.'
    }

    Copy-Item $examplePath $envPath
    Write-Host '  Created .env from .env.example' -ForegroundColor Green
}

# --- Random value generation -------------------------------------------------
# RandomNumberGenerator is the operating system's cryptographic source. Get-Random
# is deliberately not used: it is seeded pseudo-randomness and unfit for a key.
function New-Secret {
    param([int] $Length = 48)

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
    $bytes = New-Object 'System.Byte[]' $Length
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()

    try {
        $rng.GetBytes($bytes)
    } finally {
        $rng.Dispose()
    }

    $builder = New-Object System.Text.StringBuilder
    foreach ($byte in $bytes) {
        [void] $builder.Append($alphabet[$byte % $alphabet.Length])
    }

    return $builder.ToString()
}

# --- .env editing ------------------------------------------------------------
$lines = Get-Content $envPath

function Get-EnvValue {
    param([string] $Key)

    foreach ($line in $script:lines) {
        if ($line -match "^$([regex]::Escape($Key))=(.*)$") {
            return $Matches[1].Trim()
        }
    }

    return ''
}

function Set-EnvValue {
    param([string] $Key, [string] $Value)

    $found = $false
    $updated = @()

    foreach ($line in $script:lines) {
        if ($line -match "^$([regex]::Escape($Key))=") {
            $updated += "$Key=$Value"
            $found = $true
        } else {
            $updated += $line
        }
    }

    if (-not $found) {
        $updated += "$Key=$Value"
    }

    $script:lines = $updated
}

# --- Secrets -----------------------------------------------------------------
$secrets = @{
    'POSTGRES_PASSWORD'           = 40
    'DAYFLOW_DB_SERVICE_PASSWORD' = 40
    'JWT_SECRET'                  = 64
    'INTERNAL_SIGNING_KEY'        = 64
    'ENCRYPTION_KEY'              = 64
}

$generated = 0
$kept = 0

foreach ($key in $secrets.Keys) {
    $current = Get-EnvValue -Key $key

    if ($current -ne '' -and -not $Force) {
        $kept++
        continue
    }

    Set-EnvValue -Key $key -Value (New-Secret -Length $secrets[$key])
    $generated++
}

if ($generated -gt 0) { Write-Host "  Generated $generated secret(s)" -ForegroundColor Green }
if ($kept -gt 0)      { Write-Host "  Kept $kept existing secret(s). Use -Force to replace them." -ForegroundColor DarkGray }

# --- Administrator account ---------------------------------------------------
$currentEmail = Get-EnvValue -Key 'SEED_ADMIN_EMAIL'
$currentPassword = Get-EnvValue -Key 'SEED_ADMIN_PASSWORD'

if ($AdminEmail) {
    Set-EnvValue -Key 'SEED_ADMIN_EMAIL' -Value $AdminEmail
} elseif ($currentEmail -eq '' -or $currentEmail -eq 'admin@example.com') {
    $entered = Read-Host '  Email for the first administrator'
    if ($entered) { Set-EnvValue -Key 'SEED_ADMIN_EMAIL' -Value $entered }
}

if ($AdminPassword) {
    Set-EnvValue -Key 'SEED_ADMIN_PASSWORD' -Value $AdminPassword
} elseif ($currentPassword -eq '') {
    $secure = Read-Host '  Password for that account' -AsSecureString
    $plain = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto(
        [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    )

    if (-not $plain) {
        throw 'An administrator password is required.'
    }

    Set-EnvValue -Key 'SEED_ADMIN_PASSWORD' -Value $plain
}

# --- Write it back -----------------------------------------------------------
# UTF-8 without a byte order mark: a BOM would end up inside the first variable
# name when Docker Compose reads the file.
$content = ($lines -join "`n") + "`n"
[System.IO.File]::WriteAllText($envPath, $content, (New-Object System.Text.UTF8Encoding $false))

Write-Host ''
Write-Host '  .env is ready.' -ForegroundColor Green
Write-Host ''
Write-Host '  Next:' -ForegroundColor Cyan
Write-Host '    docker compose up'
Write-Host ''
Write-Host '  Then open http://localhost:8000'
Write-Host ''
Write-Host '  Keep .env out of version control. It is already listed in .gitignore.' -ForegroundColor DarkGray
Write-Host ''
