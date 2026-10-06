<?php
require '../db.php';
include_once '../error/errorHandler.php';

// ============================================================
// Derniers logons Active Directory : utilisateurs et ordinateurs
// (connexion et fonctions communes : ad_commun.php)
// ============================================================
require 'ad_commun.php';

const SEUIL_INACTIF_JOURS = 90;    // logon plus ancien -> alerte
const SEUIL_ATTENTION_JOURS = 30;  // logon plus ancien -> attention
const SEUIL_MDP_JOURS = 365;       // mot de passe plus ancien -> alerte

// Classe CSS selon l'anciennete
function classeAnciennete($jours, $seuilAlerte, $seuilAttention = null) {
    if ($jours === null) return 'alerte';
    if ($jours > $seuilAlerte) return 'alerte';
    if ($seuilAttention !== null && $jours > $seuilAttention) return 'attention';
    return 'ok';
}

$ds = adConnexionPrincipale();
list($utilisateurs, $ordinateurs, $dcs, $dcsInjoignables) = adChargerComptes($ds);
ldap_unbind($ds);

// Tri : les plus anciens logons en premier
$triLogon = function ($a, $b) { return ($a['logon'] ?? 0) <=> ($b['logon'] ?? 0); };
uasort($utilisateurs, $triLogon);
uasort($ordinateurs, $triLogon);

// ------------------------------------------------------------
// Export Excel : tableau HTML ouvert par Excel, avec le format de
// cellule impose (mso-number-format) -> vraies dates jj/mm/aaaa hh:mm
// ------------------------------------------------------------
function celluleXls($valeur, $classe = 'txt') {
    return '<td class="' . $classe . '">' . htmlspecialchars((string) $valeur) . '</td>';
}

// Date au format "aaaa-mm-jj hh:mm" : Excel la reconnait comme une date quelle que soit la langue
function celluleDateXls($ts, $siVide = '') {
    if ($ts === null) return celluleXls($siVide);
    return celluleXls(date('Y-m-d H:i', $ts), 'dt');
}

if (isset($_GET['export']) && in_array($_GET['export'], ['utilisateurs', 'ordinateurs'], true)) {
    $type = $_GET['export'];
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ad_' . $type . '_' . date('Ymd_Hi') . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"><style>'
       . 'td, th { font-family: Calibri, Arial; font-size: 11pt; }'
       . 'th { background: #d9e1f2; font-weight: bold; }'
       . '.txt { mso-number-format:"\\@"; }'
       . '.dt { mso-number-format:"dd\\/mm\\/yyyy\\ hh\\:mm"; text-align: left; }'
       . '.num { mso-number-format:"0"; }'
       . '</style></head><body><table border="1">';

    if ($type === 'utilisateurs') {
        echo '<tr><th>Login</th><th>Nom</th><th>Actif</th><th>Dernier logon</th><th>Jours</th>'
           . '<th>Dernier changement mdp</th><th>Jours</th><th>Mdp n\'expire jamais</th><th>Chemin AD</th></tr>';
        foreach ($utilisateurs as $u) {
            echo '<tr>'
               . celluleXls($u['login'])
               . celluleXls($u['nom'])
               . celluleXls($u['actif'] ? 'Oui' : 'Non')
               . celluleDateXls($u['logon'], 'Jamais')
               . celluleXls(joursDepuis($u['logon']), 'num')
               . ($u['mdpAChanger'] ? celluleXls('A changer') : celluleDateXls($u['mdp']))
               . celluleXls(joursDepuis($u['mdp']), 'num')
               . celluleXls($u['mdpExpire'] ? 'Oui' : 'Non')
               . celluleXls($u['chemin'])
               . '</tr>';
        }
    } else {
        echo '<tr><th>Ordinateur</th><th>Systeme</th><th>Actif</th><th>Derniere connexion</th>'
           . '<th>Jours</th><th>Dernier changement mdp machine</th><th>Chemin AD</th></tr>';
        foreach ($ordinateurs as $o) {
            echo '<tr>'
               . celluleXls($o['nom'])
               . celluleXls($o['os'])
               . celluleXls($o['actif'] ? 'Oui' : 'Non')
               . celluleDateXls($o['logon'], 'Jamais')
               . celluleXls(joursDepuis($o['logon']), 'num')
               . celluleDateXls($o['mdp'])
               . celluleXls($o['chemin'])
               . '</tr>';
        }
    }
    echo '</table></body></html>';
    exit;
}

