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
$moline_available = file_exists(DOL_DOCUMENT_ROOT.'/mrp/class/moline.class.php');
if ($moline_available) {
    require_once DOL_DOCUMENT_ROOT.'/mrp/class/moline.class.php';
}
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

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

    $total_theo    = 0;
    $total_reel    = 0;
    $errors_list   = array();
    $auto_valider  = GETPOST('auto_valider', 'int');

    $auto_valider = ($auto_valider && $moline_available) ? 1 : 0;

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

        // Qté fût réelle et prix de fabrication → note_private + note_public
        $note_lines = array();
        if (!empty($qte_reel_fut)) {
            $total_reel += (float)$qte_reel_fut;
            $note_lines[] = $langs->trans('QteFutReelle').' : '.price2num($qte_reel_fut, 'MS').' L';
        }
        if (!empty($prix_fab)) {
            $note_lines[] = $langs->trans('PrixFabUnitaire').' : '.price2num($prix_fab, 'MU').' '.$langs->trans('HT');
        }
        if (!empty($note_lines)) {
            $mo->note_private = implode("\n", $note_lines);
            $mo->note_public  = implode("\n", $note_lines);
        }

        $total_theo += (float)$qty * (float)$d->volume_unitaire;

        $result = $mo->create($user);
        if ($result <= 0) {
            $errors_list[] = $langs->trans('ErreurCreationOF', dol_escape_htmltag($d->product_ref)).': '.$mo->error;
            continue;
        }

        $mo->fetch($result);

        // Stocker le prix de fab dans l'extrafield cost_estimated_total
        if (!empty($prix_fab)) {
            $mo->array_options['options_cost_estimated_total'] = price2num($prix_fab * $qty, 'MU');
            $mo->insertExtraFields();
        }

        $mo_status_final = Mo::STATUS_DRAFT;

        // ── Auto-validation + consommation/production ──────────────
        if ($auto_valider && !empty($qte_reel_fut) && $qte_reel_fut > 0) {
            $db->begin();
            $err_auto = 0;

            // 1. Valider l'OF
            if ($mo->validate($user) <= 0) {
                $err_auto++;
                $errors_list[] = 'Validation OF '.$mo->ref.' : '.$mo->error;
            }

            if (!$err_auto) {
                $mo->setStatut(Mo::STATUS_INPROGRESS, 0, '', 'MRP_MO_PRODUCED');
                $mo->fetchLines();
                $stockmove = new MouvementStock($db);
                $stockmove->setOrigin($mo->element, $mo->id);
                $labelmovement = $g->label.' - '.$d->product_ref;
                $pos = 0;

                // 2. Consommer le fût (qte_reel_fut)
                foreach ($mo->lines as $moline) {
                    if ($moline->role !== 'toconsume') continue;

                    $stockmove->context['mrp_role'] = 'toconsume';
                    $idmove = $stockmove->livraison($user, $moline->fk_product, (int)$g->fk_warehouse, (float)$qte_reel_fut, 0, $labelmovement, dol_now());
                    if ($idmove < 0) {
                        $err_auto++;
                        $errors_list[] = 'Mouvement stock conso '.$mo->ref.' : '.$stockmove->error;
                        break;
                    }

                    $consumed = new MoLine($db);
                    $consumed->fk_mo           = $mo->id;
                    $consumed->position        = $pos++;
                    $consumed->fk_product      = $moline->fk_product;
                    $consumed->fk_warehouse    = (int)$g->fk_warehouse;
                    $consumed->qty             = (float)$qte_reel_fut;
                    $consumed->role            = 'consumed';
                    $consumed->fk_mrp_production = $moline->id;
                    $consumed->fk_stock_movement = $idmove ?: null;
                    $consumed->fk_user_creat   = $user->id;
                    if ($consumed->create($user) <= 0) {
                        $err_auto++;
                        $errors_list[] = 'Moline consumed '.$mo->ref.' : '.$consumed->error;
                    }
                    break; // une seule ligne toconsume (le fût)
                }

                // 3. Produire les produits finis
                if (!$err_auto) {
                    foreach ($mo->lines as $moline) {
                        if ($moline->role !== 'toproduce') continue;

                        $stockmove->context['mrp_role'] = 'toproduce';
                        $idmove2 = $stockmove->reception($user, $moline->fk_product, (int)$g->fk_warehouse, (float)$qty, (float)$prix_fab, $labelmovement, '', '', '', dol_now());
                        if ($idmove2 < 0) {
                            $err_auto++;
                            $errors_list[] = 'Mouvement stock prod '.$mo->ref.' : '.$stockmove->error;
                            break;
                        }

                        $produced = new MoLine($db);
                        $produced->fk_mo           = $mo->id;
                        $produced->position        = $pos++;
                        $produced->fk_product      = $moline->fk_product;
                        $produced->fk_warehouse    = (int)$g->fk_warehouse;
                        $produced->qty             = (float)$qty;
                        $produced->role            = 'produced';
                        $produced->fk_mrp_production = $moline->id;
                        $produced->fk_stock_movement = $idmove2;
                        $produced->fk_user_creat   = $user->id;
                        if ($produced->create($user) <= 0) {
                            $err_auto++;
                            $errors_list[] = 'Moline produced '.$mo->ref.' : '.$produced->error;
                        }
                        break;
                    }
                }

                // 4. Passer en Produit
                if (!$err_auto) {
                    $mo->setStatut(Mo::STATUS_PRODUCED, 0, '', 'MRP_MO_PRODUCED');
                    $mo_status_final = Mo::STATUS_PRODUCED;
                }
            }

            if ($err_auto) {
                $db->rollback();
            } else {
                $db->commit();
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
            'status'      => $mo_status_final,
        );
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
        print '<td>'.$langs->trans('Status').'</td>';
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
            if ($mo_info['status'] == Mo::STATUS_PRODUCED) {
                print '<td><span class="badge badge-status6">'.$langs->trans('StatusMOProduced').'</span></td>';
            } else {
                print '<td><span class="badge badge-status0">'.$langs->trans('Draft').'</span></td>';
            }
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

// Récupérer le dernier prix d'achat unitaire du fût (dernière facture fournisseur)
$prix_achat_fut = 0;
$sqlp = "SELECT fd.subprice, f.datef FROM ".MAIN_DB_PREFIX."facture_fourn_det fd"
    ." INNER JOIN ".MAIN_DB_PREFIX."facture_fourn f ON f.rowid = fd.fk_facture_fourn"
    ." WHERE fd.fk_product = ".(int)$g->fk_product_source
    ." ORDER BY f.datef DESC, fd.rowid DESC"
    ." LIMIT 1";
$resqlp = $db->query($sqlp);
if ($resqlp) {
    $objp = $db->fetch_object($resqlp);
    if ($objp && $objp->subprice > 0) $prix_achat_fut = (float)$objp->subprice;
}
// Fallback : prix fournisseur catalogue
if ($prix_achat_fut <= 0) {
    $sqlp2 = "SELECT unitprice FROM ".MAIN_DB_PREFIX."product_fournisseur_price"
        ." WHERE fk_product = ".(int)$g->fk_product_source
        ." ORDER BY tms DESC LIMIT 1";
    $resqlp2 = $db->query($sqlp2);
    if ($resqlp2) {
        $objp2 = $db->fetch_object($resqlp2);
        if ($objp2 && $objp2->unitprice > 0) $prix_achat_fut = (float)$objp2->unitprice;
    }
}

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
if ($prix_achat_fut > 0) {
    print '<div class="info">'.$langs->trans('DernierPrixAchatFut').' : <b>'.price($prix_achat_fut).' '.$langs->trans('HT').'/'.$langs->trans('Litre').'</b></div><br>';
}
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
    print '<td class="right"><input type="text" name="qte_reel_fut_'.$d->id.'" id="qte_reel_fut_'.$d->id.'" class="flat width75 right" value="" placeholder="optionnel" oninput="calcPrixFab()"> L</td>';
    print '<td class="right"><input type="text" name="prix_fab_'.$d->id.'" id="prix_fab_'.$d->id.'" class="flat width75 right" value="" placeholder="optionnel"></td>';
    print '<td>'.$doublon_html.'</td>';
    print '</tr>';
}

