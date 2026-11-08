<?php

/* This file is part of Jeedom. Licence AGPL.
 *
 * Page principale du plugin Éco Pilot.
 * L'interface n'emploie que les classes du cœur (panel, table, nav-pills, label, btn, alert) :
 * aucune couleur n'est codée en dur, la page suit le thème actif (clair ou sombre).
 */

if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}

$plugin = plugin::byId('ecopilot');
include_file('desktop', 'ecopilot', 'css', 'ecopilot');
include_file('desktop', 'ecopilot', 'js', 'ecopilot');
require_once dirname(__FILE__) . '/../../core/class/ecopilot_opti.class.php';
$tab = init('tab', 'sondes');
$vue = init('vue', 'galerie');
if ($tab == 'valeurs') { $tab = 'pieces'; } /* onglet fusionne dans Pièces (0.1.21) */
if ($tab == 'simulateur') { $tab = 'optimiseur'; } /* onglet renommé (0.1.22) */
$base = 'index.php?v=d&p=ecopilot&m=ecopilot';

$object = ecopilot::ensureObject();
$sondes = ecopilot_sonde::all();
$pieces = ecopilot_piece::all();
$maison = ecopilot_maison::ensure();
$sim = ecopilot_sim::ensure();
$sondeSel = ecopilot_sonde::byId(init('sonde_id'));
$pieceSel = ecopilot_piece::byId(init('piece_id'));

/* La fiche d'une sonde relit toujours ses sources avant de s'afficher : après une mise à jour
 * ou un reliage, on ne montre jamais une valeur calculée par une version précédente. */
if (is_object($sondeSel) && init('norefresh', 0) != 1) {
	try {
		ecopilot::refreshSonde($sondeSel);
		$sondeSel->refresh();
	} catch (Throwable $e) {
		ecopilot::log('relecture à l\'affichage impossible : ' . $e->getMessage());
	}
}

$nbGrandeurs = 0;
$nbARelie = 0;
$nbMuettes = 0;
$clesUtilisees = array();
foreach ($sondes as $sonde) {
	foreach ($sonde->getGrandeurs() as $key => $conf) {
		$nbGrandeurs++;
		$clesUtilisees[$key] = $key;
		if (count($sonde->getSources($key)) == 0) {
			$nbARelie++;
		}
		$muette = ecopilot::cmdOf($sonde, 'g_' . $key . '_muette');
		if (is_object($muette) && $muette->getValue() == 1) {
			$nbMuettes++;
		}
	}
}

function ecopilot_aff($_value, $_decimals = 2) {
	if ($_value === null || $_value === '') {
		return '—';
	}
	if (is_numeric($_value)) {
		$value = floatval($_value);
		if ($value == floor($value) && abs($value) >= 100) {
			return number_format($value, 0, ',', ' ');
		}
		return rtrim(rtrim(number_format($value, $_decimals, ',', ' '), '0'), ',');
	}
	return htmlspecialchars($_value);
}

function ecopilot_icon($_eqLogic) {
	if (is_object($_eqLogic) && method_exists($_eqLogic, 'getIcon')) {
		return $_eqLogic->getIcon();
	}
	return 'fas fa-broadcast-tower';
}

/**
 * Sélecteur d'icône de pièce : la grille est produite par ecopilot::pickIcone()
 * (catalogue dans core/config/pieces.json), la valeur enregistrée reste la classe Font Awesome.
 */
function ecopilot_pickIcone($_class, $_value) {
	return ecopilot::pickIcone($_class, $_value);
}
?>
<div class="row row-overflow ecopilot">

	<div class="col-lg-2 col-md-3 col-sm-4">
		<legend><i class="fas fa-leaf"></i> {{Éco Pilot}} <span class="label label-info">v<?php echo ecopilot::VERSION; ?></span></legend>
		<ul class="nav nav-pills nav-stacked">
			<li role="presentation" class="<?php echo ($tab == 'sondes') ? 'active' : ''; ?>">
				<a href="<?php echo $base; ?>&tab=sondes"><i class="fas fa-broadcast-tower"></i> {{Sondes}} <span class="badge"><?php echo count($sondes); ?></span></a>
			</li>
			<li role="presentation" class="<?php echo ($tab == 'pieces') ? 'active' : ''; ?>">
				<a href="<?php echo $base; ?>&tab=pieces"><i class="fas fa-home"></i> {{Pièces}} <span class="badge"><?php echo count($pieces); ?></span></a>
			</li>
			<li role="presentation" class="<?php echo ($tab == 'optimiseur') ? 'active' : ''; ?>">
				<a href="<?php echo $base; ?>&tab=optimiseur"><i class="fas fa-sliders-h"></i> {{Optimiseur}}</a>
			</li>
			<li role="presentation" class="<?php echo ($tab == 'aide') ? 'active' : ''; ?>">
				<a href="<?php echo $base; ?>&tab=aide"><i class="fas fa-question-circle"></i> {{Aide}}</a>
			</li>
			<li role="presentation">
				<a href="index.php?v=d&p=plugin&id=ecopilot"><i class="fas fa-cogs"></i> {{Configuration}}</a>
			</li>
		</ul>
	</div>

	<div class="col-lg-10 col-md-9 col-sm-8">

