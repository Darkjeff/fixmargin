<?php
/* Copyright (C) 2026 Jeffinfo - Olivier Geffroy <jeff@jeffinfo.com> */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp)-1; $j = strlen($tmp2)-1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp,0,($i+1))."/main.inc.php")) $res = @include substr($tmp,0,($i+1))."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/fixmargin/class/FixMarginProductionGroupe.class.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';

// Compatibility shim for Dolibarr < 14
if (!function_exists('checkToken')) {
    function checkToken() {
        $token = GETPOST('token', 'alpha');
        if (empty($token) || empty($_SESSION['newtoken']) || $token !== $_SESSION['newtoken']) {
            accessforbidden('SecurityTokenMismatch');
        }
    }
}

if (!isModEnabled('mrp')) accessforbidden();
if (!$user->hasRight('mrp', 'read')) accessforbidden();

$langs->loadLangs(array('fixmargin@fixmargin', 'mrp'));

$groupe_id = GETPOST('groupe_id', 'int');
$action    = GETPOST('action', 'aZ09');
$backurl   = dol_buildpath('/fixmargin/production_masse.php', 1);

// Load group
$g = new FixMarginProductionGroupe($db);
if ($g->fetch($groupe_id) <= 0) {
    setEventMessages($langs->trans('GroupeIntrouvable'), null, 'errors');
    header('Location: '.$backurl); exit;
}

// ─────────────────────────────────────────────────────────────
// ACTION : Créer les OF
// ─────────────────────────────────────────────────────────────
$created_mos = array(); // for summary

