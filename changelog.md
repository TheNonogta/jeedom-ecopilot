# Journal des modifications — Éco Pilot

## 0.1.1 — 2026-10-24

Version de vérification du canal de mise à jour GitHub. Aucun changement fonctionnel : seul le numéro de version
change, afin de contrôler que la détection et l'application d'une mise à jour se font correctement depuis un dépôt
GitHub (le plugin installé passe de 0.1.0 à 0.1.1 sans perdre ses réglages ni ses reliages).

## 0.1.0 — 2026-10-07

Première version publique. Brique **Entrées**.

- **Sondes** : une sonde = un équipement ; autant de grandeurs que voulu ; catalogue de 63 grandeurs en 7 familles
  plus une grandeur personnalisée ; unité reprise de la commande source.
- **Reliage** par le sélecteur de commandes natif de Jeedom ; plusieurs sources par grandeur (moyenne, minimum,
  maximum, dernière ; ou / et pour un binaire).
- **Fraîcheur** par grandeur : « à relier », « source muette » (âge lu dans l'historique), seuil réglable.
- **Pièces** : création de pièces dans le plugin, rattachement des sondes, moyenne par pièce et par grandeur.
- **Valeurs calculées** : température et hygrométrie de la maison, écart intérieur / extérieur, pièce la plus froide.
- **Deux vues** : galerie de cartes et tableau.
- **Suivi en direct** par abonnements `listener` ; relecture de secours par tâche cron `*/5 * * * *` ; historisation.
- **Simulateur** : module factice à trois grandeurs pour éprouver la chaîne sans matériel.

Correctifs de comportement vis-à-vis du cœur Jeedom 4.6 :

- la valeur d'une commande est posée par `setValue()` + `save()` **avant** `event()`, `event()` seul n'écrivant pas la
  colonne `value` ;
- la fraîcheur d'une source est lue dans l'historique (la table `cmd` n'a pas de colonne de date) ;
- les abonnements sont réenregistrés après une remise à zéro des réglages.

## À venir — 0.2.0

Brique **Sorties** : éclairage (état, marche/arrêt, niveau, teinte et saturation, mesures en lecture), volet
(position, position cible, ouvrir / fermer), chauffage (mesure, consigne chaud, consigne froid, mode, marche/arrêt),
prise (état, marche/arrêt, puissance, énergie, tension, courant) et ventilation (marche/arrêt, vitesse, sens).
