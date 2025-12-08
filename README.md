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
| Methode | Mediane | Différence entre Partisans et Opposants |
| Votes ignores | ceux qui n'ont pas voté pour le gagnant | ceux qui sont sans avis |

Le Vote Nuance prend en compte **tous les votants ayant un avis**, contrairement au Jugement Majoritaire qui utilise la mediane et peut ignorer les votes dès que la mediane est depassee, soit un peu moins de la moitie des votes.

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
├── scrutin-view.php        # Vue detail d'un scrutin (+ gestion jetons)
├── scrutin-results.php     # Resultats avec graphiques
│
├── vote.php                # Interface de vote + recepisse
├── verify.php              # Verification de vote (via cle secrete)
├── export-pdf.php          # Export PDF des resultats (impression)
├── upload.php              # Endpoint upload images
├── uploads/                # Dossier images uploadees
│
├── BACKLOG.md              # Backlog Agile avec User Stories
├── TODO.md                 # Liste des taches
└── README.md               # Ce fichier
```

### URLs

Grace au `.htaccess`, les URLs sont simplifiees :
- `/CODE` : Page de vote
- `/CODE/v/` : Vue du scrutin (sans voter)
- `/CODE/s/` : Modification du scrutin (proprietaire)
- `/CODE/r/` : Resultats du scrutin
- `/CODE/pdf/` : Export PDF des resultats
- `/verify` : Page de verification de vote
- `/verify/[cle]` : Verification directe avec cle

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
- [x] Archivage des scrutins (protection contre modification si votes)

### Vote
- [x] Interface responsive (desktop et mobile)
- [x] Sans Avis selectionne par defaut
- [x] Images cliquables (lightbox plein ecran)
- [x] Cle de verification individuelle
- [x] Recepisse de vote imprimable avec QR code
- [x] Page de verification accessible sans connexion (`/verify`)

### Resultats
- [x] Algorithme Vote Nuance complet
- [x] Normalisation automatique des Sans Avis manquants
- [x] 2 graphiques : ordre initial et classement
- [x] Affichage QCM, prefere du lot et reponses ouvertes
- [x] Export CSV des resultats
- [x] Export PDF des resultats (format A4 paysage avec graphiques)

### Scrutins prives (jetons)
- [x] Verification du jeton a l'acces au vote
- [x] Generation de jetons (1 a 500 par lot)
- [x] Suivi des jetons (statut, date utilisation)
- [x] Revocation des jetons non utilises
- [x] Export CSV des jetons avec liens et statut

### Navigation
- [x] Menu unifie sur toutes les pages
- [x] Pas d'impasse de navigation

## Scrutins publics vs prives

### Scrutin public (`est_public = 1`)
- Accessible a tous sans authentification
- Pas de jeton requis
- Ideal pour consultations ouvertes

### Scrutin prive (`est_public = 0`)
- Necessite un **jeton d'invitation** pour voter
- L'organisateur genere des jetons depuis la page du scrutin
- Chaque jeton = 1 vote maximum
- Le jeton peut etre transmis par :
  - Lien direct : `https://site.com/CODE?jeton=ABCD1234`
  - Saisie manuelle sur la page de vote

### Cycle de vie d'un jeton

```
[Generation] -> [Distribution] -> [Utilisation] -> [Marque utilise]
                     |
                     v
              [Revocation] (si non utilise)
```

1. **Generation** : L'organisateur genere N jetons depuis `scrutin-view.php`
2. **Distribution** : Export CSV ou copie des liens pour envoi aux participants
3. **Utilisation** : Le participant accede au vote avec son jeton
4. **Verification** : Le systeme verifie que le jeton existe et n'est pas utilise
5. **Vote** : Si valide, le participant peut voter
6. **Marquage** : Apres le vote, le jeton est marque comme utilise

### Format des jetons

- 8 caracteres alphanumeriques
- Majuscules + chiffres (sans I, O, 0, 1 pour eviter confusion)
- Exemple : `ABCD1234`, `XY7KM3NP`

### Interface organisateur

Sur la page du scrutin (`/CODE/s/` ou vue detail), l'organisateur voit :
- **Statistiques** : Total / Utilises / Disponibles
- **Formulaire** : Generer X jetons
- **Tableau** : Liste complete avec statut et actions
- **Export** : Boutons "Copier tous les liens" et "Exporter CSV"

## Verification de vote

Apres avoir vote, chaque participant recoit :
- Un **recepisse imprimable** avec le recapitulatif de ses choix
- Un **QR code** pointant vers la page de verification
- Une **cle secrete** de 64 caracteres

Pour verifier son vote :
1. Aller sur `/verify`
2. Saisir la cle ou scanner le QR code
3. Le systeme affiche le recepisse complet

Cette verification prouve que le vote a ete enregistre sans reveler l'identite du votant.

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

### Termine
- [x] Authentification SSO (Google/Microsoft)
- [x] Gestion des donnees personnelles
- [x] Schema BDD complet
- [x] Interface de creation/modification de scrutin
- [x] Upload d'images avec lightbox
- [x] Interface de vote responsive
- [x] Calcul et affichage des resultats (algorithme Vote Nuance)
- [x] Verification individuelle (ballot_secret)
- [x] Navigation unifiee
- [x] Gestion des jetons (scrutins prives)
- [x] Export CSV des resultats
- [x] Export PDF des resultats (A4 paysage avec graphiques)
- [x] Question "Prefere du lot"
- [x] Recepisse de vote imprimable avec QR code
- [x] Page de verification de vote (`/verify`)
- [x] Archivage des scrutins

### A venir
- [ ] Paiement Stripe pour les jetons (priorite haute)
- [ ] Emails de notification (priorite basse)
- [ ] Mode sombre (priorite basse)

## Backlog

Voir [BACKLOG.md](BACKLOG.md) pour le backlog Agile complet avec User Stories.

### Prochaine priorite
- **US-013** : Paiement Stripe pour les jetons (1EUR/jeton)

## Deploiement

Le script `deploy.sh` permet de deployer sur le serveur FTP :

```bash
./deploy.sh
```

Application en production : https://app.decision-collective.fr

## Licence

AGPLv3 - Code libre, modifications a partager.
