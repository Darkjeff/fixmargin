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

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';

if (!isModEnabled('mrp')) accessforbidden();
if (!$user->hasRight('mrp', 'read')) accessforbidden();

$langs->loadLangs(array('mrp', 'fixmargin@fixmargin'));

$form = new Form($db);

// Paramètres GET
$tab         = GETPOST('tab', 'alpha');
if (empty($tab)) $tab = 'tabcosts';

$year        = GETPOST('year', 'int');
if (empty($year)) $year = (int) dol_print_date(dol_now(), '%Y');

$month       = GETPOST('month', 'int');
$productid   = GETPOST('productid', 'int');
$type_report = GETPOST('type_report', 'alpha');
if (empty($type_report)) $type_report = 'detail';
$action      = GETPOST('action', 'alpha');

$alert_threshold = getDolGlobalInt('FIXMARGIN_ALERT_THRESHOLD', 20);

$year_prev = $year - 1;
$year_next = $year + 1;

function buildTabUrl($year, $month, $productid, $type_report)
{
	$url  = dol_buildpath('/fixmargin/analyses_mrp.php', 1).'?year='.(int)$year.'&month='.(int)$month;
	if ($productid > 0) $url .= '&productid='.(int)$productid;
	$url .= '&type_report='.urlencode($type_report);
	return $url;
}

// Export CSV — avant tout output HTML
if ($action === 'export_csv') {
	$tab_export = GETPOST('tab', 'alpha');

	if ($tab_export === 'tabcosts') {
		$sql  = "SELECT pprod.ref AS product_created,";
		$sql .= " SUM(moe.cost_estimated_total) AS cost_planned,";
		$sql .= " SUM(moe.cost_stock_total) AS cost_real";
		$sql .= " FROM ".$db->prefix()."mrp_mo AS mo";
		$sql .= " INNER JOIN ".$db->prefix()."mrp_mo_extrafields AS moe ON mo.rowid=moe.fk_object";
		$sql .= " INNER JOIN ".$db->prefix()."product AS pprod ON pprod.rowid=mo.fk_product";
		$sql .= " WHERE YEAR(mo.tms)=".(int)$year;
		if ($month > 0) $sql .= " AND MONTH(mo.tms)=".(int)$month;
		if ($productid > 0) $sql .= " AND mo.fk_product=".(int)$productid;
		$sql .= " AND mo.status=".(int)Mo::STATUS_PRODUCED;
		$sql .= " GROUP BY pprod.rowid ORDER BY pprod.ref ASC";

		$resql    = $db->query($sql);
		$filename = 'analyse_couts_mrp_'.$year.($month > 0 ? sprintf('%02d', $month) : '').'.csv';

		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="'.$filename.'"');
		header('Pragma: no-cache');
		echo "\xEF\xBB\xBF";

		echo $langs->trans('ProductDone').';'.$langs->trans('FixMarginMOTotalCostEstimated').';'.$langs->trans('FixMarginMOTotalCostStock').';'.$langs->trans('PercentDiff')."\n";

		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$cost_planned = (float) $obj->cost_planned;
				$cost_real    = (float) $obj->cost_real;
				$ecart = ($cost_real != 0) ? round((($cost_planned / $cost_real) - 1) * 100, 2) : '';
				echo dol_escape_htmltag($obj->product_created).';'.price2num($cost_planned, 'MU').';'.price2num($cost_real, 'MU').';'.$ecart."\n";
			}
			$db->free($resql);
		}
		$db->close();
		exit;
	}

	if ($tab_export === 'tabqty') {
		$sql = "SELECT ";
		if ($type_report === 'detail') $sql .= "pprod.ref AS product_created,";
		$sql .= " pused.ref AS product_used,";
		$sql .= " SUM(toconsume.qty) AS qty_planned, SUM(toproduced.qty) AS qty_real";
		$sql .= " FROM ".$db->prefix()."mrp_mo AS mo";
		$sql .= " INNER JOIN ".$db->prefix()."mrp_mo_extrafields AS moe ON mo.rowid=moe.fk_object";
		$sql .= " INNER JOIN ".$db->prefix()."product AS pprod ON pprod.rowid=mo.fk_product";
		$sql .= " INNER JOIN ".$db->prefix()."mrp_production AS toconsume ON toconsume.fk_mo=mo.rowid AND toconsume.role='toconsume'";
		$sql .= " INNER JOIN ".$db->prefix()."mrp_production AS toproduced ON toproduced.fk_mo=mo.rowid AND toproduced.role='consumed'";
		$sql .= " INNER JOIN ".$db->prefix()."product AS pused ON pused.rowid=toconsume.fk_product AND pused.rowid=toproduced.fk_product";
		$sql .= " WHERE YEAR(mo.tms)=".(int)$year;
		if ($month > 0) $sql .= " AND MONTH(mo.tms)=".(int)$month;
		if ($productid > 0) $sql .= " AND mo.fk_product=".(int)$productid;
		$sql .= " AND mo.status=".(int)Mo::STATUS_PRODUCED;
		if ($type_report === 'detail') {
			$sql .= " GROUP BY pprod.rowid, pused.rowid ORDER BY pprod.ref ASC, pused.ref ASC";
		} else {
			$sql .= " GROUP BY pused.rowid ORDER BY pused.ref ASC";
		}

		$resql    = $db->query($sql);
		$filename = 'analyse_qte_mrp_'.$year.($month > 0 ? sprintf('%02d', $month) : '').'.csv';

		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="'.$filename.'"');
		header('Pragma: no-cache');
		echo "\xEF\xBB\xBF";

		$header = '';
		if ($type_report === 'detail') $header .= $langs->trans('ProductDone').';';
		$header .= $langs->trans('ProductUsed').';'.$langs->trans('FixMarginMOTotalQtyEstimated').';'.$langs->trans('FixMarginMOTotalQtyReal').';'.$langs->trans('PercentLost')."\n";
		echo $header;

		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$qty_planned = (float) $obj->qty_planned;
				$qty_real    = (float) $obj->qty_real;
				$ecart = ($qty_real != 0) ? round((($qty_planned / $qty_real) - 1) * 100, 2) : '';
				$line = '';
				if ($type_report === 'detail') $line .= dol_escape_htmltag($obj->product_created).';';
				$line .= dol_escape_htmltag($obj->product_used).';'.price2num($qty_planned, 'MU').';'.price2num($qty_real, 'MU').';'.$ecart."\n";
				echo $line;
			}
			$db->free($resql);
		}
		$db->close();
		exit;
	}
}

