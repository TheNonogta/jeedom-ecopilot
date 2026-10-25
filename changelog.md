# Journal des modifications — Éco Pilot

## 0.1.6 — 2026-10-25

Correctif de fond : une commande source peut être **une commande virtuelle** dont la valeur est une référence du cœur
(`#1083#`, c'est-à-dire « la valeur de la commande 1083 »). Éco Pilot lisait ce texte tel quel — d'où des valeurs
absurdes (le numéro de la commande au lieu de la mesure) ou vides auparavant. Les références sont maintenant résolues
(profondeur 5, références imbriquées comprises, y compris au milieu d'un texte comme `#1083# °C`), et la fraîcheur est
calculée sur la **commande réellement mesurée**. Une référence impossible à résoudre donne une valeur vide plutôt
qu'un nombre inventé.

## 0.1.5 — 2026-10-25

Nouvelle colonne **Source lue** dans « Valeurs lues et état » : pour chaque grandeur elle affiche l'identifiant de la
commande reliée et la valeur brute qu'elle porte réellement (ou `vide` + la dernière valeur d'historique). Permet de
voir d'un coup d'œil si Éco Pilot lit la bonne commande et ce qu'elle contient.

## 0.1.4 — 2026-10-25

- Lecture d'une valeur qui contient son unité (« 24.5 °C », « 85 % », « 1 234,5 W ») : le nombre est extrait au lieu
  de rejeter la lecture — certains modules rangent l'unité dans la valeur et la grandeur restait vide.
- Le repli sur l'historique ignore les lignes vides (un module peut historiser une valeur vide entre deux mesures).
- Nouveau panneau **Diagnostic des sources** sur la fiche d'une sonde : pour chaque grandeur, il affiche la commande
  source (id, type, valeur brute, unité, historisée ou non, nombre de lignes d'historique, dernière valeur et sa date),
  ce que le plugin en lit et ce qu'il en calcule. Bouton « Tout sélectionner » pour copier le texte.

## 0.1.3 — 2026-10-25

Correctif : une sonde reliée à une commande dont la colonne `value` est vide affichait « — » alors que la source
remontait bien. C'est le cas de certains modules, et du cœur 4.6.1 lui-même, qui alimentent l'historique sans
remplir la valeur de la commande. La lecture reprend désormais **la dernière valeur de l'historique** quand la
valeur courante est vide (la fraîcheur reste calculée sur la même date, donc l'état « source muette » ne change pas).

Ajout d'un repère de diagnostic dans « Valeurs lues et état » : quand rien n'est calculé, la ligne affiche la valeur
brute de la commande source et celle de son historique, pour distinguer « source vide » de « source muette ».

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
