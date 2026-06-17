# 🔮 GRIOT DE CRISTAL — Portail Panafricaniste IA (v2.0)
> **Projet conçu, développé et configuré exclusivement pour Jérémie / Jo Dalton & l'UCAA (Unité Culturelle Africaine & Afro-descendants)**

[![Stack](https://img.shields.io/badge/Stack-PHP%208.3%20%7C%20SQLite%203%20%7C%20Mistral%20API-gold?style=for-the-badge)](https://ucaa.org)
[![Design](https://img.shields.io/badge/Design-2Advanced%20%7C%20Blanc%20Futuriste-white?style=for-the-badge)]()
[![License](https://img.shields.io/badge/License-Proprietary%20%2F%20UCAA-black?style=for-the-badge)]()

---

## 🦅 1. Vision du Projet & Dédicace

Le **Griot de Cristal** n'est pas un simple outil technique, c'est une architecture de combat culturel, politique et technologique. Conçu sous l'impulsion et pour **Jérémie / Jo Dalton**, ce portail incarne l'alliance ultime entre la **Conscience Panafricaine** et l'**Intelligence Artificielle de pointe**. 

L'application agit comme un bouclier et un amplificateur pour l'**UCAA**, ancré dans les trois piliers fondamentaux du mouvement :
* ☯️ **PEACE (和) — La Paix Combattante :** Maîtrise absolue du narratif, de la rhétorique et de la communication stratégique face aux conflits systémiques.
* ❤️ **LOVE (愛) — L'Amour Stratégique :** L'amour inconditionnel du peuple, de la culture et de la diaspora comme moteur d'élévation indestructible.
* ✊ **UNITY (統) — L'Unité Absolue :** Fédérer les 54 nations africaines et l'ensemble des afro-descendants autour d'un écosystème numérique indépendant et autonome.

---

## 💎 2. Avantages Stratégiques du Projet

Contrairement aux applications web modernes lourdes et dépendantes d'infrastructures tierces (SaaS), le Griot de Cristal offre des avantages uniques :

* 🌿 **Zéro Framework, Zéro Dépendance :** Écrit en PHP pur (Vanillla PHP) et Vanilla JavaScript. Aucun risque de panne liée à une mise à jour de framework (Laravel, Symfony, React, Vue) ou à l'effondrement d'un package Node.js (NPM).
* 💸 **Coût d'Infrastructure Égal à Zéro :** Conçu spécifiquement pour tourner sur un hébergement mutualisé standard et très économique (ex: **Hostinger Mutualisé**). Il ne nécessite pas de serveur VPS ou Cloud coûteux.
* 🔋 **Haute Disponibilité par Rotation de Clés :** Intègre un algorithme de distribution dynamique basé sur le temps micro-seconde. Si une clé API atteint sa limite de requêtes, le système bascule instantanément sur les autres clés sans aucune interruption pour l'utilisateur.
* 🥋 **Souveraineté Intégrale & Confidentialité :** Aucune donnée ne transite par des scripts publicitaires, trackers ou cookies tiers (Google, Meta, etc.). Les données restent confinées dans votre base SQLite privée.
* 🕊️ **Éligibilité au Free Tier (Gratuité de l'IA) :** Le code est nativement compatible avec le plan d'accès gratuit (Free Tier) de Mistral AI, permettant d'exploiter la puissance de l'IA sans débourser un centime.

---

## 🛠️ 3. Architecture Technique & Base de Données

Le backend utilise **PDO SQLite 3** pour une persistance locale ultra-rapide. À l'initialisation du fichier `index - Copie.php`, les tables suivantes sont automatiquement créées et isolées :

### Structure du Schéma SQLite (`griot_data.sqlite`)
* **`conversations`** : Stocke les sessions asynchrones, le rôle (`user`/`assistant`) et les historiques par module pour alimenter la mémoire contextuelle de l'IA.
* **`vigilance_reports`** : Archive les signalements du module juridique (catégorie, description, niveau de gravité calculé par l'IA et statut d'analyse).
* **`members`** : Gère l'annuaire de matching de l'UCAA, suit la consommation de tokens et segmente par archétypes professionnels.

---

## 🔮 4. Les 5 Modules IA Détaillés

Le portail orchestre plusieurs agents spécialisés utilisant le modèle phare `mistral-large-latest` (ou les versions optimisées en fonction de votre allocation) :

1. **Le Griot Chat (`griot`)** : Gardien numérique de la mémoire et de la sagesse africaine. Il valorise l'histoire, les inventions et l'élévation des civilisations africaines avec une posture noble et digne.
2. **Les Archives Vivantes (`archives`)** : Interface d'invocation mémorielle permettant de dialoguer directement, à la première personne, avec les grandes figures de la pensée panafricaine (*Thomas Sankara, Cheikh Anta Diop, Patrice Lumumba, Toussaint Louverture, Marcus Garvey...*).
3. **Vigilance Citoyenne (`vigilance`)** : Module juridique de combat. L'utilisateur saisit une injustice ou discrimination ; l'IA l'analyse instantanément, évalue sa gravité (échelle de 1 à 5), détermine la typologie de l'abus, propose des actions immédiates et cite les textes de lois applicables en France et en Europe.
4. **Labo Hip-Hop Politique (`hiphop`)** : Parolier algorithmique de rap conscient et engagé. Refuse les clichés et la dégradation pour produire des textes structurés (Intro, Couplets, Refrains, Outro) axés sur l'émancipation, la souveraineté et le respect de la mémoire.
5. **Traduction & Matching Diaspora (`translate`)** : Outil double combinant la traduction fine vers les langues de la diaspora (ex: *Wolof*) avec des explications contextuelles de sens, et un algorithme textuel de matching professionnel pour connecter les talents au sein de l'UCAA.

---

## 🔑 5. Guide : Obtenir ses Clés d'API Mistral AI (Free Tier)

Pour faire fonctionner le projet gratuitement en utilisant l'offre *Free Tier* de Mistral AI, suivez rigoureusement ces étapes :

1. Rendez-vous sur la plateforme officielle des développeurs Mistral AI : [https://console.mistral.ai/](https://console.mistral.ai/)
2. Créez un compte ou connectez-vous (via votre adresse email ou votre compte GitHub/Google).
3. Une fois dans le tableau de bord de la Console, allez dans l'onglet **"API Keys"** (Clés API) situé dans le menu latéral gauche.
4. Cliquez sur le bouton **"Create New Key"** (Créer une nouvelle clé).
5. Donnez-lui un nom explicite (ex: `Griot_UCAA_1`). Copiez immédiatement la clé générée (elle commence généralement par `ey...` ou possède un format spécifique) et conservez-la en lieu sûr.
6. **Astuce Multi-Clés (Free Tier Bypass)** : Le forfait gratuit de Mistral applique une limite de requêtes par minute (Rate Limit). Pour contourner cette contrainte et garantir une fluidité totale à vos utilisateurs sous l'impulsion de Jo Dalton, créez **3 clés différentes** depuis votre console (ou via des comptes distincts de l'association) et insérez-les toutes les trois dans le tableau de configuration PHP du projet.

---

## 🚀 6. Mode d'Emploi Complet & Déploiement

### Prérequis Serveur
* Un hébergement web (ex: **Hostinger**, formule de base *Single* ou *Premium*).
* **PHP 8.3** ou version ultérieure sélectionnée dans votre panneau Hostinger (hPanel).
* Les extensions PHP standard `php_pdo_sqlite` et `php_curl` activées (elles le sont par défaut sur 99% des hébergements mutualisés).

### Étape 1 : Préparation du Fichier
Ouvrez votre fichier de code source principal et localisez les premières lignes correspondantes à la définition des clés API :

```php
define('MISTRAL_API_KEYS', [
    'COLLEZ_ICI_VOTRE_PREMIERE_CLE_MISTRAL',
    'COLLEZ_ICI_VOTRE_DEUXIEME_CLE_MISTRAL',
    'COLLEZ_ICI_VOTRE_TROISIEME_CLE_MISTRAL'
]);

Remplacez les valeurs fictives par les véritables clés obtenues à l'étape précédente.

Étape 2 : Téléversement (Upload)
Connectez-vous à votre panneau de contrôle Hostinger et ouvrez le Gestionnaire de fichiers (File Manager).

Naviguez vers le dossier racine de votre site, généralement appelé public_html.

Importez votre fichier. Pour qu'il s'exécute comme page d'accueil de votre outil, renommez-le précisément en : index.php

Étape 3 : Premier Lancement et Droits d'Accès
Ouvrez votre navigateur internet et accédez à l'adresse de votre site (ex: https://votre-site-ucaa.com/).

Lors de ce premier affichage, le script va exécuter automatiquement sa routine initDB().

Il crée un fichier nommé griot_data.sqlite à la racine de votre dossier.

Important (Sécurité des fichiers sur Hostinger) : Vérifiez dans votre gestionnaire de fichiers que le dossier public_html et le fichier griot_data.sqlite possèdent les permissions de lecture/écriture correctes (droits 0644 pour le fichier sqlite, et 0755 pour les dossiers). Si l'application affiche une erreur d'écriture, appliquez ces permissions en un clic depuis l'interface Hostinger.

Étape 4 : Utilisation au Quotidien
Navigation Fluide : Utilisez le menu supérieur asymétrique futuriste pour basculer d'un module à un autre sans rechargement de page.

Soumission AJAX : Lorsque vous validez un formulaire (comme un signalement de discrimination ou la génération d'un texte Hip-Hop), une animation de chargement en surbrillance s'active. La réponse de l'IA s'injecte de manière asynchrone dès sa réception.

Consultation des Rapports : Le module de vigilance permet de lister l'historique des analyses stockées localement pour un suivi rigoureux des dossiers de l'association.

🔒 7. Sécurité & Entretien
Sauvegarde de la Base de Données : Pensez à télécharger régulièrement le fichier griot_data.sqlite depuis votre gestionnaire de fichiers Hostinger. Ce fichier contient l'intégralité de l'historique des requêtes et des signalements de l'UCAA.

Journal des Erreurs : En cas de comportement inattendu ou de refus de l'API Mistral (ex: clé expirée ou quota dépassé), consultez le fichier griot_error.log automatiquement généré à la racine pour connaître le code de retour exact de la requête cURL.

👑 8. Crédits & Propriété
Commanditaire & Inspirateur : Jérémie / Jo Dalton — Mouvement National & International UCAA.

Direction Artistique & Développement Web : Inspiré du design géométrique épuré de 2Advanced Studios.

Moteur d'Inférence : Écosystème de modèles ouverts et souverains de Mistral AI Europe.

Peace. Love. Unity. L'histoire est en marche, et elle s'écrit maintenant à travers le Griot de Cristal.
