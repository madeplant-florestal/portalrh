param(
    [string]$OutputDir = "dist",
    [string]$PackageName = "rhmadeplant",
    [switch]$SkipBuildCss
)

$ErrorActionPreference = "Stop"
$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $repoRoot

Write-Host "== Empacotamento RH Madeplant ==" -ForegroundColor Cyan
Write-Host "Root:" $repoRoot

# 0) Gerar metadados de versão a partir do último commit
Write-Host "Gerando metadados de versão (git hash/data)..." -ForegroundColor Yellow
$php = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $php) {
  throw "php não encontrado para gerar app/config/build.php."
}
$metaProc = Start-Process -FilePath $php.Source -ArgumentList "scripts/generate_build_info.php" -NoNewWindow -Wait -PassThru
if ($metaProc.ExitCode -ne 0) {
  throw "Falha ao gerar metadados de build (ExitCode=$($metaProc.ExitCode))."
}

# 1) Build CSS do Tailwind (produção)
if (-not $SkipBuildCss) {
  Write-Host "Compilando Tailwind (npm run build:css)..." -ForegroundColor Yellow
  $npm = Get-Command npm -ErrorAction SilentlyContinue
  if ($null -eq $npm) {
    Write-Warning "npm não encontrado. Pulando build do CSS. Certifique-se de que public/assets/tailwind.css existe."
  } else {
    $proc = Start-Process -FilePath $npm.Source -ArgumentList "run", "build:css" -NoNewWindow -Wait -PassThru
    if ($proc.ExitCode -ne 0) {
      throw "Falha ao compilar Tailwind (ExitCode=$($proc.ExitCode))."
    }
  }
}

# Verificar se CSS existe
$cssPath = Join-Path $repoRoot "public/assets/tailwind.css"
if (-not (Test-Path $cssPath)) {
  throw "Arquivo CSS não encontrado: $cssPath"
}

# 2) Preparar diretórios
$destRoot = Join-Path $repoRoot $OutputDir
$destApp = Join-Path $destRoot $PackageName
if (Test-Path $destApp) { Remove-Item $destApp -Recurse -Force }
New-Item -ItemType Directory -Path $destApp | Out-Null

# 3) Copiar estrutura mínima de runtime
Write-Host "Copiando estrutura de runtime..." -ForegroundColor Yellow
$runtimeItems = @(
  "app",
  "public",
  "database",
  "scripts",
  "storage",
  ".htaccess",
  "index.php"
)
foreach ($item in $runtimeItems) {
  $source = Join-Path $repoRoot $item
  if (Test-Path $source) {
    Copy-Item -Path $source -Destination $destApp -Recurse -Force
  }
}

# 4) Limpeza de artefatos não necessários em produção
$removeItems = @(
  "tests",
  "test-results",
  "playwright-report",
  "node_modules",
  "assets",
  "router.php",
  "package.json",
  "package-lock.json",
  "tailwind.config.js",
  "postcss.config.js",
  "playwright.config.js",
  "deploy.ps1"
)
foreach ($item in $removeItems) {
  $target = Join-Path $destApp $item
  if (Test-Path $target) {
    Remove-Item $target -Recurse -Force
  }
}

# 5) Compactar em ZIP
$zipPath = Join-Path $destRoot ("$PackageName.zip")
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Write-Host "Gerando ZIP: $zipPath" -ForegroundColor Yellow
Compress-Archive -Path (Join-Path $destApp "*") -DestinationPath $zipPath -Force

Write-Host "Pacote pronto: $zipPath" -ForegroundColor Green
Write-Host "Após extrair no servidor, acesse /install.php para concluir a instalação web guiada." -ForegroundColor Green
