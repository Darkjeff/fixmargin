<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2024 Jeffinfo - Olivier Geffroy <jeff@jeffinfo.com>
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp)-1; $j = strlen($tmp2)-1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp,0,($i+1))."/main.inc.php")) $res = @include substr($tmp,0,($i+1))."/main.inc.php";
if (!$res && $i > 0 && file_exists(dirname(substr($tmp,0,($i+1)))."/main.inc.php")) $res = @include dirname(substr($tmp,0,($i+1)))."/main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $langs, $user;

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/fixmargin.lib.php';

$langs->loadLangs(array("admin", "fixmargin@fixmargin"));
$hookmanager->initHooks(array('fixmarginsetup', 'globalsetup'));

if (!$user->admin) accessforbidden();

$action     = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$error = 0;

/*
 * Actions
 */

if ($action == 'recalallmo') {
	dol_include_once('/fixmargin/class/fixmarginhelper.class.php');
	$sql = "SELECT rowid FROM ".$db->prefix()."mrp_mo as t WHERE t.entity IN (".getEntity('mo').") AND (t.status=".Mo::STATUS_PRODUCED.")";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$helper = new FixMarginHelpers($db);
			$resultCalc = $helper->calculateMOCost($obj->rowid);
			if ($resultCalc < 0) setEventMessages($helper->error, $helper->errors, 'errors');
		}
	} else {
		setEventMessage($db->lasterror(), 'errors');
	}
}

if ($action == 'savethreshold') {
	$threshold = GETPOST('FIXMARGIN_ALERT_THRESHOLD', 'int');
	if ($threshold < 1 || $threshold > 100) {
		setEventMessage($langs->trans('BadValueForParameter'), 'errors');
		$error++;
	}
	if (!$error) {
		dolibarr_set_const($db, 'FIXMARGIN_ALERT_THRESHOLD', (int)$threshold, 'chaine', 0, '', $conf->entity);
		setEventMessage($langs->trans('SetupSaved'));
	}
}

/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans('FixMarginSetup'), '', '', 0, 0, '', '', '', 'mod-fixmargin page-admin');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('FixMarginSetup'), $linkback, 'title_setup');

$head = fixmarginAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('FixMarginSetup'), -1, "fixmargin@fixmargin");

// Recalcul MO
print '<form name="recalmo" action="'.$_SERVER["PHP_SELF"].'" method="POST">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="recalallmo">';
print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';
print '<input type="submit" class="button" name="recalallmo" value="'.$langs->trans('FixMarginCalcMO').'">';
print '</form>';

print '<br>';

// Seuil d'alerte
print '<form name="threshold" action="'.$_SERVER["PHP_SELF"].'" method="POST">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savethreshold">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3">'.$langs->trans('FixMarginAlertThreshold').'</td></tr>';
print '<tr class="oddeven">';
print '<td class="titlefield">'.$langs->trans('FixMarginAlertThreshold').'</td>';
print '<td><input type="number" name="FIXMARGIN_ALERT_THRESHOLD" class="flat maxwidth50" min="1" max="100" value="'.getDolGlobalInt('FIXMARGIN_ALERT_THRESHOLD', 20).'"> %</td>';
print '<td class="right"><input type="submit" class="button" value="'.$langs->trans('Save').'"></td>';
print '</tr>';
print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('FixMarginAlertThresholdDesc').'</span></td></tr>';
print '</table>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
