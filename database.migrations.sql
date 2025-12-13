-- ============================================================================
-- DECO v2 - Migrations de base de données
-- À exécuter sur une BDD existante pour la mettre à jour
-- ============================================================================

-- ============================================================================
-- Migration 001 - 2024-12-07 - Ajout ordre des mentions
-- ============================================================================
-- Permet de choisir l'ordre d'affichage des mentions (Contre->Pour ou Pour->Contre)

ALTER TABLE scrutins ADD COLUMN ordre_mentions TINYINT(1) DEFAULT 0
    COMMENT '0=Contre vers Pour, 1=Pour vers Contre';

-- ============================================================================
-- Migration 002 - 2024-12-10 - Table achats pour paiements Stripe
-- ============================================================================
-- Stocke les achats de jetons via Stripe

CREATE TABLE IF NOT EXISTS achats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    scrutin_id INT UNSIGNED NOT NULL,
    nb_jetons INT UNSIGNED NOT NULL,
    montant_cents INT UNSIGNED NOT NULL COMMENT 'Montant en centimes (ex: 1000 = 10 EUR)',
    stripe_session_id VARCHAR(255) NULL COMMENT 'ID de la session Stripe Checkout',
    stripe_payment_intent VARCHAR(255) NULL COMMENT 'ID du PaymentIntent Stripe',
    status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (scrutin_id) REFERENCES scrutins(id) ON DELETE CASCADE,
    INDEX idx_stripe_session (stripe_session_id),
    INDEX idx_user_scrutin (user_id, scrutin_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Migration 003 - 2024-12-10 - Colonnes import pour bulletins
-- ============================================================================
-- Permet de tracer les bulletins importes depuis un fichier XLS

ALTER TABLE bulletins ADD COLUMN est_importe TINYINT(1) DEFAULT 0
    COMMENT '1 si bulletin importe depuis fichier XLS';

ALTER TABLE bulletins ADD COLUMN imported_at DATETIME NULL
    COMMENT 'Date/heure de l import';

-- ============================================================================
-- Migration 004 - 2024-12-13 - Echelles flexibles (US-020)
-- ============================================================================
-- Permet de choisir le nombre de mentions par scrutin (3, 5 ou 7)

ALTER TABLE scrutins ADD COLUMN nb_mentions TINYINT UNSIGNED DEFAULT 7
    COMMENT 'Nombre de mentions: 3, 5 ou 7 (defaut: 7)';

-- ============================================================================
-- FIN DES MIGRATIONS
-- ============================================================================
