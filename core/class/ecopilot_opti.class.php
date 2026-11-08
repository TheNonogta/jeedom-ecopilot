<?php

/* This file is part of Jeedom. Licence AGPL. */

/**
 * Optimiseur de chauffage — lot A : observation seule.
 *
 * Le moteur recalcule ce qu'il enverrait comme consigne et l'explique, mais n'envoie
 * jamais rien. Il mesure au passage la déperdition et l'inertie de chaque pièce, et en
 * déduit un classement énergétique.
 *
 * Toutes les grandeurs sont lues par la même chaîne que les sondes : la colonne `value`
 * d'abord, puis l'historique, puis la résolution des références `#cmd#`.
 */
class ecopilot_opti {

	/** Hystérésis : en dessous de cet écart (°C) on ne propose rien, pour ne pas osciller. */
	const HYSTERESIS = 0.3;

	/** Écart maximal admis entre la mesure et la cible (°C). */
	const ECART_MAX = 0.5;

	/** Avance de consigne quand on anticipe (°C au-dessus de la cible). */
	const ANTICIPATION = 0.5;

	/** Durée de mesure minimale pour publier une classe (en heures de données exploitables). */
	const MIN_HEURES_MESURE = 6;

	/** Seuils du classement, en °C perdus par heure et par degré d'écart avec l'extérieur. */
	public static $seuils = array(
		'A' => 0.05,
		'B' => 0.08,
		'C' => 0.12,
		'D' => 0.18,
		'E' => 0.25,
		'F' => 0.35,
	);

	/* ------------------------------------------------------------------ réglages */

	public static function mode() {
		$mode = config::byKey('optiMode', 'ecopilot', 'confort');
		return in_array($mode, array('confort', 'reduit', 'absence', 'horsgel')) ? $mode : 'confort';
	}

	public static function setMode($_mode) {
		if (!in_array($_mode, array('confort', 'reduit', 'absence', 'horsgel'))) {
			throw new Exception(__('Mode inconnu', __FILE__));
		}
		config::save('optiMode', $_mode, 'ecopilot');
	}

	/** Cible de consigne d'une pièce selon le mode courant. */
	public static function cible($_piece, $_mode = null) {
		$mode = ($_mode === null) ? self::mode() : $_mode;
		switch ($mode) {
			case 'reduit':
				return floatval($_piece->getConfiguration('opti_reduit', 17));
			case 'absence':
				$v = $_piece->getConfiguration('opti_absence', '');
				return ($v === '') ? 16.0 : floatval($v);
			case 'horsgel':
				return 8.0;
			default:
				return floatval($_piece->getConfiguration('opti_confort', 20));
		}
	}

	public static function borneMin($_piece) {
		$v = $_piece->getConfiguration('opti_min', '');
		return ($v === '') ? 8.0 : floatval($v);
	}

	public static function borneMax($_piece) {
		$v = $_piece->getConfiguration('opti_max', '');
		return ($v === '') ? 24.0 : floatval($v);
	}

	/* ------------------------------------------------------------------ lecture */

	/** Valeur numérique d'une commande, avec les mêmes replis que la lecture des sondes. */
	public static function lire($_cmdId) {
		if ($_cmdId === '' || $_cmdId === null) {
			return null;
		}
		$cmd = cmd::byId($_cmdId);
		if (!is_object($cmd)) {
			return null;
		}
		$raw = $cmd->getValue();
		if ($raw === '' || $raw === null) {
			$raw = ecopilot::lastHistoValue($cmd);
		}
		$raw = ecopilot::resolveRef($raw);
		if ($raw === '' || $raw === null || preg_match('/#\d+#/', (string) $raw)) {
			$raw = ecopilot::lastHistoValue($cmd);
		}
		return ecopilot::toNum($raw);
	}

