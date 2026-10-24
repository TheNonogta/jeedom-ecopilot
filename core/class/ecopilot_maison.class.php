<?php

/* This file is part of Jeedom. Licence AGPL. */

/**
 * « Valeurs calculées » — un seul équipement porte les valeurs de la maison,
 * calculées à partir des pièces qui comptent.
 */
class ecopilot_maison extends eqLogic {

	public static $_widgetPossibility = array('custom' => true);

	public static function all($_onlyEnable = false) {
		return self::byType(__CLASS__, $_onlyEnable);
	}

	public static function ensure() {
		$all = self::all();
		if (count($all) > 0) {
			return $all[0];
		}
		$object = ecopilot::ensureObject();
		$maison = new ecopilot_maison();
		$maison->setEqType_name(__CLASS__);
		$maison->setName(__('Valeurs calculées', __FILE__));
		$maison->setObject_id(is_object($object) ? $object->getId() : null);
		$maison->setIsEnable(1);
		$maison->setIsVisible(1);
		$maison->save();
		return $maison;
	}

	public function postSave() {
		return;
	}
}
