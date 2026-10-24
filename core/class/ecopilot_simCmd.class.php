<?php

/* This file is part of Jeedom. Licence AGPL. */

/** Commandes du simulateur : pousser une valeur (curseur) ou en générer d'aléatoires. */
class ecopilot_simCmd extends cmd {

	public function execute($_options = array()) {
		$logicalId = $this->getLogicalId();
		$sim = ecopilot_sim::byId($this->getEqLogic_id());
		if (!is_object($sim)) {
			throw new Exception(__('Simulateur introuvable', __FILE__));
		}
		if ($logicalId == 'aleatoire') {
			ecopilot_sim::pushRandom($sim);
			return true;
		}
		if (strpos($logicalId, 'set_') !== 0) {
			return true;
		}
		$key = substr($logicalId, 4);
		$value = null;
		if (is_array($_options)) {
			if (isset($_options['slider']) && $_options['slider'] !== '') {
				$value = floatval($_options['slider']);
			} elseif (isset($_options['message']) && $_options['message'] !== '') {
				$value = floatval(str_replace(',', '.', $_options['message']));
			}
		}
		if ($value === null || !isset(ecopilot_sim::$grandeurs[$key])) {
			throw new Exception(__('Valeur non fournie pour', __FILE__) . ' ' . $key);
		}
		if ($key == 'eclairement') {
			$value = intval($value);
		}
		ecopilot_sim::push($sim, $key, $value);
		return true;
	}
}
