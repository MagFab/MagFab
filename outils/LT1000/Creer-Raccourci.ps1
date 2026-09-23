# Cree sur le bureau un raccourci "Unlock LT1000" qui lance Unlock-LT1000.ps1 (a executer une seule fois).
$Script   = Join-Path $PSScriptRoot 'Unlock-LT1000.ps1'
$Shortcut = Join-Path ([Environment]::GetFolderPath('Desktop')) 'Unlock LT1000.lnk'

$Shell = New-Object -ComObject WScript.Shell
$Lnk = $Shell.CreateShortcut($Shortcut)
$Lnk.TargetPath       = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$Lnk.Arguments        = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$Script`""
$Lnk.WorkingDirectory = $PSScriptRoot
$Lnk.IconLocation     = "$env:SystemRoot\System32\imageres.dll,54"   # icone cadenas ouvert
$Lnk.Description      = 'Deverrouiller le magasin du lecteur de sauvegarde LT1000'
$Lnk.Save()

Write-Host "Raccourci cree : $Shortcut"
