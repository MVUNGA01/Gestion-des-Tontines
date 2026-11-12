-- =============================================================================
-- BASE DE DONNÉES COMPLÈTE - SYSTÈME DE GESTION DE PRÊTS AMÉLIORÉ
-- =============================================================================

-- Création de la base de données
CREATE DATABASE IF NOT EXISTS loan_management_system;
USE loan_management_system;

-- =============================================================================
-- TABLE DES UTILISATEURS SYSTÈME
-- =============================================================================
CREATE TABLE utilisateurs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nom_complet VARCHAR(200) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    photo_profil VARCHAR(500) NULL,
    role ENUM('admin', 'gestionnaire', 'caissier', 'analyste') NOT NULL DEFAULT 'gestionnaire',
    permissions JSON,
    statut ENUM('actif', 'inactif', 'suspendu') DEFAULT 'actif',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME NULL,
    date_modification DATETIME NULL,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_statut_utilisateur (statut)
) COMMENT = 'Utilisateurs du système avec rôles et permissions';

-- =============================================================================
-- TABLE DES CATÉGORIES DE MEMBRES  
-- =============================================================================
CREATE TABLE categories_membres (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code_categorie VARCHAR(20) UNIQUE NOT NULL,
    nom_categorie VARCHAR(50) NOT NULL,
    description TEXT,
    avantages TEXT,
    
    -- Paramètres financiers de base
    cotisation_mensuelle DECIMAL(12, 0) NOT NULL COMMENT 'Montant en FBU',
    
    -- Paramètres de prêt
    duree_min_pret_mois INT DEFAULT 6 COMMENT 'Durée minimale de prêt pour cette catégorie',
    duree_max_pret_mois INT DEFAULT 60 COMMENT 'Durée maximale de prêt pour cette catégorie',
    taux_interet_categorie DECIMAL(5, 2) DEFAULT 12.5 COMMENT 'Taux d''intérêt de référence pour la catégorie',
    
    -- Conditions d'éligibilité
    duree_min_cotisation_mois INT DEFAULT 3 COMMENT 'Durée minimale de cotisation requise pour emprunter',
    
    -- Métadonnées
    couleur_theme VARCHAR(20) DEFAULT '#3498db',
    icone VARCHAR(100) DEFAULT 'fas fa-user',
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code_categorie (code_categorie)
) COMMENT = 'Catégories de membres';

-- =============================================================================
-- TABLE DES TYPES DE PRÊTS
-- =============================================================================
CREATE TABLE types_pret (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom_type VARCHAR(100) NOT NULL,
    description TEXT,
    icone VARCHAR(100) DEFAULT 'fas fa-hand-holding-usd',
    couleur VARCHAR(20) DEFAULT '#27ae60',
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
) COMMENT = 'Types de prêts disponibles dans le système';

-- =============================================================================
-- TABLE DES PLANS DE PRÊTS AVEC RÈGLES DE COTISATION (SANS RESTRICTION PLAFOND)
-- =============================================================================
CREATE TABLE plans_pret (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type_pret_id INT NOT NULL,
    nom_pret VARCHAR(100) NOT NULL,
    description TEXT,
    code_plan VARCHAR(20) UNIQUE NOT NULL,
    
    -- Paramètres financiers
    taux_interet_annuel DECIMAL(5, 2) NOT NULL,
    methode_calcul ENUM('annuite_constante', 'lineaire', 'in fine') DEFAULT 'annuite_constante',
    
    -- Durée et montants en FBU
    duree_min_mois INT NOT NULL,
    duree_max_mois INT NOT NULL,
    montant_min DECIMAL(15, 0) NOT NULL COMMENT 'Montant minimum en FBU',
    montant_max DECIMAL(15, 0) NOT NULL COMMENT 'Montant maximum en FBU',
    
    -- Frais et pénalités
    penalite_retard DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
    frais_dossier DECIMAL(12, 0) DEFAULT 0 COMMENT 'Frais en FBU',
    assurance DECIMAL(5, 2) DEFAULT 0.00,
    frais_dossier_type ENUM('fixe', 'pourcentage') DEFAULT 'fixe',
    
    -- Conditions d'éligibilité
    score_credit_min INT DEFAULT 300,
    anciennete_min_mois INT DEFAULT 3,
    cotisation_requise BOOLEAN DEFAULT TRUE,
    duree_min_cotisation_mois INT DEFAULT 3 COMMENT 'Durée minimale de cotisation continue requise',
    montant_min_cotisation_total DECIMAL(15, 0) DEFAULT 0 COMMENT 'Montant total minimum de cotisations payées en FBU',
    autoriser_multiple_prets BOOLEAN DEFAULT FALSE COMMENT 'Autoriser plusieurs prêts simultanés',
    
    -- Restrictions catégorielles
    categories_membres_json JSON,
    
    -- Métadonnées
    statut ENUM('actif', 'inactif', 'brouillon') DEFAULT 'brouillon',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_activation DATETIME NULL,
    cree_par INT NOT NULL,
    
    FOREIGN KEY (type_pret_id) REFERENCES types_pret(id),
    FOREIGN KEY (cree_par) REFERENCES utilisateurs(id),
    
    INDEX idx_statut_plan (statut),
    INDEX idx_type_pret (type_pret_id),
    INDEX idx_code_plan (code_plan)
) COMMENT = 'Plans de prêt avec règles d''éligibilité et conditions de cotisation';

