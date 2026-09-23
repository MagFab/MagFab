# Optionnel : enregistre le mot de passe du lecteur chiffre (DPAPI) dans LT1000.cred,
# a la place de le laisser en clair dans Unlock-LT1000.ps1 (mettre alors $Password = '').
# A executer avec le compte Windows qui utilisera le raccourci, sur le meme PC.
Get-Credential -Message 'Compte du lecteur LT1000' |
    Export-Clixml (Join-Path $PSScriptRoot 'LT1000.cred')
Write-Host 'Mot de passe enregistre dans LT1000.cred'
