<?php
require '../db.php';
include_once '../error/errorHandler.php';

// ============================================================
// Derniers logons Active Directory : utilisateurs et ordinateurs
//
// Connexion a l'AD via l'extension PHP "ldap", avec un compte de
// service en lecture seule. Meme convention que le reste de
// l'intranet : parametres fournis via les variables d'environnement.
//   LDAP_HOST     : ldap://srv-dc1.acebesancon.lan  (ou ldaps://...)
//   LDAP_USER     : svc-intranet@acebesancon.lan
//   LDAP_PASSWORD : mot de passe du compte de service
//   LDAP_BASE_DN  : DC=acebesancon,DC=lan  (facultatif)
// ============================================================
const SEUIL_INACTIF_JOURS = 90;    // logon plus ancien -> alerte
const SEUIL_ATTENTION_JOURS = 30;  // logon plus ancien -> attention
const SEUIL_MDP_JOURS = 365;       // mot de passe plus ancien -> alerte

// lastLogon est conserve en UTC dans l'AD : on l'affiche en heure de Paris
date_default_timezone_set('Europe/Paris');

// Lecture d'un parametre : $_ENV (fichier .env charge par db.php),
// sinon $_SERVER (SetEnv Apache, variables FastCGI IIS), sinon getenv()
function parametre($nom, $defaut = '') {
    if (!empty($_ENV[$nom])) return $_ENV[$nom];
    if (!empty($_SERVER[$nom])) return $_SERVER[$nom];
    $v = getenv($nom);
    return ($v !== false && $v !== '') ? $v : $defaut;
}

// Pour le diagnostic : ou PHP trouve-t-il une variable ?
function origineParametre($nom) {
    $origines = [];
    if (!empty($_ENV[$nom])) $origines[] = '$_ENV';
    if (!empty($_SERVER[$nom])) $origines[] = '$_SERVER';
    if (getenv($nom) !== false && getenv($nom) !== '') $origines[] = 'getenv()';
    return $origines ? implode(', ', $origines) : 'introuvable';
}

$ldapHost = parametre('LDAP_HOST');
$ldapUser = parametre('LDAP_USER');
$ldapPass = parametre('LDAP_PASSWORD');
$baseDn   = parametre('LDAP_BASE_DN', 'DC=acebesancon,DC=lan');

if (!function_exists('ldap_connect')) {
    http_response_code(500);
    die("L'extension PHP ldap n'est pas activee sur le serveur.");
}

$manquants = [];
foreach (['LDAP_HOST' => $ldapHost, 'LDAP_USER' => $ldapUser, 'LDAP_PASSWORD' => $ldapPass] as $nom => $valeur) {
    if ($valeur === '') $manquants[] = $nom;
}
if ($manquants) {
    http_response_code(500);
    // Fichiers .env presents autour de la page, pour savoir lequel est lu
    $fichiersEnv = [];
    foreach ([__DIR__, dirname(__DIR__), dirname(__DIR__, 2)] as $dossier) {
        if (is_file($dossier . DIRECTORY_SEPARATOR . '.env')) {
            $f = realpath($dossier . DIRECTORY_SEPARATOR . '.env');
            $contenu = (string) @file_get_contents($f);
            $fichiersEnv[] = $f . (preg_match('/^\s*LDAP_HOST\s*=/m', $contenu)
                ? ' (contient LDAP_HOST)' : ' (ne contient PAS LDAP_HOST)');
        }
    }
    die("<pre>Parametre(s) LDAP non defini(s) : " . implode(', ', $manquants) . "\n\n"
        . "Diagnostic :\n"
        . "  DB_HOST   trouve dans : " . origineParametre('DB_HOST') . "\n"
        . "  LDAP_HOST trouve dans : " . origineParametre('LDAP_HOST') . "\n"
        . "  Fichier(s) .env : " . ($fichiersEnv ? htmlspecialchars(implode(' | ', $fichiersEnv)) : 'aucun trouve') . "\n"
        . "</pre>");
}

// Message de la derniere erreur de connexion, pour l'affichage
$derniereErreurLdap = '';

function ldapConnexion($uri, $user, $pass) {
    global $derniereErreurLdap;
    $ds = @ldap_connect($uri);
    if (!$ds) {
        $derniereErreurLdap = "adresse invalide (attendu : ldap://serveur ou ldaps://serveur)";
        return false;
    }
    ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, 3);
    if (!@ldap_bind($ds, $user, $pass)) {
        $derniereErreurLdap = ldap_error($ds);
        if (@ldap_get_option($ds, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag) && $diag) {
            $derniereErreurLdap .= ' - ' . $diag;
        }
        return false;
    }
    return $ds;
}