-- =============================================================================
-- TABLE DES MEMBRES AVEC GÉNÉRATION AUTOMATIQUE DU CODE
-- =============================================================================
CREATE TABLE membres (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code_membre VARCHAR(20) UNIQUE NOT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    telephone VARCHAR(20),
    adresse TEXT,
    photo_profil VARCHAR(500) NULL,
    categorie_id INT NOT NULL,
    date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('actif', 'inactif', 'suspendu') DEFAULT 'actif',
    date_naissance DATE NULL,
    lieu_naissance VARCHAR(100) NULL,
    profession VARCHAR(100) NULL,
    employeur VARCHAR(150) NULL,
    salaire_mensuel DECIMAL(12, 0) NULL COMMENT 'Salaire en FBU',
    nom_contact_urgence VARCHAR(100) NULL,
    tel_contact_urgence VARCHAR(20) NULL,
    relation_contact_urgence VARCHAR(50) NULL,
    FOREIGN KEY (categorie_id) REFERENCES categories_membres(id),
    INDEX idx_code_membre (code_membre),
    INDEX idx_categorie (categorie_id),
    INDEX idx_statut (statut),
    INDEX idx_date_inscription (date_inscription)
) COMMENT = 'Membres du système avec informations personnelles';

-- =============================================================================
-- TABLE DES COTISATIONS AVEC MONTANT AUTOMATIQUE SELON CATÉGORIE
-- =============================================================================
CREATE TABLE cotisations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code_membre VARCHAR(20) NOT NULL,
    categorie_id INT NOT NULL,
    montant DECIMAL(12, 0) NOT NULL COMMENT 'Montant payé en FBU',
    montant_categorie DECIMAL(12, 0) NOT NULL COMMENT 'Montant théorique selon la catégorie au moment du paiement en FBU',
    date_paiement DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    periode_mois INT NOT NULL DEFAULT 1 COMMENT 'Nombre de mois couverts par cette cotisation',
    statut ENUM('valide', 'expire', 'en_attente', 'annule') DEFAULT 'valide',
    methode_paiement ENUM('espece', 'mobile_money', 'virement', 'carte', 'cheque'),
    reference_paiement VARCHAR(100),
    notes TEXT,
    FOREIGN KEY (code_membre) REFERENCES membres(code_membre) ON DELETE CASCADE,
    FOREIGN KEY (categorie_id) REFERENCES categories_membres(id),
    INDEX idx_code_membre_cotisation (code_membre),
    INDEX idx_date_fin (date_fin),
    INDEX idx_date_debut (date_debut),
    INDEX idx_statut_cotisation (statut)
) COMMENT = 'Cotisations des membres avec suivi des périodes';

-- =============================================================================
-- TABLE DES EMPRUNTEURS AVEC SUIVI DES COTISATIONS ET DETTES (SANS PLAFONDS)
-- =============================================================================
CREATE TABLE emprunteurs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code_membre VARCHAR(20) UNIQUE NOT NULL,
    date_qualification DATETIME DEFAULT CURRENT_TIMESTAMP,
    score_credit INT DEFAULT 500,
    statut ENUM('eligible', 'suspendu', 'en_attente', 'blackliste') DEFAULT 'en_attente',
    
    -- Statistiques financières en FBU
    total_emprunte DECIMAL(15, 0) DEFAULT 0 COMMENT 'Total emprunté en FBU',
    total_rembourse DECIMAL(15, 0) DEFAULT 0 COMMENT 'Total remboursé en FBU',
    dette_actuelle DECIMAL(15, 0) DEFAULT 0 COMMENT 'Dette totale actuelle en FBU',
    nombre_prets_actifs INT DEFAULT 0 COMMENT 'Nombre de prêts actifs',
    nombre_prets_total INT DEFAULT 0,
    
    -- Suivi des cotisations
    date_premiere_cotisation DATE NULL,
    date_derniere_cotisation DATE NULL,
    mois_cotises_consecutifs INT DEFAULT 0,
    mois_cotises_total INT DEFAULT 0,
    total_cotisations_payees DECIMAL(15, 0) DEFAULT 0 COMMENT 'Total des cotisations payées en FBU',
    cotisation_a_jour BOOLEAN DEFAULT FALSE,
    
    derniere_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (code_membre) REFERENCES membres(code_membre) ON DELETE CASCADE,
    INDEX idx_statut_emprunteur (statut),
    INDEX idx_score_credit (score_credit),
    INDEX idx_cotisation_a_jour (cotisation_a_jour),
    INDEX idx_dette_actuelle (dette_actuelle)
) COMMENT = 'Statistiques et éligibilité des emprunteurs avec suivi des cotisations';

-- =============================================================================
-- TABLE DES PRÊTS AVEC VÉRIFICATION DES CONDITIONS (SANS VÉRIFICATION PLAFOND)
-- =============================================================================
CREATE TABLE prets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code_pret VARCHAR(20) UNIQUE NOT NULL,
    code_membre VARCHAR(20) NOT NULL,
    plan_pret_id INT NOT NULL,
    
    -- Détails du prêt en FBU
    montant DECIMAL(15, 0) NOT NULL COMMENT 'Montant du prêt en FBU',
    montant_restant DECIMAL(15, 0) NOT NULL COMMENT 'Montant restant à rembourser en FBU',
    taux_interet DECIMAL(5, 2) NOT NULL,
    duree_mois INT NOT NULL,
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    mensualite DECIMAL(15, 0) NOT NULL COMMENT 'Mensualité en FBU',
    
    -- Vérification des conditions
    conditions_remplies BOOLEAN DEFAULT FALSE,
    motif_refus TEXT NULL,
    verification_cotisations BOOLEAN DEFAULT FALSE,
    verification_dette BOOLEAN DEFAULT FALSE,
    
    -- Statut et suivi
    statut ENUM('brouillon', 'en_attente', 'approuve', 'actif', 'complet', 'en_retard', 'default', 'annule') DEFAULT 'brouillon',
    penalite_retard DECIMAL(5, 2) NOT NULL,
    frais_dossier DECIMAL(12, 0) DEFAULT 0 COMMENT 'Frais de dossier en FBU',
    assurance DECIMAL(12, 0) DEFAULT 0 COMMENT 'Assurance en FBU',
    
    -- Informations supplémentaires
    motif_pret TEXT NULL,
    garanties_provisees TEXT NULL,
    approbation_par INT NULL,
    date_approbation DATETIME NULL,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_activation DATETIME NULL,
    
    FOREIGN KEY (code_membre) REFERENCES membres(code_membre) ON DELETE CASCADE,
    FOREIGN KEY (plan_pret_id) REFERENCES plans_pret(id),
    FOREIGN KEY (approbation_par) REFERENCES utilisateurs(id),
    
    INDEX idx_code_membre_pret (code_membre),
    INDEX idx_statut_pret (statut),
    INDEX idx_date_fin_pret (date_fin),
    INDEX idx_date_creation_pret (date_creation),
    INDEX idx_conditions_remplies (conditions_remplies)
) COMMENT = 'Prêts avec vérification des conditions d''éligibilité';

