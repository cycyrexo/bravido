# Lance tout ce qu'il faut pour tester BRAVIDO (serveur, worker, emulateur, application, back-office).
# Peut etre relance autant de fois que necessaire : ce qui tourne deja n'est pas relance.
# Usage : double-cliquer sur C:\wamp64\www\bravido\LANCER-BRAVIDO.bat

$ErrorActionPreference = 'Continue'

$Racine   = 'C:\wamp64\www\bravido'
$Backend  = Join-Path $Racine 'backend'
$Apk      = Join-Path $Racine 'mobile\build\app\outputs\flutter-apk\app-debug.apk'
$Sdk      = Join-Path $env:LOCALAPPDATA 'Android\sdk'
if (-not (Test-Path $Sdk)) { $Sdk = 'C:\Users\RESPON~1\AppData\Local\Android\sdk' }
$Adb      = Join-Path $Sdk 'platform-tools\adb.exe'
$Emulator = Join-Path $Sdk 'emulator\emulator.exe'
$Avd      = 'bravido_api35'
$Paquet   = 'ci.bravido.bravido'
$UrlApi   = 'http://localhost/bravido/backend/public/api/v1/config'
$UrlAdmin = 'http://localhost/bravido/backend/public/admin/login'

function Etape($n, $texte) { Write-Host ''; Write-Host "[$n/7] $texte" -ForegroundColor Cyan }
function Ok($texte)        { Write-Host "  OK  $texte" -ForegroundColor Green }
function Info($texte)      { Write-Host "  ... $texte" }
function Alerte($texte)    { Write-Host "  !!  $texte" -ForegroundColor Yellow }
function Echec($texte) {
    Write-Host ''
    Write-Host "  ECHEC : $texte" -ForegroundColor Red
    Write-Host ''
    exit 1
}

function ServeurRepond {
    try {
        $r = Invoke-WebRequest -UseBasicParsing -Uri $UrlApi -TimeoutSec 8
        return ($r.StatusCode -eq 200)
    } catch { return $false }
}

function Adb { & $Adb @args 2>&1 }

Write-Host '=============================================' -ForegroundColor Cyan
Write-Host '   BRAVIDO - lancement de l''environnement de test' -ForegroundColor Cyan
Write-Host '=============================================' -ForegroundColor Cyan

# ---------------------------------------------------------------- 1. WAMP
Etape 1 'Serveur WAMP (Apache + MySQL)'
if (ServeurRepond) {
    Ok 'Le serveur repond.'
} else {
    Info 'Le serveur ne repond pas. Tentative de demarrage des services...'
    foreach ($nom in 'wampmysqld64', 'wampapache64', 'Apache2.4') {
        $s = Get-Service -Name $nom -ErrorAction SilentlyContinue
        if ($s -and $s.Status -ne 'Running') {
            try { Start-Service -Name $nom -ErrorAction Stop; Info "Service $nom demarre." }
            catch { Info "Impossible de demarrer $nom sans droits administrateur." }
        }
    }
    if (-not (ServeurRepond)) {
        if (-not (Get-Process wampmanager -ErrorAction SilentlyContinue) -and (Test-Path 'C:\wamp64\wampmanager.exe')) {
            Info 'Ouverture de WAMP (Windows peut demander une autorisation : repondez Oui)...'
            try { Start-Process 'C:\wamp64\wampmanager.exe' } catch {}
        }
        Alerte 'Si besoin, lancez WAMP vous-meme (menu Demarrer > Wampserver64)'
        Alerte 'et attendez que son icone, en bas a droite pres de l''horloge, devienne VERTE.'
        Info 'J''attends le serveur (jusqu''a 5 minutes)...'
        $limite = (Get-Date).AddMinutes(5)
        while (-not (ServeurRepond)) {
            if ((Get-Date) -gt $limite) { Echec 'le serveur WAMP ne repond toujours pas. Lancez WAMP (icone verte) puis relancez LANCER-BRAVIDO.bat.' }
            Start-Sleep -Seconds 5
        }
    }
    Ok 'Le serveur repond.'
}