// Recherche paginee (l'AD limite a 1000 resultats par page)
function ldapRechercheTout($ds, $base, $filtre, $attributs) {
    $resultats = [];
    $cookie = '';
    do {
        $controles = [[
            'oid' => LDAP_CONTROL_PAGEDRESULTS,
            'value' => ['size' => 500, 'cookie' => $cookie],
        ]];
        $sr = @ldap_search($ds, $base, $filtre, $attributs, 0, 0, 0, LDAP_DEREF_NEVER, $controles);
        if ($sr === false) break;
        ldap_parse_result($ds, $sr, $errcode, $matcheddn, $errmsg, $referrals, $ctrlRetour);
        $entrees = ldap_get_entries($ds, $sr);
        for ($i = 0; $i < $entrees['count']; $i++) {
            $resultats[] = $entrees[$i];
        }
        $cookie = $ctrlRetour[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '';
    } while ($cookie !== '');
    return $resultats;
}

// Attribut LDAP (noms en minuscules dans ldap_get_entries)
function attr($entree, $nom) {
    return isset($entree[$nom][0]) ? $entree[$nom][0] : '';
}

// Integer8 AD (intervalles de 100 ns depuis le 01/01/1601 UTC) -> timestamp Unix
// null si 0 (jamais) ou valeur "jamais d'expiration"
function filetimeVersTimestamp($v) {
    if ($v === '' || $v === '0' || $v === '9223372036854775807') return null;
    return intdiv((int) $v, 10000000) - 11644473600;
}

function formatDate($ts) {
    return $ts === null ? '' : date('d/m/Y H:i', $ts);
}

function joursDepuis($ts) {
    return $ts === null ? null : (int) floor((time() - $ts) / 86400);
}

function maxTs($a, $b) {
    if ($a === null) return $b;
    if ($b === null) return $a;
    return max($a, $b);
}

// Met a jour la date de logon de chaque compte avec le lastLogon lu sur un DC
function majLastLogon($ds, $base, $filtre, &$liste) {
    foreach (ldapRechercheTout($ds, $base, $filtre, ['lastlogon']) as $e) {
        $dn = strtolower($e['dn']);
        if (isset($liste[$dn])) {
            $liste[$dn]['logon'] = maxTs($liste[$dn]['logon'], filetimeVersTimestamp(attr($e, 'lastlogon')));
        }
    }
}

// Classe CSS selon l'anciennete
function classeAnciennete($jours, $seuilAlerte, $seuilAttention = null) {
    if ($jours === null) return 'alerte';
    if ($jours > $seuilAlerte) return 'alerte';
    if ($seuilAttention !== null && $jours > $seuilAttention) return 'attention';
    return 'ok';
}

// ------------------------------------------------------------
// Connexion au DC principal
// ------------------------------------------------------------
$ds = ldapConnexion($ldapHost, $ldapUser, $ldapPass);
if (!$ds) {
    http_response_code(500);
    die("Erreur de connexion a l'Active Directory (" . htmlspecialchars($ldapHost) . ") : "
        . htmlspecialchars($derniereErreurLdap));
}

$filtreUsers = '(&(objectCategory=person)(objectClass=user))';
$filtreOrdis = '(objectCategory=computer)';

// ------------------------------------------------------------
// Lecture des comptes (attributs repliques) sur le DC principal
// ------------------------------------------------------------
$utilisateurs = [];
foreach (ldapRechercheTout($ds, $baseDn, $filtreUsers,
        ['samaccountname', 'displayname', 'useraccountcontrol', 'lastlogontimestamp', 'lastlogon', 'pwdlastset', 'whencreated']) as $e) {
    $dn = strtolower($e['dn']);
    $uac = (int) attr($e, 'useraccountcontrol');
    $utilisateurs[$dn] = [
        'login'     => attr($e, 'samaccountname'),
        'nom'       => attr($e, 'displayname'),
        'actif'     => !($uac & 2),                 // ACCOUNTDISABLE
        'mdpExpire' => (bool) ($uac & 65536),       // DONT_EXPIRE_PASSWORD
        'logon'     => maxTs(filetimeVersTimestamp(attr($e, 'lastlogontimestamp')),
                             filetimeVersTimestamp(attr($e, 'lastlogon'))),
        'mdp'       => filetimeVersTimestamp(attr($e, 'pwdlastset')),
        'mdpAChanger' => attr($e, 'pwdlastset') === '0',
    ];
}

$ordinateurs = [];
foreach (ldapRechercheTout($ds, $baseDn, $filtreOrdis,
        ['name', 'operatingsystem', 'operatingsystemversion', 'useraccountcontrol', 'lastlogontimestamp', 'lastlogon', 'pwdlastset']) as $e) {
    $dn = strtolower($e['dn']);
    $uac = (int) attr($e, 'useraccountcontrol');
    $ordinateurs[$dn] = [
        'nom'    => attr($e, 'name'),
        'os'     => trim(attr($e, 'operatingsystem') . ' ' . attr($e, 'operatingsystemversion')),
        'actif'  => !($uac & 2),
        'dc'     => (bool) ($uac & 8192),           // SERVER_TRUST_ACCOUNT
        'logon'  => maxTs(filetimeVersTimestamp(attr($e, 'lastlogontimestamp')),
                          filetimeVersTimestamp(attr($e, 'lastlogon'))),
        'mdp'    => filetimeVersTimestamp(attr($e, 'pwdlastset')),
    ];
}

// ------------------------------------------------------------
// lastLogon n'est PAS replique : on interroge chaque autre DC
// et on garde la date la plus recente.
// ------------------------------------------------------------
$schema = preg_match('#^ldaps://#i', $ldapHost) ? 'ldaps://' : 'ldap://';
$hotePrincipal = strtolower(preg_replace('#^ldaps?://#i', '', rtrim($ldapHost, '/')));
$hotePrincipal = preg_replace('#:\d+$#', '', $hotePrincipal);

$dcs = [];
$dcsInjoignables = [];
foreach (ldapRechercheTout($ds, $baseDn,
        '(&(objectCategory=computer)(userAccountControl:1.2.840.113556.1.4.803:=8192))', ['dnshostname']) as $e) {
    $dcs[] = strtolower(attr($e, 'dnshostname'));
}
ldap_unbind($ds);

foreach ($dcs as $dc) {
    if ($dc === '' || $dc === $hotePrincipal) continue;
    $dsDc = ldapConnexion($schema . $dc, $ldapUser, $ldapPass);
    if (!$dsDc) {
        $dcsInjoignables[] = $dc;
        continue;
    }
    majLastLogon($dsDc, $baseDn, $filtreUsers, $utilisateurs);
    majLastLogon($dsDc, $baseDn, $filtreOrdis, $ordinateurs);
    ldap_unbind($dsDc);
}

// Tri : les plus anciens logons en premier
$triLogon = function ($a, $b) { return ($a['logon'] ?? 0) <=> ($b['logon'] ?? 0); };
uasort($utilisateurs, $triLogon);
uasort($ordinateurs, $triLogon);

// ------------------------------------------------------------
// Export CSV (ouvrable directement dans Excel)
// ------------------------------------------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['utilisateurs', 'ordinateurs'], true)) {
    $type = $_GET['export'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ad_' . $type . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM pour Excel
    if ($type === 'utilisateurs') {
        fputcsv($out, ['Login', 'Nom', 'Actif', 'Dernier logon', 'Jours', 'Dernier changement mdp', 'Jours', 'Mdp n\'expire jamais'], ';');
        foreach ($utilisateurs as $u) {
            fputcsv($out, [$u['login'], $u['nom'], $u['actif'] ? 'Oui' : 'Non',
                formatDate($u['logon']), joursDepuis($u['logon']),
                $u['mdpAChanger'] ? 'A changer' : formatDate($u['mdp']), joursDepuis($u['mdp']),
                $u['mdpExpire'] ? 'Oui' : 'Non'], ';');
        }
    } else {
        fputcsv($out, ['Ordinateur', 'Systeme', 'Actif', 'Derniere connexion', 'Jours', 'Dernier changement mdp machine'], ';');
        foreach ($ordinateurs as $o) {
            fputcsv($out, [$o['nom'], $o['os'], $o['actif'] ? 'Oui' : 'Non',
                formatDate($o['logon']), joursDepuis($o['logon']), formatDate($o['mdp'])], ';');
        }
    }
    fclose($out);
    exit;
}

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
            </tr>
        </thead>
        <tbody>
        <?php foreach ($utilisateurs as $u):
            $jLogon = joursDepuis($u['logon']);
            $jMdp = joursDepuis($u['mdp']);
            $clsMdp = ($u['mdpAChanger'] || $u['mdpExpire']) ? 'attention' : classeAnciennete($jMdp, SEUIL_MDP_JOURS);
        ?>
            <tr class="<?php echo $u['actif'] ? '' : 'desactive'; ?>">
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
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ordinateurs as $o):
            $jLogon = joursDepuis($o['logon']);
        ?>
            <tr class="<?php echo $o['actif'] ? '' : 'desactive'; ?>">
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
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

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