-- =============================================================================
-- TABLE DES ÉCHÉANCES DE PRÊT
-- =============================================================================
CREATE TABLE echeances_pret (
    id INT PRIMARY KEY AUTO_INCREMENT,
    pret_id INT NOT NULL,
    numero_echeance INT NOT NULL,
    date_echeance DATE NOT NULL,
    montant_echeance DECIMAL(15, 0) NOT NULL COMMENT 'Montant de l échéance en FBU',
    capital DECIMAL(15, 0) NOT NULL COMMENT 'Part capital en FBU',
    interet DECIMAL(15, 0) NOT NULL COMMENT 'Part intérêt en FBU',
    assurance DECIMAL(15, 0) DEFAULT 0 COMMENT 'Assurance en FBU',
    capital_restant DECIMAL(15, 0) NOT NULL COMMENT 'Capital restant en FBU',
    
    statut ENUM('a_venir', 'due', 'payee', 'en_retard', 'annule') DEFAULT 'a_venir',
    date_paiement DATETIME NULL,
    penalite_retard DECIMAL(15, 0) DEFAULT 0 COMMENT 'Pénalité de retard en FBU',
    montant_paye DECIMAL(15, 0) DEFAULT 0 COMMENT 'Montant payé en FBU',
    
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (pret_id) REFERENCES prets(id) ON DELETE CASCADE,
    
    UNIQUE KEY uk_pret_echeance (pret_id, numero_echeance),
    INDEX idx_date_echeance (date_echeance),
    INDEX idx_statut_echeance (statut),
    INDEX idx_pret_id (pret_id)
) COMMENT = 'Échéances des prêts avec décomposition capital/intérêt';

-- =============================================================================
-- TABLE DES PAIEMENTS
-- =============================================================================
CREATE TABLE paiements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    pret_id INT NOT NULL,
    echeance_id INT NULL,
    montant_paye DECIMAL(15, 0) NOT NULL COMMENT 'Montant payé en FBU',
    date_paiement DATETIME DEFAULT CURRENT_TIMESTAMP,
    methode_paiement ENUM('espece', 'mobile_money', 'virement', 'carte', 'cheque') NOT NULL,
    reference_paiement VARCHAR(100),
    type_paiement ENUM('echeance', 'avance', 'solde', 'penalite') DEFAULT 'echeance',
    statut ENUM('complet', 'partiel', 'en_attente', 'annule') DEFAULT 'complet',
    
    montant_capital DECIMAL(15, 0) DEFAULT 0 COMMENT 'Montant capital en FBU',
    montant_interet DECIMAL(15, 0) DEFAULT 0 COMMENT 'Montant intérêt en FBU',
    montant_assurance DECIMAL(15, 0) DEFAULT 0 COMMENT 'Montant assurance en FBU',
    montant_penalite DECIMAL(15, 0) DEFAULT 0 COMMENT 'Montant pénalité en FBU',
    
    enregistre_par INT NOT NULL,
    notes TEXT,
    
    FOREIGN KEY (pret_id) REFERENCES prets(id) ON DELETE CASCADE,
    FOREIGN KEY (echeance_id) REFERENCES echeances_pret(id) ON DELETE SET NULL,
    FOREIGN KEY (enregistre_par) REFERENCES utilisateurs(id),
    
    INDEX idx_date_paiement (date_paiement),
    INDEX idx_pret_id_paiement (pret_id),
    INDEX idx_methode_paiement (methode_paiement)
) COMMENT = 'Paiements des prêts avec décomposition des montants';

-- =============================================================================
-- TABLES DE SUPPORT ET AUDIT
-- =============================================================================
CREATE TABLE audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    table_name VARCHAR(100) NOT NULL,
    action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    record_id VARCHAR(100) NOT NULL,
    old_values JSON,
    new_values JSON,
    user_id VARCHAR(100),
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    INDEX idx_audit_table (table_name),
    INDEX idx_audit_action (action),
    INDEX idx_audit_changed_at (changed_at)
) COMMENT = 'Journal d''audit des modifications';

CREATE TABLE parametres_systeme (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cle VARCHAR(100) UNIQUE NOT NULL,
    valeur TEXT NOT NULL,
    type_valeur ENUM('texte', 'nombre', 'decimal', 'booleen', 'json') DEFAULT 'texte',
    description TEXT,
    modifiable BOOLEAN DEFAULT TRUE,
    categorie VARCHAR(50) DEFAULT 'general',
    date_mise_a_jour DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cle_param (cle),
    INDEX idx_categorie_param (categorie)
) COMMENT = 'Paramètres de configuration du système';

CREATE TABLE historique_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code_membre VARCHAR(20) NOT NULL,
    ancien_score INT NOT NULL,
    nouveau_score INT NOT NULL,
    type_modification ENUM('automatique', 'manuel', 'correction') DEFAULT 'automatique',
    raison TEXT,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP,
    modifie_par VARCHAR(100),
    INDEX idx_code_membre_score (code_membre),
    INDEX idx_date_modification (date_modification),
    INDEX idx_type_modification (type_modification)
) COMMENT = 'Historique des scores de crédit';

