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
$tab = init('tab', 'sondes');
$vue = init('vue', 'galerie');
$base = 'index.php?v=d&p=ecopilot&m=ecopilot';

$object = ecopilot::ensureObject();
$sondes = ecopilot_sonde::all();
$pieces = ecopilot_piece::all();
$maison = ecopilot_maison::ensure();
$sim = ecopilot_sim::ensure();
$sondeSel = ecopilot_sonde::byId(init('sonde_id'));
$pieceSel = ecopilot_piece::byId(init('piece_id'));

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
			<li role="presentation" class="<?php echo ($tab == 'valeurs') ? 'active' : ''; ?>">
				<a href="<?php echo $base; ?>&tab=valeurs"><i class="fas fa-calculator"></i> {{Valeurs calculées}}</a>
			</li>
			<li role="presentation" class="<?php echo ($tab == 'simulateur') ? 'active' : ''; ?>">
				<a href="<?php echo $base; ?>&tab=simulateur"><i class="fas fa-flask"></i> {{Simulateur}}</a>
			</li>
			<li role="presentation" class="<?php echo ($tab == 'aide') ? 'active' : ''; ?>">
				<a href="<?php echo $base; ?>&tab=aide"><i class="fas fa-question-circle"></i> {{Aide}}</a>
			</li>
			<li role="presentation">
				<a href="index.php?v=d&p=plugin&id=ecopilot"><i class="fas fa-cogs"></i> {{Configuration}}</a>
			</li>
		</ul>
		<div class="alert alert-info" style="margin-top:15px;">
			<small>
				{{Le plugin ne parle à aucun matériel : il lit les commandes de vos équipements existants.}}<br>
				<?php echo $nbGrandeurs; ?> {{grandeur(s) activée(s)}} ·
				<span class="<?php echo ($nbARelie > 0) ? 'text-warning' : ''; ?>"><?php echo $nbARelie; ?> {{à relier}}</span> ·
				<span class="<?php echo ($nbMuettes > 0) ? 'text-warning' : ''; ?>"><?php echo $nbMuettes; ?> {{muette(s)}}</span>
			</small>
		</div>
	</div>

	<div class="col-lg-10 col-md-9 col-sm-8">

