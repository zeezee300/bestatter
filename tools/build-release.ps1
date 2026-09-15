[CmdletBinding()]
param(
	[string]$OutputDirectory = '',
	[switch]$SkipBuild
)

$ErrorActionPreference = 'Stop'
$appRoot = Split-Path -Parent $PSScriptRoot
if ($OutputDirectory -eq '') {
	$OutputDirectory = $appRoot
}
$OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)

$infoPath = Join-Path $appRoot 'appinfo/info.xml'
[xml]$appInfo = Get-Content -LiteralPath $infoPath -Raw
$version = [string]$appInfo.info.version
if ($version -notmatch '^\d+\.\d+\.\d+$') {
	throw "Ungültige App-Version in appinfo/info.xml: $version"
}

if (-not $SkipBuild) {
	$pnpm = Get-Command pnpm -ErrorAction SilentlyContinue
	if ($null -eq $pnpm) {
		throw 'pnpm wurde nicht gefunden. Frontend zuerst bauen oder -SkipBuild bewusst verwenden.'
	}
	Push-Location $appRoot
	try {
		& $pnpm.Source run build
		if ($LASTEXITCODE -ne 0) { throw 'Der Frontend-Build ist fehlgeschlagen.' }
	} finally {
		Pop-Location
	}
}

$requiredFiles = @('appinfo/info.xml', 'appinfo/routes.php', 'js/main.js', 'templates/main.php', 'vendor/autoload.php')
foreach ($relativePath in $requiredFiles) {
	if (-not (Test-Path -LiteralPath (Join-Path $appRoot $relativePath))) {
		throw "Erforderliche Laufzeitdatei fehlt: $relativePath"
	}
}

$runtimeDirectories = @('appinfo', 'css', 'img', 'js', 'lib', 'resources', 'templates', 'vendor')
$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('bestatter-release-' + [guid]::NewGuid().ToString('N'))
$stagingApp = Join-Path $stagingRoot 'bestatter'
New-Item -ItemType Directory -Path $stagingApp -Force | Out-Null

try {
	foreach ($directory in $runtimeDirectories) {
		Copy-Item -LiteralPath (Join-Path $appRoot $directory) -Destination $stagingApp -Recurse -Force
	}
	Get-ChildItem -LiteralPath $stagingApp -Recurse -File -Filter '*.md' | Remove-Item -Force
	Get-ChildItem -LiteralPath $stagingApp -Recurse -Directory |
		Sort-Object FullName -Descending |
		Where-Object { (Get-ChildItem -LiteralPath $_.FullName -Force).Count -eq 0 } |
		Remove-Item -Force

	New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
	$zipPath = Join-Path $OutputDirectory ("bestatter-$version.zip")
	if (Test-Path -LiteralPath $zipPath) {
		Remove-Item -LiteralPath $zipPath -Force
	}
	Compress-Archive -Path (Join-Path $stagingApp '*') -DestinationPath $zipPath -CompressionLevel Optimal

	Add-Type -AssemblyName System.IO.Compression.FileSystem
	$archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
	try {
		$forbidden = $archive.Entries | Where-Object {
			$_.FullName -match '^(docs|tests|tools|output|tmp)/' -or $_.FullName -match '(^|/)(OP-LISTE|TICKET-|.*-QA\.)' -or $_.FullName -match '\.md$'
		}
	} finally {
		$archive.Dispose()
	}
	if ($forbidden) {
		Remove-Item -LiteralPath $zipPath -Force
		throw ('Nicht erlaubte Entwicklungsdateien im Release: ' + (($forbidden.FullName) -join ', '))
	}

	$hash = Get-FileHash -LiteralPath $zipPath -Algorithm SHA256
	[pscustomobject]@{ Path = $zipPath; Version = $version; Bytes = (Get-Item -LiteralPath $zipPath).Length; SHA256 = $hash.Hash }
} finally {
	if (Test-Path -LiteralPath $stagingRoot) {
		Remove-Item -LiteralPath $stagingRoot -Recurse -Force
	}
}