CREATE TABLE garanties (
    id INT PRIMARY KEY AUTO_INCREMENT,
    pret_id INT NOT NULL,
    type_garantie ENUM('immobiliere', 'mobiliere', 'personnelle', 'autre') NOT NULL,
    description TEXT NOT NULL,
    valeur_estimee DECIMAL(15, 0) NOT NULL COMMENT 'Valeur estimée en FBU',
    date_evaluation DATE NOT NULL,
    statut ENUM('valide', 'invalide', 'a_verifier', 'expiree') DEFAULT 'a_verifier',
    documents_path TEXT,
    evaluateur INT NULL,
    notes TEXT,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME NULL,
    FOREIGN KEY (pret_id) REFERENCES prets(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluateur) REFERENCES utilisateurs(id),
    INDEX idx_pret_garantie (pret_id),
    INDEX idx_type_garantie (type_garantie),
    INDEX idx_statut_garantie (statut)
) COMMENT = 'Garanties fournies pour les prêts';

CREATE TABLE alertes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type_alerte ENUM('retard_paiement', 'cotisation_expire', 'pret_approche_echeance', 'systeme', 'securite') NOT NULL,
    severite ENUM('faible', 'moyenne', 'elevee', 'critique') DEFAULT 'moyenne',
    destinataire_type ENUM('membre', 'employe', 'systeme') NOT NULL,
    destinataire_id VARCHAR(100) NOT NULL,
    titre VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    lien_action VARCHAR(255),
    lue BOOLEAN DEFAULT FALSE,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_lue DATETIME NULL,
    date_expiration DATETIME NULL,
    INDEX idx_type_alerte (type_alerte),
    INDEX idx_destinataire (destinataire_type, destinataire_id),
    INDEX idx_lue (lue),
    INDEX idx_severite (severite)
) COMMENT = 'Système d''alertes et notifications';

CREATE TABLE documents_membres (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code_membre VARCHAR(20) NOT NULL,
    type_document ENUM('identite', 'justificatif_domicile', 'photo', 'contrat', 'bulletins_salaire', 'autre') NOT NULL,
    nom_fichier VARCHAR(255) NOT NULL,
    chemin_fichier VARCHAR(500) NOT NULL,
    taille_fichier INT,
    date_upload DATETIME DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('en_attente', 'approuve', 'rejete') DEFAULT 'en_attente',
    approuve_par INT NULL,
    date_approbation DATETIME NULL,
    notes TEXT,
    FOREIGN KEY (code_membre) REFERENCES membres(code_membre) ON DELETE CASCADE,
    FOREIGN KEY (approuve_par) REFERENCES utilisateurs(id),
    INDEX idx_code_membre_doc (code_membre),
    INDEX idx_type_document (type_document),
    INDEX idx_statut_document (statut)
) COMMENT = 'Documents des membres avec gestion des statuts';

-- =============================================================================
-- DONNÉES PAR DÉFAUT (SANS PLAFONDS)
-- =============================================================================

-- Insertion des catégories de membres
INSERT INTO categories_membres (code_categorie, nom_categorie, description, cotisation_mensuelle, 
                               duree_min_pret_mois, duree_max_pret_mois, taux_interet_categorie,
                               duree_min_cotisation_mois, couleur_theme, icone) VALUES
('standard', 'Standard', 'Membre standard avec accès aux services de base', 25000, 
 6, 36, 15.0,
 3, '#3498db', 'fas fa-user'),
 
('premium', 'Premium', 'Membre premium avec avantages étendus', 60000, 
 3, 48, 12.5,
 2, '#9b59b6', 'fas fa-crown'),
 
('vip', 'VIP', 'Membre VIP avec privilèges exclusifs', 125000, 
 1, 60, 10.0,
 1, '#e74c3c', 'fas fa-gem');

-- Insertion des types de prêts
INSERT INTO types_pret (nom_type, description, icone, couleur) VALUES
('personnel', 'Prêt personnel pour besoins divers', 'fas fa-user', '#3498db'),
('immobilier', 'Prêt immobilier pour achat ou construction', 'fas fa-home', '#e67e22'),
('automobile', 'Prêt pour l''achat d''un véhicule', 'fas fa-car', '#34495e'),
('etudes', 'Prêt pour financer des études', 'fas fa-graduation-cap', '#9b59b6'),
('urgence', 'Prêt rapide pour situations urgentes', 'fas fa-first-aid', '#e74c3c');

-- Insertion des paramètres système (suppression des paramètres liés aux plafonds)
INSERT INTO parametres_systeme (cle, valeur, type_valeur, description, categorie, modifiable) VALUES
('seuil_alerte_retard', '15', 'nombre', 'Seuil en jours pour déclencher une alerte de retard', 'prets', TRUE),
('seuil_alerte_expiration_cotisation', '30', 'nombre', 'Seuil en jours pour alerter avant expiration cotisation', 'membres', TRUE),
('duree_max_cotisation', '365', 'nombre', 'Durée maximale de validité d''une cotisation (jours)', 'membres', TRUE),
('taux_interet_defaut', '12.5', 'decimal', 'Taux d''intérêt annuel par défaut', 'prets', TRUE),
('penalite_retard_defaut', '0.5', 'decimal', 'Pénalité de retard par défaut (%)', 'prets', TRUE),
('duree_min_cotisation_defaut', '3', 'nombre', 'Durée minimale de cotisation requise par défaut', 'prets', TRUE),
('score_minimal_defaut', '300', 'nombre', 'Score de crédit minimal par défaut', 'prets', TRUE),
('autoriser_multiple_prets', 'false', 'booleen', 'Autoriser les prêts multiples par défaut', 'prets', TRUE),
('format_code_membre', 'MEM{annee}{mois}{numero}', 'texte', 'Format du code membre', 'membres', TRUE),
('devise_systeme', 'FBU', 'texte', 'Devise utilisée dans le système', 'general', FALSE);

-- Insertion de l'utilisateur admin
INSERT INTO utilisateurs (username, password_hash, nom_complet, email, role, photo_profil) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrateur Système', 'admin@loanmanagement.com', 'admin', NULL);

-- =============================================================================
-- FONCTIONS ET PROCÉDURES (SANS CALCULS DE PLAFONDS)
-- =============================================================================

DELIMITER //

