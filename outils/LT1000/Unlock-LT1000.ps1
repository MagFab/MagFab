<#
.SYNOPSIS
    Deverrouille (unlock) le magasin du lecteur de sauvegarde LT1000 via son interface web.

.DESCRIPTION
    Le script ouvre une session sur l'interface web d'administration du lecteur
    (RMU / Remote Management), envoie la commande "Unlock magazine", puis se deconnecte.
    Le resultat est affiche dans une boite de dialogue (pratique depuis un raccourci bureau).

    IMPORTANT : les URL et les noms de champs du formulaire dependent du firmware.
    Ils sont regroupes dans la section CONFIGURATION ci-dessous.
    Pour les obtenir a coup sur, voir le fichier LISEZMOI.md (capture avec F12 dans Edge/Chrome).
#>

# ============================================================================
#  CONFIGURATION  -  A RENSEIGNER
# ============================================================================

$Ip        = '192.168.1.50'      # Adresse IP du lecteur
$Login     = 'admin'             # Compte (idealement un compte dedie de niveau "operateur")
$Password  = 'MotDePasse'        # Mot de passe (laisser '' pour utiliser le fichier chiffre, voir LISEZMOI.md)
$UseHttps  = $true               # $true = https://, $false = http://
$Magazine  = 'left'              # Magasin a deverrouiller si le firmware le demande (left / right / 1 / 2...)
$Confirm   = $true               # Demander une confirmation avant le deverrouillage

# Authentification : 'Form' (formulaire de connexion, cas le plus courant) ou 'Basic' (authentification HTTP)
$AuthMode  = 'Form'

# --- Connexion (mode 'Form') ------------------------------------------------
$LoginPath = '/login.cgi'                                   # A ADAPTER
$LoginBody = { @{ username = $Login; password = $Password } }  # A ADAPTER (noms des champs)

# --- Deverrouillage ---------------------------------------------------------
$UnlockPath   = '/magazine.cgi'                             # A ADAPTER
$UnlockMethod = 'POST'                                      # POST ou GET
$UnlockBody   = { @{ action = 'unlock'; magazine = $Magazine } }  # A ADAPTER

# --- Deconnexion (laisser '' si inutile) -------------------------------------
$LogoutPath = '/logout.cgi'                                 # A ADAPTER

# Texte attendu dans la reponse pour considerer que c'est OK (laisser '' pour ne tester que le code HTTP)
$SuccessPattern = ''

# ============================================================================
#  FIN DE LA CONFIGURATION
# ============================================================================

Add-Type -AssemblyName System.Windows.Forms
$Title = 'LT1000 - Unlock magazine'

function Show-Message([string]$Text, [string]$Icon = 'Information') {
    [void][System.Windows.Forms.MessageBox]::Show($Text, $Title, 'OK', $Icon)
}

# Mot de passe chiffre (DPAPI : lisible uniquement par le meme utilisateur sur le meme PC)
if ([string]::IsNullOrEmpty($Password)) {
    $CredFile = Join-Path $PSScriptRoot 'LT1000.cred'
    if (-not (Test-Path $CredFile)) {
        Show-Message "Aucun mot de passe dans le script et fichier introuvable :`n$CredFile" 'Error'
        exit 1
    }
    $Password = (Import-Clixml $CredFile).GetNetworkCredential().Password
}

if ($Confirm) {
    $r = [System.Windows.Forms.MessageBox]::Show(
        "Deverrouiller le magasin du lecteur $Ip ?`n`nVerifiez qu'aucune sauvegarde n'est en cours.",
        $Title, 'YesNo', 'Question')
    if ($r -ne 'Yes') { exit 0 }
}

# Anciens firmwares : autoriser TLS 1.0/1.1/1.2 et accepter le certificat auto-signe du lecteur
$Protocols = [Net.SecurityProtocolType]::Tls12
foreach ($p in 'Tls11', 'Tls') { try { $Protocols = $Protocols -bor [Net.SecurityProtocolType]::$p } catch {} }
[Net.ServicePointManager]::SecurityProtocol = $Protocols

$Common = @{ UseBasicParsing = $true; TimeoutSec = 30 }
if ($PSVersionTable.PSVersion.Major -ge 6) {
    $Common.SkipCertificateCheck = $true
} else {
    [Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
}

$Scheme  = if ($UseHttps) { 'https' } else { 'http' }
$BaseUrl = "${Scheme}://$Ip"

try {
    $Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    if ($AuthMode -eq 'Basic') {
        $Pair = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("${Login}:${Password}"))
        $Session.Headers['Authorization'] = "Basic $Pair"
    } else {
        # Premiere visite pour recuperer les cookies eventuels, puis connexion
        Invoke-WebRequest @Common -Uri "$BaseUrl/" -WebSession $Session | Out-Null
        Invoke-WebRequest @Common -Uri "$BaseUrl$LoginPath" -Method Post `
            -Body (& $LoginBody) -WebSession $Session | Out-Null
    }

    $Params = @{ Uri = "$BaseUrl$UnlockPath"; Method = $UnlockMethod; WebSession = $Session }
    $Body = & $UnlockBody
    if ($Body) { $Params.Body = $Body }
    $Response = Invoke-WebRequest @Common @Params

    if ($SuccessPattern -and ($Response.Content -notmatch $SuccessPattern)) {
        throw "Reponse inattendue du lecteur (motif '$SuccessPattern' absent). Verifiez la configuration."
    }

    if ($LogoutPath) {
        try { Invoke-WebRequest @Common -Uri "$BaseUrl$LogoutPath" -WebSession $Session | Out-Null } catch {}
    }

    Show-Message "Commande de deverrouillage envoyee au lecteur $Ip.`nVous pouvez retirer le magasin."
}
catch {
    Show-Message "Echec du deverrouillage sur $Ip :`n`n$($_.Exception.Message)" 'Error'
    exit 1
}
