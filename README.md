# Vote Nuance - DECO v2

Plateforme de vote nuance avec cloisonnement vote/votant et respect de la vie privee.

## Objectif

Permettre des consultations democratiques avec une methode de vote plus expressive que le simple Oui/Non : chaque participant peut nuancer son avis sur une echelle a 7 mentions, de "Absolument Contre" a "Absolument Pour".

## Principes fondamentaux

- **Separation vote/votant** : impossible de relier un bulletin a son auteur
- **Verification individuelle** : chaque votant peut verifier que son vote a ete comptabilise (via cle secrete)
- **Triple comptage** : jetons utilises = emargements = bulletins uniques
- **Zero dependance** : PHP natif uniquement
- **RGPD compliant** : minimisation des donnees, consentement explicite

## Echelle de vote

| Mention | Code | Couleur |
|---------|------|---------|
| Absolument Contre | AC | Rouge fonce |
| Franchement Contre | FC | Orange |
| Plutot Contre | PC | Jaune |
| Sans Avis | SA | Gris |
| Plutot Pour | PP | Vert-jaune |
| Franchement Pour | FP | Vert clair |
| Absolument Pour | AP | Vert fonce |

**Calcul du score** : `(AP + FP + PP) - (AC + FC + PC)`

En cas d'egalite, departage par cascade : AP-AC, puis FP-FC, puis PP-PC.

## Architecture technique

### Prerequis

- PHP 7.4+ (recommande : PHP 8.x)
- MariaDB 10.3+ ou MySQL 5.7+
- Apache avec mod_rewrite (ou Nginx)
- HTTPS active (obligatoire en production)
- Extensions PHP : `curl`, `json`, `pdo_mysql`

### Structure de la base de donnees

8 tables principales :
- `users` : utilisateurs SSO (Google/Microsoft)
- `echelles` : definitions des echelles de vote
- `mentions` : mentions par echelle (7 pour le vote nuance)
- `scrutins` : les consultations
- `questions` : questions par scrutin (vote nuance, QCM, ouverte...)
- `reponses_possibles` : choix pour QCM
- `jetons` : droits de vote distribues
- `emargements` : registre des participations (sans lien avec les votes)
- `bulletins` : les votes eux-memes (lies par ballot_hash, pas par user)

2 vues :
- `v_integrite_scrutins` : verification du triple comptage
- `v_resultats_vote_nuance` : resultats agreges par question

Voir `database.schema.sql` pour le schema complet.

### Structure des fichiers

```
VoteNuance/
├── config.php              # Configuration (charge secrets externes)
├── functions.php           # Fonctions utilitaires (BDD, JWT, etc.)
├── database.sql            # Schema minimal (users SSO)
├── database.schema.sql     # Schema complet DECO v2
├── login.php               # Page de connexion SSO
├── oauth-redirect.php      # Redirection vers Google/Microsoft
├── callback.php            # Traitement du retour OAuth
├── dashboard.php           # Page protegee (exemple)
├── logout.php              # Deconnexion
├── my-data.php             # Gestion des donnees personnelles
├── index.php               # Page d'accueil
└── README.md               # Ce fichier
```

## Installation

### 1. Base de donnees

```bash
mysql -u root -p < database.schema.sql
```

### 2. Configuration des secrets

Creer un fichier `../secret/sso.php` (hors racine web) :

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'deco');
define('DB_USER', 'votre_user');
define('DB_PASS', 'votre_password');

define('GOOGLE_CLIENT_ID', 'xxx.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'xxx');
define('GOOGLE_REDIRECT_URI', 'https://votresite.com/callback.php');

define('MICROSOFT_CLIENT_ID', 'xxx');
define('MICROSOFT_CLIENT_SECRET', 'xxx');
define('MICROSOFT_REDIRECT_URI', 'https://votresite.com/callback.php');
```

### 3. Configuration OAuth

**Google** : [Google Cloud Console](https://console.cloud.google.com)
- Creer un projet > Identifiants > ID client OAuth 2.0
- URI de redirection : `https://votresite.com/callback.php`

**Microsoft** : [Azure Portal](https://portal.azure.com)
- Azure AD > App registrations > New registration
- URI de redirection : `https://votresite.com/callback.php`

## Securite

- HTTPS obligatoire en production
- Tokens CSRF pour actions sensibles
- Protection XSS via `htmlspecialchars()`
- Requetes preparees (anti-injection SQL)
- Sessions securisees (httponly, secure, samesite)
- Secrets hors de la racine web
- Separation stricte vote/votant

## Conformite RGPD

- Minimisation des donnees (seulement SSO ID par defaut)
- Consentement explicite pour hash email
- Droit d'acces (page "Mes donnees")
- Droit de suppression
- Transparence totale

## Donnees stockees

| Donnee | Stockage | Raison |
|--------|----------|--------|
| SSO ID | Toujours | Identification utilisateur |
| Hash email (SHA-256) | Si consentement | Detection doublons |
| Pseudo | Si fourni | Affichage |
| Email en clair | Jamais | Vie privee |
| Lien vote/votant | Jamais | Anonymat du vote |

## Etat d'avancement

- [x] Authentification SSO (Google/Microsoft)
- [x] Gestion des donnees personnelles
- [x] Schema BDD complet
- [ ] Interface de creation de scrutin
- [ ] Interface de vote
- [ ] Calcul et affichage des resultats
- [ ] Verification individuelle (ballot_secret)
- [ ] Interface d'administration
- [ ] Export des resultats

## Licence

AGPLv3 - Code libre, modifications a partager.