	/** Ouvertures de la pièce : une seule ouverture ouverte suffit à couper. */
	public static function ouvertureOuverte($_piece) {
		foreach ($_piece->getSondes() as $sondeId => $compte) {
			if (!$compte) {
				continue;
			}
			$sonde = ecopilot_sonde::byId($sondeId);
			if (!is_object($sonde)) {
				continue;
			}
			foreach ($sonde->getGrandeurs() as $key => $conf) {
				if ($key != 'ouverture') {
					continue;
				}
				$cmd = ecopilot::cmdOf($sonde, 'g_ouverture');
				if (is_object($cmd) && ecopilot::toBool($cmd->getValue())) {
					return true;
				}
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ décision */

	/**
	 * Calcule la décision d'une pièce. Retourne un tableau complet, y compris la raison
	 * en clair — c'est elle qui rend l'outil acceptable.
	 */
	public static function decider($_piece) {
		$mode = self::mode();
		$cible = self::cible($_piece, $mode);
		$min = self::borneMin($_piece);
		$max = self::borneMax($_piece);
		$temp = self::lire($_piece->getConfiguration('opti_temp', ''));
		if ($temp === null) {
			$p = ecopilot::cmdOf($_piece, 'p_temperature');
			$temp = is_object($p) ? ecopilot::toNum($p->getValue()) : null;
		}
		$consigneActuelle = self::lire($_piece->getConfiguration('opti_consigne', ''));
		$etatActif = self::lire($_piece->getConfiguration('opti_etat', ''));
		$ouverte = self::ouvertureOuverte($_piece);

		$retour = array(
			'piece' => $_piece->getName(),
			'mode' => $mode,
			'cible' => $cible,
			'temp' => $temp,
			'consigne_actuelle' => $consigneActuelle,
			'proposee' => null,
			'ecart' => null,
			'raison' => '',
			'etat' => 'ignore',
		);

		if ($temp === null) {
			$retour['raison'] = 'Aucune température lue pour cette pièce : rien à décider.';
			return $retour;
		}
		$retour['ecart'] = round($temp - $cible, 1);

		if ($ouverte) {
			$retour['proposee'] = null;
			$retour['etat'] = 'coupe';
			$retour['raison'] = sprintf('Ouverture détectée et %.1f °C pour une cible de %.1f : proposition de couper le chauffage tant que c\'est ouvert.', $temp, $cible);
			return $retour;
		}

		$ecart = $temp - $cible;
		$base = ($consigneActuelle === null) ? $cible : $consigneActuelle;

		if (abs($ecart) < self::HYSTERESIS) {
			$retour['proposee'] = round($base, 1);
			$retour['etat'] = 'stable';
			$retour['raison'] = sprintf('%.1f °C pour une cible de %.1f : écart de %+.1f °C, sous l\'hystérésis de %.1f — consigne inchangée.', $temp, $cible, $ecart, self::HYSTERESIS);
			return $retour;
		}

		if ($ecart < 0) {
			/* trop froid : on anticipe en visant au-dessus de la cible */
			$proposee = min($cible + self::ANTICIPATION, $max);
			$retour['etat'] = 'anticipe';
			$retour['raison'] = sprintf('%.1f °C pour une cible de %.1f : sous la cible de %.1f °C, on anticipe en proposant %.1f °C (plafond %.1f).', $temp, $cible, -$ecart, $proposee, $max);
		} else {
			/* trop chaud : on redescend vers la cible, sans descendre sous le plancher */
			$proposee = max($cible, $min);
			$retour['etat'] = 'reduit';
			$retour['raison'] = sprintf('%.1f °C pour une cible de %.1f : au-dessus de la cible de %.1f °C, on propose de revenir à %.1f °C.', $temp, $cible, $ecart, $proposee);
		}
		$retour['proposee'] = round($proposee, 1);

		if ($consigneActuelle === null) {
			$retour['raison'] .= ' Consigne actuelle non reliée : proposition à appliquer une fois le lien fait.';
		} elseif (abs($retour['proposee'] - $consigneActuelle) < 0.1) {
			$retour['raison'] .= ' Consigne déjà à cette valeur : aucun ordre.';
			$retour['etat'] = 'stable';
		} else {
			$retour['raison'] .= sprintf(' La consigne passerait de %.1f à %.1f °C.', $consigneActuelle, $retour['proposee']);
		}
		return $retour;
	}

	/* ------------------------------------------------------------------ mesures */

	/** Échantillons fusionnés (température + état actif) sur les N dernières heures. */
	public static function echantillons($_piece, $_heures = 72) {
		$tempId = $_piece->getConfiguration('opti_temp', '');
		if ($tempId === '') {
			$p = ecopilot::cmdOf($_piece, 'p_temperature');
			$tempId = is_object($p) ? $p->getId() : '';
		}
		if ($tempId === '') {
			return array();
		}
		$depuis = date('Y-m-d H:i:s', time() - $_heures * 3600);
		$temp = DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id AND datetime >= :d ORDER BY datetime ASC',
			array('id' => intval($tempId), 'd' => $depuis), DB::FETCH_TYPE_ALL);
		if (count($temp) < 5) {
			return array();
		}
		$etatId = $_piece->getConfiguration('opti_etat', '');
		$etat = array();
		if ($etatId !== '') {
			$etat = DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id AND datetime >= :d ORDER BY datetime ASC',
				array('id' => intval($etatId), 'd' => $depuis), DB::FETCH_TYPE_ALL);
		}
		/* état actif connu à un instant donné : dernière valeur avant cet instant */
		$etatConnu = function ($ts) use ($etat) {
			$valeur = null;
			foreach ($etat as $e) {
				if (strtotime($e['datetime']) <= $ts) {
					$valeur = ecopilot::toBool($e['value']);
				} else {
					break;
				}
			}
			return $valeur;
		};
		$out = array();
		foreach ($temp as $t) {
			$ts = strtotime($t['datetime']);
			$v = ecopilot::toNum($t['value']);
			if ($v === null) {
				continue;
			}
			$out[] = array('ts' => $ts, 'temp' => $v, 'actif' => $etatConnu($ts));
		}
		return $out;
	}

	/**
	 * Déperdition et inertie mesurées sur l'historique.
	 * - déperdition : °C perdus par heure, ramenés à 1 °C d'écart avec l'extérieur ;
	 * - inertie     : minutes pour gagner 0,5 °C quand le chauffage est actif.
	 */
	public static function mesures($_piece) {
		$samples = self::echantillons($_piece);
		$maison = ecopilot_maison::ensure();
		$extCmd = is_object($maison) ? ecopilot::cmdOf($maison, 'temperature_ext') : null;
		$ext = is_object($extCmd) ? ecopilot::toNum($extCmd->getValue()) : null;

		$perteHeures = 0.0;
		$perteDegres = 0.0;
		$gainHeures = 0.0;
		$gainDegres = 0.0;
		$sommeEcart = 0.0;
		$nEcart = 0;

		for ($i = 1; $i < count($samples); $i++) {
			$dt = $samples[$i]['ts'] - $samples[$i - 1]['ts'];
			if ($dt <= 0 || $dt > 3600) {
				continue; /* trou dans l'historique : inexploitable */
			}
			$dT = $samples[$i]['temp'] - $samples[$i - 1]['temp'];
			$h = $dt / 3600.0;
			$actif = $samples[$i]['actif'];
			if ($actif === false && $dT < -0.05) {
				$perteHeures += $h;
				$perteDegres += -$dT;
				if ($ext !== null) {
					$sommeEcart += max(0.5, $samples[$i]['temp'] - $ext);
					$nEcart++;
				}
			}
			if ($actif === true && $dT > 0.05) {
				$gainHeures += $h;
				$gainDegres += $dT;
			}
		}

		$retour = array('deperdition' => null, 'inertie' => null, 'heures' => round($perteHeures + $gainHeures, 1), 'suffisant' => false);

		if ($perteHeures >= 0.5 && $nEcart > 0) {
			$pente = $perteDegres / $perteHeures; /* °C par heure */
			$ecartMoyen = $sommeEcart / $nEcart;
			$retour['deperdition'] = round($pente / max(0.5, $ecartMoyen), 3);
		}
		if ($gainHeures >= 0.3) {
			$pente = $gainDegres / $gainHeures; /* °C par heure */
			if ($pente > 0) {
				$retour['inertie'] = intval(round(0.5 / $pente * 60));
			}
		}
		$retour['suffisant'] = ($retour['heures'] >= self::MIN_HEURES_MESURE);
		return $retour;
	}

	public static function classe($_deperdition) {
		if ($_deperdition === null || $_deperdition <= 0) {
			return null;
		}
		foreach (self::$seuils as $classe => $seuil) {
			if ($_deperdition <= $seuil) {
				return $classe;
			}
		}
		return 'G';
	}

	public static function classeLabel($_classe) {
		switch ($_classe) {
			case 'A':
			case 'B':
				return 'label label-success';
			case 'C':
				return 'label label-info';
			case 'D':
				return 'label label-warning';
			case 'E':
			case 'F':
			case 'G':
				return 'label label-danger';
		}
		return 'label label-default';
	}

	/** Énergie consommée depuis minuit, par différence d'index (kWh). */
	public static function energieJour($_piece) {
		$id = $_piece->getConfiguration('opti_energie', '');
		if ($id === '') {
			return null;
		}
		$rows = DB::Prepare('SELECT value FROM history WHERE cmd_id = :id AND datetime >= :d ORDER BY datetime ASC',
			array('id' => intval($id), 'd' => date('Y-m-d 00:00:00')), DB::FETCH_TYPE_ALL);
		if (count($rows) < 2) {
			return null;
		}
		$premier = null;
		$dernier = null;
		foreach ($rows as $r) {
			$v = ecopilot::toNum($r['value']);
			if ($v === null) {
				continue;
			}
			if ($premier === null) {
				$premier = $v;
			}
			$dernier = $v;
		}
		if ($premier === null || $dernier === null || $dernier < $premier) {
			return null;
		}
		return round($dernier - $premier, 2);
	}

	/* ------------------------------------------------------------------ rafraîchissement */

	/** Pieces pilotables : celles qui ont au moins un état de chauffage relié et un réglage activé. */
	public static function pieces() {
		$retour = array();
		foreach (ecopilot_piece::all() as $piece) {
			if ($piece->getPieceType() != 'interieur') {
				continue;
			}
			if ($piece->getConfiguration('opti_enable', 0) != 1) {
				continue;
			}
			$retour[] = $piece;
		}
		return $retour;
	}

	/** Recalcule toutes les pièces, écrit la dernière décision et le journal. */
	public static function refresh() {
		$decisions = array();
		foreach (self::pieces() as $piece) {
			$d = self::decider($piece);
			$m = self::mesures($piece);
			$d['mesures'] = $m;
			$d['classe'] = self::classe($m['deperdition']);
			$d['energie_jour'] = self::energieJour($piece);
			$d['puissance'] = self::lire($piece->getConfiguration('opti_puissance', ''));
			$d['piece_id'] = $piece->getId();
			$piece->setConfiguration('opti_last', $d);
			$piece->save();
			$decisions[] = $d;
		}
		config::save('optiLastRun', date('Y-m-d H:i:s'), 'ecopilot');
		self::journaliser($decisions);
		return $decisions;
	}

	/** Journal des dernières décisions (les refus comptent autant que les propositions). */
	private static function journaliser($_decisions) {
		$journal = config::byKey('optiJournal', 'ecopilot', array());
		if (!is_array($journal)) {
			$journal = array();
		}
		foreach ($_decisions as $d) {
			array_unshift($journal, array('ts' => date('Y-m-d H:i:s'), 'piece' => $d['piece'], 'etat' => $d['etat'], 'raison' => $d['raison']));
		}
		$journal = array_slice($journal, 0, 40);
		config::save('optiJournal', $journal, 'ecopilot');
	}

	public static function journal() {
		$j = config::byKey('optiJournal', 'ecopilot', array());
		return is_array($j) ? $j : array();
	}
}
