<?php
require '../db.php';
include_once '../error/errorHandler.php';

// ============================================================
// Groupes Active Directory : liste de tous les groupes, et au clic
// leurs membres (groupes imbriques, utilisateurs, ordinateurs),
// avec la meme fiche de detail que ad_derniers_logons.php.
// (connexion et fonctions communes : ad_commun.php)
// ============================================================
require 'ad_commun.php';

// Membres d'un groupe (DN). Au-dela de 1500 membres, l'AD renvoie
// l'attribut par tranches ("member;range=0-1499") : on lit la suite.
function membresGroupe($ds, $entree) {
    $membres = attrMulti($entree, 'member');
    $plage = null;
    foreach ($entree as $cle => $v) {
        if (is_string($cle) && strpos($cle, 'member;range=') === 0) {
            $membres = array_merge($membres, attrMulti($entree, $cle));
            $plage = $cle;
        }
    }
    while ($plage !== null && preg_match('/=(\d+)-(\d+)$/', $plage, $m)) {
        $debut = (int) $m[2] + 1;
        $sr = @ldap_read($ds, $entree['dn'], '(objectClass=*)', ["member;range=$debut-*"]);
        if ($sr === false) break;
        $r = ldap_get_entries($ds, $sr);
        $plage = null;
        if (!empty($r[0])) {
            foreach ($r[0] as $cle => $v) {
                if (is_string($cle) && strpos($cle, 'member;range=') === 0) {
                    $membres = array_merge($membres, attrMulti($r[0], $cle));
                    $plage = $cle;
                }
            }
        }
    }
    return $membres;
}

$ds = adConnexionPrincipale();

// ------------------------------------------------------------
// Lecture des groupes
// ------------------------------------------------------------
$groupes = [];
foreach (ldapRechercheTout($ds, $baseDn, FILTRE_GROUPES,
        ['cn', 'samaccountname', 'description', 'mail', 'info', 'managedby', 'grouptype', 'objectsid',
         'member', 'memberof', 'whencreated', 'whenchanged']) as $e) {
    $dn = strtolower($e['dn']);
    $gt = (int) attr($e, 'grouptype');
    if ($gt & 1) $etendue = 'Intégré (système)';
    elseif ($gt & 2) $etendue = 'Globale';
    elseif ($gt & 4) $etendue = 'Domaine local';
    elseif ($gt & 8) $etendue = 'Universelle';
    else $etendue = '';

    $groupes[$dn] = [
        'id'          => 'g' . count($groupes),
        'nom'         => attr($e, 'cn'),
        'sam'         => attr($e, 'samaccountname'),
        'description' => attr($e, 'description'),
        'mail'        => attr($e, 'mail'),
        'info'        => attr($e, 'info'),
        'gerePar'     => attr($e, 'managedby') !== '' ? nomDepuisDn(attr($e, 'managedby')) : '',
        // bit de signe de groupType = groupe de securite (test "< 0" : fonctionne aussi en PHP 32 bits)
        'type'        => $gt < 0 ? 'Sécurité' : 'Distribution',
        'etendue'     => $etendue,
        'rid'         => ridDepuisSid(attr($e, 'objectsid')),
        'chemin'      => cheminAD($e['dn']),
        'cree'        => generalizedTimeVersTimestamp(attr($e, 'whencreated')),
        'modifie'     => generalizedTimeVersTimestamp(attr($e, 'whenchanged')),
        'membresDn'   => membresGroupe($ds, $e),
        'membreDeDn'  => attrMulti($e, 'memberof'),
    ];
}

// Utilisateurs et ordinateurs (pour les fiches de detail des membres)
list($utilisateurs, $ordinateurs, $dcs, $dcsInjoignables) = adChargerComptes($ds);
ldap_unbind($ds);

// ------------------------------------------------------------
// Membres de chaque groupe
// ------------------------------------------------------------
function membreUtilisateur($u) {
    $nom = $u['nom'] !== '' ? $u['nom'] . ' (' . $u['login'] . ')' : $u['login'];
    return ['t' => 'u', 'nom' => $nom, 'chemin' => $u['chemin'], 'id' => $u['id'], 'desactive' => !$u['actif']];
}

function membreOrdinateur($o) {
    return ['t' => 'o', 'nom' => $o['nom'], 'chemin' => $o['chemin'], 'id' => $o['id'], 'desactive' => !$o['actif']];
}

// Groupe principal : ses membres ne figurent pas dans l'attribut member,
// ils sont reperes par leur primaryGroupID (= RID du groupe)
$membresPrincipaux = [];
foreach ($utilisateurs as $u) {
    if ($u['ridPrincipal'] !== '') $membresPrincipaux[$u['ridPrincipal']][] = membreUtilisateur($u);
}
foreach ($ordinateurs as $o) {
    if ($o['ridPrincipal'] !== '') $membresPrincipaux[$o['ridPrincipal']][] = membreOrdinateur($o);
}

