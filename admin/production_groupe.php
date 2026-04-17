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
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/fixmargin/class/FixMarginProductionGroupe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/fixmargin/lib/fixmargin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';

// Compatibility shim
if (!function_exists('checkToken')) {
    function checkToken() {
        $token = GETPOST('token', 'alpha');
        if (empty($token) || empty($_SESSION['newtoken']) || $token !== $_SESSION['newtoken']) {
            accessforbidden('SecurityTokenMismatch');
        }
    }
}

if (!isModEnabled('fixmargin')) accessforbidden();
if (!$user->hasRight('mrp', 'write')) accessforbidden();

$langs->loadLangs(array('fixmargin@fixmargin', 'mrp', 'admin'));

$action    = GETPOST('action', 'aZ09');
$id        = GETPOST('id', 'int');
$derive_id = GETPOST('derive_id', 'int');
$confirm   = GETPOST('confirm', 'alpha');

$groupeObj   = new FixMarginProductionGroupe($db);
$form        = new Form($db);
$formproduct = new FormProduct($db);

// ─────────────────────────────────────────────────────────────
// ACTIONS GROUPE
// ─────────────────────────────────────────────────────────────
if ($action === 'add_groupe') {
    checkToken();
    $groupeObj->label             = GETPOST('label', 'alphanohtml');
    $groupeObj->fk_product_source = GETPOST('fk_product_source', 'int');
    $groupeObj->volume_fut        = price2num(GETPOST('volume_fut', 'alpha'));
    $groupeObj->fk_warehouse      = GETPOST('fk_warehouse', 'int') ?: null;
    $groupeObj->actif             = 1;
    if (empty($groupeObj->label) || empty($groupeObj->fk_product_source)) {
        setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('Libelle').', '.$langs->trans('ProduitFut')), null, 'errors');
    } else {
        $result = $groupeObj->create($user);
        if ($result > 0) setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
        else             setEventMessages($groupeObj->error, null, 'errors');
    }
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

if ($action === 'update_groupe' && $id > 0) {
    checkToken();
    $groupeObj->fetch($id);
    $groupeObj->label             = GETPOST('label', 'alphanohtml');
    $groupeObj->fk_product_source = GETPOST('fk_product_source', 'int');
    $groupeObj->volume_fut        = price2num(GETPOST('volume_fut', 'alpha'));
    $groupeObj->fk_warehouse      = GETPOST('fk_warehouse', 'int') ?: null;
    $groupeObj->actif             = GETPOST('actif', 'int') ? 1 : 0;
    $result = $groupeObj->update($user);
    if ($result > 0) setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
    else             setEventMessages($groupeObj->error, null, 'errors');
    header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'&action=view'); exit;
}

if ($action === 'confirm_delete_groupe' && $confirm === 'yes' && $id > 0) {
    checkToken();
    $groupeObj->fetch($id);
    $result = $groupeObj->delete($user);
    if ($result > 0) setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
    else             setEventMessages($langs->trans($groupeObj->error), null, 'errors');
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

// ─────────────────────────────────────────────────────────────
// ACTIONS DERIVE
// ─────────────────────────────────────────────────────────────
if ($action === 'add_derive' && $id > 0) {
    checkToken();
    $groupeObj->fetch($id, false);
    $fk_product      = GETPOST('fk_product', 'int');
    $fk_bom          = GETPOST('fk_bom', 'int') ?: null;
    $volume_unitaire = price2num(GETPOST('volume_unitaire', 'alpha'));
    $rang            = GETPOST('rang', 'int');
    if (empty($fk_product) || $volume_unitaire <= 0) {
        setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('Product').', '.$langs->trans('VolumeUnitaire')), null, 'errors');
    } else {
        $result = $groupeObj->addDerive($user, $fk_product, $fk_bom, $volume_unitaire, $rang, 1);
        if ($result > 0) setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
        else             setEventMessages($groupeObj->error, null, 'errors');
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'&action=view'); exit;
}

if ($action === 'delete_derive' && $id > 0 && $derive_id > 0) {
    checkToken();
    $groupeObj->fetch($id, false);
    $result = $groupeObj->deleteDerive($user, $derive_id);
    if ($result > 0) setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
    else             setEventMessages($groupeObj->error, null, 'errors');
    header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'&action=view'); exit;
}

// ─────────────────────────────────────────────────────────────
// VIEW
// ─────────────────────────────────────────────────────────────
$head = fixmarginAdminPrepareHead();
llxHeader('', $langs->trans('GroupesProduction'));
print dol_get_fiche_head($head, 'production_groupes', $langs->trans('FixMarginSetup'), -1, 'wrench');