-- Fonction pour générer le code membre
CREATE FUNCTION generer_code_membre()
RETURNS VARCHAR(20)
DETERMINISTIC
BEGIN
    DECLARE v_annee CHAR(4);
    DECLARE v_mois CHAR(2);
    DECLARE v_numero INT;
    DECLARE v_code_membre VARCHAR(20);
    
    SET v_annee = YEAR(CURDATE());
    SET v_mois = LPAD(MONTH(CURDATE()), 2, '0');
    
    SELECT COALESCE(COUNT(*), 0) + 1 INTO v_numero 
    FROM membres 
    WHERE YEAR(date_inscription) = v_annee 
    AND MONTH(date_inscription) = MONTH(CURDATE());
    
    SET v_code_membre = CONCAT('MEM', v_annee, v_mois, LPAD(v_numero, 4, '0'));
    
    RETURN v_code_membre;
END//

-- Fonction pour formater l'affichage de la dette
CREATE FUNCTION formater_dette(
    p_montant DECIMAL(15,0)
)
RETURNS VARCHAR(50)
DETERMINISTIC
BEGIN
    IF COALESCE(p_montant, 0) = 0 THEN
        RETURN 'Aucune dette';
    ELSE
        RETURN CONCAT(FORMAT(p_montant, 0), ' FBU');
    END IF;
END//

-- Fonction pour vérifier si un membre a une dette en cours
CREATE FUNCTION avoir_dette_en_cours(
    p_code_membre VARCHAR(20)
) 
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_dette DECIMAL(15,0);
    DECLARE v_nombre_prets_actifs INT;
    
    SELECT COALESCE(dette_actuelle, 0), COALESCE(nombre_prets_actifs, 0)
    INTO v_dette, v_nombre_prets_actifs
    FROM emprunteurs 
    WHERE code_membre = p_code_membre;
    
    RETURN (v_dette > 0 AND v_nombre_prets_actifs > 0);
END//

-- Fonction pour vérifier les cotisations
CREATE FUNCTION verifier_cotisations_suffisantes(
    p_code_membre VARCHAR(20),
    p_duree_requise_mois INT,
    p_montant_min_total DECIMAL(15,0)
) 
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_mois_cotises INT;
    DECLARE v_total_cotisations DECIMAL(15,0);
    DECLARE v_cotisation_a_jour BOOLEAN;
    
    SELECT COALESCE(mois_cotises_consecutifs, 0), COALESCE(total_cotisations_payees, 0), COALESCE(cotisation_a_jour, FALSE)
    INTO v_mois_cotises, v_total_cotisations, v_cotisation_a_jour
    FROM emprunteurs
    WHERE code_membre = p_code_membre;
    
    RETURN (v_mois_cotises >= p_duree_requise_mois 
            AND v_total_cotisations >= p_montant_min_total
            AND v_cotisation_a_jour = TRUE);
END//

-- Procédure pour recalculer la dette du membre
CREATE PROCEDURE recalculer_dette_membre(
    IN p_code_membre VARCHAR(20)
)
BEGIN
    DECLARE v_total_dette DECIMAL(15,0) DEFAULT 0;
    
    -- Recalculer la dette pour tous les prêts du membre
    UPDATE prets p
    SET montant_restant = (
        SELECT p.montant - COALESCE(SUM(pmt.montant_capital), 0)
        FROM paiements pmt
        WHERE pmt.pret_id = p.id
        AND pmt.statut IN ('complet', 'partiel')
    )
    WHERE p.code_membre = p_code_membre
    AND p.statut IN ('actif', 'en_retard');
    
    -- Corriger les montants négatifs
    UPDATE prets 
    SET montant_restant = 0 
    WHERE code_membre = p_code_membre 
    AND montant_restant < 0;
    
    -- Mettre à jour le statut des prêts complètement remboursés
    UPDATE prets 
    SET statut = 'complet' 
    WHERE code_membre = p_code_membre 
    AND montant_restant = 0 
    AND statut IN ('actif', 'en_retard');
    
    -- Recalculer la dette totale de l'emprunteur
    SELECT COALESCE(SUM(montant_restant), 0) INTO v_total_dette
    FROM prets 
    WHERE code_membre = p_code_membre
    AND statut IN ('actif', 'en_retard');
    
    UPDATE emprunteurs 
    SET 
        dette_actuelle = v_total_dette,
        derniere_modification = NOW()
    WHERE code_membre = p_code_membre;
END//

CREATE PROCEDURE corriger_dettes_membres()
BEGIN
    DECLARE v_finished INTEGER DEFAULT 0;
    DECLARE v_code_membre VARCHAR(20);
    DECLARE v_pret_id INT;
    DECLARE v_montant_principal DECIMAL(15,0);
    DECLARE v_total_capital_rembourse DECIMAL(15,0);
    DECLARE v_dette_calculee DECIMAL(15,0);
    
    -- Curseur pour tous les prêts
    DECLARE prets_cursor CURSOR FOR 
        SELECT id, code_membre, montant
        FROM prets 
        WHERE statut IN ('actif', 'en_retard', 'complet');
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_finished = 1;
    
    OPEN prets_cursor;
    
    correction_loop: LOOP
        FETCH prets_cursor INTO v_pret_id, v_code_membre, v_montant_principal;
        IF v_finished = 1 THEN
            LEAVE correction_loop;
        END IF;
        
        -- Calculer le capital remboursé
        SELECT COALESCE(SUM(montant_capital), 0) INTO v_total_capital_rembourse
        FROM paiements 
        WHERE pret_id = v_pret_id 
        AND statut IN ('complet', 'partiel');
        
        -- Calculer la dette réelle
        SET v_dette_calculee = v_montant_principal - v_total_capital_rembourse;
        
        -- Corriger si négatif
        IF v_dette_calculee < 0 THEN
            SET v_dette_calculee = 0;
        END IF;
        
        -- Mettre à jour le prêt
        UPDATE prets 
        SET montant_restant = v_dette_calculee,
            statut = CASE 
                WHEN v_dette_calculee = 0 THEN 'complet'
                ELSE statut 
            END
        WHERE id = v_pret_id;
        
    END LOOP correction_loop;
    
    CLOSE prets_cursor;
    
    -- Mettre à jour toutes les dettes des emprunteurs
    UPDATE emprunteurs e
    SET dette_actuelle = (
        SELECT COALESCE(SUM(montant_restant), 0)
        FROM prets p
        WHERE p.code_membre = e.code_membre
        AND p.statut IN ('actif', 'en_retard')
    ),
    derniere_modification = NOW();
    