$ordreTypes = ['g' => 0, 'u' => 1, 'o' => 2, 'x' => 3];
$detailsGroupes = [];
foreach ($groupes as $dn => $g) {
    $membres = [];
    foreach ($g['membresDn'] as $dnMembre) {
        $cle = strtolower($dnMembre);
        if (isset($groupes[$cle])) {
            $sg = $groupes[$cle];
            $membres[] = ['t' => 'g', 'nom' => $sg['nom'], 'chemin' => $sg['chemin'], 'id' => $sg['id'], 'desactive' => false];
        } elseif (isset($utilisateurs[$cle])) {
            $membres[] = membreUtilisateur($utilisateurs[$cle]);
        } elseif (isset($ordinateurs[$cle])) {
            $membres[] = membreOrdinateur($ordinateurs[$cle]);
        } else {
            // contact, compte d'un autre domaine (ForeignSecurityPrincipals)...
            $membres[] = ['t' => 'x', 'nom' => nomDepuisDn($dnMembre), 'chemin' => cheminAD($dnMembre), 'id' => null, 'desactive' => false];
        }
    }
    if ($g['rid'] !== '' && isset($membresPrincipaux[$g['rid']])) {
        $membres = array_merge($membres, $membresPrincipaux[$g['rid']]);
    }
    usort($membres, function ($a, $b) use ($ordreTypes) {
        return [$ordreTypes[$a['t']], strtolower($a['nom'])] <=> [$ordreTypes[$b['t']], strtolower($b['nom'])];
    });
    $groupes[$dn]['nbMembres'] = count($membres);

    $membreDe = [];
    foreach ($g['membreDeDn'] as $dnParent) {
        $cle = strtolower($dnParent);
        $membreDe[] = [nomDepuisDn($dnParent), cheminAD($dnParent), isset($groupes[$cle]) ? $groupes[$cle]['id'] : null];
    }
    usort($membreDe, function ($a, $b) { return strcasecmp($a[0], $b[0]); });

    $detailsGroupes[$g['id']] = [
        'titre'    => $g['nom'],
        'champs'   => [
            ['Nom', $g['nom']],
            ['Nom (pré-Windows 2000)', $g['sam']],
            ['Description', $g['description']],
            ['Type', $g['type']],
            ['Étendue', $g['etendue']],
            ['Email', $g['mail']],
            ['Géré par', $g['gerePar']],
            ['Notes', $g['info']],
            ['Chemin AD', $g['chemin']],
            ['Création de l\'objet', formatDate($g['cree'])],
            ['Modification de l\'objet', formatDate($g['modifie'])],
        ],
        'membres'  => $membres,
        'membreDe' => $membreDe,
    ];
}

// Fiches des utilisateurs / ordinateurs, avec leurs groupes cliquables
$details = adDetailsObjets($utilisateurs, $ordinateurs, $groupes);

// Tri par nom
uasort($groupes, function ($a, $b) { return strcasecmp($a['nom'], $b['nom']); });

include 'sommaire.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Groupes AD</title>
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
    td.vide { color: #bbb; }
    tr.ligne-objet { cursor: pointer; }
    tr.ligne-objet:hover td { background: #f0f4fa; }
    .badge {
        display: inline-block;
        font-size: 11px;
        padding: 1px 7px;
        border-radius: 10px;
        background: #eee;
        color: #666;
    }
    .badge.securite { background: #efe7fb; color: #5e3fa0; }
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

<h1>Groupes Active Directory</h1>
<div class="sous-titre">
    <?php echo count($groupes); ?> groupe(s)
    &mdash; cliquez sur un groupe pour voir ses membres
    &mdash; généré le <?php echo date("d/m/Y H:i"); ?>
</div>

<?php if ($dcsInjoignables): ?>
    <div class="avertissement">
        Contrôleur(s) de domaine injoignable(s) : <?php echo htmlspecialchars(implode(', ', $dcsInjoignables)); ?>.
        Les dates de dernière connexion peuvent être en retard (jusqu'à 14 jours).
    </div>
<?php endif; ?>

<div class="carte">
    <div class="entete">
        <h2>Groupes (<?php echo count($groupes); ?>)</h2>
        <div class="outils">
            <input type="search" id="filtre-groupes" placeholder="Rechercher...">
            <label><input type="checkbox" id="masquer-vides"> Masquer les groupes vides</label>
        </div>
    </div>
    <table class="sortable" id="table-groupes">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Description</th>
                <th>Type</th>
                <th class="num">Membres</th>
                <th>Création</th>
                <th>Modification</th>
                <th>Chemin AD</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groupes as $g): ?>
            <tr class="ligne-objet" data-type="groupe" data-id="<?php echo $g['id']; ?>" data-nb="<?php echo $g['nbMembres']; ?>">
                <td><?php echo htmlspecialchars($g['nom']); ?></td>
                <td><?php echo htmlspecialchars($g['description']); ?></td>
                <td>
                    <span class="badge <?php echo $g['type'] === 'Sécurité' ? 'securite' : ''; ?>"><?php echo $g['type']; ?></span>
                    <?php echo htmlspecialchars($g['etendue']); ?>
                </td>
                <td class="num <?php echo $g['nbMembres'] ? '' : 'vide'; ?>"><?php echo $g['nbMembres']; ?></td>
                <td data-sort="<?php echo $g['cree'] ?? 0; ?>"><?php echo formatDate($g['cree']); ?></td>
                <td data-sort="<?php echo $g['modifie'] ?? 0; ?>"><?php echo formatDate($g['modifie']); ?></td>
                <td class="chemin"><?php echo htmlspecialchars($g['chemin']); ?></td>
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

// Recherche texte + masquage des groupes vides
function filtrerGroupes() {
    var texte = document.getElementById('filtre-groupes').value.toLowerCase();
    var masquerVides = document.getElementById('masquer-vides').checked;
    document.querySelectorAll('#table-groupes tbody tr').forEach(function (tr) {
        var visible = tr.textContent.toLowerCase().indexOf(texte) !== -1
                      && !(masquerVides && tr.getAttribute('data-nb') === '0');
        tr.style.display = visible ? '' : 'none';
    });
}
document.getElementById('filtre-groupes').addEventListener('input', filtrerGroupes);
document.getElementById('masquer-vides').addEventListener('change', filtrerGroupes);
</script>

</body>
</html>
