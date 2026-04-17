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

if (!isModEnabled('mrp')) accessforbidden();
if (!$user->hasRight('mrp', 'read')) accessforbidden();

$langs->loadLangs(array('fixmargin@fixmargin', 'mrp'));

$groupeObj = new FixMarginProductionGroupe($db);
$groups    = $groupeObj->fetchAll($conf->entity, true);

llxHeader('', $langs->trans('ProductionEnMasse'));
print load_fiche_titre($langs->trans('ProductionEnMasse'), '', 'wrench');

if (!is_array($groups) || count($groups) === 0) {
    print '<div class="info">'.$langs->trans('NoGroupeProduction').'</div>';
    print '<p><a href="'.dol_buildpath('/fixmargin/admin/production_groupe.php', 1).'" class="button">'.$langs->trans('ConfigurerGroupes').'</a></p>';
    llxFooter(); $db->close(); exit;
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('GroupeProduction').'</td>';
print '<td>'.$langs->trans('ProduitFut').'</td>';
print '<td class="right">'.$langs->trans('VolumeFut').'</td>';
print '<td class="right">'.$langs->trans('StockFut').'</td>';
print '<td>'.$langs->trans('ProduitsDerivesStocks').'</td>';
print '<td class="center">'.$langs->trans('Actions').'</td>';
print '</tr>';

foreach ($groups as $g) {
    $stock_fut = $groupeObj->getProductStock($g->fk_product_source, (int)$g->fk_warehouse);
    $stock_fut_html = '<span class="'.($stock_fut < 0 ? 'badge badge-status8' : 'badge badge-status4').'">'.price2num($stock_fut, 'MS').'</span>';

    $derives_html = '';
    $has_negative = false;
    foreach ($g->derives as $d) {
        if (!$d->actif) continue;
        $stock_d = $groupeObj->getProductStock($d->fk_product, (int)$g->fk_warehouse);
        $color   = $stock_d < 0 ? 'badge-status8' : 'badge-status4';
        if ($stock_d < 0) $has_negative = true;
        $derives_html .= '<span class="badge '.$color.' marginrightonly" title="'.dol_escape_htmltag($d->product_label).'">'.dol_escape_htmltag($d->product_ref).' : '.price2num($stock_d, 'MS').'</span> ';
    }
    if (empty($derives_html)) $derives_html = '<span class="opacitymedium">'.$langs->trans('NoDerives').'</span>';

    print '<tr class="oddeven">';
    print '<td><b>'.dol_escape_htmltag($g->label).'</b></td>';
    print '<td>'.dol_escape_htmltag($g->product_source_ref).'<br><span class="opacitymedium small">'.dol_escape_htmltag($g->product_source_label).'</span></td>';
    print '<td class="right">'.price2num($g->volume_fut, 'MS').' L</td>';
    print '<td class="right">'.$stock_fut_html.'</td>';
    print '<td>'.$derives_html.'</td>';
    print '<td class="center">';
    $url = dol_buildpath('/fixmargin/production_masse_launch.php', 1).'?groupe_id='.(int)$g->id;
    $btn_class = $has_negative ? 'button' : 'button button-secondary';
    print '<a href="'.$url.'" class="'.$btn_class.'">'.$langs->trans('LancerProduction').'</a>';
    print '</td>';
    print '</tr>';
}

print '</table>';

llxFooter();
$db->close();
