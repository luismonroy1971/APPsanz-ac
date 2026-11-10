# Carga en GitHub los secrets y variables que usa el workflow de despliegue a Hostinger.
# Ejecutar desde la raíz del proyecto:  powershell -ExecutionPolicy Bypass -File deploy\hostinger\configurar-github.ps1
# No contiene contraseñas: la clave de la base de datos se pide de forma oculta y la llave SSH se lee del archivo local.

param(
    [string]$Repo = 'luismonroy1971/APPsanz-ac',
    [string]$Domain = 'appsanzac.alldesing.es',
    [string]$SshHost = '31.97.80.236',
    [string]$SshPort = '65002',
    [string]$SshUser = 'u809653251',
    [string]$DbName = 'u809653251_APPsanzsac',
    [string]$DbUser = 'u809653251_APPsanzsac',
    [string]$KeyFile = "$HOME\.ssh\appsanzac_deploy",
    [string]$SeedDemo = 'true'
)

# Compatible con Windows PowerShell 5.1: los mensajes de gh por stderr no deben detener el script,
# por eso se revisa $LASTEXITCODE en lugar de usar ErrorActionPreference = 'Stop'.
$ErrorActionPreference = 'Continue'

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    Write-Host 'Falta GitHub CLI. Instálela con:  winget install GitHub.cli' -ForegroundColor Red
    exit 1
}

# 1. Sesión de GitHub CLI
cmd /c "gh auth status >nul 2>&1"
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Inicie sesión en GitHub (se abrirá el navegador)...' -ForegroundColor Yellow
    gh auth login -h github.com -p https -w
    if ($LASTEXITCODE -ne 0) { exit 1 }
}

if (-not (Test-Path $KeyFile)) { Write-Host "No se encontró la llave privada: $KeyFile" -ForegroundColor Red; exit 1 }

function Set-Secret([string]$Name, [string]$Value) {
    # --body evita el salto de línea que PowerShell 5.1 agrega al usar tuberías
    gh secret set $Name --repo $Repo --body $Value
    if ($LASTEXITCODE -ne 0) { Write-Host "No se pudo guardar el secret $Name" -ForegroundColor Red; exit 1 }
    Write-Host "  secret   $Name" -ForegroundColor Green
}
function Set-SecretFromFile([string]$Name, [string]$Path) {
    cmd /c "gh secret set $Name --repo $Repo < `"$Path`""
    if ($LASTEXITCODE -ne 0) { Write-Host "No se pudo guardar el secret $Name" -ForegroundColor Red; exit 1 }
    Write-Host "  secret   $Name (desde archivo)" -ForegroundColor Green
}
function Set-Var([string]$Name, [string]$Value) {
    gh variable set $Name --repo $Repo --body $Value
    if ($LASTEXITCODE -ne 0) { Write-Host "No se pudo guardar la variable $Name" -ForegroundColor Red; exit 1 }
    Write-Host "  variable $Name = $Value" -ForegroundColor Green
}

# 2. Contraseña de la base de datos (entrada oculta)
$secure = Read-Host "Contraseña de la base de datos $DbName (no se mostrará)" -AsSecureString
$dbPass = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
if ([string]::IsNullOrWhiteSpace($dbPass)) { Write-Host 'La contraseña no puede estar vacía.' -ForegroundColor Red; exit 1 }
if ($dbPass.Contains('"')) { Write-Host 'La contraseña no debe contener comillas dobles (").' -ForegroundColor Red; exit 1 }

Write-Host "Configurando $Repo ..." -ForegroundColor Cyan
Set-Secret 'SSH_HOST' $SshHost
Set-Secret 'SSH_PORT' $SshPort
Set-Secret 'SSH_USER' $SshUser
Set-SecretFromFile 'SSH_PRIVATE_KEY' $KeyFile
Set-Secret 'DB_DATABASE' $DbName
Set-Secret 'DB_USERNAME' $DbUser
Set-Secret 'DB_PASSWORD' $dbPass
$dbPass = $null

Set-Var 'APP_DOMAIN' $Domain
Set-Var 'SEED_DEMO' $SeedDemo

Write-Host "`nListo. Secrets configurados:" -ForegroundColor Cyan
gh secret list --repo $Repo
gh variable list --repo $Repo
