<?php
/* Copyright (C) 2017       Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2024  Frédéric France          <frederic.france@free.fr>
 * Copyright (C) 2024		MDW                      <mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024 Florian Henry <florian.henry@scopen.fr>
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
 * \file        fixmargin/class/fixmarginhelper.class.php
 * \ingroup     fixmargin
 * \brief       This file is a base class file for Fix Margin
 */

require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

/**
 * Class for IrisAccounting
 */
class FixMarginHelpers
{

	/**
	 * @var string        Error string
	 * @see             $errors
	 */
	public $error;

	/**
	 * @var string[]    Array of error strings
	 */
	public $errors = array();

	/**
	 * @var DoliDb		Database handler (result of a new DoliDB)
	 */
	public $db;

	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param $moId MO Id
	 * @return int 1 for OK, < 0 for KO
	 */

	public function calculateMOCost($moId = 0)
	{
		global $langs;

		if (!empty($moId)) {
			$mo = new Mo($this->db);
			$resultFetch = $mo->fetch($moId);
			if ($resultFetch < 0) {
				$this->error = $mo->error;
				$this->errors[] = $this->error;
				$this->errors = array_merge($mo->errors, $this->errors);
				return -1;
			}
			if (empty($mo->mrptype)) {
				$bom = new BOM($this->db);
				if (!empty($mo->fk_bom)) {
					$resFetchBOM = $bom->fetch($mo->fk_bom);
					if ($resFetchBOM < 0) {
						$this->error = $bom->error;
						$this->errors[] = $this->error;
						$this->errors = array_merge($bom->errors, $this->errors);
						return -1;
					}
					$qtyBOMProdLine = array();
					if (!empty($bom->lines)) {
						foreach ($bom->lines as $bomline) {
							if (!isset($qtyBOMProdLine[$bomline->fk_product])) $qtyBOMProdLine[$bomline->fk_product] = 0;
							$qtyBOMProdLine[$bomline->fk_product] += $bomline->qty;
						}
					}
					$bom->calculateCosts();
				}
				if (!empty($mo->lines)) {
					$qty_estimated_total = 0;
					$qty_real_total = 0;
					$cost_estimated_total = 0;
					$cost_stock_total = 0;
					$cost_real_total = 0;
					$consumeProdQty = array();
					$producedProdQty = 0;

					require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.product.class.php';
					$productFournisseur = new ProductFournisseur($this->db);
					$tmpproduct = new Product($this->db);

					foreach ($mo->lines as $line) {
						$tmpproduct->cost_price = 0;
						$tmpproduct->pmp = 0;
						$result = $tmpproduct->fetch($line->fk_product, '', '', '', 0, 1, 1);    // We discard selling price and language loading
						if ($result < 0) {
							$this->error = $tmpproduct->error;
							return -2;
						}
						if ($line->role == 'toconsume') {
							if ($tmpproduct->type == $tmpproduct::TYPE_PRODUCT) {
								$unit_cost = price2num((!empty($tmpproduct->cost_price)) ? $tmpproduct->cost_price : $tmpproduct->pmp);
								if (empty($unit_cost)) {
									if ($productFournisseur->find_min_price_product_fournisseur($line->fk_product) > 0) {
										if ($productFournisseur->fourn_remise_percent != "0") {
											$unit_cost = $productFournisseur->fourn_unitprice_with_discount;
										} else {
											$unit_cost = $productFournisseur->fourn_unitprice;
										}
									}
								}

								$line_total_cost = price2num($line->qty * $unit_cost, 'MT');

								$qty_estimated_total += $line->qty;

								$cost_estimated_total += $line_total_cost;
							} else {
								// Convert qty of line into hours
								$unitforline = measuringUnitString($line->fk_unit, '', '', 1);
								$qtyhourforline = convertDurationtoHour($line->qty, $unitforline);

								if (isModEnabled('workstation') && !empty($line->fk_default_workstation)) {
									$workstation = new Workstation($this->db);
									$res = $workstation->fetch($line->fk_default_workstation);

									if ($res > 0) {
										$line_total_cost = price2num($qtyhourforline * ($workstation->thm_operator_estimated + $workstation->thm_machine_estimated), 'MT');
									} else {
										$this->error = $workstation->error;
										return -3;
									}
								} else {
									$defaultdurationofservice = $tmpproduct->duration;
									$reg = array();
									$qtyhourservice = 0;
									if (preg_match('/^(\d+)([a-z]+)$/', $defaultdurationofservice, $reg)) {
										$qtyhourservice = convertDurationtoHour($reg[1], $reg[2]);
									}

									if ($qtyhourservice) {
										$line_total_cost = price2num($qtyhourforline / $qtyhourservice * $tmpproduct->cost_price, 'MT');
									} else {
										$line_total_cost = price2num($line->qty * $tmpproduct->cost_price, 'MT');
									}
								}

								$cost_estimated_total += $line_total_cost;
							}
						}
						if ($line->role == 'consumed') {
							if ($tmpproduct->type == $tmpproduct::TYPE_PRODUCT) {
								$qty_real_total += $line->qty;
								if (!isset($consumeProdQty[$tmpproduct->id])) $consumeProdQty[$tmpproduct->id] = 0;
								$consumeProdQty[$tmpproduct->id] += $line->qty;
							}
						}
						if ($line->role == 'produced') {

							if ($tmpproduct->type == $tmpproduct::TYPE_PRODUCT) {
								require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
								$stockmove = new MouvementStock($this->db);
								$restFetchStockMov = $stockmove->fetch($line->fk_stock_movement);
								if ($restFetchStockMov < 0) {
									$this->error = $stockmove->error;
									$this->errors[] = $this->error;
									$this->errors = array_merge($stockmove->errors, $this->errors);
									return -4;
								}

								$cost_stock_total += $stockmove->price * $stockmove->qty;
								$producedProdQty += $stockmove->qty;
							}
						}
					}

					//  $cost_real_total = (PU MO÷(QtéLine MO du produit consomé×Qté produite)×Qté consomé par produit
					if (!empty($consumeProdQty) && !empty($bom->unit_cost)) {
						foreach ($consumeProdQty as $prodId => $qty) {
							if (isset($qtyBOMProdLine[$prodId]) && !empty($producedProdQty)) {
								$cost_real_total += ($bom->unit_cost / ($qtyBOMProdLine[$prodId] * $producedProdQty)) * $qty;
							}

						}
					}

					$mo->array_options['options_cost_estimated_total'] = price2num($cost_estimated_total, 'MT');
					$mo->array_options['options_qty_estimated_total'] = price2num($qty_estimated_total, 'MT');
					$mo->array_options['options_qty_real_total'] = price2num($qty_real_total, 'MT');
					$mo->array_options['options_cost_stock_total'] = price2num($cost_stock_total, 'MT');
					$mo->array_options['options_cost_real_total'] = price2num($cost_real_total, 'MT');
					$time_estimated='';
					if (!empty($mo->date_start_planned) && !empty($mo->date_end_planned)) {
						$nbdays = num_between_day($mo->date_start_planned,$mo->date_end_planned,1);
						if ($nbdays>0) {
							$time_estimated .= $nbdays . ' '.$langs->trans('Day').($nbdays>1?'s':'');
						}
					}

					$mo->array_options['options_time_estimated'] = $time_estimated;

					$resultUpdate = $mo->insertExtraFields();
					if ($resultUpdate < 0) {
						$this->error = $mo->error;
						$this->errors[] = $this->error;
						$this->errors = array_merge($mo->errors, $this->errors);
						return -5;
					}
				}
			}
		}
		return 1;
	}
}