/*
 * Vue
 */

llxHeader('', $langs->trans('AnalysesMRP'));

$baseurl = buildTabUrl($year, $month, $productid, $type_report);

$textprevyear = '<a href="'.buildTabUrl($year_prev, $month, $productid, $type_report).'&tab='.$tab.'">'.img_previous().'</a>';
$textnextyear = '<a href="'.buildTabUrl($year_next, $month, $productid, $type_report).'&tab='.$tab.'">'.img_next().'</a>';

print load_fiche_titre($langs->trans('AnalysesMRP').' '.$textprevyear.' '.$langs->trans('Year').' '.$year.' '.$textnextyear, '', 'title_accountancy');

// Formulaire filtres
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="tab" value="'.dol_escape_htmltag($tab).'">';
print '<input type="hidden" name="type_report" value="'.dol_escape_htmltag($type_report).'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="5">'.$langs->trans('Filter').'</td></tr>';
print '<tr class="oddeven">';

print '<td class="titlefield">'.$langs->trans('Year').'</td>';
print '<td><input type="number" name="year" class="flat maxwidth75" value="'.(int)$year.'"></td>';

$months_options = '<option value="0"'.($month == 0 ? ' selected' : '').'>'.$langs->trans('AllMonths').'</option>';
for ($m = 1; $m <= 12; $m++) {
	$months_options .= '<option value="'.$m.'"'.($month == $m ? ' selected' : '').'>'.$langs->trans('Month'.sprintf('%02d', $m)).'</option>';
}
print '<td>'.$langs->trans('Month').'&nbsp;<select name="month" class="flat">'.$months_options.'</select></td>';

print '<td>'.img_picto('', 'product', 'class="pictofixedwidth"').$form->select_produits(($productid > 0 ? $productid : ''), 'productid', '', 0, 0, 1, 2, '', 1, array(), 0, $langs->trans('AllProducts'), 0, '', 0, '', null, 1).'</td>';

print '<td><input type="submit" class="button" value="'.$langs->trans('Refresh').'"></td>';
print '</tr>';
print '</table>';
print '</form>';

print '<br>';