END//

CREATE PROCEDURE forcer_recalcul_dette_membre(IN p_code_membre VARCHAR(20))
BEGIN
    DECLARE v_dette_totale DECIMAL(15,0);
    
    -- Recalculer la dette pour tous les prêts du membre
    UPDATE prets p
    SET montant_restant = (
        SELECT p.montant - COALESCE(SUM(pmt.montant_capital), 0)
        FROM paiements pmt
        WHERE pmt.pret_id = p.id
        AND pmt.statut IN ('complet', 'partiel')
    )
    WHERE p.code_membre = p_code_membre;
    
    -- Corriger les montants négatifs
    UPDATE prets 
    SET montant_restant = 0 
    WHERE code_membre = p_code_membre 
    AND montant_restant < 0;
    
    -- Mettre à jour le statut des prêts
    UPDATE prets 
    SET statut = 'complet' 
    WHERE code_membre = p_code_membre 
    AND montant_restant = 0 
    AND statut IN ('actif', 'en_retard');
    
    -- Recalculer la dette totale
    SELECT COALESCE(SUM(montant_restant), 0) INTO v_dette_totale
    FROM prets 
    WHERE code_membre = p_code_membre
    AND statut IN ('actif', 'en_retard');
    
    -- Mettre à jour l'emprunteur
    UPDATE emprunteurs 
    SET 
        dette_actuelle = v_dette_totale,
        nombre_prets_actifs = (
            SELECT COUNT(*)
            FROM prets
            WHERE code_membre = p_code_membre
            AND statut IN ('actif', 'en_retard')
        ),
        derniere_modification = NOW()
    WHERE code_membre = p_code_membre;
    
END//

CREATE PROCEDURE recalculer_dette_pret(IN p_pret_id INT)
BEGIN
    DECLARE v_total_capital_rembourse DECIMAL(15,0);
    DECLARE v_dette_actuelle DECIMAL(15,0);
    DECLARE v_montant_principal_pret DECIMAL(15,0);
    DECLARE v_code_membre VARCHAR(20);
    DECLARE v_nouveau_statut VARCHAR(20);
    DECLARE v_dette_totale_membre DECIMAL(15,0);
    
    -- Récupérer les informations du prêt
    SELECT code_membre, montant 
    INTO v_code_membre, v_montant_principal_pret
    FROM prets 
    WHERE id = p_pret_id;
    
    -- Calculer le capital remboursé
    SELECT COALESCE(SUM(montant_capital), 0)
    INTO v_total_capital_rembourse
    FROM paiements 
    WHERE pret_id = p_pret_id 
    AND statut IN ('complet', 'partiel');
    
    -- Calcul de la dette
    SET v_dette_actuelle = v_montant_principal_pret - v_total_capital_rembourse;
    
    IF v_dette_actuelle < 0 THEN
        SET v_dette_actuelle = 0;
    END IF;
    
    -- Déterminer le statut
    IF v_dette_actuelle = 0 THEN
        SET v_nouveau_statut = 'complet';
        
        -- Mettre à jour TOUTES les échéances non payées
        UPDATE echeances_pret 
        SET statut = 'payee'
        WHERE pret_id = p_pret_id 
        AND statut IN ('a_venir', 'due', 'en_retard');
    ELSE
        IF EXISTS (
            SELECT 1 FROM echeances_pret 
            WHERE pret_id = p_pret_id 
            AND statut IN ('due', 'en_retard')
            AND date_echeance < CURDATE()
        ) THEN
            SET v_nouveau_statut = 'en_retard';
        ELSE
            SET v_nouveau_statut = 'actif';
        END IF;
    END IF;
    
    -- Mettre à jour le prêt
    UPDATE prets 
    SET 
        montant_restant = v_dette_actuelle,
        statut = v_nouveau_statut
    WHERE id = p_pret_id;
    
    -- Recalculer la dette totale du membre
    SELECT COALESCE(SUM(montant_restant), 0)
    INTO v_dette_totale_membre
    FROM prets 
    WHERE code_membre = v_code_membre
    AND statut IN ('actif', 'en_retard');
    
    -- Mettre à jour l'emprunteur
    UPDATE emprunteurs 
    SET 
        dette_actuelle = v_dette_totale_membre,
        nombre_prets_actifs = (
            SELECT COUNT(*)
            FROM prets
            WHERE code_membre = v_code_membre
            AND statut IN ('actif', 'en_retard')
        ),
        derniere_modification = NOW()
    WHERE code_membre = v_code_membre;
END//

-- Procédure pour mettre à jour l'affichage de la dette
CREATE PROCEDURE mettre_a_jour_affichage_dette(
    IN p_code_membre VARCHAR(20)
)
BEGIN
    DECLARE v_dette_actuelle DECIMAL(15,0);
    DECLARE v_dette_affichage VARCHAR(50);
    
    SELECT COALESCE(dette_actuelle, 0) INTO v_dette_actuelle
    FROM emprunteurs 
    WHERE code_membre = p_code_membre;
    
    SET v_dette_affichage = formater_dette(v_dette_actuelle);
    
END//

DELIMITER ;

-- =============================================================================
-- VUES AMÉLIORÉES (SANS PLAFONDS)
-- =============================================================================

