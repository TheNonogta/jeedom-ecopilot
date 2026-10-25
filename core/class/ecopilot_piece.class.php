<?php

/* This file is part of Jeedom. Licence AGPL. */

/**
 * Une pièce = un équipement Éco Pilot qui regroupe des sondes.
 * Elle expose les moyennes de la pièce (température, hygrométrie, luminosité…).
 */
class ecopilot_piece extends eqLogic {

	public static $_widgetPossibility = array('custom' => true);

	public static function all($_onlyEnable = false) {
		return self::byType(__CLASS__, $_onlyEnable);
	}

	public function getPieceType() {
		return ($this->getConfiguration('piece_type', 'interieur') == 'exterieur') ? 'exterieur' : 'interieur';
	}

	public function setPieceType($_type) {
		$this->setConfiguration('piece_type', ($_type == 'exterieur') ? 'exterieur' : 'interieur');
	}

	public function getIcon() {
		$icon = $this->getConfiguration('icon', '');
		return ($icon != '') ? $icon : 'fas fa-home';
	}

	public function getIsMaison() {
		return intval($this->getConfiguration('maison', 1)) == 1;
	}

	public function setIsMaison($_value) {
		$this->setConfiguration('maison', (intval($_value) == 1) ? 1 : 0);
	}

	/** Sondes rattachées : array( id de sonde => 1 si elle compte dans la moyenne, 0 sinon ). */
	public function getSondes() {
		$raw = $this->getConfiguration('sondes', '');
		$sondes = ($raw === '' || $raw === null || $raw === '[]') ? array() : json_decode($raw, true);
		if (!is_array($sondes)) {
			$sondes = array();
		}
		$clean = array();
		foreach ($sondes as $sondeId => $compte) {
			$id = intval($sondeId);
			if ($id <= 0) {
				continue;
			}
			/* Une sonde supprimée ailleurs (liste des équipements, scénario) ne doit pas
			 * rester comptée ici : on ne garde que ce qui existe encore. */
			if (!is_object(ecopilot_sonde::byId($id))) {
				continue;
			}
			$clean[$id] = intval($compte) ? 1 : 0;
		}
		return $clean;
	}

	public function setSondes($_sondes) {
		$this->setConfiguration('sondes', json_encode($_sondes));
	}

	public function addSonde($_sondeId, $_compte = 1) {
		$sondes = $this->getSondes();
		$sondes[intval($_sondeId)] = intval($_compte) ? 1 : 0;
		$this->setSondes($sondes);
	}

	public function removeSonde($_sondeId) {
		$sondes = $this->getSondes();
		unset($sondes[intval($_sondeId)]);
		$this->setSondes($sondes);
	}

	/** Sondes réellement rattachées (objets). */
	public function getSondesObjets() {
		$sondes = array();
		foreach ($this->getSondes() as $sondeId => $compte) {
			$sonde = ecopilot_sonde::byId($sondeId);
			if (is_object($sonde)) {
				$sonde->setConfiguration('_compte', $compte);
				$sondes[] = $sonde;
			}
		}
		return $sondes;
	}

	/** Grandeurs disponibles dans cette pièce (clé => définition), d'après ses sondes. */
	public function getGrandeursDisponibles() {
		$keys = array();
		foreach ($this->getSondes() as $sondeId => $compte) {
			$sonde = ecopilot_sonde::byId($sondeId);
			if (!is_object($sonde)) {
				continue;
			}
			foreach ($sonde->getGrandeurs() as $key => $conf) {
				if (!isset($keys[$key])) {
					$def = ecopilot::grandeur($key);
					$keys[$key] = is_array($def) ? $def : array('key' => $key, 'label' => $key, 'type' => 'numeric', 'unit' => '');
				}
			}
		}
		ksort($keys);
		return $keys;
	}

	public function postSave() {
		return;
	}
}
