-- Structure de base de données pour SSO minimal
-- Créer la base de données
CREATE DATABASE IF NOT EXISTS votre_base CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE votre_base;

-- Table des utilisateurs
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Identification SSO (un seul champ unifié)
    sso_provider ENUM('google', 'microsoft') NOT NULL,
    sso_id VARCHAR(255) NOT NULL,
    
    -- Données optionnelles selon consentement utilisateur
    email_hash CHAR(64) DEFAULT NULL COMMENT 'SHA-256 du email si consentement donné',
    display_name VARCHAR(100) DEFAULT NULL COMMENT 'Pseudo choisi par utilisateur',
    email_hash_consent BOOLEAN DEFAULT FALSE COMMENT 'Utilisateur a consenti au stockage du hash',
    
    -- Métadonnées
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Index et contraintes
    UNIQUE KEY unique_sso (sso_provider, sso_id),
    INDEX idx_email_hash (email_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
