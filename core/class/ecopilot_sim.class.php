<?php

/* This file is part of Jeedom. Licence AGPL. */

/**
 * Simulateur Éco Pilot — joue le rôle d'un « équipement d'un autre plugin » :
 * il fournit des commandes d'information (température, hygrométrie, luminosité) que l'on relie
 * à une sonde, et des actions pour pousser une valeur à la main et éprouver le suivi en direct.
 */
class ecopilot_sim extends eqLogic {

	public static $_widgetPossibility = array('custom' => true);

	public static $grandeurs = array(
		'temperature' => array('label' => 'Température', 'unite' => '°C', 'defaut' => 20.5, 'min' => -10, 'max' => 35),
		'hygrometrie' => array('label' => 'Hygrométrie', 'unite' => '%', 'defaut' => 48, 'min' => 10, 'max' => 95),
		'eclairement' => array('label' => 'Luminosité', 'unite' => 'lx', 'defaut' => 320, 'min' => 0, 'max' => 2000),
	);

	public static function all($_onlyEnable = false) {
		return self::byType(__CLASS__, $_onlyEnable);
	}

	public static function ensure() {
		$all = self::all();
		if (count($all) > 0) {
			return $all[0];
		}
		$object = ecopilot::ensureObject();
		$sim = new ecopilot_sim();
		$sim->setEqType_name(__CLASS__);
		$sim->setName(__('Simulateur Éco Pilot', __FILE__));
		$sim->setObject_id(is_object($object) ? $object->getId() : null);
		$sim->setIsEnable(1);
		$sim->setIsVisible(0);
		$sim->save();
		ecopilot_sim::ensureCmds($sim);
		return $sim;
	}

	public static function ensureCmds($_sim) {
		$order = 0;
		foreach (self::$grandeurs as $key => $def) {
			$order++;
			ecopilot::ensureCmd($_sim, $key, $def['label'], array(
				'subType' => 'numeric',
				'unite' => $def['unite'],
				'historize' => 1,
				'order' => $order,
			));
			ecopilot::ensureCmd($_sim, 'set_' . $key, 'Pousser ' . $def['label'], array(
				'type' => 'action',
				'subType' => 'slider',
				'historize' => 0,
				'order' => 100 + $order,
				'visible' => 0,
			));
		}
		ecopilot::ensureCmd($_sim, 'aleatoire', 'Valeurs aléatoires', array(
			'type' => 'action',
			'subType' => 'other',
			'historize' => 0,
			'order' => 200,
		));
	}

	/** Pousse une valeur dans une commande d'information (comme le ferait un vrai module). */
	public static function push($_sim, $_key, $_value) {
		$cmd = ecopilot::cmdOf($_sim, $_key);
		if (!is_object($cmd)) {
			return false;
		}
		ecopilot::posCmdValue($cmd, $_value);
		return true;
	}

	public static function pushRandom($_sim) {
		foreach (self::$grandeurs as $key => $def) {
			$value = $def['min'] + (mt_rand(0, 1000) / 1000) * ($def['max'] - $def['min']);
			self::push($_sim, $key, ($key == 'eclairement') ? intval($value) : round($value, 1));
		}
	}

	public function postSave() {
		return;
	}
}
