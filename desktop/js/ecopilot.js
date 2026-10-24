/* This file is part of Jeedom. Licence AGPL.
 *
 * Éco Pilot — interactions de la page.
 * Le JavaScript est chargé dans l'entête : tout part du DOM au DOMContentLoaded.
 */

function epAlert(_message, _level) {
	if (typeof jeedomUtils !== 'undefined' && jeedomUtils.showAlert) {
		jeedomUtils.showAlert({message: _message, level: _level || 'success'});
	} else {
		alert(_message);
	}
}

function epAjax(_data, _callback) {
	$.ajax({
		type: 'POST',
		url: 'plugins/ecopilot/core/ajax/ecopilot.ajax.php',
		data: _data,
		dataType: 'json',
		error: function (request, status, error) {
			$.hideLoading();
			var message = error;
			if (request.responseJSON && request.responseJSON.result) {
				message = request.responseJSON.result;
			}
			epAlert(message, 'danger');
		},
		success: function (result) {
			$.hideLoading();
			if (typeof result === 'object' && result.state && result.state != 'ok') {
				epAlert(result.result, 'danger');
				return;
			}
			if (typeof _callback === 'function') {
				_callback(result);
			} else {
				epReload();
			}
		}
	});
}

function epReload(_extra) {
	var url = ecopilot_baseUrl + '&tab=' + ecopilot_tab;
	if (ecopilot_sondeId > 0 && ecopilot_tab == 'sondes') {
		url += '&sonde_id=' + ecopilot_sondeId;
	}
	if (ecopilot_pieceId > 0 && ecopilot_tab == 'pieces') {
		url += '&piece_id=' + ecopilot_pieceId;
	}
	if (typeof _extra === 'string' && _extra != '') {
		url += _extra;
	}
	window.location.href = url;
}

function epPieceSondes() {
	var sondes = {};
	$('.ep_pieceSonde').each(function () {
		var sondeId = $(this).data('sonde');
		if ($(this).is(':checked')) {
			var compte = $('.ep_pieceSondeCompte[data-sonde="' + sondeId + '"]').val();
			sondes[sondeId] = (compte == '1') ? 1 : 0;
		}
	});
	return JSON.stringify(sondes);
}

$(document).on('DOMContentLoaded', function () {
	/* rien : on passe par la délégation d'événements ci-dessous */
});

/* -------------------------------------------------------------- sondes */

$(document).on('click', '.bt_newSonde', function () {
	$('#md_newSonde').modal('show');
});

$(document).on('click', '.bt_doCreateSonde', function () {
	var name = $('.ep_newSondeName').val();
	if (name == '') {
		epAlert('{{Donne un nom à la sonde}}', 'warning');
		return;
	}
	$('#md_newSonde').modal('hide');
	epAjax({action: 'createSonde', name: name, piece_id: $('.ep_newSondePiece').val()}, function (result) {
		epAlert('{{Sonde créée}}');
		window.location.href = ecopilot_baseUrl + '&tab=sondes&sonde_id=' + result.id;
	});
});

$(document).on('click', '.bt_saveSonde', function () {
	epAjax({
		action: 'saveSonde',
		id: $(this).data('id'),
		name: $('.ep_sondeName').val(),
		piece_id: $('.ep_sondePiece').val(),
		enable: $('.ep_sondeEnable').is(':checked') ? 1 : 0
	}, function () {
		epAlert('{{Sonde enregistrée}}');
		epReload();
	});
});

$(document).on('click', '.bt_delSonde', function () {
	var id = $(this).data('id');
	jeeDialog.confirm('{{Supprimer cette sonde et toutes ses commandes ?}}', function (result) {
		if (result !== true && result !== 'ok') {
			return;
		}
		epAjax({action: 'deleteSonde', id: id}, function () {
			epAlert('{{Sonde supprimée}}');
			window.location.href = ecopilot_baseUrl + '&tab=sondes';
		});
	});
});

$(document).on('click', '.bt_refreshSonde', function () {
	epAjax({action: 'refreshSonde', id: $(this).data('id')}, function () {
		epAlert('{{Valeurs relues}}');
		epReload();
	});
});

$(document).on('click', '.bt_refreshAll', function () {
	epAjax({action: 'refreshAll'}, function () {
		epAlert('{{Tout est relu}}');
		epReload();
	});
});

/* -------------------------------------------------------------- reliage */

$(document).on('click', '.bt_addSource', function () {
	var sondeId = $(this).data('sonde');
	var key = $(this).data('key');
	var type = $(this).data('type') == 'binary' ? 'binary' : 'info';
	jeedom.cmd.getSelectModal({cmd: {type: 'info'}}, function (result) {
		if (!result || !result.cmd) {
			return;
		}
		if (result.cmd.type != 'info') {
			epAlert('{{Choisis une commande d\'information}}', 'danger');
			return;
		}
		epAjax({action: 'linkSource', sonde_id: sondeId, key: key, cmd_id: result.cmd.id}, function () {
			epAlert('{{Commande reliée}}');
			epReload();
		});
	});
});

$(document).on('click', '.bt_delSource', function () {
	epAjax({
		action: 'unlinkSource',
		sonde_id: $(this).data('sonde'),
		key: $(this).data('key'),
		cmd_id: $(this).data('cmd')
	}, function () {
		epAlert('{{Commande détachée}}');
		epReload();
	});
});

