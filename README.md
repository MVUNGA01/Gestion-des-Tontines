
# Système de Gestion de Prêts (Loan Management System)

Un système complet de gestion de prêts pour les institutions de microfinance, développé en PHP avec une base de données MySQL.

## 📋 Table des matières

- [Fonctionnalités](#fonctionnalités)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Configuration](#configuration)
- [Structure du projet](#structure-du-projet)
- [Utilisation](#utilisation)
- [Rôles et permissions](#rôles-et-permissions)
- [API](#api)
- [Contribution](#contribution)
- [Licence](#licence)

## ✨ Fonctionnalités

### Gestion des membres
- Création et gestion des profils membres avec photo
- Catégorisation des membres (Standard, Premium, VIP)
- Suivi des informations personnelles et professionnelles
- Gestion des contacts d'urgence
- Upload et gestion des documents

### Gestion des prêts
- Types de prêt configurables (personnel, immobilier, automobile, études, urgence)
- Plans de prêt avec taux et conditions personnalisables
- Calcul automatique des mensualités (annuité constante, linéaire, in fine)
- Génération automatique des échéances
- Suivi des remboursements en temps réel
- Gestion des pénalités de retard

### Gestion des cotisations
- Cotisations automatiques selon la catégorie du membre
- Suivi des périodes de cotisation
- Alertes d'expiration
- Historique complet des paiements

### Gestion des paiements
- Enregistrement des paiements (espèce, mobile money, virement, chèque)
- Paiement par échéance ou paiement libre
- Génération automatique des références
- Répartition automatique capital/intérêt

### Gestion des emprunteurs
- Score de crédit automatique
- Évaluation de l'éligibilité
- Suivi de la dette actuelle
- Historique des modifications de score

### Administration
- Gestion des utilisateurs avec rôles
- Journal d'audit complet
- Rapports et statistiques
- Paramètres système configurables

## 🔧 Prérequis

- PHP 7.4 ou supérieur
- MySQL 5.7 ou supérieur / MariaDB 10.3+
- Apache ou Nginx
- Extension PHP PDO MySQL

## 📦 Installation

### 1. Cloner le projet

```bash
git clone https://github.com/votre-username/loan-management-system.git
cd loan-management-system
2. Configuration de la base de données
1.	Créez une base de données MySQL :
sql
CREATE DATABASE loan_management_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
2.	Importez le schéma de la base de données :
bash
mysql -u root -p loan_management_system < loan_management_system.sql
3. Configuration de l'application
Modifiez le fichier config_database.php :
php
// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'loan_management_system');
define('DB_USER', 'votre_utilisateur');
define('DB_PASS', 'votre_mot_de_passe');
define('DB_CHARSET', 'utf8mb4');

// Configuration de l'application
define('APP_NAME', 'Système de Gestion de Prêts');
define('BASE_URL', 'http://localhost/loan_management_system');
4. Créer les dossiers nécessaires
bash
mkdir -p uploads/profils documents/membres documents/garanties
chmod -R 777 uploads documents
5. Configuration du serveur web
Apache (.htaccess)
apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /loan_management_system/
    
    # Rediriger vers index.php si le fichier n'existe pas
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>

# Sécurité
<FilesMatch "\.(sql|log|md)$">
    Order allow,deny
    Deny from all
</FilesMatch>
6. Accéder à l'application
Ouvrez votre navigateur et accédez à :
text
http://localhost/loan_management_system/
Identifiants par défaut :
•	Nom d'utilisateur : admin
•	Mot de passe : password
⚠️ Important : Changez immédiatement le mot de passe après la première connexion !
📁 Structure du projet
text
loan_management_system/
├── config_database.php          # Configuration et fonctions utilitaires
├── index.php                    # Point d'entrée principal
├── login.php                    # Page de connexion
├── logout.php                   # Déconnexion
├── dashboard.php                # Tableau de bord
│
├── MEMBRES
│   ├── membres.php              # Liste des membres
│   ├── nouveau_membre.php       # Création de membre
│   ├── modifier_membre.php      # Modification de membre
│   ├── profil_membre.php        # Profil détaillé
│   └── detail_membre.php        # Détails du membre
│
├── EMPRUNTEURS
│   ├── emprunteurs.php          # Liste des emprunteurs
│   └── qualifier_emprunteur.php # Qualification d'emprunteur
│
├── PRÊTS
│   ├── prets.php                # Liste des prêts
│   ├── nouveau_pret.php         # Création de prêt
│   ├── modifier_pret.php        # Modification de prêt
│   ├── details_pret.php         # Détails du prêt
│   ├── detail_pret.php          # Détails du prêt (alt)
│   └── traiter_pret.php         # Traitement/approbation
│
├── PAIEMENTS
│   ├── paiements.php            # Liste des paiements
│   └── details_paiement.php     # Détails du paiement
│
├── COTISATIONS
│   └── cotisations.php          # Gestion des cotisations
│
├── CONFIGURATION
│   ├── categories_membres.php   # Catégories de membres
│   ├── types_pret.php           # Types de prêt
│   ├── plans_pret.php           # Plans de prêt
│   └── parametres.php           # Paramètres système
│
├── ADMINISTRATION
│   ├── utilisateurs.php         # Gestion des utilisateurs
│   ├── audit_log.php            # Journal d'audit
│   ├── historique_scores.php    # Historique des scores
│   ├── garanties.php            # Gestion des garanties
│   └── documents.php            # Gestion des documents
│
├── RAPPORTS
│   ├── rapports.php             # Rapports généraux
│   ├── rapports_audit.php       # Rapports d'audit
│   └── export_audit.php         # Export des audits
│
├── ALERTES
│   └── alertes.php              # Gestion des alertes
│
├── API
│   ├── api_echeances.php        # API échéances
│   ├── api_get_echeances.php    # API récupération échéances
│   ├── api_get_membre_info.php  # API info membre
│   ├── verifier_membre.php      # Vérification membre
│   ├── verifier_cotisations_membre.php
│   └── verifier_synchronisation.php
│
├── UTILITAIRES
│   ├── ajax_charger_details_emprunteur.php
│   ├── corriger_dettes.php      # Correction des dettes
│   └── nettoyer_audit.php       # Nettoyage des logs
│
├── uploads/                     # Fichiers uploadés
│   └── profils/                 # Photos de profil
├── documents/                   # Documents
│   ├── membres/
│   └── garanties/
│
└── loan_management_system.sql   # Schéma de la base de données
👥 Rôles et permissions
Administrateur (admin)
•	Accès complet à toutes les fonctionnalités
•	Gestion des utilisateurs
•	Configuration système
•	Journal d'audit
•	Rapports complets
Gestionnaire (gestionnaire)
•	Gestion des membres et emprunteurs
•	Création et gestion des prêts
•	Approbation des prêts
•	Gestion des cotisations
•	Accès aux rapports de base
Caissier (caissier)
•	Enregistrement des paiements
•	Consultation des prêts
•	Gestion des cotisations
•	Accès limité aux rapports
Analyste (analyste)
•	Consultation des rapports
•	Accès en lecture seule aux données
•	Export des données
🔌 API
API Échéances
text
GET /api_echeances.php?pret_id={id}
Retourne les échéances d'un prêt au format JSON.
API Membre
text
GET /api_get_membre_info.php?code_membre={code}
Retourne les informations d'un membre.
Vérification Membre
text
GET /verifier_membre.php?code={code}
Vérifie l'existence et le statut d'un membre.
🔒 Sécurité
•	Mots de passe hashés avec password_hash() (bcrypt)
•	Protection contre les injections SQL avec PDO et requêtes préparées
•	Échappement des sorties avec htmlspecialchars()
•	Validation des sessions avec timeout
•	Journal d'audit pour toutes les modifications
•	Contrôle d'accès basé sur les rôles
🛠️ Maintenance
Corriger les dettes incohérentes
bash
php corriger_dettes.php
Vérifier la synchronisation
bash
php verifier_synchronisation.php
Nettoyer les vieux logs d'audit
Accédez à nettoyer_audit.php en tant qu'administrateur.
📊 Base de données
Tables principales
Table	Description
utilisateurs	Comptes utilisateurs du système
categories_membres	Catégories de membres
membres	Membres de l'institution
emprunteurs	Informations emprunteurs
types_pret	Types de prêt
plans_pret	Plans de prêt avec conditions
prets	Prêts accordés
echeances_pret	Échéances des prêts
paiements	Paiements effectués
cotisations	Cotisations des membres
garanties	Garanties des prêts
documents_membres	Documents des membres
alertes	Système d'alertes
audit_log	Journal d'audit
historique_scores	Historique des scores de crédit
parametres_systeme	Paramètres de configuration
🤝 Contribution
1.	Fork le projet
2.	Créez une branche pour votre fonctionnalité (git checkout -b feature/nouvelle-fonctionnalite)
3.	Committez vos changements (git commit -am 'Ajout d'une nouvelle fonctionnalité')
4.	Push vers la branche (git push origin feature/nouvelle-fonctionnalite)
5.	Ouvrez une Pull Request
📝 Licence
Ce projet est sous licence MIT. Voir le fichier LICENSE pour plus de détails.
📞 Support
Pour toute question ou problème :
•	Ouvrez une issue sur GitHub
•	Contactez l'équipe de développement
________________________________________
Développé avec ❤️ pour les institutions de microfinance
text

Voici un fichier README.md complet pour votre projet. Il inclut :

1. **Description du projet** : Présentation générale du système
2. **Fonctionnalités** : Liste détaillée de toutes les fonctionnalités par module
3. **Prérequis** : Versions nécessaires de PHP, MySQL, etc.
4. **Installation** : Guide étape par étape pour l'installation
5. **Structure du projet** : Arborescence complète des fichiers
6. **Rôles et permissions** : Description des différents rôles utilisateurs
7. **API** : Documentation des endpoints disponibles
8. **Sécurité** : Mesures de sécurité implémentées
9. **Maintenance** : Scripts utilitaires disponibles
10. **Base de données** : Description des tables principales

