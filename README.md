# Vote Nuance - DECO v2

Plateforme de vote nuance avec cloisonnement vote/votant et respect de la vie privee.

## Objectif

Permettre des consultations democratiques avec une methode de vote plus expressive que le simple Oui/Non : chaque participant peut nuancer son avis sur une echelle a 7 mentions, de "Absolument Contre" a "Absolument Pour".

## Principes fondamentaux

- **Separation vote/votant** : impossible de relier un bulletin a son auteur
- **Verification individuelle** : chaque votant peut verifier que son vote a ete comptabilise (via cle secrete)
- **Triple comptage** : jetons utilises = emargements = bulletins uniques
- **Zero dependance** : PHP natif uniquement (+ Chart.js pour les graphiques)
- **RGPD compliant** : minimisation des donnees, consentement explicite

## Vote Nuance vs Jugement Majoritaire

**Attention** : Le Vote Nuance n'est PAS le Jugement Majoritaire.

| Aspect | Jugement Majoritaire | Vote Nuance |
|--------|---------------------|-------------|
| Methode | Mediane | Somme ponderee |
| Votes ignores | 50% | 0% |
| Sans Avis | Compte comme vote | Poids 0.5 |

Le Vote Nuance prend en compte **tous les votants ayant un avis**, contrairement au Jugement Majoritaire qui utilise la mediane et ignore la moitie des votes.

## Echelle de vote

| Mention | Code | Couleur |
|---------|------|---------|
| Absolument Contre | AC | #D32F2F |
| Franchement Contre | FC | #F57C00 |
| Plutot Contre | PC | #FBC02D |
| Sans Avis | SA | #9E9E9E |
| Plutot Pour | PP | #C0CA33 |
| Franchement Pour | FP | #7CB342 |
| Absolument Pour | AP | #388E3C |

### Calcul du classement

```
Classement = AP + FP + PP + (SA / 2)
```

En cas d'egalite, departage par cascade :
1. **Niveau 1** : AP - AC (avis absolus)
2. **Niveau 2** : FP - FC (avis francs)
3. **Niveau 3** : PP - PC (avis normaux)

### Taux de partisans net

```
Taux = (Partisans - Opposants) / Participants
     = (AP + FP + PP - AC - FC - PC) / Total
```

## Architecture technique

### Prerequis

- PHP 7.4+ (recommande : PHP 8.x)
- MariaDB 10.3+ ou MySQL 5.7+
- Apache avec mod_rewrite (ou Nginx)
- HTTPS active (obligatoire en production)
- Extensions PHP : `curl`, `json`, `pdo_mysql`, `fileinfo`

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
├── functions.php           # Fonctions utilitaires (BDD, navigation, etc.)
├── database.sql            # Schema minimal (users SSO)
├── database.schema.sql     # Schema complet DECO v2
├── .htaccess               # URL rewriting
│
├── index.php               # Page d'accueil
├── login.php               # Page de connexion SSO
├── oauth-redirect.php      # Redirection vers Google/Microsoft
├── callback.php            # Traitement du retour OAuth
├── logout.php              # Deconnexion
│
├── dashboard.php           # Mon compte
├── my-data.php             # Gestion des donnees personnelles
├── mes-scrutins.php        # Liste des scrutins de l'utilisateur
│
├── scrutin-create.php      # Creation d'un scrutin
├── scrutin-edit.php        # Modification d'un scrutin
├── scrutin-view.php        # Vue detail d'un scrutin
├── scrutin-results.php     # Resultats avec graphiques
│
├── vote.php                # Interface de vote
├── upload.php              # Endpoint upload images
├── uploads/                # Dossier images uploadees
│
├── TODO.md                 # Liste des taches
└── README.md               # Ce fichier
```

### URLs

Grace au `.htaccess`, les URLs sont simplifiees :
- `/CODE` ou `/CODE/v/` : Page de vote
- `/CODE/s/` : Modification du scrutin (proprietaire)
- `/CODE/r/` : Resultats du scrutin

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

### 4. Permissions dossier uploads

```bash
mkdir uploads
chmod 755 uploads
```

## Fonctionnalites

### Scrutins
- [x] Creation avec 5 types de questions (vote nuance, QCM, ouverte, separateur, prefere du lot)
- [x] Image par scrutin et par question (upload direct)
- [x] Dates debut/fin optionnelles
- [x] Option "Afficher resultats avant cloture"
- [x] Option "Scrutin public" (sans connexion requise)
- [x] Ordre des mentions configurable (Pour->Contre ou Contre->Pour)

### Vote
- [x] Interface responsive (desktop et mobile)
- [x] Sans Avis selectionne par defaut
- [x] Images cliquables (lightbox plein ecran)
- [x] Cle de verification individuelle

### Resultats
- [x] Algorithme Vote Nuance complet
- [x] Normalisation automatique des Sans Avis manquants
- [x] 2 graphiques : ordre initial et classement
- [x] Affichage QCM et reponses ouvertes

### Navigation
- [x] Menu unifie sur toutes les pages
- [x] Pas d'impasse de navigation

## Securite

- HTTPS obligatoire en production
- Tokens CSRF pour toutes les actions sensibles
- Protection XSS via `htmlspecialchars()`
- Requetes preparees (anti-injection SQL)
- Sessions securisees (httponly, secure, samesite)
- Secrets hors de la racine web
- Separation stricte vote/votant
- Validation MIME type pour uploads
- Noms de fichiers aleatoires (pas de path traversal)

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
- [x] Interface de creation/modification de scrutin
- [x] Upload d'images
- [x] Interface de vote
- [x] Calcul et affichage des resultats (algorithme Vote Nuance)
- [x] Verification individuelle (ballot_secret)
- [x] Navigation unifiee
- [ ] Gestion des jetons (scrutins prives)
- [ ] Export des resultats (CSV/PDF)
- [ ] Emails de notification

## Licence

AGPLv3 - Code libre, modifications a partager.