// Fiches de detail, affichees au clic sur une ligne (ad_fenetre_detail.php)
$details = adDetailsObjets($utilisateurs, $ordinateurs);

include 'sommaire.php';

$nbUsersInactifs = 0;
foreach ($utilisateurs as $u) {
    if ($u['actif'] && classeAnciennete(joursDepuis($u['logon']), SEUIL_INACTIF_JOURS) === 'alerte') $nbUsersInactifs++;
}
$nbOrdisInactifs = 0;
foreach ($ordinateurs as $o) {
    if ($o['actif'] && classeAnciennete(joursDepuis($o['logon']), SEUIL_INACTIF_JOURS) === 'alerte') $nbOrdisInactifs++;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Derniers logons AD</title>
<style>
    body {
        font-family: Segoe UI, Arial, sans-serif;
        background: #f5f6f8;
        color: #222;
        margin: 0;
        padding: 30px;
    }
    h1 { font-size: 20px; margin-bottom: 4px; }
    h2 { font-size: 16px; margin: 0; }
    .sous-titre { color: #666; font-size: 13px; margin-bottom: 20px; }
    .carte {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.1);
        padding: 20px;
        box-sizing: border-box;
        width: 100%;
        margin-bottom: 30px;
        overflow-x: auto;
    }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 6px 12px; text-align: left; border-bottom: 1px solid #e5e5e5; font-size: 13px; }
    th { color: #666; font-weight: 600; font-size: 12px; }
    table.sortable th { cursor: pointer; user-select: none; padding-right: 18px; }
    table.sortable th.tri-asc::after { content: " \25B2"; font-size: 10px; }
    table.sortable th.tri-desc::after { content: " \25BC"; font-size: 10px; }
    td.num, th.num { text-align: right; }
    td.chemin { color: #666; font-size: 12px; }
    tr.ligne-objet { cursor: pointer; }
    tr.ligne-objet:hover td { background: #f0f4fa; }

    td.ok { color: #1d6f42; }
    td.attention { color: #b36b00; font-weight: 600; }
    td.alerte { color: #c0392b; font-weight: 600; }
    tr.desactive td { color: #999; font-weight: normal; }
    .badge {
        display: inline-block;
        font-size: 11px;
        padding: 1px 7px;
        border-radius: 10px;
        background: #eee;
        color: #666;
    }
    .badge.dc { background: #e3ecf7; color: #2c5d90; }
    .entete {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 12px;
    }
    .outils { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; font-size: 13px; }
    .outils input[type=search] {
        padding: 6px 10px;
        font-size: 13px;
        border-radius: 5px;
        border: 1px solid #ccc;
    }
    .bouton-export {
        display: inline-block;
        background: #1d6f42;
        color: #fff;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        padding: 8px 14px;
        border-radius: 5px;
    }
    .bouton-export:hover { background: #144f2f; }
    .avertissement {
        background: #fff4e0;
        border-left: 4px solid #b36b00;
        padding: 10px 14px;
        margin-bottom: 20px;
        font-size: 13px;
    }
</style>
</head>
<body>

<h1>Derniers logons Active Directory</h1>
<div class="sous-titre">
    <?php echo count($utilisateurs); ?> utilisateur(s) dont <?php echo $nbUsersInactifs; ?> actif(s) sans logon depuis plus de <?php echo SEUIL_INACTIF_JOURS; ?> jours
    &mdash; <?php echo count($ordinateurs); ?> ordinateur(s) dont <?php echo $nbOrdisInactifs; ?> actif(s) sans connexion depuis plus de <?php echo SEUIL_INACTIF_JOURS; ?> jours
    &mdash; <?php echo count($dcs); ?> contrôleur(s) de domaine interrogé(s)
    &mdash; généré le <?php echo date("d/m/Y H:i"); ?>
</div>

<?php if ($dcsInjoignables): ?>
    <div class="avertissement">
        Contrôleur(s) de domaine injoignable(s) : <?php echo htmlspecialchars(implode(', ', $dcsInjoignables)); ?>.
        Les dates de logon peuvent être en retard (jusqu'à 14 jours).
    </div>
<?php endif; ?>

<div class="carte">
    <div class="entete">
        <h2>Utilisateurs (<?php echo count($utilisateurs); ?>)</h2>
        <div class="outils">
            <input type="search" class="filtre" data-table="table-users" placeholder="Rechercher...">
            <label><input type="checkbox" class="masquer-desactives" data-table="table-users" checked> Masquer les comptes désactivés</label>
            <a class="bouton-export" href="?export=utilisateurs">Exporter vers Excel</a>
        </div>
    </div>
    <table class="sortable" id="table-users">
        <thead>
            <tr>
                <th>Login</th>
                <th>Nom</th>
                <th>Etat</th>
                <th>Dernier logon</th>
                <th class="num">Jours</th>
                <th>Dernier changement mdp</th>
                <th class="num">Jours</th>
                <th>Chemin AD</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($utilisateurs as $u):
            $jLogon = joursDepuis($u['logon']);
            $jMdp = joursDepuis($u['mdp']);
            $clsMdp = ($u['mdpAChanger'] || $u['mdpExpire']) ? 'attention' : classeAnciennete($jMdp, SEUIL_MDP_JOURS);
        ?>
            <tr class="ligne-objet <?php echo $u['actif'] ? '' : 'desactive'; ?>" data-id="<?php echo $u['id']; ?>">
                <td><?php echo htmlspecialchars($u['login']); ?></td>
                <td><?php echo htmlspecialchars($u['nom']); ?></td>
                <td><?php echo $u['actif'] ? 'Actif' : '<span class="badge">Désactivé</span>'; ?></td>
                <td class="<?php echo classeAnciennete($jLogon, SEUIL_INACTIF_JOURS, SEUIL_ATTENTION_JOURS); ?>" data-sort="<?php echo $u['logon'] ?? 0; ?>">
                    <?php echo $u['logon'] === null ? 'Jamais' : formatDate($u['logon']); ?>
                </td>
                <td class="num" data-sort="<?php echo $jLogon ?? 999999; ?>"><?php echo $jLogon ?? ''; ?></td>
                <td class="<?php echo $clsMdp; ?>" data-sort="<?php echo $u['mdp'] ?? 0; ?>">
                    <?php
                    if ($u['mdpAChanger']) echo 'A changer au prochain logon';
                    else echo formatDate($u['mdp']);
                    if ($u['mdpExpire']) echo ' <span class="badge">n\'expire jamais</span>';
                    ?>
                </td>
                <td class="num" data-sort="<?php echo $jMdp ?? 999999; ?>"><?php echo $jMdp ?? ''; ?></td>
                <td class="chemin"><?php echo htmlspecialchars($u['chemin']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="carte">
    <div class="entete">
        <h2>Ordinateurs (<?php echo count($ordinateurs); ?>)</h2>
        <div class="outils">
            <input type="search" class="filtre" data-table="table-ordis" placeholder="Rechercher...">
            <label><input type="checkbox" class="masquer-desactives" data-table="table-ordis" checked> Masquer les comptes désactivés</label>
            <a class="bouton-export" href="?export=ordinateurs">Exporter vers Excel</a>
        </div>
    </div>
    <table class="sortable" id="table-ordis">
        <thead>
            <tr>
                <th>Ordinateur</th>
                <th>Système</th>
                <th>Etat</th>
                <th>Dernière connexion</th>
                <th class="num">Jours</th>
                <th>Chemin AD</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ordinateurs as $o):
            $jLogon = joursDepuis($o['logon']);
        ?>
            <tr class="ligne-objet <?php echo $o['actif'] ? '' : 'desactive'; ?>" data-id="<?php echo $o['id']; ?>">
                <td>
                    <?php echo htmlspecialchars($o['nom']); ?>
                    <?php if ($o['dc']): ?><span class="badge dc">DC</span><?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($o['os']); ?></td>
                <td><?php echo $o['actif'] ? 'Actif' : '<span class="badge">Désactivé</span>'; ?></td>
                <td class="<?php echo classeAnciennete($jLogon, SEUIL_INACTIF_JOURS, SEUIL_ATTENTION_JOURS); ?>" data-sort="<?php echo $o['logon'] ?? 0; ?>">
                    <?php echo $o['logon'] === null ? 'Jamais' : formatDate($o['logon']); ?>
                </td>
                <td class="num" data-sort="<?php echo $jLogon ?? 999999; ?>"><?php echo $jLogon ?? ''; ?></td>
                <td class="chemin"><?php echo htmlspecialchars($o['chemin']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'ad_fenetre_detail.php'; ?>

<script>
// Tri des colonnes (meme principe que les autres pages de l'intranet)
document.querySelectorAll('table.sortable').forEach(function (table) {
    var headers = table.querySelectorAll('thead th');
    headers.forEach(function (th, index) {
        th.addEventListener('click', function () {
            var tbody = table.querySelector('tbody');
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
            var asc = th.getAttribute('data-tri') !== 'asc';

            headers.forEach(function (h) {
                h.removeAttribute('data-tri');
                h.classList.remove('tri-asc', 'tri-desc');
            });
            th.setAttribute('data-tri', asc ? 'asc' : 'desc');
            th.classList.add(asc ? 'tri-asc' : 'tri-desc');

            rows.sort(function (a, b) {
                var cellA = a.children[index];
                var cellB = b.children[index];
                var ta = cellA.getAttribute('data-sort') !== null ? cellA.getAttribute('data-sort') : cellA.textContent.trim();
                var tb = cellB.getAttribute('data-sort') !== null ? cellB.getAttribute('data-sort') : cellB.textContent.trim();
                var na = parseFloat(ta.replace(',', '.'));
                var nb = parseFloat(tb.replace(',', '.'));
                var cmp;
                if (ta !== '' && tb !== '' && !isNaN(na) && !isNaN(nb)) {
                    cmp = na - nb;
                } else {
                    cmp = ta.localeCompare(tb, 'fr', { sensitivity: 'base' });
                }
                return asc ? cmp : -cmp;
            });

            rows.forEach(function (row) { tbody.appendChild(row); });
        });
    });
});

// Recherche texte + masquage des comptes desactives
function appliquerFiltres(idTable) {
    var table = document.getElementById(idTable);
    var texte = document.querySelector('.filtre[data-table="' + idTable + '"]').value.toLowerCase();
    var masquer = document.querySelector('.masquer-desactives[data-table="' + idTable + '"]').checked;
    table.querySelectorAll('tbody tr').forEach(function (tr) {
        var visible = tr.textContent.toLowerCase().indexOf(texte) !== -1
                      && !(masquer && tr.classList.contains('desactive'));
        tr.style.display = visible ? '' : 'none';
    });
}
document.querySelectorAll('.filtre, .masquer-desactives').forEach(function (el) {
    var idTable = el.getAttribute('data-table');
    el.addEventListener('input', function () { appliquerFiltres(idTable); });
    el.addEventListener('change', function () { appliquerFiltres(idTable); });
    appliquerFiltres(idTable);
});
</script>

</body>
</html>
