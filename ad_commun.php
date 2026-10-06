<?php
// ============================================================
// Fonctions communes aux pages Active Directory de l'intranet
// (ad_derniers_logons.php, ad_groupes.php)
//
// Connexion a l'AD via l'extension PHP "ldap", avec un compte de
// service en lecture seule. Meme convention que le reste de
// l'intranet : parametres fournis via les variables d'environnement
// (fichier .env).
//   LDAP_HOST     : ldap://srv-dc1.acebesancon.lan  (ou ldaps://...)
//   LDAP_USER     : svc-intranet@acebesancon.lan
//   LDAP_PASSWORD : mot de passe du compte de service
//   LDAP_BASE_DN  : DC=acebesancon,DC=lan  (facultatif)
// ============================================================

// Attributs personnalises affiches dans le detail d'un objet (noms en minuscules)
const ATTRIBUTS_PERSO = ['azuser', 'azserv'];

const FILTRE_UTILISATEURS = '(&(objectCategory=person)(objectClass=user))';
const FILTRE_ORDINATEURS = '(objectCategory=computer)';
const FILTRE_GROUPES = '(objectCategory=group)';

// Les dates sont conservees en UTC dans l'AD : on les affiche en heure de Paris
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
            // Code "data xxx" renvoye par Active Directory : cause precise du refus
            $causes = [
                '525' => "compte introuvable (verifier LDAP_USER : compte@acebesancon.lan ou ACEBESANCON\\compte)",
                '52e' => "mot de passe incorrect (verifier LDAP_PASSWORD, et les guillemets dans le .env)",
                '52f' => "restriction sur le compte : groupe Protected Users, carte a puce obligatoire, ou mot de passe vide",
                '530' => "connexion interdite a cette heure (horaires d'acces du compte)",
                '531' => "connexion interdite depuis ce poste (onglet Compte > Se connecter a...)",
                '532' => "mot de passe expire",
                '533' => "compte desactive",
                '701' => "compte expire",
                '773' => "l'utilisateur doit changer son mot de passe a la prochaine ouverture de session",
                '775' => "compte verrouille",
            ];
            if (preg_match('/data ([0-9a-f]{3}),/i', $diag, $m) && isset($causes[strtolower($m[1])])) {
                $derniereErreurLdap .= "\n\nCause : " . $causes[strtolower($m[1])];
            }
        }
        return false;
    }
    return $ds;
}