// ── Vue détail d'un groupe ────────────────────────────────
if ($id > 0 && in_array($action, array('view', 'edit_groupe', 'add_derive'))) {
    $groupeObj->fetch($id, true);

    print '<div class="fichehalfleft">';
    if ($action === 'edit_groupe') {
        // Formulaire édition
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="update_groupe">';
        print '<input type="hidden" name="id" value="'.$id.'">';
        print '<table class="border centpercent">';
        print '<tr><td class="titlefield fieldrequired">'.$langs->trans('Libelle').'</td><td><input type="text" name="label" class="flat minwidth200" value="'.dol_escape_htmltag($groupeObj->label).'"></td></tr>';
        print '<tr><td class="fieldrequired">'.$langs->trans('ProduitFut').'</td><td>';
        print $form->select_produits($groupeObj->fk_product_source, 'fk_product_source', '', 0, 0, -1, 2, '', 0, null, 0, '1', 0, 'maxwidth300');
        print '</td></tr>';
        print '<tr><td>'.$langs->trans('VolumeFut').'</td><td><input type="text" name="volume_fut" class="flat width75 right" value="'.dol_escape_htmltag($groupeObj->volume_fut).'"> L</td></tr>';
        print '<tr><td>'.$langs->trans('Entrepot').'</td><td>';
        print $formproduct->selectWarehouses($groupeObj->fk_warehouse, 'fk_warehouse', '', 1);
        print '</td></tr>';
        print '<tr><td>'.$langs->trans('Actif').'</td><td><input type="checkbox" name="actif" value="1"'.($groupeObj->actif ? ' checked' : '').'></td></tr>';
        print '</table><br>';
        print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'"> ';
        print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=view" class="button button-cancel">'.$langs->trans('Cancel').'</a>';
        print '</form>';
    } else {
        // Affichage
        print '<table class="border centpercent">';
        print '<tr><td class="titlefield">'.$langs->trans('Libelle').'</td><td><b>'.dol_escape_htmltag($groupeObj->label).'</b></td></tr>';
        print '<tr><td>'.$langs->trans('ProduitFut').'</td><td>'.dol_escape_htmltag($groupeObj->product_source_ref).' - '.dol_escape_htmltag($groupeObj->product_source_label).'</td></tr>';
        print '<tr><td>'.$langs->trans('VolumeFut').'</td><td>'.price2num($groupeObj->volume_fut, 'MS').' L</td></tr>';
        print '<tr><td>'.$langs->trans('Entrepot').'</td><td>'.dol_escape_htmltag($groupeObj->warehouse_label).'</td></tr>';
        print '<tr><td>'.$langs->trans('Actif').'</td><td>'.($groupeObj->actif ? img_picto('', 'tick') : img_picto('', 'uncheck')).'</td></tr>';
        print '</table><br>';
        print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit_groupe" class="button">'.img_edit().' '.$langs->trans('Modify').'</a> ';
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="confirm_delete_groupe">';
        print '<input type="hidden" name="confirm" value="yes">';
        print '<input type="hidden" name="id" value="'.$id.'">';
        print '<button type="submit" class="button button-delete" onclick="return confirm(\''.dol_escape_js($langs->trans('ConfirmDeleteObject')).'\')">';
        print img_delete().' '.$langs->trans('Delete');
        print '</button></form>';
    }
    print '</div>';

    // ── Tableau des dérivés ──────────────────────────────
    print '<div class="fichehalfleft">';
    print '<h4>'.$langs->trans('ProduitsDerivés').'</h4>';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>'.$langs->trans('Product').'</td>';
    print '<td>'.$langs->trans('BOM').'</td>';
    print '<td class="right">'.$langs->trans('VolumeUnitaire').'</td>';
    print '<td class="right">'.$langs->trans('QteMaxTheorique').'</td>';
    print '<td class="center">'.$langs->trans('Actif').'</td>';
    print '<td></td>';
    print '</tr>';
    if (count($groupeObj->derives) > 0) {
        foreach ($groupeObj->derives as $d) {
            print '<tr class="oddeven">';
            print '<td>'.dol_escape_htmltag($d->product_ref).' <span class="opacitymedium">'.dol_escape_htmltag($d->product_label).'</span></td>';
            print '<td>'.dol_escape_htmltag($d->bom_ref).'</td>';
            print '<td class="right">'.price2num($d->volume_unitaire, 'MS').' L</td>';
            print '<td class="right">'.(int)$d->qte_max_theorique.'</td>';
            print '<td class="center">'.($d->actif ? img_picto('', 'tick') : img_picto('', 'uncheck')).'</td>';
            print '<td class="center">';
            print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="delete_derive">';
            print '<input type="hidden" name="id" value="'.$id.'">';
            print '<input type="hidden" name="derive_id" value="'.$d->id.'">';
            print '<button type="submit" class="buttonDelete" onclick="return confirm(\''.dol_escape_js($langs->trans('ConfirmDeleteObject')).'\')" style="background:none;border:none;cursor:pointer;padding:0">'.img_delete().'</button>';
            print '</form>';
            print '</td>';
            print '</tr>';
        }
    } else {
        print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
    }
    print '</table><br>';

    // Formulaire ajout dérivé
    print '<h4>'.$langs->trans('AjouterDerive').'</h4>';
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="add_derive">';
    print '<input type="hidden" name="id" value="'.$id.'">';
    print '<table class="border">';
    print '<tr><td class="titlefield fieldrequired">'.$langs->trans('Product').'</td><td>';
    print $form->select_produits('', 'fk_product', '', 0, 0, -1, 2, '', 0, null, 0, '1', 0, 'maxwidth250');
    print '</td></tr>';
    print '<tr><td>'.$langs->trans('BOM').'</td><td>';
    // Simple text input for BOM id (could be improved with autocomplete)
    print '<input type="text" name="fk_bom" class="flat width75" value="" placeholder="ID BOM (optionnel)">';
    print '</td></tr>';
    print '<tr><td class="fieldrequired">'.$langs->trans('VolumeUnitaire').'</td><td>';
    print '<input type="text" name="volume_unitaire" class="flat width75 right" value=""> L';
    print '</td></tr>';
    print '<tr><td>'.$langs->trans('Rang').'</td><td>';
    print '<input type="number" name="rang" class="flat width50" value="'.count($groupeObj->derives).'">';
    print '</td></tr>';
    print '</table><br>';
    print '<input type="submit" class="button button-save" value="'.$langs->trans('Add').'">';
    print '</form>';
    print '</div>';

    print '<br><a href="'.$_SERVER['PHP_SELF'].'">&laquo; '.$langs->trans('BackToList').'</a>';

} else {
    // ── Liste des groupes ──────────────────────────────────
    $list = $groupeObj->fetchAll($conf->entity, false);
    if (!is_array($list)) {
        setEventMessages('Erreur SQL fetchAll (entity='.$conf->entity.'): '.$groupeObj->error, null, 'errors');
        $list = array();
    }

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>'.$langs->trans('Libelle').'</td>';
    print '<td>'.$langs->trans('ProduitFut').'</td>';
    print '<td class="right">'.$langs->trans('VolumeFut').'</td>';
    print '<td>'.$langs->trans('Entrepot').'</td>';
    print '<td class="center">'.$langs->trans('NbDerivés').'</td>';
    print '<td class="center">'.$langs->trans('Actif').'</td>';
    print '<td></td>';
    print '</tr>';
    if (is_array($list) && count($list) > 0) {
        foreach ($list as $g) {
            print '<tr class="oddeven">';
            print '<td><a href="'.$_SERVER['PHP_SELF'].'?id='.$g->id.'&action=view"><b>'.dol_escape_htmltag($g->label).'</b></a></td>';
            print '<td>'.dol_escape_htmltag($g->product_source_ref).'</td>';
            print '<td class="right">'.price2num($g->volume_fut, 'MS').' L</td>';
            print '<td>'.dol_escape_htmltag($g->warehouse_label).'</td>';
            print '<td class="center">'.count($g->derives).'</td>';
            print '<td class="center">'.($g->actif ? img_picto('', 'tick') : img_picto('', 'uncheck')).'</td>';
            print '<td class="center">';
            print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$g->id.'&action=view" class="reposition">'.img_edit().'</a> ';
            print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="confirm_delete_groupe">';
            print '<input type="hidden" name="confirm" value="yes">';
            print '<input type="hidden" name="id" value="'.$g->id.'">';
            print '<button type="submit" class="buttonDelete" onclick="return confirm(\''.dol_escape_js($langs->trans('ConfirmDeleteObject')).'\')" style="background:none;border:none;cursor:pointer;padding:0">'.img_delete().'</button>';
            print '</form>';
            print '</td>';
            print '</tr>';
        }
    } else {
        print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
    }
    print '</table><br>';

    // Formulaire ajout groupe
    print '<h4>'.$langs->trans('NouveauGroupe').'</h4>';
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="add_groupe">';
    print '<table class="border">';
    print '<tr><td class="titlefield fieldrequired">'.$langs->trans('Libelle').'</td><td><input type="text" name="label" class="flat minwidth200" value=""></td></tr>';
    print '<tr><td class="fieldrequired">'.$langs->trans('ProduitFut').'</td><td>';
    print $form->select_produits('', 'fk_product_source', '', 0, 0, -1, 2, '', 0, null, 0, '1', 0, 'maxwidth300');
    print '</td></tr>';
    print '<tr><td>'.$langs->trans('VolumeFut').'</td><td><input type="text" name="volume_fut" class="flat width75 right" value=""> L</td></tr>';
    print '<tr><td>'.$langs->trans('Entrepot').'</td><td>';
    print $formproduct->selectWarehouses('', 'fk_warehouse', '', 1);
    print '</td></tr>';
    print '</table><br>';
    print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Add').'"></div>';
    print '</form>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
