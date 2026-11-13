# Surveillance BRAVIDO : verifie /api/v1/sante et relance le worker si la file d'attente est bloquee.
#
# - Sante OK                       : ne fait rien (silencieux).
# - queue=false (jobs > 120 s)     : lance demarrer-worker.ps1 (arrete puis relance l'unique worker).
# - Aucun worker php (queue:work)  : relance aussi le worker.
# - API injoignable / db=false     : journalise, ne touche a rien (WAMP/MySQL a verifier a la main).
# - scheduler=false                : journalise (la tache planifiee " schedule:run " ne tourne plus).
# Journal : backend\storage\logs\surveillance.log (une ligne par evenement, rien si tout va bien).
#
# ENREGISTREMENT (a faire UNE fois par l'exploitant, ce script ne s'enregistre pas tout seul) :
#   Planificateur de taches Windows > Creer une tache > Declencheur : toutes les 1 minute, indefiniment
#   Action : Demarrer un programme
#     Programme : powershell.exe
#     Arguments : -NoProfile -ExecutionPolicy Bypass -File C:\wamp64\www\bravido\outils\surveillance.ps1
#   Cocher " Executer meme si l'utilisateur n'est pas connecte ".
# Ligne de commande equivalente (a lancer dans un PowerShell administrateur) :
#   schtasks /Create /TN "BRAVIDO surveillance" /SC MINUTE /MO 1 /RL HIGHEST /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\wamp64\www\bravido\outils\surveillance.ps1"
# Idem pour le planificateur Laravel (indispensable au battement de coeur) :
#   schtasks /Create /TN "BRAVIDO schedule" /SC MINUTE /MO 1 /TR "C:\wamp64\bin\php\php8.5.0\php.exe C:\wamp64\www\bravido\backend\artisan schedule:run"
#
# Test manuel : powershell -ExecutionPolicy Bypass -File outils\surveillance.ps1 -Verbose

[CmdletBinding()]
param(
    [string]$UrlSante = 'http://localhost/bravido/backend/public/api/v1/sante',
    [int]$DelaiRelanceMin = 3   # n'enchaine pas les relances : au moins 3 min entre deux redemarrages
)

$ErrorActionPreference = 'Continue'
$Racine  = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Backend = Join-Path $Racine 'backend'
$Journal = Join-Path $Backend 'storage\logs\surveillance.log'
$Marque  = Join-Path $Backend 'storage\logs\surveillance.derniere-relance'

function Noter([string]$texte) {
    $ligne = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $texte
    Add-Content -Path $Journal -Value $ligne -Encoding UTF8
    Write-Verbose $ligne
}

function Relancer([string]$raison) {
    if (Test-Path $Marque) {
        $age = (New-TimeSpan -Start (Get-Item $Marque).LastWriteTime -End (Get-Date)).TotalMinutes
        if ($age -lt $DelaiRelanceMin) { Noter "Relance ignoree (derniere il y a $([int]$age) min) : $raison"; return }
    }
    Noter "Relance du worker : $raison"
    Set-Content -Path $Marque -Value (Get-Date -Format o)
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Backend 'demarrer-worker.ps1') | ForEach-Object { Noter "  $_" }
}

# 1) Etat de sante
$sante = $null
try {
    $sante = Invoke-RestMethod -Uri $UrlSante -TimeoutSec 15 -ErrorAction Stop
} catch {
    # 503 = base de donnees injoignable : le corps JSON est quand meme lisible
    Noter "API injoignable ou degradee : $($_.Exception.Message)"
}

# 2) Worker present ?
$workers = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like '*queue:work*' })

if ($sante) {
    if (-not $sante.db)        { Noter 'ALERTE : base de donnees injoignable (verifier MySQL/WAMP).' }
    if (-not $sante.scheduler) { Noter 'ALERTE : planificateur arrete (tache " schedule:run " absente ou en echec).' }
    if (-not $sante.queue)     { Relancer 'file d''attente bloquee (travaux en attente depuis plus de 120 s)'; exit 0 }
}

if ($workers.Count -eq 0 -and $sante -and $sante.db) {
    Relancer 'aucun worker queue:work en cours'
}
exit 0
