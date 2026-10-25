<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Éco Pilot — classe maîtresse du plugin.
 *
 * Principe : le plugin ne parle à AUCUN matériel. L'utilisateur relie lui-même, pour chaque
 * grandeur d'une sonde, la ou les commandes d'information réelles de ses autres plugins.
 * Éco Pilot se contente de lire ces commandes (en direct à chaque changement de valeur, plus
 * une relecture de secours facultative), et de recalculer les moyennes de pièce et de maison.
 *
 * Chargement : l'autoloader du cœur ne connaît que la classe maîtresse ; c'est donc elle qui
 * inclut les classes d'équipement et de commande du plugin.
 */
require_once __DIR__ . '/ecopilot_sonde.class.php';
require_once __DIR__ . '/ecopilot_piece.class.php';
require_once __DIR__ . '/ecopilot_maison.class.php';
require_once __DIR__ . '/ecopilot_sim.class.php';
require_once __DIR__ . '/ecopilot_sondeCmd.class.php';
require_once __DIR__ . '/ecopilot_simCmd.class.php';

class ecopilot {

	const VERSION = '0.1.5';
	const LOG_ID = 'ecopilot';

	/** Types génériques du cœur posés sur nos commandes, quand l'équivalent existe. */
	private static $genericTypes = array(
		'temperature' => 'TEMPERATURE',
		'hygrometrie' => 'HUMIDITY',
		'pression' => 'PRESSURE',
		'co2' => 'CO2',
		'cov' => 'AIR_QUALITY',
		'aqi' => 'AIR_QUALITY',
		'eclairement' => 'BRIGHTNESS',
		'vent' => 'WIND_SPEED',
		'vent_direction' => 'WIND_DIRECTION',
		'pluie_intensite' => 'RAIN_CURRENT',
		'pluie_cumul' => 'RAIN_TOTAL',
		'ciel' => 'WEATHER_CONDITION',
		'puissance' => 'POWER',
		'energie_index' => 'CONSUMPTION',
		'energie_hp' => 'CONSUMPTION',
		'energie_produite' => 'PRODUCTION',
		'tension' => 'VOLTAGE',
		'batterie' => 'BATTERY',
		'presence' => 'PRESENCE',
		'mouvement' => 'PRESENCE',
		'ouverture' => 'OPENING',
		'fumee' => 'SMOKE',
		'fuite_eau' => 'FLOOD',
		'vibration' => 'SHOCK',
		'bruit' => 'NOISE',
		'distance' => 'DISTANCE',
	);

	/** Grandeurs qui se regroupent au maximum au niveau d'une pièce (et non en moyenne). */
	private static $pieceMax = array('eclairement', 'luminance', 'uv', 'rayonnement_solaire', 'vent', 'rafales');

	private static $catalogue = null;
	private static $flat = null;
	private static $piecesIcones = null;

	/* --------------------------------------------------------------- icônes de pièce */

	/** Catalogue d'icônes de pièce, groupé (pièces de vie, nuit, eau, service, circulation, extérieur, général). */
	public static function piecesIcones() {
		if (self::$piecesIcones === null) {
			$file = __DIR__ . '/../config/pieces.json';
			$json = is_file($file) ? json_decode(file_get_contents($file), true) : null;
			self::$piecesIcones = (is_array($json) && isset($json['groupes'])) ? $json['groupes'] : array();
		}
		return self::$piecesIcones;
	}

	/** Libellé d'une icône de pièce d'après sa classe Font Awesome ('' si elle n'est pas au catalogue). */
	public static function pieceIconeLabel($_icon) {
		foreach (self::piecesIcones() as $groupe) {
			foreach ($groupe['icones'] as $icone) {
				if ($icone['icon'] === $_icon) {
					return $icone['label'];
				}
			}
		}
		return '';
	}

