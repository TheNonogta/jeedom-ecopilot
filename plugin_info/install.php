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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

/**
 * Installation / mise à jour du plugin Éco Pilot.
 * - objet d'accueil « Éco Pilot »
 * - équipement « Valeurs calculées » (moyennes de la maison)
 * - simulateur (de quoi éprouver la chaîne sans matériel)
 * - abonnements de suivi en direct sur les commandes déjà reliées
 */
function ecopilot_install() {
	ecopilot::ensureObject();
	ecopilot_maison::ensure();
	ecopilot_sim::ensure();
	if (config::byKey('freshDefault', 'ecopilot', '') === '') {
		config::save('freshDefault', 60, 'ecopilot');
	}
	if (config::byKey('refreshSeconds', 'ecopilot', '') === '') {
		config::save('refreshSeconds', 0, 'ecopilot');
	}
	if (config::byKey('historize', 'ecopilot', '') === '') {
		config::save('historize', 1, 'ecopilot');
	}
	/* Relecture de secours : le plugin recalcule les moyennes à chaque passage et relit
	 * les commandes sources si l'intervalle de secours est réglé (0 = jamais). */
	$cron = cron::byClassAndFunction('ecopilot', 'cron');
	if (!is_object($cron)) {
		$cron = new cron();
		$cron->setClass('ecopilot');
		$cron->setFunction('cron');
	}
	$cron->setEnable(1);
	$cron->setDeamon(0);
	$cron->setTimeout(5);
	$cron->setSchedule('*/5 * * * *');
	$cron->save();
	ecopilot::registerListeners();
	ecopilot::refreshAll();
	ecopilot::log('installation terminée');
}

function ecopilot_update() {
	ecopilot_install();
}

/**
 * Désinstallation : on retire tout ce que le plugin a créé.
 * L'objet d'accueil n'est supprimé que s'il est vide.
 */
function ecopilot_remove() {
	foreach (array('ecopilot_sonde', 'ecopilot_piece', 'ecopilot_maison', 'ecopilot_sim') as $type) {
		foreach (eqLogic::byType($type) as $eqLogic) {
			foreach ($eqLogic->getCmd() as $cmd) {
				$cmd->remove();
			}
			$eqLogic->remove();
		}
	}
	foreach (listener::byClass('ecopilot') as $listener) {
		$listener->remove();
	}
	$cron = cron::byClassAndFunction('ecopilot', 'cron');
	if (is_object($cron)) {
		$cron->remove();
	}
	$object = ecopilot::ensureObject(false);
	if (is_object($object) && count(eqLogic::byObjectId($object->getId())) == 0) {
		$object->remove();
	}
	ecopilot::log('désinstallation terminée');
}
