$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$electron = Join-Path $root 'vendor\nativephp\desktop\resources\electron'
$build = Join-Path $root 'vendor\nativephp\desktop\resources\build'
$appBuild = Join-Path $build 'app'
$dist = Join-Path $root 'nativephp\electron\dist'

Set-Location $root

if (-not (Test-Path 'vendor\nativephp\desktop')) {
    throw 'nativephp/desktop belum terinstall. Jalankan composer install dulu.'
}

if (-not (Test-Path 'vendor\nativephp\php-bin\bin\win\x64\php-8.3.zip')) {
    throw 'nativephp/php-bin belum lengkap. Jalankan composer install sampai selesai.'
}

npm.cmd run build

if (Test-Path $appBuild) {
    Remove-Item -LiteralPath $appBuild -Recurse -Force
}

New-Item -ItemType Directory -Force -Path $appBuild | Out-Null

$excludeDirs = @(
    (Join-Path $root '.agents'),
    (Join-Path $root '.codex'),
    (Join-Path $root '.git'),
    (Join-Path $root '.github'),
    (Join-Path $root 'md'),
    (Join-Path $root 'nativephp'),
    (Join-Path $root 'node_modules'),
    (Join-Path $root 'public\storage'),
    (Join-Path $root 'storage\app\private\backups'),
    (Join-Path $root 'storage\app\public\uploads'),
    (Join-Path $root 'storage\logs'),
    (Join-Path $root 'tests'),
    (Join-Path $root 'vendor\nativephp\desktop\resources\build'),
    (Join-Path $root 'vendor\nativephp\desktop\resources\electron\node_modules')
)
$excludeFiles = @(
    '.env.*', '.npmrc', '.phpunit.result.cache', 'phpunit.xml', 'README.md', 'security_report.md',
    'database.sqlite', 'nativephp.sqlite', 'nativephp.sqlite-shm', 'nativephp.sqlite-wal'
)

robocopy $root $appBuild /MIR /NFL /NDL /NJH /NJS /XD $excludeDirs /XF $excludeFiles | Out-Host
if ($LASTEXITCODE -gt 7) {
    throw "robocopy gagal dengan exit code $LASTEXITCODE"
}
$global:LASTEXITCODE = 0

$bytes = [byte[]]::new(32)
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($bytes)
$rng.Dispose()
$appKey = 'base64:' + [Convert]::ToBase64String($bytes)

@"
APP_NAME=WarungPOS
APP_ENV=production
APP_KEY=$appKey
APP_DEBUG=false
APP_URL=http://127.0.0.1
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
"@ | Set-Content -Path (Join-Path $appBuild '.env') -Encoding ASCII

composer --working-dir=$appBuild install --no-dev --no-scripts --no-progress --optimize-autoloader

$env:APP_PATH = $root
$env:APP_URL = 'http://127.0.0.1'
$env:NATIVEPHP_BUILDING = 'true'
$env:NATIVEPHP_PHP_BINARY_VERSION = '8.3'
$env:NATIVEPHP_PHP_BINARY_PATH = Join-Path $root 'vendor\nativephp\php-bin\bin\'
$env:NATIVEPHP_ELECTRON_PATH = $electron
$env:NATIVEPHP_BUILD_PATH = $build
$env:NATIVEPHP_APP_NAME = 'WarungPOS'
$env:NATIVEPHP_APP_ID = 'com.blitzzberry.warungpos'
$env:NATIVEPHP_APP_VERSION = '1.0.0'
$env:NATIVEPHP_APP_COPYRIGHT = 'Copyright 2026 Blitzz Berry'
$env:NATIVEPHP_APP_FILENAME = 'warungpos'
$env:NATIVEPHP_APP_AUTHOR = 'Blitzz Berry'
$env:NATIVEPHP_UPDATER_CONFIG = '{}'
$env:NATIVEPHP_NSIS_DELETE_APP_DATA = 'false'
$env:NATIVEPHP_DEEPLINK_SCHEME = 'warungpos'
$env:NATIVEPHP_UPDATER_ENABLED = 'false'

$hasAzureSigning = $env:AZURE_TENANT_ID -and $env:AZURE_CLIENT_ID -and $env:AZURE_CLIENT_SECRET -and $env:NATIVEPHP_AZURE_ENDPOINT -and $env:NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME -and $env:NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME
$hasCertificateSigning = $env:CSC_LINK -or $env:WIN_CSC_LINK

if (-not ($hasAzureSigning -or $hasCertificateSigning)) {
    $env:NATIVEPHP_SKIP_WIN_SIGNING = 'true'
    $env:CSC_IDENTITY_AUTO_DISCOVERY = 'false'
    Write-Warning 'Build unsigned: tambah certificate/Azure Trusted Signing untuk distribusi publik production.'
}

Set-Location $electron
npm.cmd run build
node .\node_modules\electron-builder\cli.js -p never --win --config (Join-Path $root 'scripts\electron-builder.production.mjs') --x64

Set-Location $root
Get-Item (Join-Path $dist 'WarungPOS-1.0.0-setup.exe'), (Join-Path $dist 'win-unpacked\warungpos.exe')