// Connexion au DC principal (LDAP_HOST), arret de la page en cas d'echec
function adConnexionPrincipale() {
    global $ldapHost, $ldapUser, $ldapPass, $derniereErreurLdap;
    $ds = ldapConnexion($ldapHost, $ldapUser, $ldapPass);
    if (!$ds) {
        http_response_code(500);
        die("Erreur de connexion a l'Active Directory (" . htmlspecialchars($ldapHost) . ") : "
            . nl2br(htmlspecialchars($derniereErreurLdap)));
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

// Attribut multi-valeurs -> tableau
function attrMulti($entree, $nom) {
    $valeurs = [];
    if (isset($entree[$nom]['count'])) {
        for ($i = 0; $i < $entree[$nom]['count']; $i++) $valeurs[] = $entree[$nom][$i];
    }
    return $valeurs;
}

// whenCreated / whenChanged : "20240115103000.0Z" (UTC) -> timestamp Unix
function generalizedTimeVersTimestamp($v) {
    if (!preg_match('/^(\d{14})/', (string) $v, $m)) return null;
    $d = DateTime::createFromFormat('YmdHis', $m[1], new DateTimeZone('UTC'));
    return $d ? $d->getTimestamp() : null;
}

// Integer8 AD (intervalles de 100 ns depuis le 01/01/1601 UTC) -> timestamp Unix
// null si 0 (jamais) ou valeur "jamais d'expiration"
// Calcul en flottant : un PHP 32 bits (frequent sous Windows) ne sait pas
// manipuler ces entiers sur 64 bits, (int) les ramenerait a 0 ou a 2147483647.
function filetimeVersTimestamp($v) {
    $v = trim((string) $v);
    if ($v === '' || $v === '0' || $v === '9223372036854775807' || !ctype_digit($v)) return null;
    $ts = (float) $v / 10000000 - 11644473600;
    if ($ts <= 0) return null;
    return (int) floor($ts);
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

// DN -> liste de [type, valeur] : [['CN','Jean Dupont'], ['OU','Compta'], ['DC','acebesancon'], ...]
function decouperDn($dn) {
    $rdns = [];
    // decoupe sur les virgules non echappees
    foreach (preg_split('/(?<!\\\\),/', $dn) as $rdn) {
        $pos = strpos($rdn, '=');
        if ($pos === false) continue;
        // retire les echappements LDAP : "\," -> ","  et  "\2C" -> ","
        $valeur = preg_replace_callback('/\\\\([0-9A-Fa-f]{2}|.)/', function ($m) {
            return strlen($m[1]) === 2 ? chr(hexdec($m[1])) : $m[1];
        }, substr($rdn, $pos + 1));
        $rdns[] = [strtoupper(trim(substr($rdn, 0, $pos))), $valeur];
    }
    return $rdns;
}

// distinguishedName -> chemin complet facon "Utilisateurs et ordinateurs AD"
// CN=Jean Dupont,OU=Compta,OU=Cabinet,DC=acebesancon,DC=lan
//   -> acebesancon.lan/Cabinet/Compta/Jean Dupont
function cheminAD($dn) {
    $domaine = [];
    $chemin = [];
    foreach (decouperDn($dn) as $rdn) {
        if ($rdn[0] === 'DC') $domaine[] = $rdn[1];
        else $chemin[] = $rdn[1];
    }
    return implode('/', array_merge([implode('.', $domaine)], array_reverse($chemin)));
}

// Nom d'un objet a partir de son DN : "CN=Compta,OU=Groupes,DC=..." -> "Compta"
function nomDepuisDn($dn) {
    $rdns = decouperDn($dn);
    return $rdns ? $rdns[0][1] : $dn;
}

// RID (dernier nombre) d'un objectSid binaire : 513 pour "Utilisateurs du domaine"...
function ridDepuisSid($sid) {
    if (strlen($sid) < 12) return '';
    return (string) unpack('V', substr($sid, -4))[1];
}

// Groupes d'un objet : memberOf (groupes directs) + groupe principal
// (le groupe principal, ex. "Utilisateurs du domaine", n'apparait pas dans memberOf)
function groupesObjet($entree) {
    $groupes = [];
    foreach (attrMulti($entree, 'memberof') as $dnGroupe) {
        $groupes[] = ['nom' => nomDepuisDn($dnGroupe), 'chemin' => cheminAD($dnGroupe),
                      'dn' => strtolower($dnGroupe), 'rid' => null];
    }
    $principaux = [
        '513' => 'Utilisateurs du domaine', '514' => 'Invités du domaine',
        '515' => 'Ordinateurs du domaine', '516' => 'Contrôleurs de domaine',
        '521' => 'Contrôleurs de domaine en lecture seule',
    ];
    $rid = attr($entree, 'primarygroupid');
    if ($rid !== '') {
        $groupes[] = ['nom' => ($principaux[$rid] ?? 'Groupe RID ' . $rid) . ' (groupe principal)', 'chemin' => '',
                      'dn' => null, 'rid' => $rid];
    }
    return $groupes;
}

// Valeurs des attributs personnalises (ATTRIBUTS_PERSO)
function champsPerso($entree) {
    $champs = [];
    foreach (ATTRIBUTS_PERSO as $nom) {
        $champs[] = [$nom, implode(' | ', attrMulti($entree, $nom))];
    }
    return $champs;
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

// ------------------------------------------------------------
// Lecture des utilisateurs et ordinateurs, avec la date de dernier
// logon la plus recente tous DC confondus (lastLogon n'est PAS replique).
// Retourne [$utilisateurs, $ordinateurs, $dcs, $dcsInjoignables],
// les tableaux etant indexes par DN en minuscules.
// ------------------------------------------------------------
function adChargerComptes($ds) {
    global $baseDn, $ldapHost, $ldapUser, $ldapPass;

    $utilisateurs = [];
    foreach (ldapRechercheTout($ds, $baseDn, FILTRE_UTILISATEURS,
            array_merge(['samaccountname', 'displayname', 'useraccountcontrol', 'lastlogontimestamp', 'lastlogon', 'pwdlastset',
                         'sn', 'givenname', 'mail', 'telephonenumber', 'mobile', 'description', 'physicaldeliveryofficename',
                         'memberof', 'primarygroupid', 'whencreated', 'whenchanged'], ATTRIBUTS_PERSO)) as $e) {
        $dn = strtolower($e['dn']);
        $uac = (int) attr($e, 'useraccountcontrol');
        $utilisateurs[$dn] = [
            'id'          => 'u' . count($utilisateurs),
            'login'       => attr($e, 'samaccountname'),
            'chemin'      => cheminAD($e['dn']),
            'nom'         => attr($e, 'displayname'),
            'actif'       => !($uac & 2),                 // ACCOUNTDISABLE
            'mdpExpire'   => (bool) ($uac & 65536),       // DONT_EXPIRE_PASSWORD
            'logon'       => maxTs(filetimeVersTimestamp(attr($e, 'lastlogontimestamp')),
                                   filetimeVersTimestamp(attr($e, 'lastlogon'))),
            'mdp'         => filetimeVersTimestamp(attr($e, 'pwdlastset')),
            'mdpAChanger' => attr($e, 'pwdlastset') === '0',
            'ridPrincipal' => attr($e, 'primarygroupid'),
            'cree'        => generalizedTimeVersTimestamp(attr($e, 'whencreated')),
            'modifie'     => generalizedTimeVersTimestamp(attr($e, 'whenchanged')),
            'groupes'     => groupesObjet($e),
            'champs'      => [
                ['Login', attr($e, 'samaccountname')],
                ['Nom', attr($e, 'sn')],
                ['Prénom', attr($e, 'givenname')],
                ['Email', attr($e, 'mail')],
                ['Téléphone', attr($e, 'telephonenumber')],
                ['Mobile', attr($e, 'mobile')],
                ['Description', attr($e, 'description')],
                ['Bureau', attr($e, 'physicaldeliveryofficename')],
            ],
            'perso'       => champsPerso($e),
        ];
    }

    $ordinateurs = [];
    foreach (ldapRechercheTout($ds, $baseDn, FILTRE_ORDINATEURS,
            array_merge(['name', 'operatingsystem', 'operatingsystemversion', 'useraccountcontrol', 'lastlogontimestamp', 'lastlogon', 'pwdlastset',
                         'dnshostname', 'description', 'location', 'memberof', 'primarygroupid', 'whencreated', 'whenchanged'], ATTRIBUTS_PERSO)) as $e) {
        $dn = strtolower($e['dn']);
        $uac = (int) attr($e, 'useraccountcontrol');
        $ordinateurs[$dn] = [
            'id'      => 'o' . count($ordinateurs),
            'nom'     => attr($e, 'name'),
            'chemin'  => cheminAD($e['dn']),
            'os'      => trim(attr($e, 'operatingsystem') . ' ' . attr($e, 'operatingsystemversion')),
            'actif'   => !($uac & 2),
            'dc'      => (bool) ($uac & 8192),           // SERVER_TRUST_ACCOUNT
            'logon'   => maxTs(filetimeVersTimestamp(attr($e, 'lastlogontimestamp')),
                               filetimeVersTimestamp(attr($e, 'lastlogon'))),
            'mdp'     => filetimeVersTimestamp(attr($e, 'pwdlastset')),
            'ridPrincipal' => attr($e, 'primarygroupid'),
            'cree'    => generalizedTimeVersTimestamp(attr($e, 'whencreated')),
            'modifie' => generalizedTimeVersTimestamp(attr($e, 'whenchanged')),
            'groupes' => groupesObjet($e),
            'champs'  => [
                ['Nom', attr($e, 'name')],
                ['Nom DNS', attr($e, 'dnshostname')],
                ['Système', trim(attr($e, 'operatingsystem') . ' ' . attr($e, 'operatingsystemversion'))],
                ['Description', attr($e, 'description')],
                ['Emplacement', attr($e, 'location')],
            ],
            'perso'   => champsPerso($e),
        ];
    }

    // lastLogon : on interroge chaque autre DC et on garde la date la plus recente
    $schema = preg_match('#^ldaps://#i', $ldapHost) ? 'ldaps://' : 'ldap://';
    $hotePrincipal = strtolower(preg_replace('#^ldaps?://#i', '', rtrim($ldapHost, '/')));
    $hotePrincipal = preg_replace('#:\d+$#', '', $hotePrincipal);

    $dcs = [];
    $dcsInjoignables = [];
    foreach (ldapRechercheTout($ds, $baseDn,
            '(&(objectCategory=computer)(userAccountControl:1.2.840.113556.1.4.803:=8192))', ['dnshostname']) as $e) {
        $dcs[] = strtolower(attr($e, 'dnshostname'));
    }

    foreach ($dcs as $dc) {
        if ($dc === '' || $dc === $hotePrincipal) continue;
        $dsDc = ldapConnexion($schema . $dc, $ldapUser, $ldapPass);
        if (!$dsDc) {
            $dcsInjoignables[] = $dc;
            continue;
        }
        majLastLogon($dsDc, $baseDn, FILTRE_UTILISATEURS, $utilisateurs);
        majLastLogon($dsDc, $baseDn, FILTRE_ORDINATEURS, $ordinateurs);
        ldap_unbind($dsDc);
    }

    return [$utilisateurs, $ordinateurs, $dcs, $dcsInjoignables];
}

// ------------------------------------------------------------
// Details de chaque objet pour la fenetre de detail (cle = id de l'objet).
// $groupes (facultatif, page des groupes) : permet de rendre les groupes
// cliquables dans la fiche d'un objet.
// ------------------------------------------------------------
function adDetailsObjets($utilisateurs, $ordinateurs, $groupes = []) {
    $ridVersGroupe = [];
    foreach ($groupes as $g) {
        if ($g['rid'] !== '') $ridVersGroupe[$g['rid']] = $g;
    }

    $details = [];
    foreach ([$utilisateurs, $ordinateurs] as $liste) {
        foreach ($liste as $obj) {
            $titre = isset($obj['login'])
                ? trim($obj['nom'] . ' (' . $obj['login'] . ')')
                : $obj['nom'];

            // [nom, chemin, id du groupe ou null]
            $listeGroupes = [];
            foreach ($obj['groupes'] as $g) {
                if ($g['dn'] !== null && isset($groupes[$g['dn']])) {
                    $listeGroupes[] = [$g['nom'], $g['chemin'], $groupes[$g['dn']]['id']];
                } elseif ($g['rid'] !== null && isset($ridVersGroupe[$g['rid']])) {
                    $gp = $ridVersGroupe[$g['rid']];
                    $listeGroupes[] = [$gp['nom'] . ' (groupe principal)', $gp['chemin'], $gp['id']];
                } else {
                    $listeGroupes[] = [$g['nom'], $g['chemin'], null];
                }
            }
            usort($listeGroupes, function ($a, $b) { return strcasecmp($a[0], $b[0]); });

            $details[$obj['id']] = [
                'titre'   => $titre,
                'champs'  => array_merge($obj['champs'], [
                    ['Chemin AD', $obj['chemin']],
                    ['Dernière connexion', $obj['logon'] === null ? 'Jamais' : formatDate($obj['logon'])],
                    ['Création de l\'objet', formatDate($obj['cree'])],
                    ['Modification de l\'objet', formatDate($obj['modifie'])],
                ]),
                'perso'   => $obj['perso'],
                'groupes' => $listeGroupes,
            ];
        }
    }
    return $details;
}