<?php if ($tab == 'sondes') { ?>

		<div class="panel panel-default">
			<div class="panel-heading">
				<i class="fas fa-broadcast-tower"></i> {{Sondes}}
				<span class="label label-info"><?php echo count($sondes); ?> {{sonde(s)}} · <?php echo $nbGrandeurs; ?> {{grandeur(s)}}</span>
				<div class="pull-right">
					<div class="btn-group">
						<a class="btn btn-sm btn-default <?php echo ($vue == 'galerie') ? 'active' : ''; ?>" href="<?php echo $base; ?>&tab=sondes&vue=galerie"><i class="fas fa-th"></i> {{Galerie}}</a>
						<a class="btn btn-sm btn-default <?php echo ($vue == 'tableau') ? 'active' : ''; ?>" href="<?php echo $base; ?>&tab=sondes&vue=tableau"><i class="fas fa-list"></i> {{Tableau}}</a>
					</div>
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

<?php if ($vue == 'galerie') { ?>
		<div class="row">
			<?php foreach ($sondes as $sonde) {
				$etat = $sonde->getEtatLabel();
				$pieceSonde = $sonde->getPiece();
				?>
			<div class="col-lg-4 col-md-6">
				<div class="panel panel-default">
					<div class="panel-heading">
						<i class="<?php echo ecopilot_icon($pieceSonde); ?>"></i>
						<?php echo htmlspecialchars($sonde->getName()); ?>
						<span class="label <?php echo $etat['class']; ?>"><?php echo $etat['label']; ?></span>
						<div class="pull-right">
							<a class="btn btn-xs btn-default" href="<?php echo $base; ?>&tab=sondes&sonde_id=<?php echo $sonde->getId(); ?>" title="{{Réglage}}"><i class="fas fa-cog"></i></a>
						</div>
					</div>
					<div class="panel-body">
						<div class="row">
							<?php foreach ($sonde->getGrandeurs() as $key => $conf) {
								$def = ecopilot::grandeurDef($sonde, $key);
								$cmd = ecopilot::cmdOf($sonde, 'g_' . $key);
								$muette = ecopilot::cmdOf($sonde, 'g_' . $key . '_muette');
								$valeur = is_object($cmd) ? $cmd->getValue() : '';
								$unite = is_object($cmd) ? $cmd->getUnite() : '';
								$nbCol = (count($sonde->getGrandeurs()) > 2) ? 4 : 6;
								?>
							<div class="col-xs-<?php echo $nbCol; ?> text-center">
								<span style="font-size:20px;font-weight:bold;<?php echo (is_object($muette) && $muette->getValue() == 1) ? 'text-decoration:line-through;' : ''; ?>">
									<?php echo ecopilot_aff($valeur, 1); ?>
								</span>
								<small><?php echo htmlspecialchars($unite); ?></small>
								<div><small><?php echo htmlspecialchars($def['label']); ?></small></div>
							</div>
							<?php } ?>
						</div>
					</div>
					<div class="panel-footer">
						<small>
							<?php if (is_object($pieceSonde)) { ?><i class="fas fa-home"></i> <?php echo htmlspecialchars($pieceSonde->getName()); ?> · <?php } ?>
							<?php echo $sonde->getNbSources(); ?> {{source(s)}}
							· <?php echo ($sonde->getConfiguration('last_read', '') != '') ? htmlspecialchars($sonde->getConfiguration('last_read')) : '{{jamais lu}}'; ?>
						</small>
					</div>
				</div>
			</div>
			<?php } ?>
			<?php if (count($sondes) > 0) { ?>
			<div class="col-lg-4 col-md-6">
				<div class="panel panel-default" style="border-style:dashed;">
					<div class="panel-body text-center" style="padding:40px 10px;">
						<button class="btn btn-success bt_newSonde"><i class="fas fa-plus"></i> {{Ajouter une sonde}}</button>
						<div><small>{{Nom, pièce, puis tu coches ses grandeurs et tu les relies.}}</small></div>
					</div>
				</div>
			</div>
			<?php } ?>
		</div>
<?php } else { ?>
		<table class="table table-condensed table-striped">
			<thead>
				<tr>
					<th>{{Sonde}}</th>
					<th>{{Pièce}}</th>
					<?php foreach (array_keys($clesUtilisees) as $key) {
						$def = ecopilot::grandeur($key);
						?>
					<th><?php echo htmlspecialchars(is_array($def) ? $def['label'] : $key); ?></th>
					<?php } ?>
					<th>{{Sources}}</th>
					<th>{{Dernière lecture}}</th>
					<th>{{État}}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($sondes as $sonde) {
					$etat = $sonde->getEtatLabel();
					$pieceSonde = $sonde->getPiece();
					?>
				<tr>
					<td><b><?php echo htmlspecialchars($sonde->getName()); ?></b></td>
					<td><?php echo is_object($pieceSonde) ? htmlspecialchars($pieceSonde->getName()) : '—'; ?></td>
					<?php foreach (array_keys($clesUtilisees) as $key) {
						$conf = $sonde->getGrandeur($key);
						if (!is_array($conf)) {
							echo '<td>—</td>';
							continue;
						}
						$cmd = ecopilot::cmdOf($sonde, 'g_' . $key);
						$muette = ecopilot::cmdOf($sonde, 'g_' . $key . '_muette');
						$valeur = is_object($cmd) ? $cmd->getValue() : '';
						$unite = is_object($cmd) ? $cmd->getUnite() : '';
						echo '<td>' . ecopilot_aff($valeur, 1) . ' <small>' . htmlspecialchars($unite) . '</small>';
						if (is_object($muette) && $muette->getValue() == 1) {
							echo ' <i class="fas fa-exclamation-triangle text-warning" title="{{Source muette}}"></i>';
						}
						if (count($sonde->getSources($key)) > 1) {
							echo ' <small>(' . count($sonde->getSources($key)) . ')</small>';
						}
						echo '</td>';
					} ?>
					<td><?php echo $sonde->getNbSources(); ?></td>
					<td><?php echo ($sonde->getConfiguration('last_read', '') != '') ? htmlspecialchars($sonde->getConfiguration('last_read')) : '—'; ?></td>
					<td><span class="label <?php echo $etat['class']; ?>"><?php echo $etat['label']; ?></span></td>
					<td><a class="btn btn-xs btn-default" href="<?php echo $base; ?>&tab=sondes&vue=tableau&sonde_id=<?php echo $sonde->getId(); ?>"><i class="fas fa-cog"></i></a></td>
				</tr>
				<?php } ?>
			</tbody>
		</table>
<?php } ?>

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
									<input type="number" class="form-control input-sm ep_fresh" style="width:70px;" data-sonde="<?php echo $sondeSel->getId(); ?>" data-key="<?php echo $key; ?>"
										value="<?php echo isset($conf['fresh']) ? intval($conf['fresh']) : 60; ?>"> <small>min</small>
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
					<tr><th>{{Grandeur}}</th><th>{{Valeur}}</th><th>{{Sources}}</th><th>{{Dernière remontée}}</th><th>{{Muette}}</th></tr>
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
						<?php
						/* Repère de diagnostic : quand rien n'est calculé, on montre ce que porte
						 * réellement la commande source (utile si un module écrit ailleurs). */
						if (is_object($cmd) && ($cmd->getValue() === '' || $cmd->getValue() === null)) {
							$srcs = $sondeSel->getSources($key);
							if (count($srcs)) {
								$srcCmd = cmd::byId($srcs[0]);
								if (is_object($srcCmd)) {
									$brut = $srcCmd->getValue();
									$histo = ecopilot::lastHistoValue($srcCmd);
									echo '<div><small class="text-warning">{{source}} : '
										. (($brut === '' || $brut === null) ? '{{vide}}' : htmlspecialchars($brut))
										. ' · {{historique}} : ' . ($histo === '' ? '{{aucun}}' : htmlspecialchars($histo))
										. '</small></div>';
								}
							}
						}
						?>
					</td>
						<td><?php echo count($sondeSel->getSources($key)); ?></td>
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

<?php } elseif ($tab == 'valeurs') { ?>
		<div class="panel panel-default">
			<div class="panel-heading">
				<i class="fas fa-calculator"></i> {{Valeurs calculées}} — <?php echo htmlspecialchars($maison->getName()); ?>
				<div class="pull-right"><button class="btn btn-sm btn-default bt_refreshAll"><i class="fas fa-sync"></i> {{Recalculer}}</button></div>
			</div>
			<table class="table table-condensed table-striped">
				<thead><tr><th>{{Valeur}}</th><th>{{Résultat}}</th><th>{{Calcul}}</th></tr></thead>
				<tbody>
					<?php
					$explications = array(
						'temperature' => "{{Moyenne des pièces intérieures qui comptent, à partir de leurs températures de pièce.}}",
						'hygrometrie' => "{{Moyenne des pièces intérieures qui comptent.}}",
						'ecart' => "{{Température maison moins température extérieure.}}",
						'plus_froide' => "{{Température de la pièce intérieure la plus froide.}}",
						'plus_froide_piece' => "{{Nom de cette pièce.}}",
						'pieces' => "{{Nombre de pièces comptées dans la moyenne de la maison.}}",
					);
foreach (array('temperature', 'hygrometrie', 'ecart', 'plus_froide', 'plus_froide_piece', 'pieces') as $logicalId) {
	$cmd = ecopilot::cmdOf($maison, $logicalId);
	if (!is_object($cmd)) {
		continue;
	}
	?>
					<tr>
						<td><b><?php echo htmlspecialchars($cmd->getName()); ?></b></td>
						<td><?php echo ecopilot_aff($cmd->getValue(), 1); ?> <small><?php echo htmlspecialchars($cmd->getUnite()); ?></small></td>
						<td><small><?php echo $explications[$logicalId]; ?></small></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
			<div class="panel-footer">
				<small>{{Ces commandes appartiennent à l'équipement}} « <?php echo htmlspecialchars($maison->getName()); ?> »
				(<?php echo htmlspecialchars($maison->getHumanName()); ?>) : {{tu peux les utiliser dans tes scénarios.}}</small>
			</div>
		</div>

<?php } elseif ($tab == 'simulateur') { ?>
		<div class="panel panel-default">
			<div class="panel-heading">
				<i class="fas fa-flask"></i> {{Simulateur}}
				<div class="pull-right"><button class="btn btn-sm btn-default bt_simRandom"><i class="fas fa-random"></i> {{Valeurs aléatoires}}</button></div>
			</div>
			<div class="panel-body">
				<span class="help-block">{{Le simulateur joue le rôle d'un module : il fournit des commandes d'information que tu peux relier à une sonde, et te permet de pousser une valeur à la main pour voir le suivi en direct à l'œuvre.}}</span>
				<table class="table table-condensed">
					<thead><tr><th>{{Grandeur}}</th><th>{{Valeur actuelle}}</th><th>{{Pousser une valeur}}</th></tr></thead>
					<tbody>
						<?php foreach (ecopilot_sim::$grandeurs as $key => $def) {
							$cmd = ecopilot::cmdOf($sim, $key);
							?>
						<tr>
							<td><?php echo $def['label']; ?> <small><b><?php echo $cmd->getLogicalId(); ?></b> · <?php echo htmlspecialchars($cmd->getHumanName()); ?></small></td>
							<td><b><?php echo ecopilot_aff(is_object($cmd) ? $cmd->getValue() : '', 1); ?></b> <small><?php echo $def['unite']; ?></small></td>
							<td>
								<div class="input-group input-group-sm" style="max-width:280px;">
									<input type="number" class="form-control ep_simValue" data-key="<?php echo $key; ?>" value="<?php echo $def['defaut']; ?>" step="0.1">
									<span class="input-group-btn"><button class="btn btn-default bt_simPush" data-key="<?php echo $key; ?>">{{Pousser}}</button></span>
								</div>
							</td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
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