	/**
	 * Sélecteur d'icône de pièce : grille groupée + recherche + libellé de la pièce choisie.
	 * La valeur enregistrée reste la classe Font Awesome, portée par un champ caché de la
	 * classe demandée : le JS et l'AJAX continuent de lire ce champ comme avant.
	 */
	public static function pickIcone($_class, $_value) {
		$choix = ($_value != '') ? $_value : 'fas fa-home';
		$html = '<div class="ep-icones">';
		$html .= '<input type="hidden" class="' . $_class . '" value="' . htmlspecialchars($_value) . '">';
		$html .= '<input type="text" class="form-control input-sm ep-icones-filtre" placeholder="' . htmlspecialchars(__('Rechercher une pièce', __FILE__)) . '">';
		foreach (self::piecesIcones() as $groupe) {
			$html .= '<div class="ep-icones-groupe"><span class="ep-icones-titre">' . htmlspecialchars($groupe['label']) . '</span>';
			foreach ($groupe['icones'] as $icone) {
				$actif = ($icone['icon'] === $_value) ? ' active' : '';
				$html .= '<button type="button" class="btn btn-default btn-xs ep-icone' . $actif . '"'
					. ' data-icon="' . $icone['icon'] . '"'
					. ' data-label="' . htmlspecialchars($icone['label']) . '"'
					. ' title="' . htmlspecialchars($icone['label']) . '">'
					. '<i class="' . $icone['icon'] . '"></i></button>';
			}
			$html .= '</div>';
		}
		$html .= '<div class="ep-icones-choix"><i class="' . $choix . '"></i> <span>' . htmlspecialchars(self::pieceIconeLabel($_value)) . '</span></div>';
		$html .= '</div>';
		return $html;
	}

	/* ------------------------------------------------------------------ catalogue */

	public static function catalogue() {
		if (self::$catalogue === null) {
			$file = __DIR__ . '/../config/grandeurs.json';
			$json = is_file($file) ? json_decode(file_get_contents($file), true) : null;
			self::$catalogue = (is_array($json) && isset($json['families'])) ? $json['families'] : array();
		}
		return self::$catalogue;
	}

	/** Catalogue aplati : clé de grandeur => définition (+ famille). */
	public static function grandeurs() {
		if (self::$flat === null) {
			self::$flat = array();
			foreach (self::catalogue() as $family) {
				foreach ($family['grandeurs'] as $grandeur) {
					$grandeur['family'] = $family['key'];
					$grandeur['family_label'] = $family['label'];
					self::$flat[$grandeur['key']] = $grandeur;
				}
			}
		}
		return self::$flat;
	}

	public static function grandeur($_key) {
		$all = self::grandeurs();
		return isset($all[$_key]) ? $all[$_key] : null;
	}

	/**
	 * Définition d'une grandeur pour une sonde donnée : le catalogue d'abord,
	 * puis les grandeurs personnalisées de la sonde (nom, unité et type saisis par l'utilisateur).
	 */
	public static function grandeurDef($_sonde, $_key) {
		$def = self::grandeur($_key);
		if ($def !== null) {
			return $def;
		}
		if (is_object($_sonde)) {
			$conf = $_sonde->getGrandeur($_key);
			if (is_array($conf) && isset($conf['label'])) {
				return array(
					'key' => $_key,
					'label' => $conf['label'],
					'type' => isset($conf['type']) ? $conf['type'] : 'numeric',
					'unit' => isset($conf['unit']) ? $conf['unit'] : '',
					'icon' => 'fas fa-plus',
				);
			}
		}
		return null;
	}

	/** Transforme un libellé en clé technique (grandeurs personnalisées). */
	public static function slug($_label) {
		$label = str_replace(
			array('à','â','ä','á','ã','ç','é','è','ê','ë','í','î','ï','ó','ô','ö','õ','ù','û','ü','ñ','°','%','²','³'),
			array('a','a','a','a','a','c','e','e','e','e','i','i','i','o','o','o','o','u','u','u','n','','','',''),
			strtolower(trim($_label))
		);
		$label = preg_replace('/[^a-z0-9]+/', '_', $label);
		return trim($label, '_');
	}

	public static function genericType($_key) {
		return isset(self::$genericTypes[$_key]) ? self::$genericTypes[$_key] : '';
	}

	public static function log($_message, $_level = 'info') {
		log::add(self::LOG_ID, $_level, $_message);
	}

	/* ------------------------------------------------------------------ objet d'accueil */

	public static function ensureObject($_create = true) {
		foreach (jeeObject::all() as $object) {
			if ($object->getName() == 'Éco Pilot') {
				return $object;
			}
		}
		if (!$_create) {
			return null;
		}
		$object = new jeeObject();
		$object->setName('Éco Pilot');
		$object->save();
		return $object;
	}

