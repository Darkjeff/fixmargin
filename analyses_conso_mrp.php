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
 *    \file       fixmargin/analyses_conso_mrp.php
 *    \ingroup    fixmargin
 *    \brief      Stat fixmargin analyse Consoemation MRP
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

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
// Get parameters
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST("sortfield", 'alpha');
$sortorder = GETPOST("sortorder", 'alpha');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page == -1) { $page = 0; }     // If $page is not defined, or '' or -1
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortorder) $sortorder = "ASC";
if (!$sortfield) $sortfield = "position_name";

$typesReport = [0=>'detail',1=>'consolidated'];
$type_report = GETPOST('type_report', 'alpha');
if (empty($type_report)) {
	$type_report=$typesReport[0];
}


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

llxHeader("", $langs->trans("Analyse_Conso"));

$textprevyear = '<a href="'.$_SERVER["PHP_SELF"].'?type_report='.$type_report.'&year='.($year_current - 1).'">'.img_previous().'</a>';
$textnextyear = '&nbsp;<a href="'.$_SERVER["PHP_SELF"].'?type_report='.$type_report.'&year='.($year_current + 1).'">'.img_next().'</a>';
if ($type_report==$typesReport[0]) {
	$otherTypeReport = $langs->trans('ChangeModeReport').': <a href="'.$_SERVER["PHP_SELF"].'?type_report='.$typesReport[1].'&year='.($year_current).'">'.$langs->trans('TypeReportMRP_'.$typesReport[1]).'</a>';
} else {
	$otherTypeReport = $langs->trans('ChangeModeReport').': <a href="'.$_SERVER["PHP_SELF"].'?type_report='.$typesReport[0].'&year='.($year_current).'">'.$langs->trans('TypeReportMRP_'.$typesReport[0]).'</a>';
}

print load_fiche_titre($langs->trans("Analyse_Conso")." ".$textprevyear." ".$langs->trans("Year")." ".$year_start." ".$textnextyear . " ".$otherTypeReport, '', 'title_accountancy');

print_barre_liste($langs->trans("Analyse_Conso"). " ". $langs->trans('TypeReportMRP_'.$type_report), '', '', '', '', '', '', -1, '', '', 0, '', '', 0, 1, 1);


print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
if ($type_report==$typesReport[0]) {
	print '<td>' . $langs->trans('ProductDone') . '</td>';
}
print '<td>'.$langs->trans('ProductUsed').'</td>';
print '<td class="right">'.$langs->trans('FixMarginMOTotalQtyEstimated').'</td>';
print '<td class="right">'.$langs->trans('FixMarginMOTotalQtyReal').'</td>';
print '<td class="right">'.$langs->trans('PercentLost').'</td>';



$sql = "SELECT ";

if ($type_report==$typesReport[0]) {
	$sql .= " pprod.rowid as product_created_id,
	pprod.ref as product_created,";
}

$sql .= "pused.rowid as product_used_id,
	pused.ref as product_used,
	SUM(toconsume.qty) as qty_planned,
	SUM(toproduced.qty) as qty_real
FROM " . $db->prefix() . "mrp_mo as mo
	INNER JOIN " . $db->prefix() . "mrp_mo_extrafields as moe ON mo.rowid=moe.fk_object
	INNER JOIN " . $db->prefix() . "product as pprod ON pprod.rowid=mo.fk_product
	INNER JOIN " . $db->prefix() . "mrp_production as toconsume ON toconsume.fk_mo=mo.rowid AND toconsume.role='toconsume'
	INNER JOIN " . $db->prefix() . "mrp_production as toproduced ON toproduced.fk_mo=mo.rowid AND toproduced.role='consumed'
	INNER JOIN " . $db->prefix() . "product as pused ON pused.rowid=toconsume.fk_product AND pused.rowid=toproduced.fk_product
WHERE
	YEAR(mo.tms)=" . (int)$year_current . "
	AND mo.status=" . (int)$object::STATUS_PRODUCED;

if ($type_report==$typesReport[0]) {
	$sql .= " GROUP BY pprod.rowid,pused.rowid
		ORDER BY pprod.ref,pused.ref DESC";
} elseif ($type_report==$typesReport[1]) {
	$sql .= " GROUP BY pused.rowid
		ORDER BY pused.ref DESC";
}


$resql = $db->query($sql);
if ($resql) {
	$num = $db->num_rows($resql);
	$totalPlanned = 0;
	$totalUsed = 0;
	while ($obj = $db->fetch_object($resql)) {

		print '<tr class="oddeven">';
		if ($type_report==$typesReport[0]) {
			print '<td class="nowrap">';
			$product = new Product($db);
			$product->fetch($obj->product_created_id);
			print $product->getNomUrl(1);//$obj->product_created;
			print '</td>';
		}
		print '<td class="nowrap">';
		$product = new Product($db);
		$product->fetch($obj->product_used_id);
		print $product->getNomUrl(1);
		//print $obj->product_used;
		print '</td>';
		print '<td class="nowrap right">'.price2num($obj->qty_planned,'MU').'</td>';
		print '<td class="nowrap right">'.price2num($obj->qty_real,'MU').'</td>';
		print '<td class="nowrap right">'.
			(!empty($obj->qty_planned)?price2num((($obj->qty_planned/$obj->qty_real)-1)*100,'MU') . ' %':'N/A')
			.'</td>';

		print '</tr>';

		$totalPlanned += (float) $obj->qty_planned;
		$totalUsed += (float) $obj->qty_real;
	}
	if ($num>0) {
		print '<tr class="liste_total"><td class="left">Total</td>';
		if ($type_report==$typesReport[0]) {
			print '<td class="nowrap"></td>';
		}
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
