# ============================================================
# Variante a lancer manuellement (clic droit > Executer avec
# PowerShell, ou dans une console EMS) pour tester/consulter les
# delegations de boites mails Exchange sans passer par la page
# web. Demande le login et le mot de passe via Get-Credential
# (fenetre ou prompt securise, le mot de passe ne s'affiche pas).
#
# Recupere, pour chaque boite mail utilisateur : nom, prenom,
# login, email, et les delegations (Acces complet / Envoyer en
# tant que / Envoyer de la part de). Affiche le resultat dans la
# console et l'exporte aussi en CSV a cote du script.
#
# A adapter a votre configuration Exchange 2013 on-premise :
#   - $ConnectionUri : http vs https selon la conf IIS du vdir PowerShell
#   - $AuthMethod    : Kerberos (domaine, nom d'hote) / Negotiate (IP,
#                      NTLM) / Basic (necessite HTTPS)
# ============================================================

$ErrorActionPreference = 'Stop'
$WarningPreference = 'SilentlyContinue'

$ExchangeServer = '10.4.17.234'
$ConnectionUri = "http://$ExchangeServer/PowerShell/"
$AuthMethod = 'Negotiate'

$cred = Get-Credential -Message "Identifiants Exchange (ex: domaine\administrateur)"
if (-not $cred) {
    Write-Host "Annule : aucun identifiant saisi." -ForegroundColor Yellow
    exit 1
}

$session = $null
try {
    Write-Host "Connexion a $ExchangeServer..." -ForegroundColor Cyan
    $session = New-PSSession -ConnectionUri $ConnectionUri -Authentication $AuthMethod -Credential $cred -ErrorAction Stop
    Import-PSSession -Session $session -DisableNameChecking -AllowClobber -ErrorAction Stop | Out-Null

    Write-Host "Recuperation des boites mails..." -ForegroundColor Cyan

    # Uniquement les boites mails "utilisateur" (exclut salles,
    # equipements, boites partagees, boites de decouverte, etc.)
    $mailboxes = Get-Mailbox -ResultSize Unlimited -Filter { RecipientTypeDetails -eq 'UserMailbox' }

    $resultats = foreach ($mbx in $mailboxes) {

        $user = $null
        try { $user = Get-User -Identity $mbx.Identity -ErrorAction Stop } catch {}

        # --- Acces complet (Full Access) ---
        $accesComplet = @()
        try {
            $accesComplet = Get-MailboxPermission -Identity $mbx.Identity -ErrorAction Stop |
                Where-Object {
                    -not $_.IsInherited -and
                    $_.Deny -eq $false -and
                    $_.AccessRights -contains 'FullAccess' -and
                    $_.User.ToString() -notlike 'NT AUTHORITY\SELF'
                } |
                ForEach-Object { $_.User.ToString() }
        } catch {}

        # --- Envoyer en tant que (Send As) ---
        $envoyerEnTantQue = @()
        try {
            $envoyerEnTantQue = Get-RecipientPermission -Identity $mbx.Identity -ErrorAction Stop |
                Where-Object {
                    $_.AccessControlType -eq 'Allow' -and
                    $_.Trustee.ToString() -notlike 'NT AUTHORITY\SELF'
                } |
                ForEach-Object { $_.Trustee.ToString() }
        } catch {}

        # --- Envoyer de la part de (Send on Behalf) ---
        $envoyerDeLaPartDe = @()
        if ($mbx.GrantSendOnBehalfTo) {
            foreach ($entry in $mbx.GrantSendOnBehalfTo) {
                try {
                    $envoyerDeLaPartDe += (Get-Recipient -Identity $entry.ToString() -ErrorAction Stop).DisplayName
                } catch {
                    $envoyerDeLaPartDe += $entry.ToString()
                }
            }
        }

        [PSCustomObject]@{
            Nom               = if ($user) { $user.LastName } else { '' }
            Prenom            = if ($user) { $user.FirstName } else { '' }
            Login             = if ($user) { $user.SamAccountName } else { $mbx.SamAccountName }
            Email             = $mbx.PrimarySmtpAddress.ToString()
            AccesComplet      = ($accesComplet -join '; ')
            EnvoyerEnTantQue  = ($envoyerEnTantQue -join '; ')
            EnvoyerDeLaPartDe = ($envoyerDeLaPartDe -join '; ')
        }
    }

    $resultats | Sort-Object Nom, Prenom | Format-Table -AutoSize -Wrap

    $csvPath = Join-Path $PSScriptRoot ("delegations_exchange_" + (Get-Date -Format 'yyyyMMdd_HHmmss') + ".csv")
    $resultats | Sort-Object Nom, Prenom | Export-Csv -Path $csvPath -Delimiter ';' -NoTypeInformation -Encoding UTF8

    Write-Host ""
    Write-Host "$($resultats.Count) boite(s) mail traitee(s)." -ForegroundColor Green
    Write-Host "Export CSV : $csvPath" -ForegroundColor Green
}
catch {
    Write-Host "Erreur : $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
finally {
    if ($session) { Remove-PSSession -Session $session -ErrorAction SilentlyContinue }
}
