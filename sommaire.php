<?php
require '../db.php';
include_once '../error/errorHandler.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../CSS/sommaire.css">
    <link rel="stylesheet" href="../CSS/all.min.css">
    <title>Intranet ACE</title>
    <script type="text/javascript" src="../JS/all.min.js"></script>
    <script type="text/javascript" src="../JS/jquery-3.6.0.min.js"></script>
		<script type="text/javascript" src="../JS/admin.js"></script>
		<script type="text/javascript" src="../JS/flux.js"></script>
		<script type="text/javascript" src="../JS/dossier.js"></script>
		<script type="text/javascript" src="../JS/courrier.js"></script>
    <script type="text/javascript" src="../JS/accueil.js"></script>
</head>
<body>
<div class="sace">
  <div class="swrapper">
    <div class="sbox sb"><a href="page_accueil.php"><img src="../IMG/ace_logo.png"></a></div>
    <div class="sbox sa"><b><div id="anniv" style="font-size:20; margin-top: 5px"></div></b></div>
    <div class="sbox sc"><b><div id="news" style="font-size:20; margin-top: 5px">
                <?php
                $today = new DateTime();
                $today = date('Y-m-d');

                $conn = mysqli_connect($_ENV['DB_HOST'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $_ENV['DB_DATABASE2']);
                $sql = "SELECT dte_deb, dte_fin, contenu FROM news WHERE dte_deb <= '".$today."'";
                $result = mysqli_query($conn,$sql);
                try {
                    while($row = mysqli_fetch_array($result))
                    {
                        if($today <= $row['dte_fin'])
                        {
                            $contenu .= $row['contenu']." --- ";
                            $test = 1;
                        } else
                        {
                        }
                    }
                    $contenu=substr($contenu,0,$contenu.lenght-5);
                    if($test === 1) {
                        echo('<marquee direction="left" behavior="scroll" truespeed scrolldelay="50";>'.$contenu.'</marquee>');
                    } else
                    {
                        ?>
                        <script>
                            $(document).ready(function() {
                                $('#news').remove();
                            });
                        </script>
                        <?php
                    }
                }
                        catch (Exception $e) {
                            set_error_handler('aceErrorHandler');
                            trigger_error($e->getMessage());
                        }
                ?>

            </div>
  </b></div>
  </div>
</div>

<nav class="main-navigation">
  <ul class="menu">
    <li><a href="telephone_interne.php">Tel. Interne</a></li>
    <li><a href="liste_procedure.php">Procédures</a></li>
      <li class="menu-item-has-children"><a href="#">Outils Paie <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
          <ul class="sub-menu">
              <li><a href="edition_profils.php">Editions des profils d'un dossier</a></li>
              <li><a href="edition_profils_avance.php">Editions des profils avancés</a></li>
              <li><a href="rubrique_periode.php">Recherche de rubriques pour une période</a></li>
              <li><a href="recherche_force.php">Recherche de rubriques forcées sur un dossier</a></li>
              <li><a href="rubrique.php">Afichage des rubriques du cabinet</a></li>
              <li><a href="rubrique_dsn.php">Affichage des rubriques du cabinet DSN</a></li>
              <li><a href="caisse_cabinet.php">Affichage des caisses du cabinet</a></li>
              <li><a href="periodes_avancement.php">Affichage des périodes d'avancement</a></li>
          </ul>
      </li>
      <li class="menu-item-has-children"><a href="#">Outils Bancaire <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
          <ul class="sub-menu">
              <li><a href="liste_banques.php">Banques & relevés EBICS des dossiers du cabinet</a></li>
              <li><a href="liste_ebics.php">Liste des fichiers EBICS</a></li>
              <li><a href="liste_ebics_lan.php">Liste des fichiers EBICS LAN</a></li>
              <li><a href="lecteur_cfonb.php">Lecteur de relevé CFONB</a></li>
          </ul>
      </li>
      <li class="menu-item-has-children"><a href="#">Outils <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
          <ul class="sub-menu">
              <li><a href="exchange_delegations.php">Délégations boîtes mails Exchange</a></li>
          </ul>
      </li>
    <li class="menu-item-has-children"><a href="#">Cabinet ACE <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
      <ul class="sub-menu">
        <li><a href="diaporama.php">Diaporama</a></li>
        <li><a href="gestion_annonce.php">Gestion des Annonces</a></li>
        <li><a href="cabinet_ace.php">Vie au Cabinet</a></li>
      </ul>
    </li>
    <li class="menu-item-has-children"><a href="#">Connexions Users <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
        <ul class="sub-menu">
            <li><a href="utilisateurs_connectes.php">Utilisateurs connectés Diapaie</a></li>
            <li><a href="utilisateurs_connectes_compta.php">Utilisateurs connectés ACDCompta</a></li>
        </ul></li>

    <li><a href="info_dossier.php">Info Dossier</a></li>
    <li class="menu-item-has-children"><a href="#">Sites/Liens <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
        <ul class="sub-menu">
            <li><a href="https://mon-expert-en-gestion.fr">RCA</a></li>
            <li><a href="https://Lexispoly.acebesancon.fr/Pnr">PolyActe</a></li>
            <li><a href="https://acebesancon.silae.fr/silae/">Silaexpert</a></li>
            <li><a href="lien_internet.php">Liens Internet</a></li>
        </ul></li>
    <li class="menu-item-has-children"><a href="#">Secrétariat <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
        <ul class="sub-menu">
            <li><a href="flux_visites.php" id="flux_pannel">Flux & Visites</a></li>
            <li><a href="dossier.php" id="dossier_pannel">Dossiers</a></li>
            <li><a href="gestion_courrier.php" id="courrier_pannel">Courrier</a></li>
        </ul>
    </li>
    <li class="menu-item-has-children" id="pannelAdmin"><a href="#">Informatique <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
        <ul class="sub-menu">
            <li><a href="gestion_actualite.php">Gestion Actualité</a></li>
            <li><a href="rib_partis.php">RIB CLient Partis</a></li>
        </ul>
    </li>
    <li><a href="formulaire_connexion.php">Connexion</a></li>
    <li class="menu-item-has-children">
        <a href="#" onclick="return demanderMotDePasseGestionInterne(event);">Gestion Interne <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
        <ul class="sub-menu" id="gestionInterneSubMenu" style="display:none;">
            <li><a href="gestion_periode.php">Gestion des périodes</a></li>
            <li><a href="tableau_mensuel_periode.php">Heures théoriques mensuelles</a></li>
        </ul>
    </li>
    <li class="menu-item-has-children">
        <a href="#" onclick="return demanderMotDePasseStats(event);">Statistiques <i class="fas fa-angle-down" style="padding-left: 10px"></i></a>
        <ul class="sub-menu" id="statsSubMenu" style="display:none;">
            <li><a href="doc_par_annee.php">Documents par annee</a></li>
            <li><a href="dossiers_entree_sortie.php">Dossiers entrees/sorties</a></li>
            <li><a href="dossiers_par_chef_mission.php">Dossiers par chef de mission</a></li>
            <li><a href="temps_par_dossier.php">Temps par dossier</a></li>
            <li><a href="temps_par_collaborateur_mois.php">Temps par collaborateur et mois</a></li>
            <li><a href="tarifs_collaborateurs.php">Tarifs des collaborateurs</a></li>
            <li><a href="dossiers_sans_charges.php">Dossiers sans charges</a></li>
            <li><a href="dossiers_avec_charges.php">Dossiers avec charges</a></li>
            <li><a href="correspondants_dossier.php">Correspondants d'un dossier</a></li>
            <li><a href="webusers_isuite.php">Users web iSuite</a></li>
            <li><a href="Liste_Etat_Clients_PA.php">Liste Etat Clients PA</a></li>
        </ul>
    </li>
  </ul>
</nav>

<script>
// sessionStorage : reste memorise tant que l'onglet/navigateur reste ouvert sur le site,
// meme en naviguant d'une page a l'autre (contrairement a une simple variable JS,
// qui est remise a zero a chaque chargement de page).
function statsEstDeverrouille() {
    return sessionStorage.getItem('statsDeverrouille') === '1';
}

function demanderMotDePasseStats(e) {
    e.preventDefault();
    var subMenu = document.getElementById('statsSubMenu');

    if (statsEstDeverrouille()) {
        subMenu.style.display = (subMenu.style.display === 'block') ? 'none' : 'block';
        return false;
    }

    var mdp = prompt('Mot de passe requis pour acceder aux statistiques :');
    if (mdp === null) {
        return false;
    }
    if (mdp === 'Claude') {
        sessionStorage.setItem('statsDeverrouille', '1');
        subMenu.style.display = 'block';
    } else {
        alert('Mot de passe incorrect.');
    }
    return false;
}

// Meme mecanisme que pour Statistiques : verrou cote client, memorise pour l'onglet en cours
function gestionInterneEstDeverrouille() {
    return sessionStorage.getItem('gestionInterneDeverrouille') === '1';
}

function demanderMotDePasseGestionInterne(e) {
    e.preventDefault();
    var subMenu = document.getElementById('gestionInterneSubMenu');

    if (gestionInterneEstDeverrouille()) {
        subMenu.style.display = (subMenu.style.display === 'block') ? 'none' : 'block';
        return false;
    }

    var mdp = prompt('Mot de passe requis pour acceder a la gestion interne :');
    if (mdp === null) {
        return false;
    }
    if (mdp === 'Claude') {
        sessionStorage.setItem('gestionInterneDeverrouille', '1');
        subMenu.style.display = 'block';
    } else {
        alert('Mot de passe incorrect.');
    }
    return false;
}
</script>

</body>
</html>
