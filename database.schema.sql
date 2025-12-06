-- ============================================================================
-- DECO v2 - Schéma de base de données pour PHP natif
-- Vote nuancé avec cloisonnement vote/votant
-- ============================================================================
-- Principes :
--   1. Séparation stricte entre "qui a voté" et "ce qui a été voté"
--   2. Authentification SSO uniquement (Google/Microsoft)
--   3. Vérification individuelle par clé secrète (ballot_secret)
--   4. Triple comptage : jetons utilisés = émargements = bulletins uniques
-- ============================================================================

CREATE DATABASE IF NOT EXISTS deco
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE deco;

-- ============================================================================
-- BLOC 1 : UTILISATEURS (SSO)
-- ============================================================================

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Identification SSO
    sso_provider ENUM('google', 'microsoft') NOT NULL,
    sso_id VARCHAR(255) NOT NULL,

    -- Données optionnelles (consentement utilisateur)
    email_hash CHAR(64) DEFAULT NULL
        COMMENT 'SHA-256 de l''email si consentement donné',
    email_hash_consent BOOLEAN DEFAULT FALSE
        COMMENT 'Utilisateur a consenti au stockage du hash',
    display_name VARCHAR(100) DEFAULT NULL
        COMMENT 'Pseudo choisi par l''utilisateur',

    -- Métadonnées
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Contraintes
    UNIQUE KEY uk_sso (sso_provider, sso_id),
    INDEX idx_email_hash (email_hash)

) ENGINE=InnoDB;

-- ============================================================================
-- BLOC 2 : ÉCHELLES ET MENTIONS
-- ============================================================================

CREATE TABLE echelles (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    libelle VARCHAR(128) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB;

CREATE TABLE mentions (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    echelle_id SMALLINT UNSIGNED NOT NULL,

    libelle VARCHAR(32) NOT NULL
        COMMENT 'Ex: Absolument Pour, Franchement Contre...',
    code VARCHAR(4) NOT NULL
        COMMENT 'AP, FP, PP, SA, PC, FC, AC',
    poids DECIMAL(3,2) NOT NULL
        COMMENT 'Poids pour le calcul du score',
    rang TINYINT UNSIGNED NOT NULL
        COMMENT 'Ordre d''affichage (1=Absolument Contre, 7=Absolument Pour)',
    couleur CHAR(7) DEFAULT NULL
        COMMENT 'Code couleur hex #RRGGBB',
    est_partisan TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1=Pour, 0=Sans avis, -1=Contre (pour classement)',

    CONSTRAINT fk_mentions_echelle
        FOREIGN KEY (echelle_id) REFERENCES echelles(id),
    INDEX idx_echelle_rang (echelle_id, rang)

) ENGINE=InnoDB;

-- ============================================================================
-- BLOC 3 : SCRUTINS
-- ============================================================================

CREATE TABLE scrutins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Identification
    code VARCHAR(32) NOT NULL UNIQUE
        COMMENT 'Code court pour URL/partage',
    titre VARCHAR(256) NOT NULL,
    resume TEXT DEFAULT NULL,
    notice TEXT DEFAULT NULL
        COMMENT 'Instructions pour les votants',
    image_url VARCHAR(256) DEFAULT NULL,

    -- Temporalité
    debut_at DATETIME DEFAULT NULL,
    fin_at DATETIME DEFAULT NULL,

    -- Paramètres
    nb_participants_attendus INT UNSIGNED DEFAULT 0
        COMMENT '0 = illimité',
    nb_gagnants SMALLINT UNSIGNED DEFAULT 1
        COMMENT 'Nombre de propositions retenues',
    format TINYINT UNSIGNED DEFAULT 0,

    -- Affichage
    affiche_resultats TINYINT(1) DEFAULT 0
        COMMENT 'Résultats visibles avant clôture',
    est_public TINYINT(1) DEFAULT 0
        COMMENT 'Scrutin visible publiquement',
    est_archive TINYINT(1) DEFAULT 0,

    -- Notifications
    type_notification TINYINT UNSIGNED DEFAULT 0,
    destination_notification VARCHAR(256) DEFAULT NULL,

    -- Propriétaire
    owner_id INT UNSIGNED DEFAULT NULL,

    -- Métadonnées
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_scrutins_owner
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_code (code),
    INDEX idx_dates (debut_at, fin_at)

) ENGINE=InnoDB;

-- ============================================================================
-- BLOC 4 : QUESTIONS ET RÉPONSES
-- ============================================================================

