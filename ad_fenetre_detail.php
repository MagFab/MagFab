<?php
// ============================================================
// Fenetre de detail des objets AD, partagee par les pages
// ad_derniers_logons.php et ad_groupes.php.
//
// A inclure en fin de page, apres avoir prepare :
//   $details        : fiches des utilisateurs / ordinateurs (adDetailsObjets)
//   $detailsGroupes : fiches des groupes (facultatif, page des groupes)
//
// Javascript : ouvrirFiche('objet', id) ou ouvrirFiche('groupe', id).
// Dans une fiche, les groupes et les membres sont cliquables ; le
// bouton "Retour" revient a la fiche precedente.
// ============================================================
$detailsGroupes = $detailsGroupes ?? [];
$optionsJson = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<style>
    .fond-detail {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.4);
        z-index: 1000;
        align-items: flex-start;
        justify-content: center;
        overflow-y: auto;
        padding: 40px 16px;
    }
    .fond-detail.ouvert { display: flex; }
    .fenetre-detail {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.25);
        width: 100%;
        max-width: 760px;
        padding: 20px 24px;
        box-sizing: border-box;
    }
    .fenetre-detail .entete-detail {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
    }
    .fenetre-detail .entete-detail h2 { font-size: 16px; margin: 0; }
    .fenetre-detail .boutons-detail { display: flex; gap: 8px; flex-shrink: 0; }
    .fenetre-detail h3 { font-size: 13px; color: #666; margin: 18px 0 6px 0; text-transform: uppercase; }
    .fenetre-detail table { border-collapse: collapse; width: 100%; }
    .fenetre-detail table td { font-size: 13px; vertical-align: top; padding: 5px 10px; border-bottom: 1px solid #eee; }
    .fenetre-detail table td:first-child { color: #666; width: 200px; white-space: nowrap; }
    .fenetre-detail .vide { color: #bbb; }
    .fenetre-detail ul { margin: 0; padding-left: 18px; font-size: 13px; columns: 2; }
    .fenetre-detail ul.membres { columns: 1; list-style: none; padding-left: 0; }
    .fenetre-detail li { margin-bottom: 3px; break-inside: avoid; }
    .fenetre-detail ul.membres li { padding: 3px 0; border-bottom: 1px solid #f0f0f0; }
    .fenetre-detail a.lien-fiche { color: #2c5d90; text-decoration: none; }
    .fenetre-detail a.lien-fiche:hover { text-decoration: underline; }
    .fenetre-detail .type-membre {
        display: inline-block;
        min-width: 78px;
        font-size: 11px;
        padding: 1px 7px;
        margin-right: 8px;
        border-radius: 10px;
        background: #eee;
        color: #666;
        text-align: center;
    }
    .fenetre-detail .type-membre.g { background: #efe7fb; color: #5e3fa0; }
    .fenetre-detail .type-membre.u { background: #e3ecf7; color: #2c5d90; }
    .fenetre-detail .type-membre.o { background: #e3f3ea; color: #1d6f42; }
    .fenetre-detail .chemin-membre { color: #999; font-size: 11px; margin-left: 6px; }
    .fenetre-detail .membre-desactive { color: #999; }
    .bouton-fermer {
        background: #eee;
        border: none;
        border-radius: 5px;
        padding: 6px 12px;
        font-size: 13px;
        cursor: pointer;
    }
    .bouton-fermer:hover { background: #ddd; }
    @media (max-width: 600px) {
        .fenetre-detail ul { columns: 1; }
        .fenetre-detail table td:first-child { width: auto; white-space: normal; }
        .fenetre-detail .chemin-membre { display: block; margin-left: 0; }
    }
</style>

<div class="fond-detail" id="fond-detail">
    <div class="fenetre-detail" role="dialog" aria-modal="true">
        <div class="entete-detail">
            <h2 id="detail-titre"></h2>
            <div class="boutons-detail">
                <button type="button" class="bouton-fermer" id="detail-retour">&larr; Retour</button>
                <button type="button" class="bouton-fermer" id="detail-fermer">Fermer</button>
            </div>
        </div>
        <div id="detail-contenu"></div>
    </div>
</div>

<script>
// Fiches AD (generees par PHP)
var DETAILS_AD = <?php echo json_encode((object) $details, $optionsJson); ?>;
var GROUPES_AD = <?php echo json_encode((object) $detailsGroupes, $optionsJson); ?>;

// Pile des fiches ouvertes, pour le bouton Retour
var pileFiches = [];

function el(balise, classe, texte) {
    var e = document.createElement(balise);
    if (classe) e.className = classe;
    if (texte !== undefined && texte !== null) e.textContent = texte;
    return e;
}

function lienFiche(texte, type, id) {
    var a = el('a', 'lien-fiche', texte);
    a.href = '#';
    a.addEventListener('click', function (e) {
        e.preventDefault();
        ouvrirFiche(type, id);
    });
    return a;
}

function tableChamps(champs) {
    var table = el('table');
    champs.forEach(function (c) {
        var tr = el('tr');
        tr.appendChild(el('td', '', c[0]));
        var vide = (c[1] === '' || c[1] === null);
        tr.appendChild(el('td', vide ? 'vide' : '', vide ? '—' : c[1]));
        table.appendChild(tr);
    });
    return table;
}

// Liste de groupes [nom, chemin, idGroupe|null] : cliquables si la fiche du groupe existe
function listeGroupes(groupes) {
    var ul = el('ul');
    groupes.forEach(function (g) {
        var li = el('li');
        if (g[2] && GROUPES_AD[g[2]]) li.appendChild(lienFiche(g[0], 'groupe', g[2]));
        else li.textContent = g[0];
        if (g[1]) li.title = g[1];   // chemin complet au survol
        ul.appendChild(li);
    });
    if (groupes.length === 0) ul.appendChild(el('li', 'vide', 'Aucun'));
    return ul;
}

var LIBELLES_TYPES = { g: 'Groupe', u: 'Utilisateur', o: 'Ordinateur', x: 'Autre' };

// Membres d'un groupe : {t: g|u|o|x, nom, chemin, id, desactive}
function listeMembres(membres) {
    var ul = el('ul', 'membres');
    membres.forEach(function (m) {
        var li = el('li', m.desactive ? 'membre-desactive' : '');
        li.appendChild(el('span', 'type-membre ' + m.t, LIBELLES_TYPES[m.t]));
        if (m.t === 'g' && GROUPES_AD[m.id]) li.appendChild(lienFiche(m.nom, 'groupe', m.id));
        else if ((m.t === 'u' || m.t === 'o') && DETAILS_AD[m.id]) li.appendChild(lienFiche(m.nom, 'objet', m.id));
        else li.appendChild(document.createTextNode(m.nom));
        if (m.desactive) li.appendChild(el('span', 'chemin-membre', '(désactivé)'));
        if (m.chemin) li.appendChild(el('span', 'chemin-membre', m.chemin));
        ul.appendChild(li);
    });
    if (membres.length === 0) ul.appendChild(el('li', 'vide', 'Aucun membre'));
    return ul;
}

function afficherFicheCourante() {
    var courante = pileFiches[pileFiches.length - 1];
    var contenu = document.getElementById('detail-contenu');
    contenu.innerHTML = '';
    document.getElementById('detail-retour').style.display = pileFiches.length > 1 ? '' : 'none';

    if (courante.type === 'groupe') {
        var g = GROUPES_AD[courante.id];
        document.getElementById('detail-titre').textContent = 'Groupe : ' + g.titre;
        contenu.appendChild(tableChamps(g.champs));
        contenu.appendChild(el('h3', '', 'Membres (' + g.membres.length + ')'));
        contenu.appendChild(listeMembres(g.membres));
        contenu.appendChild(el('h3', '', 'Membre de (' + g.membreDe.length + ')'));
        contenu.appendChild(listeGroupes(g.membreDe));
    } else {
        var d = DETAILS_AD[courante.id];
        document.getElementById('detail-titre').textContent = d.titre;
        contenu.appendChild(tableChamps(d.champs));
        contenu.appendChild(el('h3', '', 'Attributs personnalisés'));
        contenu.appendChild(tableChamps(d.perso));
        contenu.appendChild(el('h3', '', 'Groupes (' + d.groupes.length + ')'));
        contenu.appendChild(listeGroupes(d.groupes));
    }
    document.getElementById('fond-detail').classList.add('ouvert');
    document.querySelector('.fond-detail').scrollTop = 0;
}

// type : 'objet' ou 'groupe' ; depuis le tableau (nouvelle=true) on repart d'une pile vide
function ouvrirFiche(type, id, nouvelle) {
    var source = type === 'groupe' ? GROUPES_AD : DETAILS_AD;
    if (!source[id]) return;
    if (nouvelle) pileFiches = [];
    pileFiches.push({ type: type, id: id });
    afficherFicheCourante();
}

function fermerDetail() {
    pileFiches = [];
    document.getElementById('fond-detail').classList.remove('ouvert');
}

document.getElementById('detail-retour').addEventListener('click', function () {
    if (pileFiches.length > 1) {
        pileFiches.pop();
        afficherFicheCourante();
    }
});
document.getElementById('detail-fermer').addEventListener('click', fermerDetail);
document.getElementById('fond-detail').addEventListener('click', function (e) {
    if (e.target === this) fermerDetail();   // clic en dehors de la fenetre
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') fermerDetail();
});

// Lignes de tableau cliquables : <tr class="ligne-objet" data-type="objet|groupe" data-id="...">
document.querySelectorAll('tr.ligne-objet').forEach(function (tr) {
    tr.addEventListener('click', function () {
        ouvrirFiche(tr.getAttribute('data-type') || 'objet', tr.getAttribute('data-id'), true);
    });
});
</script>