	/* ------------------------------------------------------------------ conversions */

	public static function toBool($_value) {
		if (is_bool($_value)) {
			return $_value;
		}
		$value = strtolower(trim((string) $_value));
		return in_array($value, array('1', 'true', 'on', 'oui', 'yes', 'open', 'ouvert', 'ouverte', 'allume', 'allumé', 'detecte', 'détecté', 'present', 'présent'));
	}

	public static function toNum($_value) {
		if ($_value === null || $_value === '') {
			return null;
		}
		$value = str_replace(array("\xc2\xa0", ' '), '', (string) $_value);
		$value = str_replace(',', '.', $value);
		if (is_numeric($value)) {
			return floatval($value);
		}
		/* Certains modules rangent l'unité dans la valeur elle-même (« 24.5 °C »,
		 * « 85 % », « 1 234.5 W ») : on extrait le premier nombre au lieu de tout
		 * rejeter, sinon la grandeur reste vide alors que la source remonte. */
		if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) {
			return floatval($m[0]);
		}
		return null;
	}

	public static function aggregateNum(array $_values, $_agg) {
		if (count($_values) == 0) {
			return null;
		}
		switch ($_agg) {
			case 'last':
				return end($_values);
			case 'max':
				return max($_values);
			case 'min':
				return min($_values);
			case 'sum':
				return array_sum($_values);
			default:
				return array_sum($_values) / count($_values);
		}
	}

	/* ------------------------------------------------------------------ commandes */

	public static function cmdOf($_eqLogic, $_logicalId) {
		if (!is_object($_eqLogic)) {
			return null;
		}
		$cmd = cmd::byEqLogicIdAndLogicalId($_eqLogic->getId(), $_logicalId);
		return is_object($cmd) ? $cmd : null;
	}

	public static function ensureCmd($_eqLogic, $_logicalId, $_name, $_options = array()) {
		$options = array_merge(array(
			'type' => 'info',
			'subType' => 'numeric',
			'unite' => '',
			'historize' => 1,
			'genericType' => '',
			'visible' => 1,
			'order' => 0,
		), $_options);
		$cmd = self::cmdOf($_eqLogic, $_logicalId);
		if (!is_object($cmd)) {
			$class = get_class($_eqLogic) . 'Cmd';
			$cmd = class_exists($class) ? new $class() : new cmd();
			$cmd->setEqLogic_id($_eqLogic->getId());
			$cmd->setLogicalId($_logicalId);
			$cmd->setType($options['type']);
		}
		$cmd->setName($_name);
		$cmd->setSubType($options['subType']);
		if ($options['unite'] != '') {
			$cmd->setUnite($options['unite']);
		}
		$cmd->setIsVisible(intval($options['visible']));
		$cmd->setIsHistorized(intval($options['historize']));
		$cmd->setOrder(intval($options['order']));
		$cmd->setGeneric_type($options['genericType']);
		$cmd->save();
		return $cmd;
	}

	/**
	 * Crée (ou met à jour) les commandes d'une grandeur de sonde :
	 * la valeur, l'âge de la dernière remontée et l'indicateur « muette ».
	 */
	public static function ensureGrandeurCmds($_sonde, $_key, $_unite = '', $_order = 0) {
		$def = self::grandeurDef($_sonde, $_key);
		$subType = in_array($def['type'], array('binary', 'string')) ? $def['type'] : 'numeric';
		$historize = (config::byKey('historize', self::LOG_ID, 1) == 1 && $subType == 'numeric') ? 1 : 0;
		$cmds = array();
		$cmds['value'] = self::ensureCmd($_sonde, 'g_' . $_key, $def['label'], array(
			'subType' => $subType,
			'unite' => $_unite != '' ? $_unite : (isset($def['unit']) ? $def['unit'] : ''),
			'historize' => $historize,
			'genericType' => self::genericType($_key),
			'order' => $_order,
		));
		$cmds['age'] = self::ensureCmd($_sonde, 'g_' . $_key . '_age', $def['label'] . ' — dernière remontée', array(
			'subType' => 'numeric',
			'unite' => 'min',
			'historize' => 0,
			'order' => 900 + $_order,
		));
		$cmds['muette'] = self::ensureCmd($_sonde, 'g_' . $_key . '_muette', $def['label'] . ' — muette', array(
			'subType' => 'binary',
			'historize' => 0,
			'order' => 920 + $_order,
		));
		return $cmds;
	}

	public static function removeGrandeurCmds($_sonde, $_key) {
		foreach (array('g_' . $_key, 'g_' . $_key . '_age', 'g_' . $_key . '_muette') as $logicalId) {
			$cmd = self::cmdOf($_sonde, $logicalId);
			if (is_object($cmd)) {
				$cmd->remove();
			}
		}
	}

	/**
	 * Date de la dernière remontée d'une commande source.
	 *
	 * Le cœur ne persiste pas toujours collectDate (sur ce cœur 4.6.1, la table cmd n'a même
	 * pas de colonne de date) : on se rabat alors sur le dernier point d'historique, qui lui
	 * est bien enregistré. Renvoie null si aucune date n'est connue.
	 */
	public static function lastChange($_cmd) {
		/* L'historique d'abord : c'est la seule trace fiable quand le cœur ne persiste pas
		 * la date de remontée (sur ce cœur, getCollectDate() exécute la commande et renvoie
		 * l'heure courante — la lire en premier donnerait toujours « à l'instant »). */
		$row = DB::Prepare('SELECT MAX(datetime) AS d FROM history WHERE cmd_id = :id', array('id' => $_cmd->getId()), DB::FETCH_TYPE_ROW);
		if (is_array($row) && isset($row['d']) && $row['d'] !== null) {
			return $row['d'];
		}
		$date = $_cmd->getCollectDate();
		return ($date === '' || $date === null) ? null : $date;
	}

	/**
	 * Dernière valeur de l'historique d'une commande ('' s'il n'y en a pas).
	 * Sert de repli quand la colonne `value` de la commande source est vide.
	 */
	public static function lastHistoValue($_cmd) {
		if (!is_object($_cmd)) {
			return '';
		}
		/* On saute les lignes vides : certains modules historisent une valeur vide
		 * entre deux mesures, et la dernière ligne n'est pas forcément la bonne. */
		$row = DB::Prepare("SELECT value FROM history WHERE cmd_id = :id AND value IS NOT NULL AND value <> '' ORDER BY datetime DESC LIMIT 1", array('id' => $_cmd->getId()), DB::FETCH_TYPE_ROW);
		if (is_array($row) && isset($row['value']) && $row['value'] !== '') {
			return $row['value'];
		}
		return '';
	}

	/* ------------------------------------------------------------------ lecture des sources */

	/**
	 * Lit les commandes sources d'une grandeur : valeurs converties, unité du module,
	 * date de la remontée la plus récente.
	 */
	public static function readSources($_sonde, $_key, $_conf, $_def) {
		$sources = isset($_conf['sources']) ? $_conf['sources'] : array();
		$nums = array();
		$bools = array();
		$strings = array();
		$newest = null;
		$unite = '';
		$lues = 0;
		foreach ($sources as $cmdId) {
			$cmd = cmd::byId($cmdId);
			if (!is_object($cmd)) {
				continue;
			}
			$lues++;
			$raw = $cmd->getValue();
			/* Certains modules (et le cœur lui-même sur 4.6.1) alimentent l'historique
			 * sans remplir la colonne `value` : la commande paraît vide alors qu'elle
			 * remonte. On reprend alors la dernière valeur de l'historique. */
			if ($raw === '' || $raw === null) {
				$raw = self::lastHistoValue($cmd);
			}
			if ($_def['type'] == 'binary') {
				$bools[] = self::toBool($raw);
			} elseif ($_def['type'] == 'string') {
				if ($raw !== '' && $raw !== null) {
					$strings[] = $raw;
				}
			} else {
				$num = self::toNum($raw);
				if ($num !== null) {
					$nums[] = $num;
				}
				if ($unite == '' && $cmd->getUnite() != '') {
					$unite = $cmd->getUnite();
				}
			}
			$date = self::lastChange($cmd);
			if ($date != '' && $date !== null && ($newest === null || $date > $newest)) {
				$newest = $date;
			}
		}
		$age = null;
		if ($newest !== null) {
			$age = intval(floor((time() - strtotime($newest)) / 60));
			if ($age < 0) {
				$age = 0;
			}
		}
		return array(
			'nb' => $lues,
			'nums' => $nums,
			'bools' => $bools,
			'strings' => $strings,
			'age' => $age,
			'unite' => $unite,
		);
	}

	/* ------------------------------------------------------------------ calculs */

	public static function computeGrandeur($_key, $_conf, $_def, $_read) {
		$agg = isset($_conf['agg']) ? $_conf['agg'] : ($_def['type'] == 'binary' ? 'or' : 'mean');
		$value = null;
		if ($_read['nb'] == 0) {
			return array('value' => null, 'state' => 'a_relier', 'age' => null, 'unite' => '', 'muette' => 0, 'agg' => $agg);
		}
		if ($_def['type'] == 'binary') {
			if (count($_read['bools']) > 0) {
				$value = ($agg == 'and') ? !in_array(false, $_read['bools']) : in_array(true, $_read['bools']);
				$value = $value ? 1 : 0;
			}
		} elseif ($_def['type'] == 'string') {
			$value = count($_read['strings']) > 0 ? ($agg == 'last' ? end($_read['strings']) : $_read['strings'][0]) : null;
		} else {
			$value = self::aggregateNum($_read['nums'], $agg);
		}
		$fresh = isset($_conf['fresh']) ? intval($_conf['fresh']) : intval(config::byKey('freshDefault', self::LOG_ID, 60));
		$muette = ($_read['age'] !== null && $fresh > 0 && $_read['age'] > $fresh) ? 1 : 0;
		return array(
			'value' => $value,
			'state' => $muette ? 'muette' : 'ok',
			'age' => $_read['age'],
			'unite' => $_read['unite'],
			'muette' => $muette,
			'agg' => $agg,
		);
	}

	/* ------------------------------------------------------------------ rafraîchissement */

		/**
	 * Pose la valeur d'une commande.
	 *
	 * On passe par event() — c'est l'API du cœur : historique, scénarios, abonnés, interface —
	 * ET par setValue()+save(), car sur ce cœur (4.6.1) event() n'écrit pas la colonne `value` :
	 * sans ce second appel, la valeur reste vide en base (et donc à l'écran après un rechargement).
	 */
	public static function posCmdValue($_cmd, $_value) {
		if (!is_object($_cmd)) {
			return;
		}
		if (is_bool($_value)) {
			$_value = $_value ? 1 : 0;
		}
		/* Un entier 0 n'est pas enregistré par le cœur (il le transforme en vide) :
		 * on l'écrit sous forme de chaîne, ce qui donne bien « 0 » en base. */
		if (is_int($_value)) {
			$_value = strval($_value);
		}
		/* L'ordre compte : la valeur doit être en base AVANT event(), car event() prévient
		 * les scénarios, les abonnés (dont nous) et l'interface, qui relisent la commande. */
		$_cmd->setValue($_value);
		$_cmd->save();
		$_cmd->event($_value);
	}

	public static function refreshSonde($_sonde, $_readSources = true) {
		if (!is_object($_sonde)) {
			return;
		}
		$grandeurs = $_sonde->getGrandeurs();
		$states = array();
		$order = 0;
		$unites = array();
		foreach ($grandeurs as $key => $conf) {
			$def = self::grandeurDef($_sonde, $key);
			if ($def === null) {
				$def = array('label' => $key, 'type' => 'numeric', 'unit' => '');
			}
			$order++;
			$cmds = self::ensureGrandeurCmds($_sonde, $key, '', $order);
			$read = self::readSources($_sonde, $key, $conf, $def);
			if ($read['unite'] != '' && $cmds['value']->getUnite() != $read['unite'] && $def['type'] == 'numeric') {
				$cmds['value']->setUnite($read['unite']);
				$cmds['value']->save();
				$unites[$key] = $read['unite'];
			}
			$result = self::computeGrandeur($key, $conf, $def, $read);
			if ($result['value'] !== null) {
				self::posCmdValue($cmds['value'], $result['value']);
			}
			self::posCmdValue($cmds['age'], $result['age'] !== null ? $result['age'] : '');
			self::posCmdValue($cmds['muette'], isset($result['muette']) ? $result['muette'] : 0);
			$states[] = $result['state'];
		}
		$etat = 'ok';
		if (in_array('a_relier', $states)) {
			$etat = 'a_relier';
		} elseif (in_array('muette', $states)) {
			$etat = 'muette';
		}
		self::ensureCmd($_sonde, 'etat', 'État', array('subType' => 'string', 'historize' => 0, 'visible' => 0, 'order' => 990));
		self::ensureCmd($_sonde, 'a_relier', 'À relier', array('subType' => 'binary', 'historize' => 0, 'order' => 991));
		self::ensureCmd($_sonde, 'muette', 'Source muette', array('subType' => 'binary', 'historize' => 0, 'order' => 992));
		self::posCmdValue(self::cmdOf($_sonde, 'etat'), $etat);
		self::posCmdValue(self::cmdOf($_sonde, 'a_relier'), $etat == 'a_relier' ? 1 : 0);
		self::posCmdValue(self::cmdOf($_sonde, 'muette'), $etat == 'muette' ? 1 : 0);
		$_sonde->setConfiguration('last_read', date('Y-m-d H:i:s'));
		$_sonde->save();
	}

	/**
	 * Moyennes d'une pièce : on ne garde que les sondes cochées « compter dans la moyenne »,
	 * et on écarte les grandeurs signalées muettes.
	 */
	public static function refreshPiece($_piece) {
		if (!is_object($_piece)) {
			return;
		}
		$buckets = array();
		$unites = array();
		$defs = array();
		foreach ($_piece->getSondes() as $sondeId => $compte) {
			if (!$compte) {
				continue;
			}
			$sonde = ecopilot_sonde::byId($sondeId);
			if (!is_object($sonde) || !$sonde->getIsEnable()) {
				continue;
			}
			foreach ($sonde->getGrandeurs() as $key => $conf) {
				$value = self::cmdOf($sonde, 'g_' . $key);
				$muette = self::cmdOf($sonde, 'g_' . $key . '_muette');
				if (!is_object($value) || $value->getValue() === '' || $value->getValue() === null) {
					continue;
				}
				if (is_object($muette) && $muette->getValue() == 1) {
					continue;
				}
				if (!isset($buckets[$key])) {
					$buckets[$key] = array();
					$defs[$key] = self::grandeurDef($sonde, $key);
				}
				$buckets[$key][] = $value->getValue();
				if (!isset($unites[$key]) && $value->getUnite() != '') {
					$unites[$key] = $value->getUnite();
				}
			}
		}
		$order = 0;
		foreach ($buckets as $key => $values) {
			$def = isset($defs[$key]) ? $defs[$key] : null;
			if ($def === null) {
				$def = array('label' => $key, 'type' => 'numeric');
			}
			$order++;
			$subType = in_array($def['type'], array('binary', 'string')) ? $def['type'] : 'numeric';
			$agg = in_array($key, self::$pieceMax) ? 'max' : ($def['type'] == 'binary' ? 'or' : 'mean');
			$result = null;
			if ($def['type'] == 'binary') {
				$bools = array_map(array(__CLASS__, 'toBool'), $values);
				$result = in_array(true, $bools) ? 1 : 0;
			} elseif ($def['type'] == 'string') {
				$result = $values[0];
			} else {
				$nums = array();
				foreach ($values as $value) {
					$num = self::toNum($value);
					if ($num !== null) {
						$nums[] = $num;
					}
				}
				$result = self::aggregateNum($nums, $agg);
			}
			if ($result === null) {
				continue;
			}
			$cmd = self::ensureCmd($_piece, 'p_' . $key, $def['label'], array(
				'subType' => $subType,
				'unite' => isset($unites[$key]) ? $unites[$key] : (isset($def['unit']) ? $def['unit'] : ''),
				'historize' => ($subType == 'numeric' && config::byKey('historize', self::LOG_ID, 1) == 1) ? 1 : 0,
				'genericType' => self::genericType($key),
				'order' => $order,
			));
			self::posCmdValue($cmd, $result);
		}
		$nb = 0;
		foreach ($_piece->getSondes() as $sondeId => $compte) {
			if ($compte) {
				$nb++;
			}
		}
		$cmdNb = self::ensureCmd($_piece, 'p_sondes', 'Sondes comptées', array('subType' => 'numeric', 'historize' => 0, 'order' => 900));
		self::posCmdValue($cmdNb, $nb);
		// une grandeur qui n'a plus de sonde : on retire sa commande
		foreach ($_piece->getCmd('info') as $cmd) {
			$logicalId = $cmd->getLogicalId();
			if (strpos($logicalId, 'p_') === 0 && $logicalId != 'p_sondes' && !isset($buckets[substr($logicalId, 2)])) {
				$cmd->remove();
			}
		}
	}

	/** Valeurs de la maison : moyennes des pièces intérieures + écart avec l'extérieur. */
	public static function refreshMaison() {
		$maison = ecopilot_maison::ensure();
		if (!is_object($maison)) {
			return;
		}
		$interieurs = array();
		$exterieurs = array();
		$toutes = array();
		foreach (ecopilot_piece::all() as $piece) {
			$temp = self::cmdOf($piece, 'p_' . 'temperature');
			$humi = self::cmdOf($piece, 'p_hygrometrie');
			$lumi = self::cmdOf($piece, 'p_eclairement');
			$entry = array(
				'piece' => $piece,
				'temp' => (is_object($temp) && $temp->getValue() !== '' && $temp->getValue() !== null) ? self::toNum($temp->getValue()) : null,
				'humi' => (is_object($humi) && $humi->getValue() !== '' && $humi->getValue() !== null) ? self::toNum($humi->getValue()) : null,
				'lumi' => (is_object($lumi) && $lumi->getValue() !== '' && $lumi->getValue() !== null) ? self::toNum($lumi->getValue()) : null,
			);
			$toutes[] = $entry;
			if ($piece->getPieceType() == 'exterieur') {
				$exterieurs[] = $entry;
			} elseif ($piece->getIsMaison()) {
				$interieurs[] = $entry;
			}
		}
		$temps = array();
		foreach ($interieurs as $entry) {
			if ($entry['temp'] !== null) {
				$temps[] = $entry['temp'];
			}
		}
		$humis = array();
		foreach ($interieurs as $entry) {
			if ($entry['humi'] !== null) {
				$humis[] = $entry['humi'];
			}
		}
		$tempMaison = count($temps) > 0 ? array_sum($temps) / count($temps) : null;
		$humiMaison = count($humis) > 0 ? array_sum($humis) / count($humis) : null;
		$extTemps = array();
		foreach ($exterieurs as $entry) {
			if ($entry['temp'] !== null) {
				$extTemps[] = $entry['temp'];
			}
		}
		$tempExt = count($extTemps) > 0 ? array_sum($extTemps) / count($extTemps) : null;
		$plusFroide = null;
		$plusFroidePiece = '';
		foreach ($interieurs as $entry) {
			if ($entry['temp'] === null) {
				continue;
			}
			if ($plusFroide === null || $entry['temp'] < $plusFroide) {
				$plusFroide = $entry['temp'];
				$plusFroidePiece = $entry['piece']->getName();
			}
		}
		$cmds = array(
			'temperature' => array('Température maison', 'numeric', '°C', 'TEMPERATURE', 1),
			'hygrometrie' => array('Hygrométrie maison', 'numeric', '%', 'HUMIDITY', 2),
			'ecart' => array('Écart intérieur / extérieur', 'numeric', '°C', '', 3),
			'plus_froide' => array('Pièce la plus froide', 'numeric', '°C', 'TEMPERATURE', 4),
			'plus_froide_piece' => array('Pièce la plus froide — nom', 'string', '', '', 5),
			'pieces' => array('Pièces comptées', 'numeric', '', '', 6),
		);
		foreach ($cmds as $logicalId => $def) {
			self::ensureCmd($maison, $logicalId, $def[0], array(
				'subType' => $def[1],
				'unite' => $def[2],
				'genericType' => $def[3],
				'historize' => ($def[1] == 'numeric') ? 1 : 0,
				'order' => $def[4],
			));
		}
		if ($tempMaison !== null) {
			self::posCmdValue(self::cmdOf($maison, 'temperature'), round($tempMaison, 1));
		}
		if ($humiMaison !== null) {
			self::posCmdValue(self::cmdOf($maison, 'hygrometrie'), round($humiMaison, 0));
		}
		if ($tempMaison !== null && $tempExt !== null) {
			self::posCmdValue(self::cmdOf($maison, 'ecart'), round($tempMaison - $tempExt, 1));
		}
		if ($plusFroide !== null) {
			self::posCmdValue(self::cmdOf($maison, 'plus_froide'), round($plusFroide, 1));
			self::posCmdValue(self::cmdOf($maison, 'plus_froide_piece'), $plusFroidePiece);
		}
		self::posCmdValue(self::cmdOf($maison, 'pieces'), count($temps));
	}

	public static function refreshAll() {
		foreach (ecopilot_sonde::all() as $sonde) {
			self::refreshSonde($sonde);
		}
		foreach (ecopilot_piece::all() as $piece) {
			self::refreshPiece($piece);
		}
		self::refreshMaison();
	}

	/* ------------------------------------------------------------------ suivi en direct */

	/**
	 * Abonne le plugin aux commandes sources réellement reliées : le cœur appelle
	 * onSourceEvent() à chaque changement de valeur (via la table listener).
	 */
	public static function registerListeners() {
		foreach (listener::byClass(self::LOG_ID) as $old) {
			$old->remove();
		}
		if (config::byKey('liveFollow', self::LOG_ID, 1) != 1) {
			return;
		}
		$events = array();
		foreach (ecopilot_sonde::all() as $sonde) {
			if (!$sonde->getIsEnable()) {
				continue;
			}
			foreach ($sonde->getGrandeurs() as $key => $conf) {
				foreach ((isset($conf['sources']) ? $conf['sources'] : array()) as $cmdId) {
					if (!isset($events[$cmdId])) {
						$events[$cmdId] = array();
					}
					$events[$cmdId][] = array('sonde' => intval($sonde->getId()), 'key' => $key);
				}
			}
		}
		foreach ($events as $cmdId => $targets) {
			$listener = new listener();
			$listener->setClass(self::LOG_ID);
			$listener->setFunction('onSourceEvent');
			$listener->setOption(array('background' => 0, 'cmd_id' => intval($cmdId), 'targets' => $targets));
			$listener->emptyEvent();
			$listener->addEvent('#' . $cmdId . '#');
			$listener->save();
		}
		self::log('abonnements : ' . count($events) . ' commande(s) source suivie(s)');
	}

	/** Appelé par le cœur à chaque changement de valeur d'une commande source. */
	public static function onSourceEvent($_option) {
		$targets = isset($_option['targets']) ? $_option['targets'] : array();
		$pieces = array();
		foreach ($targets as $target) {
			$sonde = ecopilot_sonde::byId($target['sonde']);
			if (!is_object($sonde) || !$sonde->getIsEnable()) {
				continue;
			}
			self::refreshSonde($sonde);
			if ($sonde->getPieceId() > 0) {
				$pieces[$sonde->getPieceId()] = $sonde->getPieceId();
			}
		}
		foreach ($pieces as $pieceId) {
			self::refreshPiece(ecopilot_piece::byId($pieceId));
		}
		self::refreshMaison();
	}

	/* ------------------------------------------------------------------ cron */

	public static function cron() {
		$refresh = intval(config::byKey('refreshSeconds', self::LOG_ID, 0));
		$readSources = true;
		if ($refresh > 0) {
			$last = intval(cache::byKey('ecopilot::last_refresh')->getValue(0));
			$readSources = ((time() - $last) >= ($refresh * 60));
			if ($readSources) {
				cache::set('ecopilot::last_refresh', time());
			}
		}
		foreach (ecopilot_sonde::all() as $sonde) {
			self::refreshSonde($sonde, $readSources);
		}
		foreach (ecopilot_piece::all() as $piece) {
			self::refreshPiece($piece);
		}
		self::refreshMaison();
	}

	public static function cron5() {
		return;
	}

	/* ------------------------------------------------------------------ état pour l'interface */

	public static function sondeState($_sonde) {
		$etat = self::cmdOf($_sonde, 'etat');
		$value = is_object($etat) ? $etat->getValue() : '';
		if ($value == '') {
			$value = 'a_relier';
		}
		return $value;
	}

	public static function stateLabel($_state) {
		switch ($_state) {
			case 'ok':
				return array('label' => 'OK', 'class' => 'label label-success');
			case 'muette':
				return array('label' => '{{Source muette}}', 'class' => 'label label-warning');
			default:
				return array('label' => '{{À relier}}', 'class' => 'label label-warning');
		}
	}
}
