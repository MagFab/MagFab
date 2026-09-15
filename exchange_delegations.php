<?php
// ============================================================
// Delegations des boites mails Exchange (acces complet, envoyer
// en tant que, envoyer de la part de) pour toutes les boites
// mails utilisateur.
//
// Les identifiants Exchange sont saisis a chaque consultation
// (rien n'est stocke cote serveur) et transmis au script
// PowerShell exchange_delegations.ps1 uniquement via son entree
// standard (jamais en argument de commande ni en GET), afin de
// ne jamais apparaitre dans les journaux ou la liste des
// processus.
// ============================================================

include 'sommaire.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Intranet accessible uniquement en HTTP sur le reseau interne (pas de
// certificat HTTPS sur be-intra16) : le controle HTTPS a ete retire ici,
// en connaissance de cause, car le mot de passe Exchange transite alors
// en clair sur le LAN interne le temps de la requete POST.

$erreur = null;
$mailboxes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['login'], $_POST['password']) || trim($_POST['login']) === '' || $_POST['password'] === '') {
        $erreur = "Identifiant et mot de passe requis.";
    } else {
        $login = trim($_POST['login']);
        $password = $_POST['password'];

        $script = __DIR__ . DIRECTORY_SEPARATOR . 'exchange_delegations.ps1';
        $cmd = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
             . escapeshellarg($script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            $erreur = "Impossible de lancer PowerShell sur le serveur.";
        } else {
            $payload = json_encode(['login' => $login, 'password' => $password]);
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            // Le mot de passe ne doit pas rester en memoire plus que necessaire.
            $password = str_repeat('x', strlen($password));
            unset($password, $payload);

            $data = json_decode($stdout, true);
            if ($data === null) {
                // Diagnostic temporaire : a retirer une fois le flux valide.
                $erreur = "Reponse invalide du script PowerShell (code retour $exitCode).\n"
                        . "--- stdout ---\n" . ($stdout !== '' ? $stdout : '(vide)') . "\n"
                        . "--- stderr ---\n" . ($stderr !== '' ? $stderr : '(vide)');
            } elseif (isset($data['error'])) {
                $erreur = $data['error'];
            } else {
                $mailboxes = $data['mailboxes'] ?? [];
            }
        }
    }
}

function formatDelegations($m) {
    $parties = [];
    if (!empty($m['accesComplet'])) {
        $parties[] = 'Acces complet : ' . implode(', ', $m['accesComplet']);
    }
    if (!empty($m['envoyerEnTantQue'])) {
        $parties[] = 'Envoyer en tant que : ' . implode(', ', $m['envoyerEnTantQue']);
    }
    if (!empty($m['envoyerDeLaPartDe'])) {
        $parties[] = 'Envoyer de la part de : ' . implode(', ', $m['envoyerDeLaPartDe']);
    }
    return $parties ? implode(' \xe2\x80\x94 ', $parties) : '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Delegations des boites mails Exchange</title>
<style>
    body { font-family: Segoe UI, Arial, sans-serif; background: #f5f6f8; color: #222; margin: 0; padding: 30px; }
    h1 { font-size: 20px; margin-bottom: 4px; }
    .sous-titre { color: #666; font-size: 13px; margin-bottom: 20px; }
    .carte { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 20px; box-sizing: border-box; width: 100%; margin-bottom: 30px; }
    .erreur { background: #fdecea; color: #611a15; border: 1px solid #f5c6cb; border-radius: 6px; padding: 12px 16px; margin-bottom: 20px; }
    form.form-login label { font-size: 13px; color: #444; }
    form.form-login input { padding: 7px 10px; font-size: 14px; border-radius: 5px; border: 1px solid #ccc; box-sizing: border-box; }
    form.form-login button { margin-top: 10px; padding: 7px 16px; font-size: 14px; background: #3d7ab8; color: #fff; border: none; border-radius: 5px; cursor: pointer; }
    form.form-login button:hover { background: #2c5d90; }
    form.form-login > div { margin-bottom: 10px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 6px 12px; text-align: left; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
    th { color: #666; font-weight: 600; font-size: 12px; }
    table.sortable th { cursor: pointer; user-select: none; position: relative; padding-right: 18px; }
    table.sortable th.tri-asc::after { content: " \25B2"; font-size: 10px; }
    table.sortable th.tri-desc::after { content: " \25BC"; font-size: 10px; }
    .lien-changer { font-size: 13px; margin-bottom: 20px; display: inline-block; }
</style>
</head>
<body>

<h1>Delegations des boites mails Exchange</h1>
<div class="sous-titre">Serveur Exchange : BE-EXCHANGE.acebesancon.lan</div>

<?php if ($erreur): ?>
    <div class="erreur"><pre style="white-space:pre-wrap;margin:0;font-family:inherit;"><?php echo htmlspecialchars($erreur); ?></pre></div>
<?php endif; ?>

<?php if ($mailboxes === null): ?>

    <div class="carte" style="max-width:380px;">
        <form class="form-login" method="post" autocomplete="off">
            <div>
                <label>Identifiant Exchange</label><br>
                <input type="text" name="login" style="width:100%;" placeholder="domaine\administrateur" required>
            </div>
            <div>
                <label>Mot de passe</label><br>
                <input type="password" name="password" style="width:100%;" autocomplete="new-password" required>
            </div>
            <button type="submit">Afficher les delegations</button>
        </form>
    </div>

<?php else: ?>

    <div class="sous-titre">
        <?php echo count($mailboxes); ?> boite(s) mail &mdash; genere le <?php echo date("d/m/Y H:i"); ?>
    </div>
    <a class="lien-changer" href="exchange_delegations.php">&laquo; Nouvelle recherche</a>

    <div class="carte">
        <table class="sortable">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Prenom</th>
                    <th>Login</th>
                    <th>Email</th>
                    <th>Delegations</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($mailboxes as $m): ?>
                <tr>
                    <td><?php echo htmlspecialchars($m['nom'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($m['prenom'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($m['login'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($m['email'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars(formatDelegations($m)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

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
                var ta = a.children[index].textContent.trim();
                var tb = b.children[index].textContent.trim();
                var cmp = ta.localeCompare(tb, 'fr', { sensitivity: 'base' });
                return asc ? cmp : -cmp;
            });

            rows.forEach(function (row) { tbody.appendChild(row); });
        });
    });
});
</script>

</body>
</html>