<?php if ($tab == 'sondes') { ?>

		<div class="panel panel-default">
			<div class="panel-heading">
				<i class="fas fa-broadcast-tower"></i> {{Sondes}}
				<span class="label label-info"><?php echo count($sondes); ?> {{sonde(s)}} · <?php echo $nbGrandeurs; ?> {{grandeur(s)}}</span>
				<div class="pull-right">
					<button class="btn btn-sm btn-default bt_refreshAll"><i class="fas fa-sync"></i> {{Tout actualiser}}</button>
					<button class="btn btn-sm btn-success bt_newSonde"><i class="fas fa-plus"></i> {{Ajouter une sonde}}</button>
				</div>
			</div>
			<div class="panel-body">
				<span class="help-block">
					{{Une sonde = un équipement. Tu actives les grandeurs qu'elle fournit (température, hygrométrie, luminosité…) et tu relies chacune à la commande de ton module.}}
				</span>
			</div>
		</div>

<?php if (count($sondes) == 0) { ?>
		<div class="alert alert-info">{{Aucune sonde pour l'instant : commence par « Ajouter une sonde ».}}</div>
<?php } ?>

<div class="row" style="margin-bottom:10px;">
			<div class="col-sm-5">
				<input type="text" class="form-control input-sm ep_filtre" placeholder="{{Filtrer par sonde ou pièce…}}">
			</div>
			<div class="col-sm-3">
				<select class="form-control input-sm ep_filtreEtat">
					<option value="">{{Tous les états}}</option>
					<option value="ok">{{OK}}</option>
					<option value="a_relier">{{À relier}}</option>
					<option value="muette">{{Source muette}}</option>
				</select>
			</div>
		</div>
		<div class="table-responsive">
		<table class="table table-condensed table-striped" id="ep_tableSondes">
			<thead>
				<tr>
					<th class="ep_th" data-type="text">{{Sonde}} <i class="fas fa-sort ep_sortIcon"></i></th>
					<th class="ep_th" data-type="text">{{Pièce}} <i class="fas fa-sort ep_sortIcon"></i></th>
					<?php foreach (array_keys($clesUtilisees) as $key) {
						$def = ecopilot::grandeur($key);
						?>
					<th class="ep_th" data-type="num"><?php echo htmlspecialchars(is_array($def) ? $def['label'] : $key); ?> <i class="fas fa-sort ep_sortIcon"></i></th>
					<?php } ?>
					<th class="ep_th" data-type="num">{{Sources}} <i class="fas fa-sort ep_sortIcon"></i></th>
					<th class="ep_th" data-type="text">{{Dernière lecture}} <i class="fas fa-sort ep_sortIcon"></i></th>
					<th class="ep_th" data-type="text">{{État}} <i class="fas fa-sort ep_sortIcon"></i></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($sondes as $sonde) {
					$etat = $sonde->getEtatLabel();
					$etatCle = $sonde->getEtat();
					$pieceSonde = $sonde->getPiece();
					$recherche = mb_strtolower($sonde->getName() . ' ' . (is_object($pieceSonde) ? $pieceSonde->getName() : ''));
					?>
				<tr data-etat="<?php echo htmlspecialchars($etatCle); ?>" data-nom="<?php echo htmlspecialchars($recherche); ?>">
					<td><?php echo htmlspecialchars($sonde->getName()); ?></td>
					<td><?php echo is_object($pieceSonde) ? htmlspecialchars($pieceSonde->getName()) : '—'; ?></td>
					<?php foreach (array_keys($clesUtilisees) as $key) {
						$conf = $sonde->getGrandeur($key);
						if (!is_array($conf)) {
							echo '<td data-val=""></td>';
							continue;
						}
						$cmd = ecopilot::cmdOf($sonde, 'g_' . $key);
						$muette = ecopilot::cmdOf($sonde, 'g_' . $key . '_muette');
						$valeur = is_object($cmd) ? $cmd->getValue() : '';
						$unite = is_object($cmd) ? $cmd->getUnite() : '';
						$brut = ($valeur === '' || $valeur === null) ? '' : $valeur;
						echo '<td data-val="' . htmlspecialchars($brut) . '">' . ecopilot_aff($valeur, 1) . ' <small>' . htmlspecialchars($unite) . '</small>';
						if (is_object($muette) && $muette->getValue() == 1) {
							echo ' <i class="fas fa-exclamation-triangle text-warning" title="{{Source muette}}"></i>';
						}
						if (count($sonde->getSources($key)) > 1) {
							echo ' <small>(' . count($sonde->getSources($key)) . ')</small>';
						}
						echo '</td>';
					} ?>
					<td data-val="<?php echo $sonde->getNbSources(); ?>"><?php echo $sonde->getNbSources(); ?></td>
					<td><?php echo ($sonde->getConfiguration('last_read', '') != '') ? htmlspecialchars($sonde->getConfiguration('last_read')) : '—'; ?></td>
					<td><span class="label <?php echo $etat['class']; ?>"><?php echo $etat['label']; ?></span></td>
					<td><a class="btn btn-xs btn-default" href="<?php echo $base; ?>&tab=sondes&sonde_id=<?php echo $sonde->getId(); ?>"><i class="fas fa-cog"></i></a></td>
				</tr>
				<?php } ?>
			</tbody>
		</table>
		</div>

<?php if (is_object($sondeSel)) {
	$etatSel = $sondeSel->getEtatLabel();
	?>
		<div class="panel panel-default">
			<div class="panel-heading">
				<i class="fas fa-sliders-h"></i> <?php echo htmlspecialchars($sondeSel->getName()); ?> — {{réglage}}
				<span class="label <?php echo $etatSel['class']; ?>"><?php echo $etatSel['label']; ?></span>
				<div class="pull-right">
					<button class="btn btn-sm btn-default bt_refreshSonde" data-id="<?php echo $sondeSel->getId(); ?>"><i class="fas fa-sync"></i> {{Relire}}</button>
					<button class="btn btn-sm btn-danger bt_delSonde" data-id="<?php echo $sondeSel->getId(); ?>"><i class="fas fa-trash"></i> {{Supprimer}}</button>
					<button class="btn btn-sm btn-primary bt_saveSonde" data-id="<?php echo $sondeSel->getId(); ?>"><i class="fas fa-check"></i> {{Enregistrer}}</button>
				</div>
			</div>
			<div class="panel-body">
				<div class="row">
					<div class="col-md-5">
						<div class="form-group">
							<label class="control-label">{{Nom de la sonde}}</label>
							<input type="text" class="form-control input-sm ep_sondeName" value="<?php echo htmlspecialchars($sondeSel->getName()); ?>">
						</div>
					</div>
					<div class="col-md-4">
						<div class="form-group">
							<label class="control-label">{{Pièce}}</label>
							<select class="form-control input-sm ep_sondePiece">
								<option value="0">{{— aucune —}}</option>
								<?php foreach ($pieces as $piece) { ?>
								<option value="<?php echo $piece->getId(); ?>" <?php echo ($sondeSel->getPieceId() == $piece->getId()) ? 'selected' : ''; ?>><?php echo htmlspecialchars($piece->getName()); ?></option>
								<?php } ?>
							</select>
						</div>
					</div>
					<div class="col-md-3">
						<div class="form-group">
							<label class="control-label">{{Sonde active}}</label><br>
							<input type="checkbox" class="ep_sondeEnable" <?php echo $sondeSel->getIsEnable() ? 'checked' : ''; ?>>
						</div>
					</div>
				</div>

				<hr>
				<div class="form-group">
					<label class="control-label">{{Grandeurs de cette sonde}}</label>
					<table class="table table-condensed">
						<thead>
							<tr>
								<th style="width:32%">{{Grandeur}}</th>
								<th style="width:38%">{{Commande source (sélecteur Jeedom)}}</th>
								<th style="width:10%">{{Unité}}</th>
								<th style="width:10%">{{Fraîcheur}}</th>
								<th style="width:10%">{{Agrégation}}</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($sondeSel->getGrandeurs() as $key => $conf) {
								$def = ecopilot::grandeurDef($sondeSel, $key);
								$cmd = ecopilot::cmdOf($sondeSel, 'g_' . $key);
								$sources = $sondeSel->getSources($key);
								$typeAttendu = $def['type'];
								?>
							<tr>
								<td>
									<i class="<?php echo $def['icon']; ?>"></i> <b><?php echo htmlspecialchars($def['label']); ?></b>
									<?php if (strpos($key, 'x_') === 0) { ?> <span class="label label-default">{{personnalisée}}</span><?php } ?>
									<div><small>
										<?php echo ($typeAttendu == 'binary') ? '{{oui / non}}' : (($typeAttendu == 'string') ? '{{texte}}' : '{{numérique}}'); ?>
									</small></div>
								</td>
								<td>
									<?php if (count($sources) == 0) { ?>
										<button class="btn btn-xs btn-success bt_addSource" data-sonde="<?php echo $sondeSel->getId(); ?>" data-key="<?php echo $key; ?>" data-type="<?php echo $typeAttendu; ?>">
											<i class="fas fa-hand-pointer-o"></i> {{Relier une commande}}
										</button>
									<?php } else {
										foreach ($sources as $cmdId) {
											$cmdSource = cmd::byId($cmdId);
											?>
										<div class="input-group input-group-sm" style="margin-bottom:3px;">
											<input type="text" class="form-control" readonly value="<?php echo is_object($cmdSource) ? htmlspecialchars($cmdSource->getHumanName()) : '{{commande supprimée}}'; ?>">
											<span class="input-group-btn">
												<button class="btn btn-danger bt_delSource" data-sonde="<?php echo $sondeSel->getId(); ?>" data-key="<?php echo $key; ?>" data-cmd="<?php echo $cmdId; ?>" title="{{Détacher}}"><i class="fas fa-times"></i></button>
											</span>
										</div>
										<?php } ?>
										<button class="btn btn-xs btn-default bt_addSource" data-sonde="<?php echo $sondeSel->getId(); ?>" data-key="<?php echo $key; ?>" data-type="<?php echo $typeAttendu; ?>">
											<i class="fas fa-plus"></i> {{Ajouter une source}}
										</button>
									<?php } ?>
								</td>
								<td><?php echo is_object($cmd) ? htmlspecialchars($cmd->getUnite()) : '—'; ?></td>
								<td>
									<?php $freshDefaut = ecopilot::freshDefault(ecopilot::grandeurDef($sondeSel, $key)); ?>
									<input type="number" min="0" class="form-control input-sm ep_fresh" style="width:70px;" data-sonde="<?php echo $sondeSel->getId(); ?>" data-key="<?php echo $key; ?>"
										title="{{Délai sans remontée avant de déclarer la source muette. 0 = jamais.}}"
										value="<?php echo (isset($conf['fresh']) && $conf['fresh'] !== '') ? intval($conf['fresh']) : $freshDefaut; ?>">
									<small><?php echo $freshDefaut == 0 ? '{{jamais muette}}' : 'min'; ?></small>
								</td>
								<td>
									<select class="form-control input-sm ep_agg" data-sonde="<?php echo $sondeSel->getId(); ?>" data-key="<?php echo $key; ?>" style="width:110px;">
										<?php if ($typeAttendu == 'binary') {
											$aggs = array('or' => '{{Ou}}', 'and' => '{{Et}}', 'last' => '{{Dernière}}');
										} elseif ($typeAttendu == 'string') {
											$aggs = array('last' => '{{Dernière}}', 'mean' => '{{Première}}');
										} else {
											$aggs = array('mean' => '{{Moyenne}}', 'last' => '{{Dernière}}', 'max' => '{{Maximum}}', 'min' => '{{Minimum}}');
										}
										$aggCourant = isset($conf['agg']) ? $conf['agg'] : 'mean';
										foreach ($aggs as $value => $label) { ?>
										<option value="<?php echo $value; ?>" <?php echo ($aggCourant == $value) ? 'selected' : ''; ?>><?php echo $label; ?></option>
										<?php } ?>
									</select>
								</td>
								<td>
									<button class="btn btn-xs btn-danger bt_delGrandeur" data-sonde="<?php echo $sondeSel->getId(); ?>" data-key="<?php echo $key; ?>" title="{{Retirer cette grandeur}}"><i class="fas fa-trash"></i></button>
								</td>
							</tr>
							<?php } ?>
						</tbody>
					</table>
					<button class="btn btn-sm btn-success bt_showCatalogue"><i class="fas fa-plus"></i> {{Ajouter une grandeur}}</button>
					<button class="btn btn-sm btn-default bt_showCustom"><i class="fas fa-plus"></i> {{Grandeur personnalisée}}</button>
				</div>

				<div class="alert alert-info">
					<small>
						{{Commandes engendrées pour cette sonde}} :
						<?php foreach ($sondeSel->getGrandeurs() as $key => $conf) {
							$def = ecopilot::grandeurDef($sondeSel, $key);
							echo '<b>' . htmlspecialchars($def['label']) . '</b>, ';
						} ?>
						{{plus « dernière remontée » et « muette » par grandeur — utilisables dans tes scénarios.}}
					</small>
				</div>
			</div>
		</div>

		<!-- grandes valeurs lues (contrôle) -->
		<div class="panel panel-default">
			<div class="panel-heading"><i class="fas fa-eye"></i> {{Valeurs lues et état}}</div>
			<table class="table table-condensed table-striped">
				<thead>
					<tr><th>{{Grandeur}}</th><th>{{Valeur}}</th><th>{{Sources}}</th><th>{{Source lue}}</th><th>{{Dernière remontée}}</th><th>{{Muette}}</th></tr>
				</thead>
				<tbody>
					<?php foreach ($sondeSel->getGrandeurs() as $key => $conf) {
						$def = ecopilot::grandeurDef($sondeSel, $key);
						$cmd = ecopilot::cmdOf($sondeSel, 'g_' . $key);
						$age = ecopilot::cmdOf($sondeSel, 'g_' . $key . '_age');
						$muette = ecopilot::cmdOf($sondeSel, 'g_' . $key . '_muette');
						?>
					<tr>
						<td><?php echo htmlspecialchars($def['label']); ?></td>
						<td><?php echo ecopilot_aff(is_object($cmd) ? $cmd->getValue() : '', 1); ?> <small><?php echo is_object($cmd) ? htmlspecialchars($cmd->getUnite()) : ''; ?></small>
					</td>
						<td><?php echo count($sondeSel->getSources($key)); ?></td>
					<td><small><?php
						$srcs = $sondeSel->getSources($key);
						if (count($srcs) == 0) {
							echo '—';
						}
						foreach ($srcs as $srcId) {
							$srcCmd = cmd::byId($srcId);
							if (!is_object($srcCmd)) {
								echo '#' . $srcId . ' {{introuvable}}<br>';
								continue;
							}
							$srcVal = $srcCmd->getValue();
							echo '#' . $srcId . ' · ';
							echo ($srcVal === '' || $srcVal === null) ? '{{vide}}' : htmlspecialchars($srcVal);
							echo ' ' . htmlspecialchars($srcCmd->getUnite());
							$h = ecopilot::lastHistoValue($srcCmd);
							echo ' · {{histo}} ' . ($h === '' ? '{{aucun}}' : htmlspecialchars($h));
							$readSrc = ecopilot::readSources($sondeSel, $key, $conf, $def);
							echo ' · {{lu maintenant}} ' . htmlspecialchars(json_encode($readSrc['nums'] != array() ? $readSrc['nums'] : $readSrc['bools']));
							echo '<br>';
						}
					?></small></td>
						<td><?php echo is_object($age) && $age->getValue() !== '' ? intval($age->getValue()) . ' min' : '—'; ?></td>
						<td><?php echo (is_object($muette) && $muette->getValue() == 1) ? '<span class="label label-warning">{{oui}}</span>' : '<span class="label label-success">{{non}}</span>'; ?></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>

<?php } ?>

<?php } elseif ($tab == 'pieces') { ?>

		<div class="panel panel-default">
			<div class="panel-heading">
				<i class="fas fa-home"></i> {{Pièces}}
				<span class="label label-info"><?php echo count($pieces); ?> {{pièce(s)}}</span>
				<div class="pull-right"><button class="btn btn-sm btn-success bt_newPiece"><i class="fas fa-plus"></i> {{Ajouter une pièce}}</button></div>
			</div>
			<div class="panel-body">
				<span class="help-block">{{Les pièces sont créées dans le plugin et regroupent les sondes : c'est ce qui permet les moyennes par pièce, la température de la maison et l'écart intérieur/extérieur.}}</span>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading">
				<i class="fas fa-house-chimney"></i> {{Valeurs de la maison}}
				<div class="pull-right"><button class="btn btn-sm btn-default bt_refreshAll"><i class="fas fa-sync"></i> {{Recalculer}}</button></div>
			</div>
			<table class="table table-condensed">
				<tbody>
					<?php
					$lignesMaison = array(
						array('temperature', '{{Température maison}}', '°C', '{{Moyenne des pièces intérieures}}'),
						array('hygrometrie', '{{Hygrométrie maison}}', '%', '{{Moyenne des pièces intérieures}}'),
						array('eclairement', '{{Luminosité maison}}', 'lx', '{{Moyenne des pièces intérieures}}'),
						array('temperature_ext', '{{Température extérieure}}', '°C', ''),
						array('ecart', '{{Écart intérieur / extérieur}}', '°C', '{{Température maison moins température extérieure}}'),
						array('plus_froide', '{{Pièce la plus froide}}', '°C', '{{Température de la pièce intérieure la plus froide}}'),
						array('plus_chaude', '{{Pièce la plus chaude}}', '°C', '{{Température de la pièce intérieure la plus chaude}}'),
					);
					foreach ($lignesMaison as $ligne) {
						$cmd = ecopilot::cmdOf($maison, $ligne[0]);
						$nomPiece = '';
						if ($ligne[0] == 'plus_froide' || $ligne[0] == 'plus_chaude') {
							$cmdPiece = ecopilot::cmdOf($maison, $ligne[0] . '_piece');
							$nomPiece = is_object($cmdPiece) ? trim($cmdPiece->getValue()) : '';
						}
						?>
					<tr>
						<td style="width:230px;"><b><?php echo $ligne[1]; ?></b></td>
						<td style="width:170px;">
							<?php echo is_object($cmd) ? ecopilot_aff($cmd->getValue(), 1) : '—'; ?>
							<small><?php echo htmlspecialchars($ligne[2]); ?></small>
							<?php if ($nomPiece != '') { ?><small>· <?php echo htmlspecialchars($nomPiece); ?></small><?php } ?>
						</td>
						<td><small><?php echo $ligne[3]; ?></small></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>

<?php if (count($pieces) == 0) { ?>
		<div class="alert alert-info">{{Aucune pièce pour l'instant.}}</div>
<?php } ?>

		<div class="row">
			<?php foreach ($pieces as $piece) { ?>
			<div class="col-lg-4 col-md-6">
				<div class="panel panel-default">
					<div class="panel-heading">
						<i class="<?php echo $piece->getIcon(); ?>"></i> <?php echo htmlspecialchars($piece->getName()); ?>
						<span class="label label-default"><?php echo count($piece->getSondes()); ?> {{sonde(s)}}</span>
						<?php if ($piece->getPieceType() == 'exterieur') { ?><span class="label label-info">{{extérieure}}</span><?php } ?>
						<div class="pull-right">
							<a class="btn btn-xs btn-default" href="<?php echo $base; ?>&tab=pieces&piece_id=<?php echo $piece->getId(); ?>"><i class="fas fa-cog"></i></a>
						</div>
					</div>
					<div class="panel-body">
						<table class="table table-condensed" style="margin-bottom:0;">
							<?php foreach ($piece->getGrandeursDisponibles() as $key => $def) {
								$cmd = ecopilot::cmdOf($piece, 'p_' . $key);
								?>
							<tr>
								<td><i class="<?php echo $def['icon']; ?>"></i> <?php echo htmlspecialchars($def['label']); ?></td>
								<td class="text-right"><b><?php echo ecopilot_aff(is_object($cmd) ? $cmd->getValue() : '', 1); ?></b> <small><?php echo is_object($cmd) ? htmlspecialchars($cmd->getUnite()) : ''; ?></small></td>
							</tr>
							<?php } ?>
						</table>
					</div>
				</div>
			</div>
			<?php } ?>
		</div>

<?php if (is_object($pieceSel)) { ?>
		<div class="panel panel-default">
			<div class="panel-heading">
				<i class="fas fa-sliders-h"></i> <?php echo htmlspecialchars($pieceSel->getName()); ?> — {{réglage}}
				<div class="pull-right">
					<button class="btn btn-sm btn-danger bt_delPiece" data-id="<?php echo $pieceSel->getId(); ?>"><i class="fas fa-trash"></i> {{Supprimer}}</button>
					<button class="btn btn-sm btn-primary bt_savePiece" data-id="<?php echo $pieceSel->getId(); ?>"><i class="fas fa-check"></i> {{Enregistrer}}</button>
				</div>
			</div>
			<div class="panel-body">
				<div class="row">
					<div class="col-md-4">
						<div class="form-group">
							<label class="control-label">{{Nom}}</label>
							<input type="text" class="form-control input-sm ep_pieceName" value="<?php echo htmlspecialchars($pieceSel->getName()); ?>">
						</div>
					</div>
					<div class="col-md-4">
						<div class="form-group">
							<label class="control-label">{{Type}}</label>
							<select class="form-control input-sm ep_pieceType">
								<option value="interieur" <?php echo ($pieceSel->getPieceType() == 'interieur') ? 'selected' : ''; ?>>{{Intérieure}}</option>
								<option value="exterieur" <?php echo ($pieceSel->getPieceType() == 'exterieur') ? 'selected' : ''; ?>>{{Extérieure}}</option>
							</select>
							<div><small>{{Une pièce extérieure sert à l'écart intérieur/extérieur.}}</small></div>
						</div>
					</div>
				</div>
				<div class="form-group">
					<label class="control-label">{{Icône de la pièce}}</label>
					<?php echo ecopilot_pickIcone('ep_pieceIcon', $pieceSel->getIcon()); ?>
				</div>
				<div class="form-group">
					<label class="control-label">{{Compter dans la moyenne de la maison}}</label>
					<input type="checkbox" class="ep_pieceMaison" <?php echo $pieceSel->getIsMaison() ? 'checked' : ''; ?>>
				</div>

				<hr>
				<div class="form-group">
					<label class="control-label">{{Sondes rattachées à cette pièce}}</label>
					<table class="table table-condensed">
						<thead>
							<tr><th>{{Sonde}}</th><th>{{Grandeurs}}</th><th style="width:140px">{{Compter dans la moyenne}}</th></tr>
						</thead>
						<tbody>
							<?php foreach ($sondes as $sonde) {
								$sondesPiece = $pieceSel->getSondes();
								$estIce = array_key_exists(intval($sonde->getId()), $sondesPiece);
								$compte = $estIce ? intval($sondesPiece[intval($sonde->getId())]) : 0;
								$grandeursSonde = array();
								foreach ($sonde->getGrandeurs() as $key => $conf) {
									$def = ecopilot::grandeurDef($sonde, $key);
									$grandeursSonde[] = $def['label'];
								}
								?>
							<tr>
								<td>
									<input type="checkbox" class="ep_pieceSonde" data-sonde="<?php echo $sonde->getId(); ?>" <?php echo $estIce ? 'checked' : ''; ?>>
									<?php echo htmlspecialchars($sonde->getName()); ?>
								</td>
								<td><small><?php echo htmlspecialchars(implode(' · ', $grandeursSonde)); ?></small></td>
								<td>
									<select class="form-control input-sm ep_pieceSondeCompte" data-sonde="<?php echo $sonde->getId(); ?>" <?php echo $estIce ? '' : 'disabled'; ?>>
										<option value="1" <?php echo ($compte == 1) ? 'selected' : ''; ?>>{{oui}}</option>
										<option value="0" <?php echo ($compte == 0) ? 'selected' : ''; ?>>{{non}}</option>
									</select>
								</td>
							</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
<?php } ?>

<?php } elseif ($tab == 'optimiseur') {

	$pilotables = ecopilot_opti::pieces();
	$modeOpti = ecopilot_opti::mode();
	$journal = ecopilot_opti::journal();
	$modes = array('confort' => '{{Confort}}', 'reduit' => '{{Réduit}}', 'absence' => '{{Absence}}', 'horsgel' => '{{Hors gel}}');
	?>
		<div class="panel panel-default">
			<div class="panel-heading">
				<i class="fas fa-sliders-h"></i> {{Optimiseur — chauffage}}
				<span class="label label-info">{{observation}}</span>
				<div class="pull-right">
					<div class="btn-group">
						<?php foreach ($modes as $cle => $libelle) { ?>
						<button class="btn btn-sm btn-default ep_optiMode <?php echo ($modeOpti == $cle) ? 'active' : ''; ?>" data-mode="<?php echo $cle; ?>"><?php echo $libelle; ?></button>
						<?php } ?>
					</div>
					<button class="btn btn-sm btn-default bt_refreshOpti"><i class="fas fa-sync"></i> {{Recalculer}}</button>
				</div>
			</div>
			<div class="panel-body">
				<span class="help-block" style="margin:0;">
					{{Le moteur recalcule toutes les 5 minutes la consigne qu'il enverrait, et l'explique. Rien n'est appliqué : il observe, mesure la déperdition et l'inertie de chaque pièce, et écrit ses décisions — y compris celles de ne rien faire.}}
				</span>
			</div>
		</div>

<?php if (count($pilotables) == 0) { ?>
		<div class="alert alert-info">{{Aucune pièce n'est encore réglée pour l'optimiseur : active une pièce dans le tableau des réglages ci-dessous et donne-lui ses consignes Confort et Réduit.}}</div>
<?php } else { ?>
		<div class="panel panel-default">
			<div class="panel-heading"><i class="fas fa-fire"></i> {{Pièces chauffées}} <span class="label label-default"><?php echo count($pilotables); ?></span></div>
			<div class="table-responsive">
			<table class="table table-condensed">
				<thead>
					<tr>
						<th>{{Pièce}}</th><th>{{Température}}</th><th>{{Consigne actuelle}}</th><th>{{Consigne proposée}}</th>
						<th>{{Écart}}</th><th>{{Classe}}</th><th>{{Déperdition}}</th><th>{{Inertie}}</th><th>{{Puissance}}</th><th>{{Décision}}</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($pilotables as $piece) {
						$d = $piece->getConfiguration('opti_last', array());
						$m = isset($d['mesures']) ? $d['mesures'] : array('deperdition' => null, 'inertie' => null, 'suffisant' => false);
						$classe = isset($d['classe']) ? $d['classe'] : null;
						?>
					<tr>
						<td><b><?php echo htmlspecialchars($piece->getName()); ?></b></td>
						<td><b><?php echo isset($d['temp']) && $d['temp'] !== null ? ecopilot_aff($d['temp'], 1) : '—'; ?></b> <small>°C</small></td>
						<td><?php echo isset($d['consigne_actuelle']) && $d['consigne_actuelle'] !== null ? ecopilot_aff($d['consigne_actuelle'], 1) . ' °C' : '—'; ?></td>
						<td><b><?php echo isset($d['proposee']) && $d['proposee'] !== null ? ecopilot_aff($d['proposee'], 1) . ' °C' : '—'; ?></b></td>
						<td><?php echo isset($d['ecart']) && $d['ecart'] !== null ? sprintf('%+.1f °C', $d['ecart']) : '—'; ?></td>
						<td><?php echo $classe === null ? '—' : '<span class="' . ecopilot_opti::classeLabel($classe) . '">' . $classe . '</span>'; ?></td>
						<td><?php echo $m['deperdition'] === null ? '—' : number_format($m['deperdition'], 3, ',', '') . ' °C/h'; ?></td>
						<td><?php echo $m['inertie'] === null ? '—' : intval($m['inertie']) . ' min'; ?></td>
						<td><?php echo isset($d['puissance']) && $d['puissance'] !== null ? number_format($d['puissance'], 0, ',', ' ') . ' W' : '—'; ?></td>
						<td><small><?php echo htmlspecialchars(isset($d['raison']) ? $d['raison'] : '—'); ?></small></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
			</div>
			<div class="panel-footer">
				<small>{{Le classement n'est affiché qu'après assez de mesures par pièce ; en dessous, la colonne reste vide. Le mode courant s'applique à toutes les pièces.}}</small>
			</div>
		</div>

		<div class="row">
			<div class="col-md-7">
				<div class="panel panel-default">
					<div class="panel-heading"><i class="fas fa-chart-bar"></i> {{Classement énergétique}}</div>
					<table class="table table-condensed" style="margin:0;">
						<thead><tr><th>{{Pièce}}</th><th>{{Classe}}</th><th style="width:40%">{{Déperdition}}</th><th>{{Énergie du jour}}</th></tr></thead>
						<tbody>
							<?php foreach ($pilotables as $piece) {
								$d = $piece->getConfiguration('opti_last', array());
								$dep = isset($d['mesures']['deperdition']) ? $d['mesures']['deperdition'] : null;
								$classe = isset($d['classe']) ? $d['classe'] : null;
								$largeur = ($dep === null) ? 0 : max(4, min(100, round($dep / 0.4 * 100)));
								?>
							<tr>
								<td><?php echo htmlspecialchars($piece->getName()); ?></td>
								<td><?php echo $classe === null ? '—' : '<span class="' . ecopilot_opti::classeLabel($classe) . '">' . $classe . '</span>'; ?></td>
								<td>
									<div class="progress" style="margin:0;height:8px;"><div class="progress-bar" style="width:<?php echo $largeur; ?>%"></div></div>
									<small><?php echo $dep === null ? '{{pas encore assez de mesures}}' : number_format($dep, 3, ',', '') . ' °C/h · ' . intval($d['mesures']['heures']) . ' h mesurées'; ?></small>
								</td>
								<td><?php echo isset($d['energie_jour']) && $d['energie_jour'] !== null ? number_format($d['energie_jour'], 2, ',', ' ') . ' kWh' : '—'; ?></td>
							</tr>
							<?php } ?>
						</tbody>
					</table>
					<div class="panel-footer"><small>A ≤ 0,05 · B 0,08 · C 0,12 · D 0,18 · E 0,25 · F 0,35 · G au-delà — seuils à recaler sur tes mesures.</small></div>
				</div>
			</div>
			<div class="col-md-5">
				<div class="panel panel-default">
					<div class="panel-heading"><i class="fas fa-clipboard-list"></i> {{Journal des décisions}}</div>
					<table class="table table-condensed table-striped" style="margin:0;">
						<tbody>
							<?php if (count($journal) == 0) { ?>
							<tr><td><small>{{Aucune décision enregistrée pour l'instant.}}</small></td></tr>
							<?php } foreach (array_slice($journal, 0, 12) as $ligne) { ?>
							<tr>
								<td style="width:60px;"><small><?php echo htmlspecialchars(substr($ligne['ts'], 11, 5)); ?></small></td>
								<td><small><?php echo htmlspecialchars($ligne['raison']); ?></small></td>
							</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
<?php } ?>

		<div class="panel panel-default">
			<div class="panel-heading"><i class="fas fa-cog"></i> {{Réglages et liens, pièce par pièce}}</div>
			<div class="table-responsive">
			<table class="table table-condensed">
				<thead>
					<tr>
						<th>{{Pièce}}</th><th>{{Pilotée}}</th><th>{{Confort}}</th><th>{{Réduit}}</th><th>{{Mini}}</th><th>{{Maxi}}</th>
						<th>{{Température}}</th><th>{{État actif}}</th><th>{{Consigne actuelle}}</th><th>{{Puissance}}</th><th>{{Énergie}}</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($pieces as $piece) {
						if ($piece->getPieceType() != 'interieur') { continue; }
						$roles = array(
							'temp' => '{{Température}}',
							'etat' => '{{État actif}}',
							'consigne' => '{{Consigne actuelle}}',
							'puissance' => '{{Puissance}}',
							'energie' => '{{Énergie}}',
						);
						?>
					<tr>
						<td><b><?php echo htmlspecialchars($piece->getName()); ?></b></td>
						<td><input type="checkbox" class="ep_optiEnable" data-piece="<?php echo $piece->getId(); ?>" <?php echo ($piece->getConfiguration('opti_enable', 0) == 1) ? 'checked' : ''; ?>></td>
						<td><input type="number" step="0.5" style="width:70px;" class="form-control input-sm ep_optiReglage" data-piece="<?php echo $piece->getId(); ?>" data-champ="confort" value="<?php echo htmlspecialchars($piece->getConfiguration('opti_confort', 20)); ?>"></td>
						<td><input type="number" step="0.5" style="width:70px;" class="form-control input-sm ep_optiReglage" data-piece="<?php echo $piece->getId(); ?>" data-champ="reduit" value="<?php echo htmlspecialchars($piece->getConfiguration('opti_reduit', 17)); ?>"></td>
						<td><input type="number" step="0.5" style="width:70px;" class="form-control input-sm ep_optiReglage" data-piece="<?php echo $piece->getId(); ?>" data-champ="min" value="<?php echo htmlspecialchars($piece->getConfiguration('opti_min', 8)); ?>"></td>
						<td><input type="number" step="0.5" style="width:70px;" class="form-control input-sm ep_optiReglage" data-piece="<?php echo $piece->getId(); ?>" data-champ="max" value="<?php echo htmlspecialchars($piece->getConfiguration('opti_max', 24)); ?>"></td>
						<?php foreach ($roles as $role => $libelle) {
							$cmdId = $piece->getConfiguration('opti_' . $role, '');
							$cmd = ($cmdId === '') ? null : cmd::byId($cmdId);
							?>
						<td>
							<?php if (is_object($cmd)) { ?>
							<span class="label label-default"><?php echo htmlspecialchars($cmd->getName()); ?></span>
							<button class="btn btn-xs btn-default bt_optiUnlink" data-piece="<?php echo $piece->getId(); ?>" data-role="<?php echo $role; ?>" title="{{Détacher}}"><i class="fas fa-times"></i></button>
							<?php } else { ?>
							<button class="btn btn-xs btn-default bt_optiLien" data-piece="<?php echo $piece->getId(); ?>" data-role="<?php echo $role; ?>"><i class="fas fa-link"></i> {{Relier}}</button>
							<?php } ?>
						</td>
						<?php } ?>
					</tr>
					<?php } ?>
				</tbody>
			</table>
			</div>
			<div class="panel-footer"><small>{{La température vient de la moyenne de la pièce si tu ne relies rien. L'ouverture est lue sur les sondes de la pièce : une ouverture ouverte coupe le chauffage.}}</small></div>
		</div>

<?php } else { ?>
		<div class="panel panel-default">
			<div class="panel-heading"><i class="fas fa-question-circle"></i> {{Aide}}</div>
			<div class="panel-body">
				<h4>{{Comment ça marche}}</h4>
				<ol>
					<li>{{Crée une pièce (onglet Pièces) : salon, chambre, bureau… et dis si elle compte dans la moyenne de la maison.}}</li>
					<li>{{Crée une sonde (onglet Sondes) et range-la dans sa pièce.}}</li>
					<li>{{Dans la sonde, active les grandeurs qu'elle fournit, puis relie chacune à la commande d'information de ton module avec le sélecteur Jeedom.}}</li>
					<li>{{Éco Pilot lit ces commandes : à chaque changement de valeur il met la sonde à jour (suivi en direct), et recalcule les moyennes de la pièce puis de la maison.}}</li>
				</ol>
				<h4>{{Bon à savoir}}</h4>
				<ul>
					<li>{{Plusieurs sources pour une même grandeur : moyenne pour une valeur numérique, ou logique pour un oui/non.}}</li>
					<li>{{Si une source ne remonte plus depuis plus longtemps que le seuil de fraîcheur, la grandeur passe en « source muette » et est écartée des moyennes.}}</li>
					<li>{{Les unités sont celles de tes commandes sources : rien n'est codé en dur.}}</li>
					<li>{{Le plugin ne parle à aucun matériel : il ne fait que lire et recalculer.}}</li>
				</ul>
			</div>
		</div>
<?php } ?>

	</div>
</div>

<!-- modales -->
<div class="modal fade" id="md_newSonde">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{Ajouter une sonde}}</h4></div>
			<div class="modal-body">
				<div class="form-group">
					<label class="control-label">{{Nom de la sonde}}</label>
					<input type="text" class="form-control ep_newSondeName" placeholder="{{Sonde salon}}">
				</div>
				<div class="form-group">
					<label class="control-label">{{Pièce}}</label>
					<select class="form-control ep_newSondePiece">
						<option value="0">{{— aucune —}}</option>
						<?php foreach ($pieces as $piece) { ?>
						<option value="<?php echo $piece->getId(); ?>"><?php echo htmlspecialchars($piece->getName()); ?></option>
						<?php } ?>
					</select>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-default" data-dismiss="modal">{{Annuler}}</button>
				<button class="btn btn-success bt_doCreateSonde"><i class="fas fa-plus"></i> {{Créer la sonde}}</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="md_newPiece">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{Ajouter une pièce}}</h4></div>
			<div class="modal-body">
				<div class="form-group">
					<label class="control-label">{{Nom}}</label>
					<input type="text" class="form-control ep_newPieceName" placeholder="{{Salon}}">
				</div>
				<div class="form-group">
					<label class="control-label">{{Icône}}</label>
					<?php echo ecopilot_pickIcone('ep_newPieceIcon', 'fas fa-home'); ?>
				</div>
				<div class="form-group">
					<label class="control-label">{{Type}}</label>
					<select class="form-control ep_newPieceType">
						<option value="interieur">Intérieure</option>
						<option value="exterieur">Extérieure</option>
					</select>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-default" data-dismiss="modal">{{Annuler}}</button>
				<button class="btn btn-success bt_doCreatePiece"><i class="fas fa-plus"></i> {{Créer la pièce}}</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="md_catalogue">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<button class="close" data-dismiss="modal">&times;</button>
				<h4 class="modal-title">{{Ajouter une grandeur}}</h4>
			</div>
			<div class="modal-body" style="max-height:60vh;overflow:auto;">
				<input type="text" class="form-control" id="in_catSearch" placeholder="{{Rechercher une grandeur…}}">
				<?php foreach (ecopilot::catalogue() as $family) { ?>
				<h5 style="margin-top:15px;"><i class="<?php echo $family['icon']; ?>"></i> <?php echo htmlspecialchars($family['label']); ?></h5>
				<div class="row">
					<?php foreach ($family['grandeurs'] as $grandeur) {
						if ($grandeur['key'] == 'personnalisee') {
							continue;
						}
						$dejaLa = is_object($sondeSel) && ($sondeSel->getGrandeur($grandeur['key']) !== null);
						?>
					<div class="col-md-4 ep_catItem" data-label="<?php echo strtolower(htmlspecialchars($grandeur['label'])); ?>">
						<button class="btn btn-xs <?php echo $dejaLa ? 'btn-default' : 'btn-success'; ?> bt_pickGrandeur" data-key="<?php echo $grandeur['key']; ?>" <?php echo $dejaLa ? 'disabled' : ''; ?> style="margin-bottom:5px;">
							<i class="<?php echo $grandeur['icon']; ?>"></i> <?php echo htmlspecialchars($grandeur['label']); ?>
							<?php if ($grandeur['unit'] != '') { ?><small>(<?php echo htmlspecialchars($grandeur['unit']); ?>)</small><?php } ?>
							<?php if ($dejaLa) { ?><small>· {{déjà activée}}</small><?php } ?>
						</button>
					</div>
					<?php } ?>
				</div>
				<?php } ?>
			</div>
			<div class="modal-footer">
				<button class="btn btn-default" data-dismiss="modal">{{Fermer}}</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="md_custom">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{Grandeur personnalisée}}</h4></div>
			<div class="modal-body">
				<div class="form-group">
					<label class="control-label">{{Nom}}</label>
					<input type="text" class="form-control ep_customLabel" placeholder="{{Ex. : niveau citerne}}">
				</div>
				<div class="form-group">
					<label class="control-label">{{Unité}}</label>
					<input type="text" class="form-control ep_customUnit" placeholder="{{Ex. : L}}">
				</div>
				<div class="form-group">
					<label class="control-label">{{Type}}</label>
					<select class="form-control ep_customType">
						<option value="numeric">{{Numérique}}</option>
						<option value="binary">{{Oui / non}}</option>
						<option value="string">{{Texte}}</option>
					</select>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-default" data-dismiss="modal">{{Annuler}}</button>
				<button class="btn btn-success bt_doCreateCustom"><i class="fas fa-plus"></i> {{Ajouter}}</button>
			</div>
		</div>
	</div>
</div>

<script>
	ecopilot_baseUrl = '<?php echo $base; ?>';
	ecopilot_sondeId = <?php echo is_object($sondeSel) ? intval($sondeSel->getId()) : 0; ?>;
	ecopilot_pieceId = <?php echo is_object($pieceSel) ? intval($pieceSel->getId()) : 0; ?>;
	ecopilot_tab = '<?php echo $tab; ?>';
</script>
