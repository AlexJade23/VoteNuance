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
-- FIN DES MIGRATIONS
-- ============================================================================