-- Vue complète des membres avec toutes les informations
CREATE OR REPLACE VIEW vue_membres_complets AS
SELECT 
    m.*,
    cm.nom_categorie,
    cm.code_categorie,
    cm.cotisation_mensuelle,
    e.score_credit,
    e.statut as statut_emprunteur,
    -- Afficher "Aucune dette" quand la dette est 0
    CASE 
        WHEN COALESCE(e.dette_actuelle, 0) = 0 THEN 'Aucune dette'
        ELSE FORMAT(e.dette_actuelle, 0)
    END as dette_actuelle_affichage,
    -- Conserver la valeur numérique pour les calculs
    e.dette_actuelle,
    e.cotisation_a_jour,
    e.mois_cotises_consecutifs,
    e.total_cotisations_payees
FROM membres m
LEFT JOIN categories_membres cm ON m.categorie_id = cm.id
LEFT JOIN emprunteurs e ON m.code_membre = e.code_membre;

-- Vue détaillée des prêts
CREATE OR REPLACE VIEW vue_prets_detaille AS
SELECT 
    p.*,
    m.nom,
    m.prenom,
    m.email,
    m.telephone,
    cm.nom_categorie,
    pp.nom_pret,
    pp.code_plan,
    tp.nom_type as type_pret,
    u.nom_complet as approbateur_nom,
    DATEDIFF(p.date_fin, CURDATE()) as jours_restants,
    -- Affichage du montant restant
    CASE 
        WHEN COALESCE(p.montant_restant, 0) = 0 THEN 'Aucune dette'
        ELSE FORMAT(p.montant_restant, 0)
    END as montant_restant_affichage,
    CASE 
        WHEN p.statut = 'actif' AND DATEDIFF(p.date_fin, CURDATE()) < 0 THEN 'en_retard'
        ELSE p.statut
    END as statut_etendu
FROM prets p
LEFT JOIN membres m ON p.code_membre = m.code_membre
LEFT JOIN categories_membres cm ON m.categorie_id = cm.id
LEFT JOIN plans_pret pp ON p.plan_pret_id = pp.id
LEFT JOIN types_pret tp ON pp.type_pret_id = tp.id
LEFT JOIN utilisateurs u ON p.approbation_par = u.id;

-- Vue spécialisée pour les rapports avec dette formatée
CREATE OR REPLACE VIEW vue_rapport_dettes AS
SELECT 
    m.code_membre,
    CONCAT(m.nom, ' ', m.prenom) as nom_complet,
    cm.nom_categorie,
    e.dette_actuelle,
    -- Affichage formaté de la dette
    formater_dette(e.dette_actuelle) as dette_affichage,
    e.cotisation_a_jour,
    e.score_credit,
    e.statut as statut_emprunteur,
    COUNT(p.id) as nombre_prets_actifs
FROM membres m
LEFT JOIN categories_membres cm ON m.categorie_id = cm.id
LEFT JOIN emprunteurs e ON m.code_membre = e.code_membre
LEFT JOIN prets p ON m.code_membre = p.code_membre AND p.statut IN ('actif', 'en_retard')
GROUP BY m.code_membre, m.nom, m.prenom, cm.nom_categorie, e.dette_actuelle, e.cotisation_a_jour, e.score_credit, e.statut;

-- Vue des statistiques globales
CREATE OR REPLACE VIEW vue_statistiques_globales AS
SELECT 
    (SELECT COUNT(*) FROM membres WHERE statut = 'actif') as total_membres_actifs,
    (SELECT COUNT(*) FROM emprunteurs WHERE statut = 'eligible') as total_emprunteurs_eligibles,
    (SELECT COUNT(*) FROM prets WHERE statut = 'actif') as total_prets_actifs,
    (SELECT COALESCE(SUM(montant_restant), 0) FROM prets WHERE statut IN ('actif', 'en_retard')) as total_dette_active,
    (SELECT COALESCE(SUM(montant), 0) FROM prets WHERE statut = 'actif') as total_montant_prets_actifs,
    (SELECT COALESCE(SUM(montant_paye), 0) FROM paiements WHERE statut IN ('complet', 'partiel')) as total_rembourse,
    (SELECT COUNT(*) FROM prets WHERE statut = 'en_retard') as total_prets_en_retard,
    (SELECT COALESCE(SUM(montant_restant), 0) FROM prets WHERE statut = 'en_retard') as total_dette_en_retard;

-- =============================================================================
-- TRIGGERS AMÉLIORÉS (SANS CALCULS DE PLAFONDS)
-- =============================================================================

DELIMITER //

-- Trigger pour générer automatiquement le code membre
CREATE TRIGGER before_insert_membres
BEFORE INSERT ON membres
FOR EACH ROW
BEGIN
    IF NEW.code_membre IS NULL OR NEW.code_membre = '' THEN
        SET NEW.code_membre = generer_code_membre();
    END IF;
END//

-- Trigger pour créer automatiquement un emprunteur (SANS CALCUL DE PLAFOND)
CREATE TRIGGER after_insert_membres
AFTER INSERT ON membres
FOR EACH ROW
BEGIN
    INSERT INTO emprunteurs (
        code_membre,
        date_qualification,
        score_credit,
        statut
    ) VALUES (
        NEW.code_membre,
        NOW(),
        500,
        'en_attente'
    );
END//

