<?php
/* Copyright (C) 2024 Jeffinfo - Olivier Geffroy <jeff@jeffinfo.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    core/triggers/interface_99_modFixMargin_FixMarginTriggers.class.php
 * \ingroup fixmargin
 * \brief   Example trigger.
 *
 * Put detailed description here.
 *
 * \remarks You can create other triggers by copying this one.
 * - File name should be either:
 *      - interface_99_modFixMargin_MyTrigger.class.php
 *      - interface_99_all_MyTrigger.class.php
 * - The file must stay in core/triggers
 * - The class name must be InterfaceMytrigger
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';

/**
 *  Class of triggers for FixMargin module
 */
class InterfaceFixMarginTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "MO";
		$this->description = "FixMargin triggers.";
		// 'development', 'experimental', 'dolibarr' or version
		$this->version = 'dolibarr';
		$this->picto = 'fixmargin@fixmargin';
	}

	/**
	 * Trigger name
	 *
	 * @return string Name of trigger file
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc()
	{
		return $this->description;
	}


	/**
	 * Function called when a Dolibarrr business event is done.
	 * All functions "runTrigger" are triggered if file
	 * is inside directory core/triggers
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		Return integer <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('fixmargin')) {
			return 0; // If module is not enabled, we do nothing
		}

		// Put here code you want to execute when a Dolibarr business events occurs.
		// Data and type of action are stored into $object and $action

		// You can isolate code for each action in a separate method: this method should be named like the trigger in camelCase.
		// For example : COMPANY_CREATE => public function companyCreate($action, $object, User $user, Translate $langs, Conf $conf)
		$methodName = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($action)))));
		$callback = array($this, $methodName);
		if (is_callable($callback)) {
			dol_syslog(
				"Trigger '".$this->name."' for action '$action' launched by ".__FILE__.". id=".$object->id
			);

			return call_user_func($callback, $action, $object, $user, $langs, $conf);
		}

		// Or you can execute some code here
		switch ($action) {
//			case 'MOLINE_CREATE':
//				dol_include_once('/fixmargin/class/fixmarginhelper.class.php');
//				$fixMarginHelper = new FixMarginHelpers($this->db);
//				$resultUpdCost = $fixMarginHelper->calculateMOCost($object->fk_mo);
//				if ($resultUpdCost<0) {
//					$this->errors[] = $fixMarginHelper->error;
//					$this->errors = array_merge($this->errors, $fixMarginHelper->errors);
//					return -1;
//				}
//				break;

			default:
				dol_syslog("Trigger '".$this->name."' for action '".$action."' launched by ".__FILE__.". id=".$object->id);
				break;
		}

		return 0;
	}

	/**
	 * @param $moId MO Id
	 * @return int 1 for OK, < 0 for KO
	 */
	private function calcMoCost($moId=0) {
		dol_include_once('/fixmargin/class/fixmarginhelper.class.php');
		$fixMarginHelper = new FixMarginHelpers($this->db);
		$resultUpdCost = $fixMarginHelper->calculateMOCost($moId);
		if ($resultUpdCost<0) {
			$this->errors[] = $fixMarginHelper->error;
			$this->errors = array_merge($this->errors, $fixMarginHelper->errors);
			return -1;
		}
		return 1;
	}

	/**
	 * @param string $action action
	 * @param MoLine $object MoLine
	 * @param User $user current user
	 * @param Translate $langs translation
	 * @param Conf $conf conf
	 * @return int 1 for OK, < 0 for KO
 	*/
	public function moLineCreate($action, MoLine $object, User $user, Translate $langs, Conf $conf) {
		return $this->calcMoCost($object->fk_mo);
	}

	/**
	 * @param string $action action
	 * @param Mo $object Mo
	 * @param User $user current user
	 * @param Translate $langs translation
	 * @param Conf $conf conf
	 * @return int 1 for OK, < 0 for KO
 	*/
	public function moCreate($action, Mo $object, User $user, Translate $langs, Conf $conf) {
		return $this->calcMoCost($object->id);
	}
}
