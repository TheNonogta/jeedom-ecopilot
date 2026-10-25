# Éco Pilot — plugin Jeedom

**Version 0.1.8** · Jeedom 4.4 ou supérieur · PHP 7.4 ou supérieur · Licence AGPL-3.0

Plugin de pilotage économe de l'habitat. Ce n'est **pas** un plugin matériel : c'est une **couche d'abstraction**.
Il ne parle à aucun équipement — vous reliez vous-même, fonction par fonction, les commandes d'information et d'action
de vos plugins existants (MQTT, Z-Wave, Zigbee, Shelly, IPX800…), par le sélecteur de commandes natif de Jeedom.

Le format, l'unité et les valeurs de mode sont toujours **repris du module source** : rien n'est codé en dur.

## État d'avancement

| Brique | État |
|---|---|
| **Entrées** — sondes, pièces, valeurs calculées de la maison | livrée en 0.1.0 |
| **Sorties** — éclairage, volet, chauffage, prise, ventilation | en préparation (0.2.0) |
| **Règles éco** — comparer entrées et sorties pour économiser | à l'étude |

## Ce que fait la version 0.1.0

- **Sondes** : une sonde = un équipement Jeedom, portant autant de grandeurs que voulu (température, hygrométrie,
  luminosité, CO₂…). Catalogue de 63 grandeurs en 7 familles, plus une grandeur personnalisée par sonde.
- **Reliage par le sélecteur natif** : une grandeur peut avoir plusieurs sources (moyenne, minimum, maximum, dernière ;
  ou / et pour un oui-non). L'unité est lue sur la commande source.
- **Fraîcheur par grandeur** : états « à relier » et « source muette », avec seuil réglable.
- **Pièces** : de vraies pièces créées dans le plugin (intérieure / extérieure, compter dans la maison), rattachement des
  sondes, moyenne par pièce et par grandeur.
- **Valeurs calculées** : température et hygrométrie de la maison, écart intérieur / extérieur, pièce la plus froide.
- **Deux vues** : galerie de cartes et tableau.
- **Suivi en direct** par abonnement aux commandes source (aucune scrutation), plus une relecture de secours par tâche
  cron ; tout est historisé.
- **Simulateur** : un module factice à trois grandeurs pour éprouver la chaîne sans matériel.

## Installation

Le plugin n'est pas publié sur le Market Jeedom. Il s'installe depuis ce dépôt GitHub, sur autant de box que voulu :

1. Dans Jeedom : **Plugins → Gestion des plugins**.
2. Cliquer sur la tuile **« Plugins »** (icône **+**, en haut de la liste des dépôts).
3. Choisir le fournisseur **Github** et renseigner :
   - **Utilisateur ou organisation du dépôt** : le compte qui héberge ce dépôt,
   - **Nom du dépôt** : `jeedom-ecopilot`,
   - **Branche** : `main`,
   - **Token** : laisser vide (dépôt public).
4. Valider : le plugin apparaît dans la liste des plugins disponibles. L'installer, puis l'activer.
5. Ouvrir **Éco Pilot** dans le menu des plugins.

Mise à jour : **Plugins → Gestion des plugins → Vérifier les mises à jour**, ou relancer l'installation du dépôt.
La version installée est celle de `plugin_info/info.json`.

## Fonctionnement

`cmd::event()` du cœur n'écrit pas la colonne `value` de la table `cmd` : le plugin pose donc la valeur
(`setValue()` + `save()`) **avant** d'appeler `event()`, pour que les scénarios et les abonnés relisent une valeur
déjà en base. La fraîcheur d'une source est lue dans l'historique, la table `cmd` n'ayant aucune colonne de date.

Le plugin ne crée aucune commande sur vos équipements existants : il lit et pilote les vôtres.

## Licence

GNU Affero General Public License v3.0 — voir [LICENSE](LICENSE).

Auteur : Arnaud Pelletier.