# ---------------------------------------------------------------- 2. Worker
Etape 2 'Traitement des alertes en arriere-plan (worker)'
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Backend 'demarrer-worker.ps1') | ForEach-Object { Info $_ }
$nb = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*queue:work*' }).Count
if ($nb -eq 1) { Ok 'Un worker actif.' } else { Alerte "Nombre de workers actifs : $nb (attendu : 1)." }

# ---------------------------------------------------------------- 3. Emulateur
Etape 3 "Telephone virtuel Android ($Avd)"
if (-not (Test-Path $Adb)) { Echec "outil adb introuvable ($Adb)." }
Adb start-server | Out-Null
$appareil = (Adb devices) -match '^emulator-\d+\s+device'
if ($appareil) {
    Ok 'Le telephone virtuel est deja allume.'
} else {
    Info 'Allumage du telephone virtuel (la fenetre peut mettre 1 a 2 minutes a apparaitre)...'
    $res = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{
        CommandLine = "`"$Emulator`" -avd $Avd -no-snapshot-save"
        CurrentDirectory = (Split-Path $Emulator)
    }
    if ($res.ReturnValue -ne 0) { Echec "impossible de lancer l'emulateur (code $($res.ReturnValue))." }
}

# ---------------------------------------------------------------- 4. Demarrage complet
Etape 4 'Attente du demarrage complet d''Android'
$limite = (Get-Date).AddMinutes(6)
while ($true) {
    $boot = (Adb shell getprop sys.boot_completed | Out-String).Trim()
    if ($boot -eq '1') { break }
    if ((Get-Date) -gt $limite) { Echec 'Android n''a pas fini de demarrer en 6 minutes. Fermez la fenetre de l''emulateur et relancez.' }
    Start-Sleep -Seconds 5
}
Adb shell input keyevent 82 | Out-Null   # deverrouille l'ecran
Ok 'Android est pret.'

# ---------------------------------------------------------------- 5. Application
Etape 5 'Installation de l''application BRAVIDO'
if (Test-Path $Apk) {
    $date = (Get-Item $Apk).LastWriteTime.ToString('dd/MM/yyyy HH:mm')
    Info "Installation de la derniere version (compilee le $date)..."
    $sortie = (Adb install -r $Apk | Out-String)
    if ($sortie -match 'Success') { Ok 'Application installee / mise a jour.' }
    else { Alerte "Installation non confirmee : $($sortie.Trim())" }
} else {
    Alerte "Aucun fichier APK trouve ($Apk) : on garde la version deja installee."
}
foreach ($perm in 'android.permission.ACCESS_FINE_LOCATION', 'android.permission.ACCESS_COARSE_LOCATION', 'android.permission.POST_NOTIFICATIONS') {
    Adb shell pm grant $Paquet $perm | Out-Null
}
Ok 'Autorisations localisation + notifications accordees.'

# ---------------------------------------------------------------- 6. Position + lancement
Etape 6 'Position GPS a Abidjan et ouverture de l''application'
Adb emu geo fix -4.0083 5.36 | Out-Null
Ok 'Position reglee sur Abidjan (Plateau).'
Adb shell monkey -p $Paquet -c android.intent.category.LAUNCHER 1 | Out-Null
Ok 'Application BRAVIDO ouverte dans le telephone virtuel.'

# ---------------------------------------------------------------- 7. Back-office
Etape 7 'Ouverture du back-office dans le navigateur'
Start-Process $UrlAdmin
Ok $UrlAdmin
Info 'Identifiant : admin@bravido.ci   Mot de passe : ChangezMoi!2026'

Write-Host ''
Write-Host '=============================================' -ForegroundColor Green
Write-Host '   Tout est pret. Suivez le guide COMMENT-TESTER.md' -ForegroundColor Green
Write-Host '   Code de verification SMS (mode test) : 123456' -ForegroundColor Green
Write-Host '=============================================' -ForegroundColor Green
exit 0