CREATE TABLE questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scrutin_id INT UNSIGNED NOT NULL,
    echelle_id SMALLINT UNSIGNED DEFAULT NULL
        COMMENT 'NULL si pas de type vote nuancé',

    -- Type de question
    type_question TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0=Vote nuancé, 1=Ouverte, 2=Séparateur, 3=Préféré du lot, 4=QCM',

    -- Contenu
    numero VARCHAR(16) DEFAULT NULL
        COMMENT 'Numérotation affichée (ex: 1.a)',
    titre VARCHAR(256) NOT NULL,
    question TEXT DEFAULT NULL
        COMMENT 'Énoncé détaillé',
    description TEXT DEFAULT NULL
        COMMENT 'Contexte/explications',
    image_url VARCHAR(256) DEFAULT NULL,

    -- Organisation
    lot SMALLINT UNSIGNED DEFAULT 0
        COMMENT 'Regroupement pour vote préféré',
    ordre SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Options
    est_obligatoire TINYINT(1) DEFAULT 0,
    est_cle TINYINT(1) DEFAULT 0
        COMMENT 'Question clé pour analyse',
    horodatage TINYINT(1) DEFAULT 0,

    -- RGPD
    est_donnee_personnelle TINYINT(1) DEFAULT 0,
    est_donnee_sensible TINYINT(1) DEFAULT 0,

    CONSTRAINT fk_questions_scrutin
        FOREIGN KEY (scrutin_id) REFERENCES scrutins(id) ON DELETE CASCADE,
    CONSTRAINT fk_questions_echelle
        FOREIGN KEY (echelle_id) REFERENCES echelles(id),
    INDEX idx_scrutin_ordre (scrutin_id, ordre)

) ENGINE=InnoDB;

CREATE TABLE reponses_possibles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,

    libelle VARCHAR(256) NOT NULL,
    ordre SMALLINT UNSIGNED DEFAULT 0,

    -- Conditionnement (afficher une question si cette réponse est choisie)
    question_conditionnee_id INT UNSIGNED DEFAULT NULL,

    CONSTRAINT fk_reponses_question
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_reponses_conditionnee
        FOREIGN KEY (question_conditionnee_id) REFERENCES questions(id) ON DELETE SET NULL,
    INDEX idx_question_ordre (question_id, ordre)

) ENGINE=InnoDB;

-- ============================================================================
-- BLOC 5 : JETONS ET ÉMARGEMENTS (qui a le droit de voter / qui a voté)
-- ============================================================================

CREATE TABLE jetons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scrutin_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL
        COMMENT 'NULL si jeton anonyme distribué',

    code VARCHAR(128) NOT NULL
        COMMENT 'Le jeton lui-même (UUID ou code lisible)',

    est_organisateur TINYINT(1) DEFAULT 0,
    est_utilise TINYINT(1) DEFAULT 0,
    utilise_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_jetons_scrutin
        FOREIGN KEY (scrutin_id) REFERENCES scrutins(id) ON DELETE CASCADE,
    CONSTRAINT fk_jetons_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_scrutin_code (scrutin_id, code),
    INDEX idx_user (user_id)

) ENGINE=InnoDB;

-- Table d'émargement : SÉPARÉE des bulletins
-- Enregistre uniquement qu'une participation a eu lieu
CREATE TABLE emargements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scrutin_id INT UNSIGNED NOT NULL,

    -- Horodatage de la participation
    emarge_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Métadonnées anonymisées (optionnel, pour statistiques)
    ip_hash CHAR(64) DEFAULT NULL
        COMMENT 'SHA-256 de l''IP (pas l''IP en clair)',

    CONSTRAINT fk_emargements_scrutin
        FOREIGN KEY (scrutin_id) REFERENCES scrutins(id) ON DELETE CASCADE,
    INDEX idx_scrutin (scrutin_id)

) ENGINE=InnoDB;

-- ============================================================================
-- BLOC 6 : BULLETINS (ce qui a été voté - SANS LIEN avec qui)
-- ============================================================================

-- Table des bulletins : indexée par hash de la clé secrète
CREATE TABLE bulletins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scrutin_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,

    -- Identifiant du bulletin (hash de ballot_secret)
    -- Permet au votant de retrouver son vote sans révéler le lien
    ballot_hash CHAR(64) NOT NULL
        COMMENT 'SHA-256 de la clé secrète du votant',

    -- Le vote lui-même
    vote_mention TINYINT UNSIGNED DEFAULT NULL
        COMMENT 'Rang de la mention (1-7) pour type=0',
    reponse TEXT DEFAULT NULL
        COMMENT 'Texte ou choix pour types 1,3,4',

    -- Horodatage
    vote_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Flag pour tests (ne pas compter dans résultats)
    est_test TINYINT(1) DEFAULT 0,

    CONSTRAINT fk_bulletins_scrutin
        FOREIGN KEY (scrutin_id) REFERENCES scrutins(id) ON DELETE CASCADE,
    CONSTRAINT fk_bulletins_question
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,

    -- Un seul vote par bulletin/question
    UNIQUE KEY uk_ballot_question (ballot_hash, question_id),
    INDEX idx_scrutin_question (scrutin_id, question_id),
    INDEX idx_ballot (ballot_hash)

) ENGINE=InnoDB;

-- ============================================================================
-- BLOC 7 : VUES POUR RÉSULTATS ET VÉRIFICATIONS
-- ============================================================================

