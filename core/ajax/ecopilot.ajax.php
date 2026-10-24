<?php

/* This file is part of Jeedom. Licence AGPL.
 *
 * Points d'entrée AJAX du plugin Éco Pilot.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

try {
	ajax::init();

	$action = init('action');

	/* ------------------------------------------------------------ sondes */

	if ($action == 'createSonde') {
		$object = ecopilot::ensureObject();
		$sonde = new ecopilot_sonde();
		$sonde->setEqType_name('ecopilot_sonde');
		$sonde->setName(trim(init('name', __('Nouvelle sonde', __FILE__))));
		$sonde->setObject_id(is_object($object) ? $object->getId() : null);
		$sonde->setIsEnable(1);
		$sonde->setIsVisible(1);
		$sonde->setPieceId(init('piece_id', 0));
		$sonde->setGrandeurs(array(
			'temperature' => array(
				'sources' => array(),
				'agg' => 'mean',
				'fresh' => intval(config::byKey('freshDefault', 'ecopilot', 60)),
			),
		));
		$sonde->save();
		ecopilot::refreshSonde($sonde);
		ajax::success(array('id' => $sonde->getId()));
	}

	if ($action == 'saveSonde') {
		$sonde = ecopilot_sonde::byId(init('id'));
		if (!is_object($sonde)) {
			throw new Exception(__('Sonde introuvable', __FILE__));
		}
		if (init('name') !== '') {
			$sonde->setName(trim(init('name')));
		}
		$sonde->setPieceId(init('piece_id', 0));
		$sonde->setIsEnable(init('enable', 1));
		$sonde->save();
		ecopilot::registerListeners();
		ecopilot::refreshSonde($sonde);
		ecopilot::refreshMaison();
		ajax::success();
	}

	if ($action == 'deleteSonde') {
		$sonde = ecopilot_sonde::byId(init('id'));
		if (!is_object($sonde)) {
			throw new Exception(__('Sonde introuvable', __FILE__));
		}
		foreach (ecopilot_piece::all() as $piece) {
			$piece->removeSonde($sonde->getId());
			$piece->save();
			ecopilot::refreshPiece($piece);
		}
		foreach ($sonde->getCmd() as $cmd) {
			$cmd->remove();
		}
		$sonde->remove();
		ecopilot::registerListeners();
		ecopilot::refreshMaison();
		ajax::success();
	}

	if ($action == 'addGrandeur') {
		$sonde = ecopilot_sonde::byId(init('sonde_id'));
		$key = init('key');
		$def = ecopilot::grandeur($key);
		if (!is_object($sonde) || $def === null) {
			throw new Exception(__('Grandeur inconnue', __FILE__));
		}
		$sonde->setGrandeur($key, array(
			'sources' => array(),
			'agg' => ($def['type'] == 'binary') ? 'or' : 'mean',
			'fresh' => intval(config::byKey('freshDefault', 'ecopilot', 60)),
		));
		$sonde->save();
		ecopilot::refreshSonde($sonde);
		ajax::success();
	}

	if ($action == 'addCustomGrandeur') {
		$sonde = ecopilot_sonde::byId(init('sonde_id'));
		$label = trim(init('label'));
		if (!is_object($sonde) || $label === '') {
			throw new Exception(__('Nom de la grandeur manquant', __FILE__));
		}
		$key = 'x_' . ecopilot::slug($label);
		$type = init('type', 'numeric');
		if (!in_array($type, array('numeric', 'binary', 'string'))) {
			$type = 'numeric';
		}
		$sonde->setGrandeur($key, array(
			'label' => $label,
			'unit' => trim(init('unit')),
			'type' => $type,
			'sources' => array(),
			'agg' => ($type == 'binary') ? 'or' : 'mean',
			'fresh' => intval(config::byKey('freshDefault', 'ecopilot', 60)),
		));
		$sonde->save();
		ecopilot::refreshSonde($sonde);
		ajax::success(array('key' => $key));
	}

	if ($action == 'removeGrandeur') {
		$sonde = ecopilot_sonde::byId(init('sonde_id'));
		$key = init('key');
		if (!is_object($sonde)) {
			throw new Exception(__('Sonde introuvable', __FILE__));
		}
		ecopilot::removeGrandeurCmds($sonde, $key);
		$sonde->removeGrandeur($key);
		$sonde->save();
		ecopilot::registerListeners();
		ecopilot::refreshSonde($sonde);
		ecopilot::refreshMaison();
		ajax::success();
	}

	if ($action == 'linkSource') {
		$sonde = ecopilot_sonde::byId(init('sonde_id'));
		$key = init('key');
		$cmd = cmd::byId(init('cmd_id'));
		$def = ecopilot::grandeur($key);
		if (!is_object($sonde) || !is_object($cmd) || $def === null) {
			throw new Exception(__('Reliage impossible : élément introuvable', __FILE__));
		}
		if ($cmd->getType() != 'info') {
			throw new Exception(__('Seule une commande d\'information peut être reliée à une entrée', __FILE__));
		}
		if ($def['type'] == 'numeric' && $cmd->getSubType() != 'numeric') {
			throw new Exception(__('Cette grandeur attend une commande numérique, or la commande choisie est de type', __FILE__) . ' ' . $cmd->getSubType());
		}
		if ($def['type'] == 'binary' && $cmd->getSubType() != 'binary') {
			throw new Exception(__('Cette grandeur attend une commande oui/non, or la commande choisie est de type', __FILE__) . ' ' . $cmd->getSubType());
		}
		$sonde->addSource($key, $cmd->getId());
		$sonde->save();
		ecopilot::registerListeners();
		ecopilot::refreshSonde($sonde);
		$piece = $sonde->getPiece();
		if (is_object($piece)) {
			ecopilot::refreshPiece($piece);
		}
		ecopilot::refreshMaison();
		ajax::success(array('human' => $cmd->getHumanName(), 'unite' => $cmd->getUnite()));
	}

	if ($action == 'unlinkSource') {
		$sonde = ecopilot_sonde::byId(init('sonde_id'));
		if (!is_object($sonde)) {
			throw new Exception(__('Sonde introuvable', __FILE__));
		}
		$sonde->removeSource(init('key'), init('cmd_id'));
		$sonde->save();
		ecopilot::registerListeners();
		ecopilot::refreshSonde($sonde);
		ecopilot::refreshMaison();
		ajax::success();
	}

	if ($action == 'saveGrandeurOptions') {
		$sonde = ecopilot_sonde::byId(init('sonde_id'));
		$key = init('key');
		if (!is_object($sonde)) {
			throw new Exception(__('Sonde introuvable', __FILE__));
		}
		$grandeur = $sonde->getGrandeur($key);
		if (!is_array($grandeur)) {
			throw new Exception(__('Grandeur non activée sur cette sonde', __FILE__));
		}
		if (init('agg') !== '') {
			$grandeur['agg'] = init('agg');
		}
		if (init('fresh') !== '') {
			$grandeur['fresh'] = intval(init('fresh'));
		}
		$sonde->setGrandeur($key, $grandeur);
		$sonde->save();
		ecopilot::refreshSonde($sonde);
		ajax::success();
	}

	if ($action == 'refreshSonde') {
		$sonde = ecopilot_sonde::byId(init('id'));
		if (!is_object($sonde)) {
			throw new Exception(__('Sonde introuvable', __FILE__));
		}
		ecopilot::refreshSonde($sonde);
		$piece = $sonde->getPiece();
		if (is_object($piece)) {
			ecopilot::refreshPiece($piece);
		}
		ecopilot::refreshMaison();
		ajax::success();
	}

	/* ------------------------------------------------------------ pièces */

	if ($action == 'createPiece') {
		$object = ecopilot::ensureObject();
		$piece = new ecopilot_piece();
		$piece->setEqType_name('ecopilot_piece');
		$piece->setName(trim(init('name', __('Nouvelle pièce', __FILE__))));
		$piece->setObject_id(is_object($object) ? $object->getId() : null);
		$piece->setIsEnable(1);
		$piece->setIsVisible(1);
		$piece->setPieceType(init('piece_type', 'interieur'));
		$piece->setConfiguration('icon', init('icon', 'fas fa-home'));
		$piece->setIsMaison(1);
		$piece->setSondes(array());
		$piece->save();
		ajax::success(array('id' => $piece->getId()));
	}

	if ($action == 'savePiece') {
		$piece = ecopilot_piece::byId(init('id'));
		if (!is_object($piece)) {
			throw new Exception(__('Pièce introuvable', __FILE__));
		}
		if (init('name') !== '') {
			$piece->setName(trim(init('name')));
		}
		$piece->setPieceType(init('piece_type', 'interieur'));
		$piece->setConfiguration('icon', init('icon', 'fas fa-home'));
		$piece->setIsMaison(init('maison', 1));
		$piece->save();
		ecopilot::refreshPiece($piece);
		ecopilot::refreshMaison();
		ajax::success();
	}

	if ($action == 'deletePiece') {
		$piece = ecopilot_piece::byId(init('id'));
		if (!is_object($piece)) {
			throw new Exception(__('Pièce introuvable', __FILE__));
		}
		foreach (ecopilot_sonde::all() as $sonde) {
			if ($sonde->getPieceId() == $piece->getId()) {
				$sonde->setPieceId(0);
				$sonde->save();
			}
		}
		foreach ($piece->getCmd() as $cmd) {
			$cmd->remove();
		}
		$piece->remove();
		ecopilot::refreshMaison();
		ajax::success();
	}

	if ($action == 'setPieceSondes') {
		$piece = ecopilot_piece::byId(init('id'));
		if (!is_object($piece)) {
			throw new Exception(__('Pièce introuvable', __FILE__));
		}
		$sondes = json_decode(init('sondes', '[]'), true);
		if (!is_array($sondes)) {
			$sondes = array();
		}
		$clean = array();
		foreach ($sondes as $sondeId => $compte) {
			$sondeId = intval($sondeId);
			$clean[$sondeId] = intval($compte) ? 1 : 0;
			$sonde = ecopilot_sonde::byId($sondeId);
			if (is_object($sonde) && $sonde->getPieceId() != $piece->getId()) {
				$sonde->setPieceId($piece->getId());
				$sonde->save();
			}
		}
		$piece->setSondes($clean);
		$piece->save();
		ecopilot::refreshPiece($piece);
		ecopilot::refreshMaison();
		ajax::success();
	}

	/* ------------------------------------------------------------ maison & simulateur */

	if ($action == 'refreshAll') {
		ecopilot::registerListeners();
		ecopilot::refreshAll();
		ajax::success();
	}

	if ($action == 'simPush') {
		$sim = ecopilot_sim::ensure();
		$key = init('key');
		if ($key == 'aleatoire') {
			ecopilot_sim::pushRandom($sim);
			ajax::success();
		}
		if (!isset(ecopilot_sim::$grandeurs[$key])) {
			throw new Exception(__('Grandeur inconnue pour le simulateur', __FILE__));
		}
		ecopilot_sim::push($sim, $key, init('value'));
		ajax::success();
	}

	throw new Exception(__('Action inconnue', __FILE__) . ' : ' . $action);
} catch (Exception $e) {
	ajax::error(displayException($e), $e->getCode());
}
