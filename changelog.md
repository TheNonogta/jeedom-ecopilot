# Journal des modifications — Éco Pilot

## 0.1.2 — 2026-10-24

Icônes de pièce : catalogue de **54 icônes** couvrant les pièces d'une maison — pièces de vie (salon, séjour, salle à
manger, cuisine, bureau, bibliothèque, salle de jeux, salle de sport, salle de musique), chambres et nuit (chambre,
chambre parentale, chambre d'amis, chambre d'enfant, chambre de bébé, dressing, mezzanine, studio), eau (salle de bain,
salle d'eau, WC, buanderie, spa), service et technique (cellier, chaufferie, local technique, cave, cave à vin,
sous-sol, grenier, atelier, garage, abri de jardin, poulailler, grange, rangement), circulation (entrée, hall, couloir,
palier, dégagement, escalier), extérieur (terrasse, jardin, piscine, pool house, balcon, véranda, cour, potager,
verger) et général.

Le sélecteur devient une **grille groupée avec recherche** dans la création comme dans la modification d'une pièce ;
la valeur enregistrée reste la classe Font Awesome, donc les pièces existantes conservent leur icône. Le numéro de
version affiché dans la page suit désormais celui de `info.json`.

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
