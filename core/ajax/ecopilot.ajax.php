<?php

/* This file is part of Jeedom. Licence AGPL.
 *
 * Points d'entrée AJAX du plugin Éco Pilot.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../class/ecopilot_opti.class.php';

try {
	ajax::init();

	$action = init('action');

	/* Trace d'entrée : l'action et ses paramètres, utiles quand une erreur remonte à l'interface. */
	ecopilot::log('AJAX << ' . $action . ' ' . json_encode($_REQUEST), 'debug');

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
			'fresh' => ecopilot::freshDefault($def),
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
			'fresh' => ecopilot::freshDefault(array('type' => $type)),
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
		if (init('unit') !== '') {
			$grandeur['unit'] = trim(init('unit'));
		} else {
			unset($grandeur['unit']);
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

	/* ------------------------------------------------------------ optimiseur */

	if ($action == 'setOptiMode') {
		ecopilot_opti::setMode(init('mode'));
		ajax::success(array('mode' => ecopilot_opti::mode()));
	}

	if ($action == 'saveOptiReglage') {
		$piece = ecopilot_piece::byId(init('piece_id'));
		if (!is_object($piece)) {
			throw new Exception(__('Pièce introuvable', __FILE__));
		}
		foreach (array('confort' => 'opti_confort', 'reduit' => 'opti_reduit', 'min' => 'opti_min', 'max' => 'opti_max') as $champ => $cle) {
			if (init($champ) !== '') {
				$piece->setConfiguration($cle, floatval(init($champ)));
			}
		}
		if (init('enable') !== '') {
			$piece->setConfiguration('opti_enable', intval(init('enable')));
		}
		$piece->save();
		if ($piece->getConfiguration('opti_enable', 0) == 1) {
			/* à l'activation, on relie ce qui est identifiable sans ambiguïté */
			ecopilot_opti::autoLier($piece);
		}
		ecopilot_opti::refresh();
		ajax::success();
	}

	if ($action == 'saveOptiLien') {
		$piece = ecopilot_piece::byId(init('piece_id'));
		if (!is_object($piece)) {
			throw new Exception(__('Pièce introuvable', __FILE__));
		}
		$role = init('role');
		if (!in_array($role, array('temp', 'etat', 'consigne', 'puissance', 'energie'))) {
			throw new Exception(__('Rôle inconnu', __FILE__));
		}
		$cmdId = intval(init('cmd_id'));
		if ($cmdId > 0) {
			$cmd = cmd::byId($cmdId);
			if (!is_object($cmd)) {
				throw new Exception(__('Commande introuvable', __FILE__));
			}
			if ($cmd->getType() != 'info') {
				throw new Exception(__('Seule une commande d\'information peut être lue', __FILE__));
			}
		}
		/* détacher = enregistrer une chaîne vide, jamais 0 : sinon la détection croit le rôle déjà pris */
		$piece->setConfiguration('opti_' . $role, $cmdId > 0 ? $cmdId : '');
		$piece->save();
		ecopilot_opti::refresh();
		ajax::success(array('human' => is_object($cmd) ? $cmd->getHumanName() : ''));
	}

	if ($action == 'autoLinkOpti') {
		$piece = ecopilot_piece::byId(init('piece_id'));
		if (!is_object($piece)) {
			throw new Exception(__('Pièce introuvable', __FILE__));
		}
		$rapport = ecopilot_opti::autoLier($piece);
		ecopilot_opti::refresh();
		ajax::success($rapport);
	}

	if ($action == 'copierConso') {
		$source = ecopilot_piece::byId(init('piece_id'));
		if (!is_object($source)) {
			throw new Exception(__('Pièce source introuvable', __FILE__));
		}
		$roles = array();
		foreach (explode(',', init('roles')) as $r) {
			if (in_array($r, array('puissance', 'energie')) && $source->getConfiguration('opti_' . $r, '') !== '') {
				$roles[] = $r;
			}
		}
		if (count($roles) == 0) {
			throw new Exception(__('Cette pièce n\'a aucune consommation à reprendre', __FILE__));
		}
		$cibles = array_filter(array_map('intval', explode(',', init('cibles'))));
		$n = 0;
		foreach ($cibles as $id) {
			$cible = ecopilot_piece::byId($id);
			if (!is_object($cible) || $cible->getId() == $source->getId()) {
				continue;
			}
			foreach ($roles as $r) {
				$cible->setConfiguration('opti_' . $r, $source->getConfiguration('opti_' . $r, ''));
			}
			$cible->save();
			$n++;
		}
		ecopilot_opti::refresh();
		ajax::success(array('pieces' => $n));
	}

	if ($action == 'refreshOpti') {
		ajax::success(ecopilot_opti::refresh());
	}

	throw new Exception(__('Action inconnue', __FILE__) . ' : ' . $action);
} catch (\Throwable $e) {
	/* Journal complet : action, paramètres, message, fichier, ligne et début de trace.
	 * Sans cela, une erreur d'action ne laisse rien dans le log et il faut deviner. */
	$params = $_REQUEST;
	unset($params['password'], $params['token'], $params['apikey']);
	ecopilot::log('AJAX !! ' . (isset($action) ? $action : '?')
		. ' | ' . get_class($e) . ' : ' . $e->getMessage()
		. ' | ' . $e->getFile() . ':' . $e->getLine()
		. ' | params=' . json_encode($params), 'error');
	foreach (array_slice($e->getTrace(), 0, 4) as $i => $frame) {
		ecopilot::log('AJAX .. #' . $i . ' ' . (isset($frame['class']) ? $frame['class'] . '::' : '')
			. (isset($frame['function']) ? $frame['function'] : '?')
			. ' ' . (isset($frame['file']) ? $frame['file'] : '') . ':' . (isset($frame['line']) ? $frame['line'] : ''), 'error');
	}
	if ($e instanceof \Exception) {
		ajax::error(displayException($e), $e->getCode());
	} else {
		ajax::error($e->getMessage(), 0);
	}
}
