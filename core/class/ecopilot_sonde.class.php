<?php

/* This file is part of Jeedom. Licence AGPL. */

/**
 * Une sonde = un équipement Éco Pilot.
 * Elle porte autant de grandeurs que voulu (température, hygrométrie, luminosité…),
 * chacune reliée à une ou plusieurs commandes d'information d'autres plugins.
 */
class ecopilot_sonde extends eqLogic {

	public static $_widgetPossibility = array('custom' => true);

	public static function all($_onlyEnable = false) {
		return self::byType(__CLASS__, $_onlyEnable);
	}

	/* -------------------------------------------------- grandeurs */

	public function getGrandeurs() {
		$raw = $this->getConfiguration('grandeurs', '');
		$grandeurs = ($raw === '' || $raw === null || $raw === '[]') ? array() : json_decode($raw, true);
		if (!is_array($grandeurs)) {
			$grandeurs = array();
		}
		ksort($grandeurs);
		return $grandeurs;
	}

	public function setGrandeurs($_grandeurs) {
		$this->setConfiguration('grandeurs', json_encode($_grandeurs));
	}

	public function getGrandeur($_key) {
		$all = $this->getGrandeurs();
		return isset($all[$_key]) ? $all[$_key] : null;
	}

	public function setGrandeur($_key, $_conf) {
		$all = $this->getGrandeurs();
		$all[$_key] = $_conf;
		$this->setGrandeurs($all);
	}

	public function removeGrandeur($_key) {
		$all = $this->getGrandeurs();
		unset($all[$_key]);
		$this->setGrandeurs($all);
	}

	/* -------------------------------------------------- pièce */

	public function getPieceId() {
		return intval($this->getConfiguration('piece', 0));
	}

	public function setPieceId($_id) {
		$this->setConfiguration('piece', intval($_id));
	}

	public function getPiece() {
		$piece = ecopilot_piece::byId($this->getPieceId());
		return is_object($piece) ? $piece : null;
	}

	/* -------------------------------------------------- sources reliées */

	public function getSources($_key) {
		$grandeur = $this->getGrandeur($_key);
		if (!is_array($grandeur) || !isset($grandeur['sources'])) {
			return array();
		}
		$sources = array();
		foreach ($grandeur['sources'] as $cmdId) {
			$sources[] = intval($cmdId);
		}
		return $sources;
	}

	public function addSource($_key, $_cmdId) {
		$grandeur = $this->getGrandeur($_key);
		if (!is_array($grandeur)) {
			$grandeur = array('sources' => array(), 'agg' => 'mean');
		}
		if (!isset($grandeur['sources'])) {
			$grandeur['sources'] = array();
		}
		if (!in_array(intval($_cmdId), $grandeur['sources'])) {
			$grandeur['sources'][] = intval($_cmdId);
		}
		if (!isset($grandeur['fresh'])) {
			$grandeur['fresh'] = intval(config::byKey('freshDefault', 'ecopilot', 60));
		}
		if (!isset($grandeur['agg'])) {
			$def = ecopilot::grandeur($_key);
			$grandeur['agg'] = (is_array($def) && $def['type'] == 'binary') ? 'or' : 'mean';
		}
		$this->setGrandeur($_key, $grandeur);
	}

	public function removeSource($_key, $_cmdId) {
		$grandeur = $this->getGrandeur($_key);
		if (!is_array($grandeur) || !isset($grandeur['sources'])) {
			return;
		}
		$sources = array();
		foreach ($grandeur['sources'] as $cmdId) {
			if (intval($cmdId) != intval($_cmdId)) {
				$sources[] = intval($cmdId);
			}
		}
		$grandeur['sources'] = array_values($sources);
		$this->setGrandeur($_key, $grandeur);
	}

	/* -------------------------------------------------- état */

	public function getEtat() {
		return ecopilot::sondeState($this);
	}

	public function getEtatLabel() {
		return ecopilot::stateLabel($this->getEtat());
	}

	/** Nombre de sources reliées sur l'ensemble de la sonde. */
	public function getNbSources() {
		$nb = 0;
		foreach ($this->getGrandeurs() as $key => $conf) {
			$nb += count($this->getSources($key));
		}
		return $nb;
	}

	public function postSave() {
		return;
	}
}