-- Trigger amélioré pour les paiements - maintient la cohérence de l'affichage
CREATE TRIGGER after_insert_paiements_corrige_dette
AFTER INSERT ON paiements
FOR EACH ROW
BEGIN
    DECLARE v_code_membre VARCHAR(20);
    DECLARE v_pret_id INT;
    DECLARE v_montant_principal DECIMAL(15,0);
    DECLARE v_total_capital_rembourse DECIMAL(15,0);
    DECLARE v_dette_calculee DECIMAL(15,0);
    DECLARE v_dette_totale_membre DECIMAL(15,0);
    
    -- Récupérer les informations du prêt
    SELECT code_membre, montant, id 
    INTO v_code_membre, v_montant_principal, v_pret_id
    FROM prets 
    WHERE id = NEW.pret_id;
    
    -- Calculer le capital total remboursé pour CE prêt
    SELECT COALESCE(SUM(montant_capital), 0)
    INTO v_total_capital_rembourse
    FROM paiements 
    WHERE pret_id = NEW.pret_id 
    AND statut IN ('complet', 'partiel');
    
    -- Calculer la dette actuelle pour CE prêt
    SET v_dette_calculee = v_montant_principal - v_total_capital_rembourse;
    
    -- S'assurer que la dette n'est pas négative
    IF v_dette_calculee < 0 THEN
        SET v_dette_calculee = 0;
    END IF;
    
    -- Mettre à jour la dette du prêt
    UPDATE prets 
    SET 
        montant_restant = v_dette_calculee,
        statut = CASE 
            WHEN v_dette_calculee = 0 THEN 'complet'
            WHEN v_dette_calculee > 0 THEN 
                CASE 
                    WHEN EXISTS (
                        SELECT 1 FROM echeances_pret 
                        WHERE pret_id = v_pret_id 
                        AND statut IN ('due', 'en_retard')
                        AND date_echeance < CURDATE()
                    ) THEN 'en_retard'
                    ELSE 'actif'
                END
            ELSE statut
        END
    WHERE id = v_pret_id;
    
    IF v_dette_calculee = 0 THEN
        UPDATE echeances_pret 
        SET statut = 'payee'
        WHERE pret_id = v_pret_id 
        AND statut IN ('a_venir', 'due', 'en_retard');
    END IF;
    
    -- Calculer la dette totale du membre pour TOUS ses prêts
    SELECT COALESCE(SUM(montant_restant), 0)
    INTO v_dette_totale_membre
    FROM prets 
    WHERE code_membre = v_code_membre
    AND statut IN ('actif', 'en_retard');
    
    -- Mettre à jour la dette dans la table emprunteurs
    UPDATE emprunteurs 
    SET 
        dette_actuelle = v_dette_totale_membre,
        total_rembourse = (
            SELECT COALESCE(SUM(pmt.montant_capital), 0)
            FROM paiements pmt
            JOIN prets p ON pmt.pret_id = p.id
            WHERE p.code_membre = v_code_membre
            AND pmt.statut IN ('complet', 'partiel')
        ),
        nombre_prets_actifs = (
            SELECT COUNT(*)
            FROM prets
            WHERE code_membre = v_code_membre
            AND statut IN ('actif', 'en_retard')
        ),
        derniere_modification = NOW()
    WHERE code_membre = v_code_membre;
    
END//

CREATE TRIGGER after_update_paiements_corrige_dette
AFTER UPDATE ON paiements
FOR EACH ROW
BEGIN
    -- Si le statut ou le montant capital change, recalculer la dette
    IF OLD.statut != NEW.statut OR OLD.montant_capital != NEW.montant_capital THEN
        -- Appeler la procédure de recalcul
        CALL recalculer_dette_pret(NEW.pret_id);
    END IF;
END//

-- Trigger pour mettre à jour les cotisations
CREATE TRIGGER after_insert_cotisations
AFTER INSERT ON cotisations
FOR EACH ROW
BEGIN
    DECLARE v_mois_consecutifs INT DEFAULT 0;
    DECLARE v_total_cotisations DECIMAL(15,0);
    DECLARE v_cotisation_a_jour BOOLEAN;
    DECLARE v_date_derniere_cotisation DATE;
    
    -- Calculer les mois consécutifs de cotisation
    SELECT COALESCE(MAX(mois_cotises_consecutifs), 0) INTO v_mois_consecutifs
    FROM (
        SELECT COUNT(*) as mois_cotises_consecutifs
        FROM cotisations c1
        WHERE c1.code_membre = NEW.code_membre
        AND c1.statut = 'valide'
        AND c1.date_fin >= DATE_SUB(NEW.date_fin, INTERVAL 12 MONTH)
        GROUP BY YEAR(c1.date_fin), MONTH(c1.date_fin)
    ) as consecutive_months;
    
    -- Calculer le total des cotisations
    SELECT COALESCE(SUM(montant), 0) INTO v_total_cotisations
    FROM cotisations
    WHERE code_membre = NEW.code_membre
    AND statut = 'valide';
    
    -- Vérifier si la cotisation est à jour
    SET v_cotisation_a_jour = (NEW.date_fin >= CURDATE());
    
    -- Récupérer la date de la dernière cotisation
    SELECT MAX(date_fin) INTO v_date_derniere_cotisation
    FROM cotisations
    WHERE code_membre = NEW.code_membre
    AND statut = 'valide';
    
    -- Mettre à jour l'emprunteur
    UPDATE emprunteurs
    SET 
        total_cotisations_payees = v_total_cotisations,
        mois_cotises_consecutifs = v_mois_consecutifs,
        cotisation_a_jour = v_cotisation_a_jour,
        date_derniere_cotisation = v_date_derniere_cotisation,
        derniere_modification = NOW()
    WHERE code_membre = NEW.code_membre;
    
END//

DELIMITER ;

-- =============================================================================
-- INDEX OPTIMISÉS
-- =============================================================================

-- Index pour les recherches fréquentes
CREATE INDEX idx_membres_nom_prenom ON membres(nom, prenom);
CREATE INDEX idx_prets_code_membre_statut ON prets(code_membre, statut);
CREATE INDEX idx_paiements_date_pret ON paiements(date_paiement, pret_id);
CREATE INDEX idx_cotisations_code_date ON cotisations(code_membre, date_fin);
CREATE INDEX idx_emprunteurs_statut_score ON emprunteurs(statut, score_credit);
CREATE INDEX idx_alertes_destinataire_lue ON alertes(destinataire_type, destinataire_id, lue);

-- Index pour les performances des rapports
CREATE INDEX idx_prets_statut_date ON prets(statut, date_creation);
CREATE INDEX idx_paiements_statut_date ON paiements(statut, date_paiement);
CREATE INDEX idx_cotisations_statut_date ON cotisations(statut, date_paiement);

-- =============================================================================
-- FIN DE LA BASE DE DONNÉES COMPLÈTE
-- =============================================================================