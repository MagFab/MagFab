<?php
require '../db.php';
include_once '../error/errorHandler.php';
include 'sommaire.php';

// ============================================================
// Connexion a la base de donnees
// Meme convention que le reste de l'intranet : identifiants
// fournis via les variables d'environnement ($_ENV).
// ============================================================
$conn = mysqli_connect(
    $_ENV['DB_HOST4'],
    $_ENV['DB_USERNAME'],
    $_ENV['DB_PASSWORD'],
    $_ENV['DB_DATABASE4']
);

if (!$conn) {
    http_response_code(500);
    die("Erreur de connexion a MariaDB : " . htmlspecialchars(mysqli_connect_error()));
}

function toUtf8($s) {
    return mb_convert_encoding((string) $s, 'UTF-8', 'ISO-8859-1');
}

// TEMPS_DATE : varchar(8) format YYYYMMDD -> jj/mm/aaaa
function formatDate8($d) {
    if (!preg_match('/^[0-9]{8}$/', $d)) return '';
    return substr($d, 6, 2) . '/' . substr($d, 4, 2) . '/' . substr($d, 0, 4);
}

function formatHeures($v) {
    return number_format((float) $v, 2, ',', ' ');
}

// ------------------------------------------------------------
// Liste des exercices disponibles (pour le formulaire de choix)
// EXO_CODE est stocke sous la forme "AAAA/N" (ex: 2023/2) ; seuls
// les 4 premiers caracteres (l'annee) nous interessent ici.
// ------------------------------------------------------------
$exercices = [];
$resExo = mysqli_query($conn, "SELECT DISTINCT LEFT(EXO_CODE, 4) AS EXO_CODE FROM temps ORDER BY EXO_CODE DESC");
while ($row = mysqli_fetch_assoc($resExo)) {
    $exercices[] = $row['EXO_CODE'];
}

$exoChoisi = null;
if (isset($_GET['exo']) && in_array($_GET['exo'], $exercices, true)) {
    $exoChoisi = $_GET['exo'];
}

$dossiers = [];
$totalHeures = 0;
$totalSaisies = 0;

$detailAdrId = null;
$detailDossier = null;
$detailRows = null;