-- Vue : comptage d'intégrité par scrutin
CREATE OR REPLACE VIEW v_integrite_scrutins AS
SELECT
    s.id AS scrutin_id,
    s.code,
    s.titre,
    (SELECT COUNT(*) FROM jetons j WHERE j.scrutin_id = s.id AND j.est_utilise = 1) AS nb_jetons_utilises,
    (SELECT COUNT(*) FROM emargements e WHERE e.scrutin_id = s.id) AS nb_emargements,
    (SELECT COUNT(DISTINCT ballot_hash) FROM bulletins b WHERE b.scrutin_id = s.id AND b.est_test = 0) AS nb_bulletins_uniques,
    CASE
        WHEN (SELECT COUNT(*) FROM jetons j WHERE j.scrutin_id = s.id AND j.est_utilise = 1)
           = (SELECT COUNT(*) FROM emargements e WHERE e.scrutin_id = s.id)
         AND (SELECT COUNT(*) FROM emargements e WHERE e.scrutin_id = s.id)
           = (SELECT COUNT(DISTINCT ballot_hash) FROM bulletins b WHERE b.scrutin_id = s.id AND b.est_test = 0)
        THEN 'OK'
        ELSE 'ALERTE'
    END AS integrite
FROM scrutins s;

-- Vue : résultats vote nuancé (Score = AP+FP+PP - AC-FC-PC)
CREATE OR REPLACE VIEW v_resultats_vote_nuance AS
SELECT
    b.scrutin_id,
    b.question_id,
    q.titre AS question_titre,

    -- Comptage par mention
    SUM(CASE WHEN b.vote_mention = 1 THEN 1 ELSE 0 END) AS absolument_contre,
    SUM(CASE WHEN b.vote_mention = 2 THEN 1 ELSE 0 END) AS franchement_contre,
    SUM(CASE WHEN b.vote_mention = 3 THEN 1 ELSE 0 END) AS plutot_contre,
    SUM(CASE WHEN b.vote_mention = 4 THEN 1 ELSE 0 END) AS sans_avis,
    SUM(CASE WHEN b.vote_mention = 5 THEN 1 ELSE 0 END) AS plutot_pour,
    SUM(CASE WHEN b.vote_mention = 6 THEN 1 ELSE 0 END) AS franchement_pour,
    SUM(CASE WHEN b.vote_mention = 7 THEN 1 ELSE 0 END) AS absolument_pour,

    -- Score principal : 1 voix = 1 voix
    (SUM(CASE WHEN b.vote_mention = 7 THEN 1 ELSE 0 END)
   + SUM(CASE WHEN b.vote_mention = 6 THEN 1 ELSE 0 END)
   + SUM(CASE WHEN b.vote_mention = 5 THEN 1 ELSE 0 END)
   - SUM(CASE WHEN b.vote_mention = 1 THEN 1 ELSE 0 END)
   - SUM(CASE WHEN b.vote_mention = 2 THEN 1 ELSE 0 END)
   - SUM(CASE WHEN b.vote_mention = 3 THEN 1 ELSE 0 END)) AS score,

    -- Niveaux de départage (cascade AP-AC, FP-FC, PP-PC)
    (SUM(CASE WHEN b.vote_mention = 7 THEN 1 ELSE 0 END)
   - SUM(CASE WHEN b.vote_mention = 1 THEN 1 ELSE 0 END)) AS niveau1_ap_ac,
    (SUM(CASE WHEN b.vote_mention = 6 THEN 1 ELSE 0 END)
   - SUM(CASE WHEN b.vote_mention = 2 THEN 1 ELSE 0 END)) AS niveau2_fp_fc,
    (SUM(CASE WHEN b.vote_mention = 5 THEN 1 ELSE 0 END)
   - SUM(CASE WHEN b.vote_mention = 3 THEN 1 ELSE 0 END)) AS niveau3_pp_pc,

    COUNT(*) AS nb_votes

FROM bulletins b
JOIN questions q ON q.id = b.question_id
WHERE b.est_test = 0
  AND q.type_question = 0
GROUP BY b.scrutin_id, b.question_id, q.titre;

-- ============================================================================
-- BLOC 8 : DONNÉES DE RÉFÉRENCE (échelle standard vote nuancé)
-- ============================================================================

INSERT INTO echelles (id, libelle, description) VALUES
(1, 'Vote Nuancé 7 mentions', 'Échelle standard : Absolument Contre → Absolument Pour');

INSERT INTO mentions (echelle_id, libelle, code, poids, rang, couleur, est_partisan) VALUES
(1, 'Absolument Contre', 'AC', -1.00, 1, '#D32F2F', -1),
(1, 'Franchement Contre', 'FC', -0.67, 2, '#F57C00', -1),
(1, 'Plutôt Contre', 'PC', -0.33, 3, '#FBC02D', -1),
(1, 'Sans Avis', 'SA', 0.00, 4, '#9E9E9E', 0),
(1, 'Plutôt Pour', 'PP', 0.33, 5, '#C0CA33', 1),
(1, 'Franchement Pour', 'FP', 0.67, 6, '#7CB342', 1),
(1, 'Absolument Pour', 'AP', 1.00, 7, '#388E3C', 1);

-- ============================================================================
-- FIN DU SCHÉMA
-- ============================================================================
