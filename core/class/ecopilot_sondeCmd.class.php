<?php

/* This file is part of Jeedom. Licence AGPL. */

/** Commandes d'une sonde : aujourd'hui une seule action, « Rafraîchir ». */
class ecopilot_sondeCmd extends cmd {

	public function execute($_options = array()) {
		$logicalId = $this->getLogicalId();
		if ($logicalId != 'rafraichir') {
			return true;
		}
		$sonde = ecopilot_sonde::byId($this->getEqLogic_id());
		if (!is_object($sonde)) {
			throw new Exception(__('Sonde introuvable', __FILE__));
		}
		ecopilot::refreshSonde($sonde);
		$piece = $sonde->getPiece();
		if (is_object($piece)) {
			ecopilot::refreshPiece($piece);
		}
		ecopilot::refreshMaison();
		return true;
	}
}
