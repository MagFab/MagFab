# Raccourci "Unlock magazine" pour le lecteur de sauvegarde LT1000

Ce dossier contient un script PowerShell qui appelle l'interface web (RMU) du
lecteur pour envoyer la commande de deverrouillage du magasin, plus un script
pour deposer un raccourci sur le bureau.

Fichiers :
- `Unlock-LT1000.ps1` : le script qui fait le travail (a configurer, voir plus bas).
- `Creer-Raccourci.ps1` : cree le raccourci bureau "Unlock LT1000" (a executer une fois).
- `Enregistrer-MotDePasse.ps1` : optionnel, stocke le mot de passe chiffre au lieu de le
  laisser en clair dans le script.

## 1. Pourquoi je ne peux pas remplir les URL a votre place

Je n'ai pas de fiche technique du firmware exact de votre LT1000 sous les yeux,
et les interfaces web de lecteurs/autoloaders de bandes changent selon la
version de firmware (souvent une page `login.cgi` / `magazine.cgi` ou
similaire, parfois une API JSON). Le script est pret a l'emploi mais 3 blocs
sont a verifier/adapter dans `Unlock-LT1000.ps1` (section CONFIGURATION en haut
du fichier) :

- `$LoginPath` / `$LoginBody` : la page et les champs du formulaire de connexion.
- `$UnlockPath` / `$UnlockMethod` / `$UnlockBody` : la page et les parametres qui
  declenchent le deverrouillage du magasin.
- `$LogoutPath` : la page de deconnexion (optionnelle).

## 2. Comment trouver les bonnes valeurs en 5 minutes

1. Ouvrez l'interface web du lecteur dans Edge ou Chrome (`https://<ip-du-lecteur>/`).
2. Appuyez sur **F12** pour ouvrir les outils de developpement, onglet **Reseau / Network**.
3. Connectez-vous normalement (login/mot de passe) : repérez la requete POST
   vers la page de login, notez son URL et les noms des champs envoyes
   (onglet "Charge utile / Payload" ou "Form Data").
4. Allez faire l'action "Unlock magazine" depuis le menu du lecteur : repérez
   la requete correspondante (URL, methode GET/POST, parametres envoyes).
5. Reportez ces valeurs dans `Unlock-LT1000.ps1`.

Si le lecteur propose un webservice SOAP/REST documente (souvent dans le menu
"Help" de l'interface, ou dans le manuel d'administration du LT1000), c'est
encore plus simple et plus fiable : on peut adapter le script pour appeler
directement ce webservice plutot que de rejouer le formulaire HTML.

## 3. Configurer le script

Ouvrez `Unlock-LT1000.ps1` et renseignez en haut du fichier :

```powershell
$Ip        = '192.168.1.50'   # IP du lecteur
$Login     = 'admin'
$Password  = 'MotDePasse'
```

Astuce securite : plutot que de laisser le mot de passe en clair dans le
script, vous pouvez :
- executer une fois `Enregistrer-MotDePasse.ps1` (il demande le mot de passe et
  le stocke chiffre avec DPAPI dans `LT1000.cred`, lisible uniquement par
  votre compte Windows sur ce PC) ;
- laisser `$Password = ''` dans `Unlock-LT1000.ps1` : il ira alors lire
  automatiquement `LT1000.cred`.

Testez d'abord en lancant le script directement dans une console PowerShell
(clic droit > Executer avec PowerShell, ou `.\Unlock-LT1000.ps1`) pour voir les
messages d'erreur eventuels, avant de creer le raccourci.

## 4. Creer le raccourci sur le bureau

Une fois le script testé avec succès :

```powershell
.\Creer-Raccourci.ps1
```

Cela cree un raccourci **"Unlock LT1000"** sur le bureau qui lance le script en
PowerShell caché (pas de fenêtre bleue qui traîne), avec une confirmation avant
d'agir et une boîte de dialogue de résultat à la fin.

## 5. Notes

- Le script accepte le certificat auto-signé du lecteur (courant sur ce type
  de matériel) et autorise TLS 1.0/1.1 en repli si le firmware est ancien.
- `$Confirm = $true` affiche une boîte "Oui/Non" avant d'envoyer la commande,
  pour éviter un double-clic malheureux pendant une sauvegarde en cours.
  Passez à `$false` si vous ne voulez plus de confirmation.
- Si votre firmware expose plusieurs magasins, ajustez `$Magazine`
  (`left`/`right`, `1`/`2`, etc. selon ce que montre la requête capturée).