if ($action === 'create_of' && $user->hasRight('mrp', 'write')) {
    checkToken();

    $total_theo  = 0;
    $total_reel  = 0;
    $errors_list = array();

    foreach ($g->derives as $d) {
        if (!$d->actif) continue;
        $checked = GETPOST('derive_check_'.$d->id, 'int');
        if (!$checked) continue;

        $qty            = price2num(GETPOST('qty_'.$d->id, 'alpha'));
        $qte_reel_fut   = price2num(GETPOST('qte_reel_fut_'.$d->id, 'alpha'));
        $prix_fab       = price2num(GETPOST('prix_fab_'.$d->id, 'alpha'));

        if ($qty <= 0) continue;

        // Doublon check
        $existing_mo_id = $g->checkExistingOpenMo($d->fk_product);
        if ($existing_mo_id > 0) {
            $mo_url = dol_buildpath('/mrp/mo.php?id='.$existing_mo_id, 1);
            $errors_list[] = $langs->trans('WarningDoublonOF', dol_escape_htmltag($d->product_ref), '<a href="'.$mo_url.'">MO#'.$existing_mo_id.'</a>');
        }

        // Create MO
        $mo = new Mo($db);
        $mo->fk_product         = (int)$d->fk_product;
        $mo->qty                = (float)$qty;
        $mo->fk_bom             = !empty($d->fk_bom) ? (int)$d->fk_bom : null;
        $mo->fk_warehouse       = !empty($g->fk_warehouse) ? (int)$g->fk_warehouse : null;
        $mo->date_start_planned = dol_now();
        $mo->date_end_planned   = dol_now();
        $mo->label              = $g->label.' - '.dol_escape_htmltag($d->product_ref);
        $mo->mrptype            = 0; // Manufacturing
        $mo->status             = Mo::STATUS_DRAFT;

        // Prix de fabrication → note_private
        $note_lines = array();
        if (!empty($qte_reel_fut)) {
            $total_reel += (float)$qte_reel_fut;
            $note_lines[] = $langs->trans('QteFutReelle').' : '.price2num($qte_reel_fut, 'MS').' L';
        }
        if (!empty($prix_fab)) {
            $note_lines[] = $langs->trans('PrixFabUnitaire').' : '.price2num($prix_fab, 'MU');
        }
        if (!empty($note_lines)) $mo->note_private = implode("\n", $note_lines);

        $total_theo += (float)$qty * (float)$d->volume_unitaire;

        $result = $mo->create($user);
        if ($result > 0) {
            $mo->fetch($result);

            // Si qte_reel_fut renseignée, mettre à jour la ligne toconsume avec la quantité réelle
            if (!empty($qte_reel_fut) && $qte_reel_fut > 0) {
                require_once DOL_DOCUMENT_ROOT.'/mrp/class/moline.class.php';
                $mo->fetchLines();
                foreach ($mo->lines as $moline) {
                    if ($moline->role == 'toconsume') {
                        $moline->qty = (float)$qte_reel_fut;
                        $moline->update($user, 1);
                        break;
                    }
                }
            }

            $created_mos[] = array(
                'id'          => $result,
                'ref'         => $mo->ref,
                'product_ref' => $d->product_ref,
                'qty'         => $qty,
                'qte_theo'    => price2num((float)$qty * (float)$d->volume_unitaire, 'MS'),
                'qte_reel'    => $qte_reel_fut,
                'prix_fab'    => $prix_fab,
            );

            // Stocker le prix de fab dans l'extrafield cost_estimated_total si renseigné
            if (!empty($prix_fab)) {
                $mo->array_options['options_cost_estimated_total'] = price2num($prix_fab * $qty, 'MU');
                $mo->insertExtraFields();
            }
        } else {
            $errors_list[] = $langs->trans('ErreurCreationOF', dol_escape_htmltag($d->product_ref)).': '.$mo->error;
        }
    }

    // Show warnings
    foreach ($errors_list as $err) {
        setEventMessages($err, null, 'warnings');
    }

    if (!empty($created_mos)) {
        // Show summary screen
        llxHeader('', $langs->trans('RecapProductionEnMasse'));
        print load_fiche_titre($langs->trans('RecapProductionEnMasse'), '<a href="'.$backurl.'" class="button button-secondary">'.$langs->trans('BackToList').'</a>', 'wrench');

        print '<h4>'.dol_escape_htmltag($g->label).'</h4>';
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('RefOF').'</td>';
        print '<td>'.$langs->trans('Product').'</td>';
        print '<td class="right">'.$langs->trans('QtyToProduce').'</td>';
        print '<td class="right">'.$langs->trans('QteFutTheorique').'</td>';
        print '<td class="right">'.$langs->trans('QteFutReelle').'</td>';
        print '<td class="right">'.$langs->trans('PrixFabUnitaire').'</td>';
        print '</tr>';

        foreach ($created_mos as $mo_info) {
            $mo_url = dol_buildpath('/mrp/mo.php?id='.$mo_info['id'], 1);
            print '<tr class="oddeven">';
            print '<td><a href="'.dol_escape_htmltag($mo_url).'" target="_blank"><b>'.dol_escape_htmltag($mo_info['ref']).'</b></a></td>';
            print '<td>'.dol_escape_htmltag($mo_info['product_ref']).'</td>';
            print '<td class="right">'.price2num($mo_info['qty'], 'MS').'</td>';
            print '<td class="right">'.price2num($mo_info['qte_theo'], 'MS').' L</td>';
            print '<td class="right">'.($mo_info['qte_reel'] ? price2num($mo_info['qte_reel'], 'MS').' L' : '<span class="opacitymedium">-</span>').'</td>';
            print '<td class="right">'.($mo_info['prix_fab'] ? price2num($mo_info['prix_fab'], 'MU') : '<span class="opacitymedium">-</span>').'</td>';
            print '</tr>';
        }
        print '</table><br>';

        // Total consommation
        print '<table class="noborder" style="min-width:400px">';
        print '<tr class="liste_titre"><td>'.$langs->trans('Totaux').'</td><td class="right"></td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('TotalFutTheorique').'</td><td class="right"><b>'.price2num($total_theo, 'MS').' L</b></td></tr>';
        if ($total_reel > 0) {
            $class = $total_reel > $g->volume_fut ? 'badge badge-status8' : 'badge badge-status4';
            print '<tr class="oddeven"><td>'.$langs->trans('TotalFutReel').'</td><td class="right"><b><span class="'.$class.'">'.price2num($total_reel, 'MS').' L</span></b>';
            if ($total_reel > $g->volume_fut) {
                print ' <span class="badge badge-status8">'.$langs->trans('DepasementVolumeFut').'</span>';
            }
            print '</td></tr>';
        }
        print '<tr class="oddeven"><td>'.$langs->trans('VolumeFut').'</td><td class="right">'.price2num($g->volume_fut, 'MS').' L</td></tr>';
        print '</table>';

        llxFooter(); $db->close(); exit;
    }
}