$(document).on('change', '.ep_fresh', function () {
	epAjax({
		action: 'saveGrandeurOptions',
		sonde_id: $(this).data('sonde'),
		key: $(this).data('key'),
		fresh: $(this).val()
	}, function () {
		epAlert('{{Seuil enregistré}}');
	});
});

$(document).on('change', '.ep_agg', function () {
	epAjax({
		action: 'saveGrandeurOptions',
		sonde_id: $(this).data('sonde'),
		key: $(this).data('key'),
		agg: $(this).val()
	}, function () {
		epAlert('{{Agrégation enregistrée}}');
	});
});

$(document).on('click', '.bt_delGrandeur', function () {
	var sondeId = $(this).data('sonde');
	var key = $(this).data('key');
	jeeDialog.confirm('{{Retirer cette grandeur de la sonde ? Ses commandes seront supprimées.}}', function (result) {
		if (result !== true && result !== 'ok') {
			return;
		}
		epAjax({action: 'removeGrandeur', sonde_id: sondeId, key: key}, function () {
			epAlert('{{Grandeur retirée}}');
			epReload();
		});
	});
});

/* -------------------------------------------------------------- catalogue */

$(document).on('click', '.bt_showCatalogue', function () {
	$('#md_catalogue').modal('show');
});

$(document).on('keyup', '#in_catSearch', function () {
	var search = $(this).val().toLowerCase();
	$('.ep_catItem').each(function () {
		var label = String($(this).data('label'));
		$(this).toggle(label.indexOf(search) >= 0);
	});
});

$(document).on('click', '.bt_pickGrandeur', function () {
	var key = $(this).data('key');
	$('#md_catalogue').modal('hide');
	epAjax({action: 'addGrandeur', sonde_id: ecopilot_sondeId, key: key}, function () {
		epAlert('{{Grandeur ajoutée : relie-lui une commande}}');
		epReload();
	});
});

$(document).on('click', '.bt_showCustom', function () {
	$('#md_custom').modal('show');
});

$(document).on('click', '.bt_doCreateCustom', function () {
	var label = $('.ep_customLabel').val();
	if (label == '') {
		epAlert('{{Donne un nom à la grandeur}}', 'warning');
		return;
	}
	$('#md_custom').modal('hide');
	epAjax({
		action: 'addCustomGrandeur',
		sonde_id: ecopilot_sondeId,
		label: label,
		unit: $('.ep_customUnit').val(),
		type: $('.ep_customType').val()
	}, function () {
		epAlert('{{Grandeur personnalisée ajoutée}}');
		epReload();
	});
});

/* -------------------------------------------------------------- pièces */

$(document).on('click', '.bt_newPiece', function () {
	$('#md_newPiece').modal('show');
});

$(document).on('click', '.bt_doCreatePiece', function () {
	var name = $('.ep_newPieceName').val();
	if (name == '') {
		epAlert('{{Donne un nom à la pièce}}', 'warning');
		return;
	}
	$('#md_newPiece').modal('hide');
	epAjax({
		action: 'createPiece',
		name: name,
		icon: $('.ep_newPieceIcon').val(),
		piece_type: $('.ep_newPieceType').val()
	}, function (result) {
		epAlert('{{Pièce créée}}');
		window.location.href = ecopilot_baseUrl + '&tab=pieces&piece_id=' + result.id;
	});
});

$(document).on('click', '.bt_savePiece', function () {
	epAjax({
		action: 'savePiece',
		id: $(this).data('id'),
		name: $('.ep_pieceName').val(),
		icon: $('.ep_pieceIcon').val(),
		piece_type: $('.ep_pieceType').val(),
		maison: $('.ep_pieceMaison').is(':checked') ? 1 : 0
	}, function () {
		epAlert('{{Pièce enregistrée}}');
		epReload();
	});
});

$(document).on('click', '.bt_delPiece', function () {
	var id = $(this).data('id');
	jeeDialog.confirm('{{Supprimer cette pièce ? Les sondes ne sont pas supprimées, elles seront simplement détachées.}}', function (result) {
		if (result !== true && result !== 'ok') {
			return;
		}
		epAjax({action: 'deletePiece', id: id}, function () {
			epAlert('{{Pièce supprimée}}');
			window.location.href = ecopilot_baseUrl + '&tab=pieces';
		});
	});
});

$(document).on('change', '.ep_pieceSonde', function () {
	var sondeId = $(this).data('sonde');
	$('.ep_pieceSondeCompte[data-sonde="' + sondeId + '"]').prop('disabled', !$(this).is(':checked'));
	epAjax({action: 'setPieceSondes', id: ecopilot_pieceId, sondes: epPieceSondes()}, function () {
		epAlert('{{Pièce mise à jour}}');
		epReload();
	});
});

$(document).on('change', '.ep_pieceSondeCompte', function () {
	epAjax({action: 'setPieceSondes', id: ecopilot_pieceId, sondes: epPieceSondes()}, function () {
		epAlert('{{Pièce mise à jour}}');
		epReload();
	});
});

/* -------------------------------------------------------------- simulateur */

$(document).on('click', '.bt_simPush', function () {
	var key = $(this).data('key');
	var value = $('.ep_simValue[data-key="' + key + '"]').val();
	epAjax({action: 'simPush', key: key, value: value}, function () {
		epAlert('{{Valeur poussée}}');
		epReload();
	});
});

$(document).on('click', '.bt_simRandom', function () {
	epAjax({action: 'simPush', key: 'aleatoire'}, function () {
		epAlert('{{Valeurs aléatoires poussées}}');
		epReload();
	});
});