if ($exoChoisi !== null) {
    // --- Stats : temps total par dossier pour l'exercice choisi ---
    $stmt = mysqli_prepare($conn, "SELECT adresse.ADR_ID, adresse.ADR_CODE, adresse.ADR_NOM, adresse.ADR_PRENOM,
                                           SUM(temps.TEMPS_DUREE) AS total_duree, COUNT(*) AS nb_saisies
                                    FROM temps
                                    INNER JOIN adresse ON adresse.ADR_ID = temps.ADR_ID
                                    WHERE LEFT(temps.EXO_CODE, 4) = ?
                                    AND adresse.GENRE_CODE NOT LIKE 'PERSO%'
                                    AND adresse.GENRE_CODE NOT LIKE 'CLIENTPAR%'
                                    GROUP BY adresse.ADR_ID, adresse.ADR_CODE, adresse.ADR_NOM, adresse.ADR_PRENOM
                                    ORDER BY total_duree DESC");
    mysqli_stmt_bind_param($stmt, "s", $exoChoisi);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $duree = (float) $row['total_duree'];
        $dossiers[] = [
            'adr_id' => (int) $row['ADR_ID'],
            'code' => $row['ADR_CODE'],
            'nom' => $row['ADR_NOM'],
            'prenom' => $row['ADR_PRENOM'],
            'duree' => $duree,
            'nb' => (int) $row['nb_saisies'],
        ];
        $totalHeures += $duree;
        $totalSaisies += (int) $row['nb_saisies'];
    }
    mysqli_stmt_close($stmt);

    // --- Detail : saisies de temps du dossier clique, pour cet exercice ---
    if (isset($_GET['adr_id']) && ctype_digit($_GET['adr_id'])) {
        $detailAdrId = (int) $_GET['adr_id'];
        foreach ($dossiers as $d) {
            if ($d['adr_id'] === $detailAdrId) {
                $detailDossier = $d;
                break;
            }
        }

        if ($detailDossier !== null) {
            $stmt = mysqli_prepare($conn, "SELECT COL_CODE, TEMPS_DATE, PREST_CODE, TEMPS_DUREE, TEMPS_MEMO
                                            FROM temps
                                            WHERE ADR_ID = ? AND LEFT(EXO_CODE, 4) = ?
                                            ORDER BY TEMPS_DATE, TEMPS_ID");
            mysqli_stmt_bind_param($stmt, "is", $detailAdrId, $exoChoisi);
            mysqli_stmt_execute($stmt);
            $detailResult = mysqli_stmt_get_result($stmt);
            $detailRows = [];
            while ($row = mysqli_fetch_assoc($detailResult)) {
                $detailRows[] = $row;
            }
            mysqli_stmt_close($stmt);
        }
    }
}

mysqli_close($conn);

// --- Generation d'un graphique en barres SVG (cote serveur, pas de dependance JS/CDN) ---
// Limite aux N plus gros dossiers pour rester lisible ; le tableau en dessous liste tout.
function renderBarChart($data, $maxDuree, $exo, $chartWidth = 900, $chartHeight = 380) {
    $marginLeft = 60;
    $marginRight = 20;
    $marginTop = 30;
    $marginBottom = 80;
    $plotWidth = $chartWidth - $marginLeft - $marginRight;
    $plotHeight = $chartHeight - $marginTop - $marginBottom;

    $barCount = count($data);
    if ($barCount === 0) {
        return "<p>Aucune donnee trouvee.</p>";
    }

    $barGap = 6;
    $barWidth = ($plotWidth - ($barGap * ($barCount - 1))) / $barCount;
    $barWidth = max($barWidth, 3);

    $svg = "<svg width=\"$chartWidth\" height=\"$chartHeight\" viewBox=\"0 0 $chartWidth $chartHeight\">";
    $svg .= "<line x1=\"$marginLeft\" y1=\"" . ($marginTop + $plotHeight) . "\" x2=\"" . ($marginLeft + $plotWidth) . "\" y2=\"" . ($marginTop + $plotHeight) . "\" stroke=\"#ccc\" stroke-width=\"1\" />";

    foreach ($data as $i => $d) {
        $x = $marginLeft + $i * ($barWidth + $barGap);
        $barH = $maxDuree > 0 ? ($d['duree'] / $maxDuree) * $plotHeight : 0;
        $y = $marginTop + $plotHeight - $barH;
        $code = htmlspecialchars($d['code']);
        $heures = formatHeures($d['duree']);
        $href = "?exo=" . urlencode($exo) . "&adr_id=" . $d['adr_id'] . "#detail";

        $svg .= "<a href=\"$href\">";
        $svg .= "<rect class=\"barre\" x=\"$x\" y=\"$y\" width=\"$barWidth\" height=\"$barH\"><title>$code : $heures h - cliquer pour le detail</title></rect>";
        if ($barWidth > 14) {
            $svg .= "<text class=\"annee-label\" x=\"" . ($x + $barWidth / 2) . "\" y=\"" . ($marginTop + $plotHeight + 20) . "\" transform=\"rotate(45, " . ($x + $barWidth / 2) . ", " . ($marginTop + $plotHeight + 20) . ")\">$code</text>";
        }
        $svg .= "</a>";
    }

    $svg .= "</svg>";
    return $svg;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Temps passe par dossier</title>
<style>
    body {
        font-family: Segoe UI, Arial, sans-serif;
        background: #f5f6f8;
        color: #222;
        margin: 0;
        padding: 30px;
    }
    h1 { font-size: 20px; margin-bottom: 4px; }
    h2 { font-size: 16px; margin: 0 0 10px 0; }
    .sous-titre { color: #666; font-size: 13px; margin-bottom: 20px; }
    .carte {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.1);
        padding: 20px;
        box-sizing: border-box;
        width: 100%;
        margin-bottom: 30px;
    }
    .carte svg { width: 100%; height: auto; display: block; }
    svg text { font-size: 11px; fill: #333; }
    svg a { cursor: pointer; }
    .barre { fill: #7c5cbf; }
    .barre:hover { fill: #5e3fa0; }
    .annee-label { font-size: 11px; fill: #444; text-anchor: end; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 6px 12px; text-align: left; border-bottom: 1px solid #e5e5e5; }
    th { color: #666; font-weight: 600; font-size: 12px; text-align: left; }
    table.sortable th {
        cursor: pointer;
        user-select: none;
        position: relative;
        padding-right: 18px;
    }
    table.sortable th.tri-asc::after { content: " \25B2"; font-size: 10px; }
    table.sortable th.tri-desc::after { content: " \25BC"; font-size: 10px; }
    .detail-entete {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
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
    form.form-exo {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }
    form.form-exo select {
        padding: 6px 10px;
        font-size: 14px;
        border-radius: 5px;
        border: 1px solid #ccc;
    }
    form.form-exo button {
        padding: 7px 16px;
        font-size: 14px;
        background: #3d7ab8;
        color: #fff;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    }
    form.form-exo button:hover { background: #2c5d90; }
    .lien-changer { font-size: 13px; margin-bottom: 20px; display: inline-block; }
    td.num { text-align: right; }
    th.num { text-align: right; }
</style>
</head>
<body>

<h1>Temps passe par dossier</h1>

<?php if ($exoChoisi === null): ?>

    <div class="sous-titre">Choisissez l'exercice a traiter.</div>
    <div class="carte" style="max-width:500px;">
        <form class="form-exo" method="get">
            <label for="exo">Exercice :</label>
            <select name="exo" id="exo" required>
                <option value="">-- choisir --</option>
                <?php foreach ($exercices as $exo): ?>
                    <option value="<?php echo htmlspecialchars($exo); ?>"><?php echo htmlspecialchars($exo); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Voir les statistiques</button>
        </form>
    </div>

<?php else: ?>

    <div class="sous-titre">
        Exercice <?php echo htmlspecialchars($exoChoisi); ?>
        &mdash; <?php echo count($dossiers); ?> dossier(s), <?php echo formatHeures($totalHeures); ?> heure(s) au total, <?php echo $totalSaisies; ?> saisie(s)
        &mdash; genre PERSO / CLIENTPAR exclus
        &mdash; genere le <?php echo date("d/m/Y H:i"); ?>
    </div>
    <a class="lien-changer" href="temps_par_dossier.php">&laquo; Changer d'exercice</a>

    <div class="carte">
        <h2>Repartition du temps par dossier (top <?php echo min(40, count($dossiers)); ?>)</h2>
        <?php echo renderBarChart(array_slice($dossiers, 0, 40), count($dossiers) ? $dossiers[0]['duree'] : 0, $exoChoisi); ?>
    </div>

    <div class="carte">
        <div class="detail-entete">
            <h2>Tous les dossiers (<?php echo count($dossiers); ?>)</h2>
            <a class="bouton-export" href="export_temps_dossier.php?exo=<?php echo urlencode($exoChoisi); ?>">Exporter vers Excel</a>
        </div>
        <table class="sortable">
            <thead>
                <tr>
                    <th>Code dossier</th>
                    <th>Nom</th>
                    <th>Prenom</th>
                    <th class="num">Heures</th>
                    <th class="num">Nb saisies</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dossiers as $d): ?>
                <tr>
                    <td><a href="?exo=<?php echo urlencode($exoChoisi); ?>&adr_id=<?php echo $d['adr_id']; ?>#detail"><?php echo htmlspecialchars($d['code']); ?></a></td>
                    <td><?php echo htmlspecialchars(toUtf8($d['nom'])); ?></td>
                    <td><?php echo htmlspecialchars(toUtf8($d['prenom'])); ?></td>
                    <td class="num" data-sort="<?php echo $d['duree']; ?>"><?php echo formatHeures($d['duree']); ?></td>
                    <td class="num" data-sort="<?php echo $d['nb']; ?>"><?php echo $d['nb']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($detailAdrId !== null): ?>
    <div class="carte" id="detail">
        <?php if ($detailDossier === null): ?>
            <p>Dossier introuvable pour cet exercice.</p>
        <?php else: ?>
            <div class="detail-entete">
                <h2>
                    Detail - <?php echo htmlspecialchars($detailDossier['code']); ?>
                    - <?php echo htmlspecialchars(toUtf8($detailDossier['nom'])); ?> <?php echo htmlspecialchars(toUtf8($detailDossier['prenom'])); ?>
                    (<?php echo count($detailRows); ?> saisie<?php echo count($detailRows) > 1 ? 's' : ''; ?>, <?php echo formatHeures($detailDossier['duree']); ?> h)
                </h2>
                <a class="bouton-export"
                   href="export_temps_detail.php?exo=<?php echo urlencode($exoChoisi); ?>&adr_id=<?php echo $detailAdrId; ?>">
                    Exporter vers Excel
                </a>
            </div>
            <?php if (count($detailRows) === 0): ?>
                <p>Aucune saisie trouvee.</p>
            <?php else: ?>
                <table class="sortable">
                    <thead>
                        <tr>
                            <th>Collaborateur</th>
                            <th>Date</th>
                            <th>Prestation</th>
                            <th class="num">Duree (h)</th>
                            <th>Memo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($detailRows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['COL_CODE']); ?></td>
                            <td data-sort="<?php echo htmlspecialchars($row['TEMPS_DATE']); ?>"><?php echo htmlspecialchars(formatDate8($row['TEMPS_DATE'])); ?></td>
                            <td><?php echo htmlspecialchars($row['PREST_CODE']); ?></td>
                            <td class="num" data-sort="<?php echo $row['TEMPS_DUREE']; ?>"><?php echo formatHeures($row['TEMPS_DUREE']); ?></td>
                            <td><?php echo htmlspecialchars(toUtf8($row['TEMPS_MEMO'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

<script>
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
</script>

</body>
</html>