// Onglets
$head    = array();
$head[0] = array($baseurl.'&tab=tabcosts', $langs->trans('AnalysesMRPCosts'), 'tabcosts');
$head[1] = array($baseurl.'&tab=tabqty',   $langs->trans('AnalysesMRPQty'),   'tabqty');

print dol_get_fiche_head($head, $tab, '', 0, '');

// ── Onglet Coûts ────────────────────────────────────────────────────────────

if ($tab === 'tabcosts') {
	$sql  = "SELECT pprod.rowid AS product_created_id, pprod.ref AS product_created,";
	$sql .= " SUM(moe.cost_estimated_total) AS cost_planned,";
	$sql .= " SUM(moe.cost_stock_total) AS cost_real";
	$sql .= " FROM ".$db->prefix()."mrp_mo AS mo";
	$sql .= " INNER JOIN ".$db->prefix()."mrp_mo_extrafields AS moe ON mo.rowid=moe.fk_object";
	$sql .= " INNER JOIN ".$db->prefix()."product AS pprod ON pprod.rowid=mo.fk_product";
	$sql .= " WHERE YEAR(mo.tms)=".(int)$year;
	if ($month > 0) $sql .= " AND MONTH(mo.tms)=".(int)$month;
	if ($productid > 0) $sql .= " AND mo.fk_product=".(int)$productid;
	$sql .= " AND mo.status=".(int)Mo::STATUS_PRODUCED;
	$sql .= " GROUP BY pprod.rowid ORDER BY pprod.ref ASC";

	$resql     = $db->query($sql);
	$rows_cost = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows_cost[] = $obj;
		}
		$db->free($resql);
	}

	// Graphique
	if (!empty($rows_cost)) {
		$px = new DolGraph();
		if (!$px->isGraphKo()) {
			$data_graph = array();
			foreach ($rows_cost as $obj) {
				$data_graph[] = array(dol_trunc($obj->product_created, 10), (float)$obj->cost_planned, (float)$obj->cost_real);
			}
			$px->SetData($data_graph);
			$px->SetLegend(array($langs->trans('FixMarginMOTotalCostEstimated'), $langs->trans('FixMarginMOTotalCostStock')));
			$px->SetType(array('bars', 'bars'));
			$px->SetWidth('100%');
			$px->SetHeight(300);
			$px->draw('graphcosts', 'legendcosts');
			print $px->show(true);
		}
	}

	// Bouton export CSV
	$export_url = $_SERVER["PHP_SELF"].'?action=export_csv&tab=tabcosts&year='.(int)$year.'&month='.(int)$month.($productid > 0 ? '&productid='.(int)$productid : '');
	print '<div class="tabsAction">';
	print '<a href="'.dol_escape_htmltag($export_url).'" class="butAction">'.$langs->trans('ExportCSV').'</a>';
	print '</div>';

	// Tableau
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('ProductDone').'</td>';
	print '<td class="right">'.$langs->trans('FixMarginMOTotalCostEstimated').'</td>';
	print '<td class="right">'.$langs->trans('FixMarginMOTotalCostStock').'</td>';
	print '<td class="right">'.$langs->trans('PercentDiff').'</td>';
	print '</tr>';

	$total_planned = 0;
	$total_real    = 0;

	foreach ($rows_cost as $obj) {
		$cost_planned = (float) $obj->cost_planned;
		$cost_real    = (float) $obj->cost_real;
		$ecart        = ($cost_real != 0) ? round((($cost_planned / $cost_real) - 1) * 100, 2) : null;
		$alerte       = ($ecart !== null && abs($ecart) > $alert_threshold);
		$row_style    = $alerte ? ' style="background-color:#fdd;"' : '';
		$badge_class  = $alerte ? 'badge badge-status8' : 'badge badge-status4';

		print '<tr class="oddeven"'.$row_style.'>';
		print '<td class="nowrap">';
		$product = new Product($db);
		$product->fetch($obj->product_created_id);
		print $product->getNomUrl(1);
		print '</td>';
		print '<td class="nowrap right">'.price($cost_planned).'</td>';
		print '<td class="nowrap right">'.price($cost_real).'</td>';
		print '<td class="nowrap right">';
		if ($ecart !== null) {
			print '<span class="'.$badge_class.'">'.price2num($ecart, 'MU').' %</span>';
		} else {
			print 'N/A';
		}
		print '</td>';
		print '</tr>';

		$total_planned += $cost_planned;
		$total_real    += $cost_real;
	}

	if (!empty($rows_cost)) {
		$total_ecart  = ($total_real != 0) ? round((($total_planned / $total_real) - 1) * 100, 2) : null;
		$total_alerte = ($total_ecart !== null && abs($total_ecart) > $alert_threshold);
		$total_badge  = $total_alerte ? 'badge badge-status8' : 'badge badge-status4';
		print '<tr class="liste_total">';
		print '<td>Total</td>';
		print '<td class="nowrap right">'.price($total_planned).'</td>';
		print '<td class="nowrap right">'.price($total_real).'</td>';
		print '<td class="nowrap right">';
		if ($total_ecart !== null) {
			print '<span class="'.$total_badge.'">'.price2num($total_ecart, 'MU').' %</span>';
		} else {
			print 'N/A';
		}
		print '</td>';
		print '</tr>';
	}

	if (empty($rows_cost)) {
		print '<tr><td colspan="4" class="opacitymedium center">'.$langs->trans('NoRecordFound').'</td></tr>';
	}

	print '</table>';
	print '</div>';