// ─────────────────────────────────────────────────────────────
// VIEW : Formulaire de lancement
// ─────────────────────────────────────────────────────────────

// Charger les stocks pour chaque dérivé
$stock_fut = $g->getProductStock($g->fk_product_source, (int)$g->fk_warehouse);
$derive_stocks = array();
foreach ($g->derives as $d) {
    $derive_stocks[$d->id] = $g->getProductStock($d->fk_product, (int)$g->fk_warehouse);
}

llxHeader('', $langs->trans('LancerProduction').' - '.dol_escape_htmltag($g->label));
print load_fiche_titre(
    $langs->trans('LancerProduction').' : <b>'.dol_escape_htmltag($g->label).'</b>',
    '<a href="'.$backurl.'" class="button button-secondary">'.$langs->trans('BackToList').'</a>',
    'wrench'
);

// ── Bloc informatif ────────────────────────────────────────
print '<h4>'.$langs->trans('InformationsStocks').'</h4>';
print '<table class="noborder" style="min-width:600px">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Produit').'</td>';
print '<td class="right">'.$langs->trans('Stock').'</td>';
print '<td class="right">'.$langs->trans('QteMaxTheorique').'</td>';
print '<td class="right">'.$langs->trans('QteVendueEstimee').'</td>';
print '</tr>';

// Fût
$stock_class = $stock_fut < 0 ? 'badge-status8' : 'badge-status4';
print '<tr class="oddeven">';
print '<td><b>'.dol_escape_htmltag($g->product_source_ref).'</b> <span class="opacitymedium">'.dol_escape_htmltag($g->product_source_label).'</span>';
print ' <span class="badge badge-status1">'.$langs->trans('Fut').'</span></td>';
print '<td class="right"><span class="badge '.$stock_class.'">'.price2num($stock_fut, 'MS').'</span></td>';
print '<td class="right">'.price2num($g->volume_fut, 'MS').' L</td>';
print '<td class="right"><input type="text" id="qte_vendue_fut" class="flat width75 right" value="" placeholder="optionnel" oninput="calcQteReelFut()"> L</td>';
print '</tr>';

foreach ($g->derives as $d) {
    if (!$d->actif) continue;
    $stock_d = $derive_stocks[$d->id];
    $stock_class = $stock_d < 0 ? 'badge-status8' : 'badge-status4';
    $qte_vendue  = $stock_d < 0 ? abs($stock_d) : 0;
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($d->product_ref).' <span class="opacitymedium">'.dol_escape_htmltag($d->product_label).'</span>';
    if (!empty($d->bom_ref)) print ' <span class="opacitymedium small">('.dol_escape_htmltag($d->bom_ref).')</span>';
    print '</td>';
    print '<td class="right"><span class="badge '.$stock_class.'">'.price2num($stock_d, 'MS').'</span></td>';
    print '<td class="right">'.(int)$d->qte_max_theorique.'</td>';
    print '<td class="right">'.($qte_vendue > 0 ? price2num($qte_vendue, 'MS') : '<span class="opacitymedium">-</span>').'</td>';
    print '</tr>';
}
print '</table><br>';

// ── Formulaire de saisie ──────────────────────────────────
print '<h4>'.$langs->trans('ParametresProduction').'</h4>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="create_of">';
print '<input type="hidden" name="groupe_id" value="'.(int)$g->id.'">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td><input type="checkbox" id="check_all" onclick="toggleAll(this)" checked> '.$langs->trans('Produit').'</td>';
print '<td class="right">'.$langs->trans('QteAProduire').'</td>';
print '<td class="right">'.$langs->trans('QteFutTheorique').'</td>';
print '<td class="right">'.$langs->trans('QteFutReelle').'</td>';
print '<td class="right">'.$langs->trans('PrixFabUnitaire').'</td>';
print '<td>'.$langs->trans('DoublonOF').'</td>';
print '</tr>';

