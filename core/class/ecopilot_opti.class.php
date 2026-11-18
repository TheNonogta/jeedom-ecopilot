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

	/** Seuil de puissance au-delà duquel on considère le chauffage actif (watts). */
	public static function seuilEtat($_piece) {
		$v = $_piece->getConfiguration('opti_seuil', '');
		return ($v === '') ? 10.0 : floatval($v);
	}

	/** Surface au sol de la pièce en m², si elle est renseignée (sert au calcul kWh/m²/an). */
	public static function surface($_piece) {
		$v = $_piece->getConfiguration('opti_surface', '');
		return ($v === '') ? null : floatval($v);
	}

	/** Volume de la pièce en m³, s'il est renseigné (sert à comparer des pièces de tailles différentes). */
	public static function volume($_piece) {
		$v = $_piece->getConfiguration('opti_volume', '');
		return ($v === '') ? null : floatval($v);
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
		/* la commande mémorisée peut avoir été supprimée et recréée (nouvel id) : on retombe
		 * sur la température de la pièce, qui existe toujours */
		$tempId = intval($_piece->getConfiguration('opti_temp', 0));
		if ($tempId <= 0 || !is_object(cmd::byId($tempId))) {
			$p = ecopilot::cmdOf($_piece, 'p_temperature');
			$tempId = is_object($p) ? $p->getId() : 0;
		}
		if ($tempId <= 0) {
			return array();
		}
		$depuis = date('Y-m-d H:i:s', time() - $_heures * 3600);
		/* La moyenne de la pièce n'a pas d'historique exploitable (valeur constante, commande
		 * recréée) : on prend la température d'une sonde de la pièce, qui elle est alimentée. */
		$compter = function ($id) use ($depuis) {
			$r = DB::Prepare('SELECT COUNT(*) n FROM history WHERE cmd_id = :id AND datetime >= :d',
				array('id' => intval($id), 'd' => $depuis), DB::FETCH_TYPE_ROW);
			return is_array($r) ? intval($r['n']) : 0;
		};
		if ($compter($tempId) < 5) {
			$meilleur = $tempId;
			$nMeilleur = $compter($tempId);
			foreach ($_piece->getSondes() as $sondeId => $compte) {
				$sonde = ecopilot_sonde::byId($sondeId);
				if (!is_object($sonde)) {
					continue;
				}
				$grandeursSonde = $sonde->getGrandeurs();
				if (!isset($grandeursSonde['temperature'])) {
					continue;
				}
				$g = ecopilot::cmdOf($sonde, 'g_temperature');
				if (!is_object($g)) {
					continue;
				}
				$n = $compter($g->getId());
				if ($n > $nMeilleur) {
					$nMeilleur = $n;
					$meilleur = $g->getId();
				}
			}
			if ($meilleur != $tempId && $nMeilleur >= 5) {
				$tempId = $meilleur;
			}
		}
		$temp = DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id AND datetime >= :d ORDER BY datetime ASC',
			array('id' => intval($tempId), 'd' => $depuis), DB::FETCH_TYPE_ALL);
		if (count($temp) < 5) {
			return array();
		}
		$etatId = intval($_piece->getConfiguration('opti_etat', 0));
		if ($etatId > 0 && !is_object(cmd::byId($etatId))) {
			$etatId = 0; /* lien mort : on repasse en mesure partielle */
		}
		$etat = array();
		$derive = false;
		if ($etatId > 0) {
			$etat = DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id AND datetime >= :d ORDER BY datetime ASC',
				array('id' => intval($etatId), 'd' => $depuis), DB::FETCH_TYPE_ALL);
		} else {
			/* Pas d'état relié : on le déduit de la consommation, mais seulement si la mesure
			 * est propre à cette pièce. Une PAC commune ne dit pas quelle zone chauffe. */
			$pId = intval($_piece->getConfiguration('opti_puissance', 0));
			if ($pId > 0 && is_object(cmd::byId($pId)) && !self::consoPartagee($_piece, 'puissance')) {
				$seuil = self::seuilEtat($_piece);
				foreach (DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id AND datetime >= :d ORDER BY datetime ASC',
					array('id' => $pId, 'd' => $depuis), DB::FETCH_TYPE_ALL) as $ligne) {
					$w = ecopilot::toNum($ligne['value']);
					if ($w === null) {
						continue;
					}
					$etat[] = array('datetime' => $ligne['datetime'], 'value' => ($w > $seuil) ? 1 : 0);
				}
				$derive = (count($etat) > 0) ? 'conso' : false;
			}
			/* dernier recours, pour une zone dont la puissance est commune (PAC) et qui n'expose
			 * pas d'état : la zone appelle si sa consigne dépasse sa température mesurée. */
			if (!$derive) {
				$cId = intval($_piece->getConfiguration('opti_consigne', 0));
				if ($cId > 0 && is_object(cmd::byId($cId))) {
					$hist = DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id AND datetime >= :d ORDER BY datetime ASC',
						array('id' => $cId, 'd' => $depuis), DB::FETCH_TYPE_ALL);
					$consigneActuelle = ecopilot::toNum(cmd::byId($cId)->getValue());
					$i = 0;
					$n = count($hist);
					$consigne = $consigneActuelle;
					foreach (DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id AND datetime >= :d ORDER BY datetime ASC',
						array('id' => intval($tempId), 'd' => $depuis), DB::FETCH_TYPE_ALL) as $ligne) {
						$ts = strtotime($ligne['datetime']);
						while ($i < $n && strtotime($hist[$i]['datetime']) <= $ts) {
							$v = ecopilot::toNum($hist[$i]['value']);
							if ($v !== null) { $consigne = $v; }
							$i++;
						}
						$t = ecopilot::toNum($ligne['value']);
						if ($t === null || $consigne === null) {
							continue;
						}
						$etat[] = array('datetime' => $ligne['datetime'], 'value' => (($consigne - $t) > 0.2) ? 1 : 0);
					}
					$derive = (count($etat) > 0) ? 'consigne' : false;
				}
			}
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

		/* On ne compte plus les micro-variations : on repère des **phases** franches de baisse
		 * (au moins 0,3 °C d'amplitude) et on en tire une pente. Une maison stable produit des
		 * oscillations de 0,1 °C qui ne veulent rien dire thermiquement. */
		$segments = array();
		$n = count($samples);
		$i = 1;
		while ($i < $n) {
			$dt = $samples[$i]['ts'] - $samples[$i - 1]['ts'];
			$dT = $samples[$i]['temp'] - $samples[$i - 1]['temp'];
			if ($dt <= 0 || $dt > 3600 || abs($dT) < 0.05) {
				$i++;
				continue;
			}
			$sens = ($dT > 0) ? 1 : -1;
			$j = $i;
			$debutTs = $samples[$i - 1]['ts'];
			$debutVal = $samples[$i - 1]['temp'];
			$finVal = $samples[$j]['temp'];
			$finTs = $samples[$j]['ts'];
			$actif = $samples[$j]['actif'];
			while ($j + 1 < $n) {
				$dt2 = $samples[$j + 1]['ts'] - $samples[$j]['ts'];
				$dT2 = $samples[$j + 1]['temp'] - $samples[$j]['temp'];
				if ($dt2 <= 0 || $dt2 > 3600) {
					break;
				}
				if ($sens > 0 && $dT2 < -0.05) { break; }
				if ($sens < 0 && $dT2 > 0.05) { break; }
				$j++;
				$finVal = $samples[$j]['temp'];
				$finTs = $samples[$j]['ts'];
			}
			$amplitude = abs($finVal - $debutVal);
			$heures = ($finTs - $debutTs) / 3600.0;
			if ($heures > 0 && $amplitude >= 0.3) {
				$segments[] = array('sens' => $sens, 'amplitude' => $amplitude, 'heures' => $heures,
					'pente' => $amplitude / $heures, 'temp' => $debutVal, 'actif' => $actif);
			}
			$i = $j + 1;
		}

		$pentesPerte = array();
		$heuresPerte = 0.0;
		$pentesGain = array();
		$heuresGain = 0.0;
		foreach ($segments as $seg) {
			if ($seg['sens'] < 0 && $seg['actif'] !== true) {
				$ecart = ($ext !== null) ? max(0.5, $seg['temp'] - $ext) : null;
				$pentesPerte[] = ($ecart !== null) ? $seg['pente'] / $ecart : $seg['pente'];
				$heuresPerte += $seg['heures'];
			} elseif ($seg['sens'] > 0 && $seg['actif'] === true) {
				$pentesGain[] = $seg['pente'];
				$heuresGain += $seg['heures'];
			}
		}

		$mediane = function ($valeurs) {
			if (count($valeurs) == 0) {
				return null;
			}
			sort($valeurs);
			$milieu = intval(floor(count($valeurs) / 2));
			return (count($valeurs) % 2) ? $valeurs[$milieu] : ($valeurs[$milieu - 1] + $valeurs[$milieu]) / 2;
		};

		$etatRelie = intval($_piece->getConfiguration('opti_etat', 0));
		$retour = array(
			'deperdition' => null,
			'inertie' => null,
			'heures' => round($heuresPerte + $heuresGain, 1),
			'heures_perte' => round($heuresPerte, 1),
			'suffisant' => false,
			'partiel' => ($etatRelie <= 0 || !is_object(cmd::byId($etatRelie))),
			'derive' => (isset($derive) ? $derive : false),
			'echantillons' => count($samples),
			'segments' => count($segments),
		);

		$pentePerte = $mediane($pentesPerte);
		if ($pentePerte !== null && $heuresPerte >= 0.5) {
			$retour['deperdition'] = round($pentePerte, 3);
		}
		$penteGain = $mediane($pentesGain);
		if ($penteGain !== null && $penteGain > 0) {
			$retour['inertie'] = intval(round(0.5 / $penteGain * 60));
		}
		/* une classe est publiée dès 2 h de refroidissement observé, mais marquée provisoire
		 * tant qu'on n'a pas 6 h : en dessous de 2 h, une seule nuit froide ferait tout basculer */
		$retour['suffisant'] = ($heuresPerte >= 6.0);
		$retour['publiable'] = ($heuresPerte >= 2.0);
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

	/**
	 * Unité de l'index d'énergie d'une pièce : celle de la commande si le module la déclare,
	 * sinon celle saisie sur la sonde de la pièce. Les compteurs sont souvent en Wh.
	 */
	public static function uniteEnergie($_piece) {
		$cmdId = $_piece->getConfiguration('opti_energie', '');
		if ($cmdId !== '') {
			$cmd = cmd::byId($cmdId);
			if (is_object($cmd) && trim($cmd->getUnite()) !== '') {
				return strtolower(trim($cmd->getUnite()));
			}
		}
		foreach ($_piece->getSondes() as $sondeId => $compte) {
			$sonde = ecopilot_sonde::byId($sondeId);
			if (!is_object($sonde)) {
				continue;
			}
			$conf = $sonde->getGrandeur('energie_index');
			if (is_array($conf) && isset($conf['unit']) && $conf['unit'] !== '') {
				return strtolower(trim($conf['unit']));
			}
		}
		return '';
	}

	/**
	 * Unité d'un compteur d'énergie : celle de la commande si le module la déclare, sinon celle
	 * saisie sur la sonde qui relie cette commande (les modules de comptage n'en déclarent souvent
	 * aucune et comptent en Wh).
	 */
	public static function unitePourCompteur($_cmdId) {
		$id = intval($_cmdId);
		$cmd = cmd::byId($id);
		if (is_object($cmd) && trim($cmd->getUnite()) !== '') {
			return strtolower(trim($cmd->getUnite()));
		}
		foreach (ecopilot_sonde::all() as $sonde) {
			$grandeurs = $sonde->getGrandeurs();
			if (!isset($grandeurs['energie_index'])) {
				continue;
			}
			$conf = $grandeurs['energie_index'];
			if (!isset($conf['unit']) || trim($conf['unit']) === '') {
				continue;
			}
			$sources = isset($conf['sources']) ? array_map('intval', $conf['sources']) : array();
			if (in_array($id, $sources, true)) {
				return strtolower(trim($conf['unit']));
			}
		}
		return '';
	}

	/** Énergie consommée depuis minuit, ramenée en kWh quel que soit l'index du module (Wh ou kWh). */
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
		$delta = $dernier - $premier;
		$unite = self::unitePourCompteur($id);
		if ($unite == 'wh') {
			$delta = $delta / 1000; /* les compteurs de certains modules sont en Wh */
		}
		return round($delta, 2);
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
			/* volume renseigné : on ramène la déperdition à une pièce de référence de 40 m³,
			 * sinon une grande pièce serait toujours moins bien classée qu'un placard */
			/* Le volume ne sert PAS à corriger la classe : la vitesse de refroidissement en °C/h
			 * rapporte déjà la perte à l'inertie de la pièce. Le classement se fait donc sur la
			 * déperdition brute ; le volume reste là pour dimensionner, plus tard. */
			$ref = $m['deperdition'];
			$d['deperdition_ref'] = $ref;
			$d['classe'] = null;
			$d['classe_at'] = null;
			$d['classe_ancienne'] = false;
			if (!empty($m['publiable']) && $ref !== null) {
				$d['classe'] = self::classe($ref);
				$d['classe_at'] = date('Y-m-d H:i:s');
			} else {
				/* Une classe calculée hier ne doit pas s'effacer parce qu'aujourd'hui la pièce n'a pas
				 * assez refroidi : on conserve la dernière valeur, en la datant, pendant 48 h. */
				$memoire = $piece->getConfiguration('opti_classe', array());
				if (is_array($memoire) && isset($memoire['classe']) && isset($memoire['at'])
					&& (time() - strtotime($memoire['at'])) < 48 * 3600) {
					$d['classe'] = $memoire['classe'];
					$d['classe_at'] = $memoire['at'];
					$d['classe_ancienne'] = true;
				}
			}
			if ($d['classe'] !== null) {
				$piece->setConfiguration('opti_classe', array('classe' => $d['classe'], 'at' => $d['classe_at']));
			}
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

	/* ------------------------------------------------------------------ détection automatique */

	/** Normalise un libellé pour comparer (sans accents ni casse). */
	private static function norm($_txt) {
		$txt = str_replace(
			array('à','â','ä','á','ã','ç','é','è','ê','ë','í','î','ï','ó','ô','ö','õ','ù','û','ü','ñ','°'),
			array('a','a','a','a','a','c','e','e','e','e','i','i','i','o','o','o','o','u','u','u','n',''),
			mb_strtolower(trim((string) $_txt))
		);
		return $txt;
	}

	/**
	 * Cherche, pour une pièce, les commandes qui ressemblent à ce qu'attend chaque rôle.
	 * On ne retient un rôle que s'il y a **une seule** candidate plausible : mieux vaut
	 * laisser la main à l'utilisateur que de relier la mauvaise commande en silence.
	 *
	 * Retourne [role => ['cmd' => id, 'nom' => nom, 'raison' => ...]] et la liste des ambiguïtés.
	 */
	public static function detecter($_piece) {
		$trouve = array();
		$ambigu = array();
		$nomPiece = self::norm($_piece->getName());

		/* 1. la température : la commande de la pièce elle-même, toujours */
		$pT = ecopilot::cmdOf($_piece, 'p_temperature');
		if (is_object($pT)) {
			$trouve['temp'] = array('cmd' => $pT->getId(), 'nom' => 'Température de la pièce (moyenne)');
		}

		/* 2. les équipements de la pièce : nom qui contient celui de la pièce, ou équipement
		 *    rangé dans l'objet Jeedom du même nom (la plupart des installations rangent
		 *    « Chauffage », « Prise »… dans l'objet de la pièce). */
		$equipements = array();
		$objets = array();
		foreach (jeeObject::all() as $obj) {
			if (self::norm($obj->getName()) == $nomPiece) {
				$objets[$obj->getId()] = $obj->getId();
			}
		}
		foreach (eqLogic::all() as $eq) {
			if (in_array($eq->getEqType_name(), array('ecopilot_piece', 'ecopilot_sonde', 'ecopilot_maison', 'ecopilot_sim'))) {
				continue;
			}
			$parNom = (mb_strpos(self::norm($eq->getName()), $nomPiece) !== false);
			$parObjet = isset($objets[intval($eq->getObject_id())]);
			if ($parNom || $parObjet) {
				$equipements[] = $eq;
			}
		}
		/* s'il existe des équipements qui ressemblent à du chauffage, on ne garde que ceux-là
		 * pour l'état et la consigne : une prise de la pièce n'a rien à y faire. */
		$chauffage = array();
		foreach ($equipements as $eq) {
			if (preg_match('/chauff|pac|radiateur|thermo|clim|poele/', self::norm($eq->getName()))) {
				$chauffage[] = $eq;
			}
		}
		if (count($chauffage) > 0) {
			$equipements = $chauffage;
		}
		if (count($equipements) == 0) {
			return array('trouve' => $trouve, 'ambigu' => $ambigu, 'equipements' => 0);
		}

		$candidats = array('etat' => array(), 'consigne' => array(), 'puissance' => array(), 'energie' => array());
		foreach ($equipements as $eq) {
			foreach ($eq->getCmd('info') as $cmd) {
				$nom = self::norm($cmd->getName());
				$num = ($cmd->getSubType() == 'numeric');

				if ($cmd->getSubType() == 'binary' && preg_match('/^(actif|etat|state|marche)/', $nom)) {
					$candidats['etat'][] = $cmd;
					continue;
				}
				if ($num && preg_match('/consigne|setpoint/', $nom)) {
					$candidats['consigne'][] = $cmd;
					continue;
				}
				if ($num && preg_match('/puissance|power|watt/', $nom)) {
					$candidats['puissance'][] = $cmd;
					continue;
				}
				if ($num && preg_match('/energie|consommation|index|kwh/', $nom)) {
					$candidats['energie'][] = $cmd;
				}
			}
		}

		foreach ($candidats as $role => $liste) {
			if (count($liste) == 1) {
				$trouve[$role] = array('cmd' => $liste[0]->getId(), 'nom' => $liste[0]->getName());
			} elseif (count($liste) > 1) {
				/* plusieurs consignes : on préfère celle du chaud si elle existe */
				if ($role == 'consigne') {
					$chaud = array();
					foreach ($liste as $c) {
						if (mb_strpos(self::norm($c->getName()), 'chaud') !== false) {
							$chaud[] = $c;
						}
					}
					if (count($chaud) == 1) {
						$trouve[$role] = array('cmd' => $chaud[0]->getId(), 'nom' => $chaud[0]->getName());
						continue;
					}
				}
				$ambigu[$role] = count($liste);
			}
		}
		return array('trouve' => $trouve, 'ambigu' => $ambigu, 'equipements' => count($equipements));
	}

	/**
	 * Relie ce qui a été détecté, sans jamais écraser un lien existant.
	 * Retourne le rapport de ce qui a été relié et de ce qui reste à faire.
	 */
	public static function autoLier($_piece) {
		$detection = self::detecter($_piece);
		$liens = array();
		foreach ($detection['trouve'] as $role => $info) {
			/* 0 et '' veulent tous deux dire « pas de lien » : un détachement enregistre 0 */
			if (intval($_piece->getConfiguration('opti_' . $role, 0)) > 0) {
				continue; /* déjà relié : on ne touche pas */
			}
			$_piece->setConfiguration('opti_' . $role, $info['cmd']);
			$liens[$role] = $info['nom'];
		}
		if (count($liens) > 0) {
			$_piece->save();
		}
		return array('lies' => $liens, 'ambigu' => $detection['ambigu'], 'equipements' => $detection['equipements']);
	}

	/**
	 * Consommations regroupées par **équipement** et non par pièce : une PAC gainable
	 * alimente plusieurs zones avec une seule mesure de puissance. Additionner la même
	 * commande autant de fois qu'il y a de zones annoncerait un chiffre faux.
	 *
	 * Retourne [clé => ['nom' => équipement, 'puissance' => W, 'energie' => kWh, 'pieces' => [noms]]]
	 */
	public static function consoParEquipement() {
		$groupes = array();
		foreach (self::pieces() as $piece) {
			foreach (array('puissance' => 'puissance', 'energie' => 'energie') as $role => $champ) {
				$cmdId = $piece->getConfiguration('opti_' . $role, '');
				if ($cmdId === '') {
					continue;
				}
				$cmd = cmd::byId($cmdId);
				if (!is_object($cmd)) {
					continue;
				}
				$eq = $cmd->getEqLogic();
				$cle = is_object($eq) ? 'eq' . $eq->getId() : 'cmd' . $cmd->getId();
				$nom = is_object($eq) ? $eq->getName() : $cmd->getName();
				if (!isset($groupes[$cle])) {
					$groupes[$cle] = array('nom' => $nom, 'puissance' => null, 'energie' => null, 'pieces' => array());
				}
				if (!in_array($piece->getName(), $groupes[$cle]['pieces'])) {
					$groupes[$cle]['pieces'][] = $piece->getName();
				}
				if ($role == 'puissance') {
					$groupes[$cle]['puissance'] = self::lire($cmdId);
				} else {
					$groupes[$cle]['energie'] = self::energieJour($piece);
				}
			}
		}
		return $groupes;
	}

	/** Vrai si la commande de puissance/énergie de cette pièce est partagée avec d'autres pièces. */
	public static function consoPartagee($_piece, $_role) {
		$cmdId = $_piece->getConfiguration('opti_' . $_role, '');
		if ($cmdId === '') {
			return false;
		}
		$n = 0;
		foreach (self::pieces() as $autre) {
			if ($autre->getConfiguration('opti_' . $_role, '') == $cmdId) {
				$n++;
			}
		}
		return $n > 1;
	}

	/** Nom de l'équipement qui porte la mesure de consommation d'une pièce. */
	public static function equipementConso($_piece) {
		$cmdId = $_piece->getConfiguration('opti_puissance', '');
		if ($cmdId === '') {
			$cmdId = $_piece->getConfiguration('opti_energie', '');
		}
		$cmd = ($cmdId === '') ? null : cmd::byId($cmdId);
		if (!is_object($cmd)) {
			return '';
		}
		$eq = $cmd->getEqLogic();
		return is_object($eq) ? $eq->getName() : '';
	}

	/* ------------------------------------------------------------------ consommation réelle (type DPE) */

	/**
	 * Consommation électrique relevée sur les index, sur toute la profondeur d'historique disponible.
	 * Les commandes partagées (PAC à plusieurs zones) ne sont comptées qu'une fois.
	 */
	public static function consoRelevee() {
		$vus = array();
		$kwh = 0.0;
		$plusAncien = null;
		foreach (self::pieces() as $piece) {
			$id = intval($piece->getConfiguration('opti_energie', 0));
			if ($id <= 0 || isset($vus[$id]) || !is_object(cmd::byId($id))) {
				continue;
			}
			$vus[$id] = true;
			$rows = DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id ORDER BY datetime ASC',
				array('id' => $id), DB::FETCH_TYPE_ALL);
			if (count($rows) < 2) {
				continue;
			}
			$premier = null;
			$dernier = null;
			foreach ($rows as $ligne) {
				$v = ecopilot::toNum($ligne['value']);
				if ($v === null) {
					continue;
				}
				if ($premier === null) {
					$premier = $v;
					if ($plusAncien === null || $ligne['datetime'] < $plusAncien) {
						$plusAncien = $ligne['datetime'];
					}
				}
				$dernier = $v;
			}
			if ($premier === null || $dernier === null || $dernier < $premier) {
				continue;
			}
			$delta = $dernier - $premier;
			/* l'unité peut être déclarée sur la commande, ou saisie sur la sonde d'une des pièces
			 * qui partagent ce compteur : on prend la première indication disponible */
			$unite = self::unitePourCompteur($id);
			if ($unite == 'wh') {
				$delta = $delta / 1000; /* compteurs en Wh : on ramène en kWh */
			}
			$kwh += $delta;
		}
		$jours = ($plusAncien === null) ? 0 : max(1, intval(round((time() - strtotime($plusAncien)) / 86400)));
		return array('kwh' => round($kwh, 2), 'jours' => $jours, 'depuis' => $plusAncien);
	}

	/** Classe DPE pour une consommation en énergie primaire (kWh/m²/an) — arrêté du 31 mars 2021. */
	public static function classeDpe($_cep) {
		$seuils = array('A' => 70, 'B' => 110, 'C' => 180, 'D' => 250, 'E' => 330, 'F' => 420);
		foreach ($seuils as $classe => $seuil) {
			if ($_cep <= $seuil) {
				return $classe;
			}
		}
		return 'G';
	}

	/**
	 * Indicateur « consommation réelle », comparable au DPE : kWh électriques relevés, convertis en
	 * énergie primaire (coefficient 2,3 pour l'électricité), rapportés à la surface des pièces
	 * renseignées, puis extrapolés sur un an.
	 */
	public static function surfaceMaison() {
		$v = config::byKey('optiSurfaceMaison', 'ecopilot', '');
		return ($v === '') ? null : floatval($v);
	}

	/** Type du compteur général : 'index' (kWh/Wh) ou 'puissance' (W, à intégrer). */
	public static function typeCompteurGeneral() {
		$t = config::byKey('optiCompteurType', 'ecopilot', 'index');
		return ($t == 'puissance') ? 'puissance' : 'index';
	}

	/** Énergie obtenue en intégrant une puissance (W) dans le temps : kWh sur la période. */
	public static function consoParIntegration($_cmdId) {
		$rows = DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id ORDER BY datetime ASC',
			array('id' => intval($_cmdId)), DB::FETCH_TYPE_ALL);
		$kwh = 0.0;
		$precedent = null;
		$debut = null;
		foreach ($rows as $ligne) {
			$w = ecopilot::toNum($ligne['value']);
			if ($w === null) {
				continue;
			}
			$ts = strtotime($ligne['datetime']);
			if ($debut === null) {
				$debut = $ligne['datetime'];
			}
			if ($precedent !== null) {
				$dt = $ts - $precedent['ts'];
				/* on ignore les trous de plus de 15 min : l'intégration deviendrait une invention */
				if ($dt > 0 && $dt <= 900) {
					$kwh += (($precedent['w'] + $w) / 2) * ($dt / 3600.0) / 1000.0;
				}
			}
			$precedent = array('ts' => $ts, 'w' => $w);
		}
		$jours = ($debut === null) ? 0 : max(1, intval(round((time() - strtotime($debut)) / 86400)));
		return array('kwh' => round($kwh, 3), 'jours' => $jours, 'depuis' => $debut);
	}

	public static function compteurGeneral() {
		$id = intval(config::byKey('optiCompteurGeneral', 'ecopilot', 0));
		return ($id > 0 && is_object(cmd::byId($id))) ? $id : 0;
	}

	public static function indicateurConsommation() {
		/* Surface de référence du DPE : la surface habitable **entière**, considérée chauffée
		 * dans son ensemble. À défaut, on additionne les surfaces des pièces renseignées. */
		$surfaceMaison = self::surfaceMaison();
		/* Somme des surfaces de **toutes** les pièces intérieures, y compris celles qui ne sont pas
		 * pilotées : la surface de référence du DPE couvre le logement entier. Le champ manuel, s'il
		 * est rempli, reste prioritaire (utile pour déclarer une pièce non modélisée). */
		$surface = 0.0;
		$piecesAvecSurface = 0;
		foreach (ecopilot_piece::all() as $piece) {
			if ($piece->getPieceType() != 'interieur') {
				continue;
			}
			$s = self::surface($piece);
			if ($s !== null && $s > 0) {
				$surface += $s;
				$piecesAvecSurface++;
			}
		}
		$sourceSurface = 'pieces';
		if ($surfaceMaison !== null && $surfaceMaison > 0) {
			$surface = $surfaceMaison;
			$sourceSurface = 'maison';
		}
		$conso = self::consoRelevee();
		$general = self::compteurGeneral();
		$partiel = true;
		if ($general > 0 && self::typeCompteurGeneral() == 'puissance') {
			/* compteur en watts : on intègre la puissance pour obtenir des kWh */
			$conso = self::consoParIntegration($general);
			$partiel = false;
		} elseif ($general > 0) {
			/* compteur général : toute la consommation de la maison, comme l'exige un DPE */
			$rows = DB::Prepare('SELECT datetime, value FROM history WHERE cmd_id = :id ORDER BY datetime ASC',
				array('id' => $general), DB::FETCH_TYPE_ALL);
			$premier = null;
			$dernier = null;
			$depuis = null;
			foreach ($rows as $ligne) {
				$v = ecopilot::toNum($ligne['value']);
				if ($v === null) {
					continue;
				}
				if ($premier === null) {
					$premier = $v;
					$depuis = $ligne['datetime'];
				}
				$dernier = $v;
			}
			if ($premier !== null && $dernier !== null && $dernier >= $premier) {
				$delta = $dernier - $premier;
				if (self::unitePourCompteur($general) == 'wh') {
					$delta = $delta / 1000;
				}
				$conso = array('kwh' => round($delta, 2), 'jours' => ($depuis === null ? 0 : max(1, intval(round((time() - strtotime($depuis)) / 86400)))), 'depuis' => $depuis);
				$partiel = false;
			}
		}
		$retour = array('surface' => round($surface, 1), 'pieces' => $piecesAvecSurface,
			'source_surface' => $sourceSurface,
			'kwh' => $conso['kwh'], 'jours' => $conso['jours'], 'depuis' => $conso['depuis'],
			'general' => ($general > 0), 'partiel' => $partiel,
			'kwh_an' => null, 'cep' => null, 'classe' => null);
		if ($surface <= 0 || $conso['kwh'] <= 0 || $conso['jours'] <= 0) {
			return $retour;
		}
		$kwhAn = $conso['kwh'] * 365 / $conso['jours'];
		$retour['kwh_an'] = round($kwhAn, 0);
		$retour['cep'] = round($kwhAn * 2.3 / $surface, 0);
		$retour['classe'] = self::classeDpe($retour['cep']);
		return $retour;
	}

	public static function journal() {
		$j = config::byKey('optiJournal', 'ecopilot', array());
		return is_array($j) ? $j : array();
	}
}
