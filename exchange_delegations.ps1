# ============================================================
# Recupere, pour chaque boite mail utilisateur Exchange, les
# infos de base (nom, prenom, login, email) et les delegations
# (Acces complet / Envoyer en tant que / Envoyer de la part de).
#
# Les identifiants sont lus sur l'entree standard, au format
# JSON {"login": "...", "password": "..."} : ils ne transitent
# jamais par les arguments du processus ni par une variable
# d'environnement, pour ne pas apparaitre dans la liste des
# processus ni dans les journaux.
#
# Une seule ligne JSON est ecrite sur la sortie standard :
#   - en cas de succes : {"mailboxes": [...]}
#   - en cas d'echec    : {"error": "..."}
#
# A adapter a votre configuration Exchange 2013 on-premise :
#   - $ConnectionUri : http vs https selon la conf IIS du vdir PowerShell
#   - $AuthMethod    : Kerberos (domaine, nom d'hote) / Negotiate (IP,
#                      NTLM) / Basic (necessite HTTPS)
# ============================================================

$ErrorActionPreference = 'Stop'
$WarningPreference = 'SilentlyContinue'

# Quand sa sortie est redirigee vers un tube (le cas ici, PHP utilise
# proc_open), Windows PowerShell encode par defaut en UTF-16 : un octet nul
# entre chaque caractere, illisible pour PHP (json_decode) et le
# navigateur. On force UTF-8 pour que le JSON transmis soit exploitable.
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
[Console]::InputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

$ExchangeServer = 'BE-EXCHANGE.acebesancon.lan'
$ConnectionUri = "https://$ExchangeServer/PowerShell/"
$AuthMethod = 'Basic'

# Basic sur HTTPS : plus fiable que Kerberos ici, car ce script tourne
# dans un processus non-interactif (le pool applicatif IIS derriere la
# page PHP), qui ne peut generalement pas obtenir de ticket Kerberos pour
# un compte different du sien - la session finit alors ouverte avec
# l'identite du serveur/pool IIS (sans droits Exchange), d'ou l'acces
# refuse observe avec -Authentication Kerberos. Basic envoie directement
# les identifiants fournis, sans dependre de ce mecanisme.
#
# Le certificat (auto-signe par defaut sur Exchange 2013) doit etre
# importe dans le magasin "Autorites de certification racines de
# confiance" de l'ordinateur local sur be-intra16 pour que la validation
# TLS passe normalement (CA + nom d'hote verifies). Seul le controle de
# revocation est ignore : un certificat auto-signe n'a pas de liste de
# revocation valide, ce n'est pas lie a la confiance accordee au serveur.
$sessionOption = New-PSSessionOption -SkipRevocationCheck

function Write-JsonResult {
    param($Object)
    $Object | ConvertTo-Json -Depth 6 -Compress
}

# --- Lecture des identifiants sur stdin ---
$cred = $null
try {
    $raw = [Console]::In.ReadToEnd()
    $creds = $raw | ConvertFrom-Json
    if (-not $creds.login -or -not $creds.password) {
        Write-JsonResult @{ error = 'Identifiants manquants.' }
        exit 1
    }
    $securePwd = ConvertTo-SecureString $creds.password -AsPlainText -Force
    $cred = New-Object System.Management.Automation.PSCredential($creds.login, $securePwd)
}
catch {
    Write-JsonResult @{ error = "Impossible de lire les identifiants : $($_.Exception.Message)" }
    exit 1
}
finally {
    Remove-Variable raw, creds, securePwd -ErrorAction SilentlyContinue
}

$session = $null
try {
    $session = New-PSSession -ConnectionUri $ConnectionUri -ConfigurationName Microsoft.Exchange -Authentication $AuthMethod -Credential $cred -SessionOption $sessionOption -ErrorAction Stop
    Import-PSSession -Session $session -DisableNameChecking -AllowClobber -ErrorAction Stop | Out-Null

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
            nom               = if ($user) { $user.LastName } else { '' }
            prenom            = if ($user) { $user.FirstName } else { '' }
            login             = if ($user) { $user.SamAccountName } else { $mbx.SamAccountName }
            email             = $mbx.PrimarySmtpAddress.ToString()
            accesComplet      = @($accesComplet)
            envoyerEnTantQue  = @($envoyerEnTantQue)
            envoyerDeLaPartDe = @($envoyerDeLaPartDe)
        }
    }

    Write-JsonResult @{ mailboxes = @($resultats) }
}
catch {
    Write-JsonResult @{ error = "Erreur Exchange : $($_.Exception.Message)" }
    exit 1
}
finally {
    if ($session) { Remove-PSSession -Session $session -ErrorAction SilentlyContinue }
    if ($cred) { Remove-Variable cred -ErrorAction SilentlyContinue }
}
