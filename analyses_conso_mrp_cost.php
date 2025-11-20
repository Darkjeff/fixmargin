<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2015      Jean-François Ferry	<jfefe@aternatik.fr>
 * Copyright (C) 2025      Florian HENRY	<florian.henry@scopen.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *    \file       fixmargin/analyses_conso_mrp_cost.php
 *    \ingroup    fixmargin
 *    \brief      Stat fixmargin analyse Consommation MRP
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
//require_once __DIR__.'/class/gmapsactivity.class.php';
//$object = new GmapsActivity($db);

$object = new Mo($db);

// Load translation files required by the page
$langs->loadLangs(array('companies',"fixmargin@fixmargin"));

//$typesReport = [0=>'detail',1=>'consolidated'];
//$type_report = GETPOST('type_report', 'alpha');
//if (empty($type_report)) {
//	$type_report=$typesReport[0];
//}


// Security check
if (isset($user->socid) && $user->socid > 0) {
	$action = '';
	$socid = $user->socid;
}

$max = 5;
$now = dol_now();


if (GETPOST("year", 'int')) $year_start = GETPOST("year", 'int');
else {
	$year_start = dol_print_date(dol_now(), '%Y');
}
$year_end = $year_start + 1;
$search_date_start = dol_mktime(0, 0, 0, 1, 1, $year_start);
$search_date_end = dol_get_last_day($year_end, 12);
$year_current = $year_start;


/*
 * Actions
 */


/*
 * View
 */

llxHeader("", $langs->trans("Analyse_Conso_cost"));

$textprevyear = '<a href="'.$_SERVER["PHP_SELF"].'?year='.($year_current - 1).'">'.img_previous().'</a>';
$textnextyear = '&nbsp;<a href="'.$_SERVER["PHP_SELF"].'?year='.($year_current + 1).'">'.img_next().'</a>';

print load_fiche_titre($langs->trans("Analyse_Conso_cost")." ".$textprevyear." ".$langs->trans("Year")." ".$year_start." ".$textnextyear, '', 'title_accountancy');

print_barre_liste($langs->trans("Analyse_Conso_cost"), '', '', '', '', '', '', -1, '', '', 0, '', '', 0, 1, 1);


print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans('ProductDone') . '</td>';
print '<td class="right">'.$langs->trans('FixMarginMOTotalCostEstimated').'</td>';
print '<td class="right">'.$langs->trans('FixMarginMOTotalCostStock').'</td>';
print '<td class="right">'.$langs->trans('PercentDiff').'</td>';
print '</tr>';


$sql = "SELECT
	pprod.ref as product_created,
	pprod.rowid as product_created_id,
    SUM(moe.cost_estimated_total) as cost_planned,
    SUM(moe.cost_stock_total) as cost_real
FROM " . $db->prefix() . "mrp_mo as mo
	INNER JOIN " . $db->prefix() . "mrp_mo_extrafields as moe ON mo.rowid=moe.fk_object
	INNER JOIN " . $db->prefix() . "product as pprod ON pprod.rowid=mo.fk_product
WHERE
	YEAR(mo.tms)=" . (int)$year_current . "
	AND mo.status=" . (int)$object::STATUS_PRODUCED."
	GROUP BY pprod.ref
	ORDER BY pprod.ref DESC";



$resql = $db->query($sql);
if ($resql) {
	$num = $db->num_rows($resql);
	$totalPlanned = 0;
	$totalUsed = 0;
	while ($obj = $db->fetch_object($resql)) {

		print '<tr class="oddeven">';
		print '<td class="nowrap">';
		$product = new Product($db);
		$product->fetch($obj->product_created_id);
		print $product->getNomUrl(1);//$obj->product_created;
		print '</td>';
		print '<td class="nowrap right">'.price2num($obj->cost_planned,'MU').'</td>';
		print '<td class="nowrap right">'.price2num($obj->cost_real,'MU').'</td>';
		print '<td class="nowrap right">'.
			(!empty($obj->cost_planned)?price2num((($obj->cost_planned/$obj->cost_real)-1)*100,'MU') . ' %':'N/A')
			.'</td>';

		print '</tr>';

		$totalPlanned += (float) $obj->cost_planned;
		$totalUsed += (float) $obj->cost_real;
	}
	if ($num>0) {
		print '<tr class="liste_total"><td class="left">Total</td>';
		print '<td class="nowrap right">'.price($totalPlanned).'</td>';
		print '<td class="nowrap right">'.price($totalUsed).'</td>';
		print '<td class="nowrap right">'.(!empty($totalPlanned)?price2num((($totalPlanned/$totalUsed)-1)*100,'MU'). ' %':'N/A').'</td>';

		print '</tr>';
	}
	$db->free($resql);
} else {
	print $db->lasterror(); // Show last sql error
}
print "</table>\n";
print '</div>';



// End of page
llxFooter();
$db->close();
