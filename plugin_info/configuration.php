<?php
/* Page de configuration du plugin Éco Pilot — champs natifs (configKey), aucun AJAX à écrire. */
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<div class="row row-overflow">
	<div class="col-xs-12">
		<form class="form-horizontal">
			<fieldset>
				<legend><i class="fas fa-sign-in-alt"></i> {{Lecture des valeurs d'entrée}}</legend>
				<div class="form-group">
					<label class="col-md-4 control-label">{{Suivre les changements en direct}}
						<sup><i class="fas fa-question-circle tooltips" title="{{Le plugin est prévenu à chaque changement de valeur d'une commande source reliée (temps réel, sans scrutation).}}"></i></sup>
					</label>
					<div class="col-md-4">
						<input type="checkbox" class="configKey" data-l1key="liveFollow" checked>
					</div>
				</div>
				<div class="form-group">
					<label class="col-md-4 control-label">{{Relecture de secours (minutes)}}
						<sup><i class="fas fa-question-circle tooltips" title="{{0 = suivi en direct uniquement. Une valeur supérieure relit les commandes sources à cet intervalle, en plus du suivi en direct.}}"></i></sup>
					</label>
					<div class="col-md-4">
						<input type="number" class="configKey form-control" data-l1key="refreshSeconds" min="0" value="0">
					</div>
				</div>
				<div class="form-group">
					<label class="col-md-4 control-label">{{Seuil de fraîcheur par défaut (minutes)}}
						<sup><i class="fas fa-question-circle tooltips" title="{{Au-delà de ce délai sans nouvelle valeur, une grandeur est signalée « source muette ». Réglable grandeur par grandeur.}}"></i></sup>
					</label>
					<div class="col-md-4">
						<input type="number" class="configKey form-control" data-l1key="freshDefault" min="1" value="60">
					</div>
				</div>
			</fieldset>
			<fieldset>
				<legend><i class="fas fa-chart-line"></i> {{Historisation}}</legend>
				<div class="form-group">
					<label class="col-md-4 control-label">{{Historiser les valeurs lues}}</label>
					<div class="col-md-4">
						<input type="checkbox" class="configKey" data-l1key="historize" checked>
					</div>
				</div>
			</fieldset>
			<fieldset>
				<legend><i class="fas fa-calculator"></i> {{Calculs}}</legend>
				<div class="form-group">
					<label class="col-md-4 control-label">{{Grandeurs calculées à la maison}}</label>
					<div class="col-md-4">
						<span class="help-block">{{Température maison, écart intérieur/extérieur, pièce la plus froide et hygrométrie maison sont calculés à partir des pièces qui comptent.}}</span>
					</div>
				</div>
			</fieldset>
		</form>
	</div>
</div>