print '</table><br>';
print '<div class="center">';
if ($moline_available) {
    print '<label style="font-weight:normal;margin-right:20px">';
    print '<input type="checkbox" name="auto_valider" value="1" style="margin-right:6px">';
    print dol_escape_htmltag($langs->trans('AutoValiderEtConsommer'));
    print '</label>';
}
print '<br><br>';
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
var prixAchatFut = '.json_encode($prix_achat_fut).';

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
        var input = document.getElementById("qte_reel_fut_" + d.id);
        if (input) input.value = reelFut.toFixed(2);
    });
    calcPrixFab();
}

function calcPrixFab() {
    if (prixAchatFut <= 0) return;
    // prixAchatFut est le prix par litre (la qté sur la facture fournisseur est en litres)
    derives.forEach(function(d) {
        var qty = parseFloat(document.getElementById("qty_" + d.id) ? document.getElementById("qty_" + d.id).value : 0) || 0;
        if (qty <= 0) return;
        var inputReel = document.getElementById("qte_reel_fut_" + d.id);
        var qteReel = inputReel ? (parseFloat(inputReel.value) || 0) : 0;
        if (qteReel <= 0) return;
        var prixFab = prixAchatFut * qteReel / qty;
        var inputPrix = document.getElementById("prix_fab_" + d.id);
        if (inputPrix) inputPrix.value = prixFab.toFixed(4);
    });
}

function toggleAll(cb) {
    document.querySelectorAll(".derive_check").forEach(function(el) { el.checked = cb.checked; });
}
</script>';

llxFooter();
$db->close();