foreach ($g->derives as $d) {
    if (!$d->actif) continue;
    $stock_d   = $derive_stocks[$d->id];
    $pre_qty   = $stock_d < 0 ? abs($stock_d) : 0;
    $is_checked = $stock_d < 0;

    $existing_mo = $g->checkExistingOpenMo($d->fk_product);
    $doublon_html = '';
    if ($existing_mo > 0) {
        $mo_url = dol_buildpath('/mrp/mo.php?id='.$existing_mo, 1);
        $doublon_html = '<a href="'.dol_escape_htmltag($mo_url).'" target="_blank" class="badge badge-status8">OF#'.$existing_mo.' ouvert</a>';
    }

    print '<tr class="oddeven" id="row_'.$d->id.'">';
    print '<td>';
    print '<input type="checkbox" name="derive_check_'.$d->id.'" value="1" id="chk_'.$d->id.'" class="derive_check"'.($is_checked ? ' checked' : '').'> ';
    print '<label for="chk_'.$d->id.'"><b>'.dol_escape_htmltag($d->product_ref).'</b> '.dol_escape_htmltag($d->product_label).'</label>';
    print '</td>';
    print '<td class="right"><input type="text" name="qty_'.$d->id.'" id="qty_'.$d->id.'" class="flat width75 right" value="'.price2num($pre_qty, 'MS').'" onchange="calcTheo('.$d->id.', '.price2num($d->volume_unitaire, 'MS').')"></td>';
    print '<td class="right"><span id="theo_'.$d->id.'">'.price2num($pre_qty * $d->volume_unitaire, 'MS').'</span> L</td>';
    print '<td class="right"><input type="text" name="qte_reel_fut_'.$d->id.'" class="flat width75 right" value="" placeholder="optionnel"> L</td>';
    print '<td class="right"><input type="text" name="prix_fab_'.$d->id.'" class="flat width75 right" value="" placeholder="optionnel"></td>';
    print '<td>'.$doublon_html.'</td>';
    print '</tr>';
}

print '</table><br>';
print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('CreerLesOF').'">';
print ' <a href="'.$backurl.'" class="button button-cancel">'.$langs->trans('Cancel').'</a>';
print '</div>';
print '</form>';

// ── JavaScript ────────────────────────────────────────────
$derives_js = array();
foreach ($g->derives as $d) {
    if (!$d->actif) continue;
    $derives_js[] = array('id' => (int)$d->id, 'volume' => (float)$d->volume_unitaire);
}
$derives_js_json = json_encode($derives_js);
$volume_fut_js   = (float)$g->volume_fut;

print '<script>
var derives = '.$derives_js_json.';
var volumeFut = '.$volume_fut_js.';

function calcTheo(derive_id, volume_unitaire) {
    var qty = parseFloat(document.getElementById("qty_" + derive_id).value) || 0;
    var theo = (qty * volume_unitaire).toFixed(2);
    document.getElementById("theo_" + derive_id).textContent = theo;
    calcQteReelFut();
}

function calcQteReelFut() {
    var qteVendue = parseFloat(document.getElementById("qte_vendue_fut").value) || 0;
    if (qteVendue <= 0) return;
    var totalReel = qteVendue * volumeFut;
    var totalTheo = 0;
    derives.forEach(function(d) {
        var qty = parseFloat(document.getElementById("qty_" + d.id) ? document.getElementById("qty_" + d.id).value : 0) || 0;
        totalTheo += qty * d.volume;
    });
    if (totalTheo <= 0) return;
    derives.forEach(function(d) {
        var qty = parseFloat(document.getElementById("qty_" + d.id) ? document.getElementById("qty_" + d.id).value : 0) || 0;
        var reelFut = (qty * d.volume / totalTheo) * totalReel;
        var input = document.querySelector("[name=\'qte_reel_fut_" + d.id + "\']");
        if (input) input.value = reelFut.toFixed(2);
    });
}

function toggleAll(cb) {
    document.querySelectorAll(".derive_check").forEach(function(el) { el.checked = cb.checked; });
}
</script>';

llxFooter();
$db->close();
