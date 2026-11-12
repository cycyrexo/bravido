# Demarre (ou redemarre) l'unique worker de file d'attente BRAVIDO.
# Usage : powershell -ExecutionPolicy Bypass -File C:\wamp64\www\bravido\backend\demarrer-worker.ps1
$ErrorActionPreference = 'Stop'

$Php     = 'C:\wamp64\bin\php\php8.5.0\php.exe'
$Backend = Split-Path -Parent $MyInvocation.MyCommand.Path
$Log     = 'storage\logs\queue-worker.log'

# 1. Arreter les workers existants (php.exe artisan queue:work) et leur cmd.exe parent.
$workers = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -like '*queue:work*' })
foreach ($w in $workers) {
    Write-Host "Arret du worker PID $($w.ProcessId)"
    try { Stop-Process -Id $w.ProcessId -Force -ErrorAction Stop } catch {}
    $parent = Get-CimInstance Win32_Process -Filter "ProcessId=$($w.ParentProcessId)" -ErrorAction SilentlyContinue
    if ($parent -and $parent.Name -eq 'cmd.exe' -and $parent.CommandLine -like '*queue:work*') {
        try { Stop-Process -Id $parent.ProcessId -Force -ErrorAction Stop } catch {}
    }
}

# 2. Lancer un nouveau worker detache (survit a la fermeture de la console).
$CREATE_NEW_PROCESS_GROUP = 0x00000200
$CREATE_NO_WINDOW         = 0x08000000
$startup = New-CimInstance -ClassName Win32_ProcessStartup -ClientOnly -Property @{
    CreateFlags = [uint32]($CREATE_NEW_PROCESS_GROUP -bor $CREATE_NO_WINDOW)
}
$cmd = "cmd.exe /c $Php artisan queue:work --queue=alertes,default --tries=3 >> $Log 2>&1"
$res = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{
    CommandLine = $cmd; CurrentDirectory = $Backend; ProcessStartupInformation = $startup
}
if ($res.ReturnValue -ne 0) { throw "Echec du lancement (code $($res.ReturnValue))" }

# 3. Verifier.
Start-Sleep -Seconds 3
$now = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -like '*queue:work*' })
Write-Host "Workers actifs : $($now.Count) (PID : $(($now | ForEach-Object ProcessId) -join ', '))"