// ── Onglet Quantités ────────────────────────────────────────────────────────

} elseif ($tab === 'tabqty') {
	$otherType  = ($type_report === 'detail') ? 'consolidated' : 'detail';
	$otherLabel = ($type_report === 'detail') ? 'TypeReportMRP_consolidated' : 'TypeReportMRP_detail';
	$toggle_url = buildTabUrl($year, $month, $productid, $otherType).'&tab=tabqty';

	$sql = "SELECT ";
	if ($type_report === 'detail') {
		$sql .= "pprod.rowid AS product_created_id, pprod.ref AS product_created,";
	}
	$sql .= " pused.rowid AS product_used_id, pused.ref AS product_used,";
	$sql .= " SUM(toconsume.qty) AS qty_planned,";
	$sql .= " SUM(toproduced.qty) AS qty_real";
	$sql .= " FROM ".$db->prefix()."mrp_mo AS mo";
	$sql .= " INNER JOIN ".$db->prefix()."mrp_mo_extrafields AS moe ON mo.rowid=moe.fk_object";
	$sql .= " INNER JOIN ".$db->prefix()."product AS pprod ON pprod.rowid=mo.fk_product";
	$sql .= " INNER JOIN ".$db->prefix()."mrp_production AS toconsume ON toconsume.fk_mo=mo.rowid AND toconsume.role='toconsume'";
	$sql .= " INNER JOIN ".$db->prefix()."mrp_production AS toproduced ON toproduced.fk_mo=mo.rowid AND toproduced.role='consumed'";
	$sql .= " INNER JOIN ".$db->prefix()."product AS pused ON pused.rowid=toconsume.fk_product AND pused.rowid=toproduced.fk_product";
	$sql .= " WHERE YEAR(mo.tms)=".(int)$year;
	if ($month > 0) $sql .= " AND MONTH(mo.tms)=".(int)$month;
	if ($productid > 0) $sql .= " AND mo.fk_product=".(int)$productid;
	$sql .= " AND mo.status=".(int)Mo::STATUS_PRODUCED;
	if ($type_report === 'detail') {
		$sql .= " GROUP BY pprod.rowid, pused.rowid ORDER BY pprod.ref ASC, pused.ref ASC";
	} else {
		$sql .= " GROUP BY pused.rowid ORDER BY pused.ref ASC";
	}

	$resql    = $db->query($sql);
	$rows_qty = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows_qty[] = $obj;
		}
		$db->free($resql);
	}

	// Graphique (toujours en mode consolidé par produit consommé)
	if (!empty($rows_qty)) {
		$graph_data_by_product = array();
		foreach ($rows_qty as $obj) {
			$key = $obj->product_used_id;
			if (!isset($graph_data_by_product[$key])) {
				$graph_data_by_product[$key] = array(
					'label'       => dol_trunc($obj->product_used, 10),
					'qty_planned' => 0,
					'qty_real'    => 0,
				);
			}
			$graph_data_by_product[$key]['qty_planned'] += (float) $obj->qty_planned;
			$graph_data_by_product[$key]['qty_real']    += (float) $obj->qty_real;
		}

		$px = new DolGraph();
		if (!$px->isGraphKo()) {
			$data_graph = array();
			foreach ($graph_data_by_product as $item) {
				$data_graph[] = array($item['label'], $item['qty_planned'], $item['qty_real']);
			}
			$px->SetData($data_graph);
			$px->SetLegend(array($langs->trans('FixMarginMOTotalQtyEstimated'), $langs->trans('FixMarginMOTotalQtyReal')));
			$px->SetType(array('bars', 'bars'));
			$px->SetWidth('100%');
			$px->SetHeight(300);
			$px->draw('graphqty', 'legendqty');
			print $px->show(true);
		}
	}

	// Lien toggle mode
	print '<p>'.$langs->trans('ChangeModeReport').': <a href="'.dol_escape_htmltag($toggle_url).'">'.$langs->trans($otherLabel).'</a></p>';

	// Bouton export CSV
	$export_url = $_SERVER["PHP_SELF"].'?action=export_csv&tab=tabqty&year='.(int)$year.'&month='.(int)$month.($productid > 0 ? '&productid='.(int)$productid : '').'&type_report='.urlencode($type_report);
	print '<div class="tabsAction">';
	print '<a href="'.dol_escape_htmltag($export_url).'" class="butAction">'.$langs->trans('ExportCSV').'</a>';
	print '</div>';

	// Tableau
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	if ($type_report === 'detail') print '<td>'.$langs->trans('ProductDone').'</td>';
	print '<td>'.$langs->trans('ProductUsed').'</td>';
	print '<td class="right">'.$langs->trans('FixMarginMOTotalQtyEstimated').'</td>';
	print '<td class="right">'.$langs->trans('FixMarginMOTotalQtyReal').'</td>';
	print '<td class="right">'.$langs->trans('PercentLost').'</td>';
	print '</tr>';

	$total_planned = 0;
	$total_real    = 0;

	foreach ($rows_qty as $obj) {
		$qty_planned = (float) $obj->qty_planned;
		$qty_real    = (float) $obj->qty_real;
		$ecart       = ($qty_real != 0) ? round((($qty_planned / $qty_real) - 1) * 100, 2) : null;
		$alerte      = ($ecart !== null && abs($ecart) > $alert_threshold);
		$row_style   = $alerte ? ' style="background-color:#fdd;"' : '';
		$badge_class = $alerte ? 'badge badge-status8' : 'badge badge-status4';

		print '<tr class="oddeven"'.$row_style.'>';
		if ($type_report === 'detail') {
			print '<td class="nowrap">';
			$product = new Product($db);
			$product->fetch($obj->product_created_id);
			print $product->getNomUrl(1);
			print '</td>';
		}
		print '<td class="nowrap">';
		$product_used = new Product($db);
		$product_used->fetch($obj->product_used_id);
		print $product_used->getNomUrl(1);
		print '</td>';
		print '<td class="nowrap right">'.price2num($qty_planned, 'MU').'</td>';
		print '<td class="nowrap right">'.price2num($qty_real, 'MU').'</td>';
		print '<td class="nowrap right">';
		if ($ecart !== null) {
			print '<span class="'.$badge_class.'">'.price2num($ecart, 'MU').' %</span>';
		} else {
			print 'N/A';
		}
		print '</td>';
		print '</tr>';

		$total_planned += $qty_planned;
		$total_real    += $qty_real;
	}

	if (!empty($rows_qty)) {
		$total_ecart  = ($total_real != 0) ? round((($total_planned / $total_real) - 1) * 100, 2) : null;
		$total_alerte = ($total_ecart !== null && abs($total_ecart) > $alert_threshold);
		$total_badge  = $total_alerte ? 'badge badge-status8' : 'badge badge-status4';
		print '<tr class="liste_total">';
		if ($type_report === 'detail') print '<td></td>';
		print '<td>Total</td>';
		print '<td class="nowrap right">'.price2num($total_planned, 'MU').'</td>';
		print '<td class="nowrap right">'.price2num($total_real, 'MU').'</td>';
		print '<td class="nowrap right">';
		if ($total_ecart !== null) {
			print '<span class="'.$total_badge.'">'.price2num($total_ecart, 'MU').' %</span>';
		} else {
			print 'N/A';
		}
		print '</td>';
		print '</tr>';
	}

	if (empty($rows_qty)) {
		$colspan = ($type_report === 'detail') ? 5 : 4;
		print '<tr><td colspan="'.$colspan.'" class="opacitymedium center">'.$langs->trans('NoRecordFound').'</td></tr>';
	}

	print '</table>';
	print '</div>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
