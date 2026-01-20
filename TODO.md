# Vote Nuance - TODO

## Fait

### Infrastructure
- [x] Schema BDD complet (database.schema.sql) - 8 tables + 2 vues
- [x] Fichier migrations incrementales (database.migrations.sql)
- [x] Configuration securisee (config.php avec secrets externes)
- [x] Fonctions CRUD scrutins/questions/reponses (functions.php)
- [x] URL rewriting (.htaccess) : /CODE, /CODE/s/, /CODE/r/, /CODE/v/

### Interface
- [x] mes-scrutins.php - Liste des scrutins utilisateur
- [x] scrutin-create.php - Creation scrutin complet (5 types de questions)
- [x] scrutin-edit.php - Modification scrutin
- [x] scrutin-view.php - Vue detail scrutin
- [x] vote.php - Interface de vote responsive
  - [x] Boutons uniformes (meme taille)
  - [x] Mode portrait : boutons empiles verticalement
  - [x] Parametre ordre_mentions (C->P ou P->C)
  - [x] Sans Avis selectionne par defaut
  - [x] Images cliquables avec lightbox plein ecran
- [x] scrutin-results.php - Page resultats avec graphiques

### Resultats Vote Nuance
- [x] Algorithme Vote Nuance (different du Jugement Majoritaire)
  - [x] Classement = AP + FP + PP + (SA/2)
  - [x] 3 niveaux de departage : niveau1 (AP-AC), niveau2 (FP-FC), niveau3 (PP-PC)
- [x] Normalisation : ajout Sans Avis manquants pour egaliser les totaux
- [x] 2 graphiques Chart.js barres empilees horizontales
  - [x] Graphique 1 : Ordre initial des questions
  - [x] Graphique 2 : Classement par taux de partisans net
  - [x] Barre blanche de decalage (alignement visuel)
- [x] Message mode portrait (ecran < 640px)

### Navigation et UX
- [x] Menu de navigation unifie (toutes les pages connectees)
  - [x] Logo "Vote Nuance"
  - [x] Liens : Mes scrutins, Nouveau, Mon compte, Deconnexion
  - [x] Style gradient violet
  - [x] Indicateur page active
- [x] Suppression des impasses de navigation

### Upload d'images
- [x] Endpoint upload.php
  - [x] Verification authentification et CSRF
  - [x] Types acceptes : JPG, PNG, GIF, WebP
  - [x] Taille max : 5 Mo
  - [x] Validation MIME type et getimagesize()
  - [x] Noms de fichiers uniques (hash hex)
  - [x] Dossier /uploads/
- [x] Integration formulaires (scrutin-create.php, scrutin-edit.php)
  - [x] Bouton "Choisir une image" avec apercu
  - [x] Barre de progression
  - [x] Bouton suppression image
  - [x] Fonctionne pour scrutin ET questions
- [x] Affichage images dans vote.php
  - [x] Images centrees
  - [x] Clic = lightbox plein ecran
  - [x] Fermeture : clic fond, bouton X, touche Echap

### Corrections
- [x] Liens relatifs -> absolus (404 avec URL rewriting)
- [x] deploy.sh retire du git (securite)
- [x] README mis a jour
- [x] Titre resultats : "Classement par taux de partisans net"
- [x] Suppression ligne stats inutile sous legende
- [x] Bug classement ex aequo corrige (operateur spaceship)
- [x] Bug getTokenStats() -> countTokens() dans mes-scrutins.php

### Gestion des lots
- [x] Question "Prefere du lot" (type=3)
- [x] Melange aleatoire des questions d'un lot > 0 (anti-biais d'ordre)
- [x] Options "Prefere du lot" generees automatiquement depuis les titres du lot
- [x] Validation : lot > 0 n'accepte que type 0 et 3
- [x] Resultats par lot (un graphe de classement par lot)
- [x] Pas de graphe "ordre initial" pour lots > 0 (ordre aleatoire)

### Export resultats
- [x] Export CSV des resultats (avec sections par lot)
- [x] Export PDF des resultats (format A4, impression navigateur)
- [x] Recepisse de vote avec QR code

### Conservation des donnees en cas d'erreur
- [x] Les questions saisies sont conservees si erreur a la creation

### Paiement Stripe
- [x] Integration Stripe Checkout (1 EUR/jeton)
- [x] Webhook Stripe pour confirmation paiement
- [x] Page de succes apres paiement
- [x] Table `achats` pour historique

### UX scrutins prives (US-015)
- [x] Info-bulle sur option "Scrutin prive" (creation)
- [x] Message de rappel apres creation scrutin prive
- [x] Alerte sur page scrutin si prive et 0 jetons
- [x] Badge dans liste scrutins si prive sans jetons

### Graphique participation (US-014)
- [x] Graphique evolution emargements dans le temps
- [x] Select cumul / par periode
- [x] Granularite auto (minute/heure/jour)
- [x] Axe X proportionnel au temps (echelle temporelle)

### Export/Import XLS (Epic 7)
- [x] US-016 : Export scrutin en XLS (structure multi-onglets)
- [x] US-017 : Import scrutin depuis XLS (cree nouveau scrutin)
- [x] US-018 : Export votes en XLS avec formules (pedagogique)
- [x] US-019 : Import votes (fusion offline/online, mode ajouter/remplacer)
- [x] Format XML Spreadsheet (sans dependance externe)

### Echelles flexibles (Epic 8)
- [x] **US-020 : Echelles flexibles (3/5/7 mentions)**
  - Choix au niveau du scrutin (pas de la question)
  - 3 mentions : Contre / Sans Avis / Pour
  - 5 mentions : FC / C / SA / P / FP
  - 7 mentions : AC / FC / PC / SA / PP / FP / AP (defaut)
  - Fichiers modifies : scrutin-create.php, scrutin-edit.php, vote.php, scrutin-results.php, votes-export.php, functions.php
  - Migration BDD : colonne nb_mentions dans scrutins

### Issue #1 : Import scrutin - choix du code URL
- [x] Champ Code URL pre-rempli lors de l'import XLS
- [x] Validation format (minuscules, chiffres, tirets)
- [x] Verification unicite du code
- [x] Generation automatique si champ vide

### Configuration et securite
- [x] Architecture secrets : dossier cousin ../secret/ hors git
- [x] config.php charge ../secret/sso.php (prod) ou sso-test.php (test)
- [x] config.php dans .gitignore (jamais versionne)
- [x] Bandeau orange test sur toutes les pages (IS_TEST_ENV)

## A tester (sur tst.de-co.fr)

### Export/Import admin (admin-export.php)
- [ ] Export d'un scrutin avec ses votes
- [ ] Import du fichier SQL genere
- [ ] Verification integrite des donnees importees

### Authentification Magic Link (login-magiclink.php)
- [ ] Demande de lien de connexion par email
- [ ] Reception et validation du lien
- [ ] Connexion sans mot de passe

### TOTP / 2FA (totp-verify.php)
- [ ] Activation 2FA sur un compte
- [ ] Scan QR code avec app authenticator
- [ ] Verification code TOTP a la connexion

### Parametres securite (settings/security.php)
- [ ] Acces a la page settings
- [ ] Configuration options securite utilisateur
- [ ] Sauvegarde des preferences

### Export scrutin individuel (export-scrutin.php)
- [ ] Export d'un scrutin specifique
- [ ] Format du fichier genere

## A faire

### Notifications (priorite basse)
- [ ] US-007 : Email de confirmation de vote
- [ ] US-008 : Email notification nouveaux resultats

### Ameliorations UX (priorite basse)
- [ ] US-009 : Drag & drop pour upload images
- [ ] US-010 : Compression automatique des images
- [ ] US-011 : Mode sombre

### Maintenance (priorite basse)
- [ ] US-012 : Suppression automatique anciennes images non utilisees

### Tests unitaires (priorite moyenne)
- [ ] Installer PHPUnit et configurer phpunit.xml
- [ ] Creer dossier tests/ avec structure PSR-4
- [ ] Tests admin-export.php :
  - [ ] Test generation SQL sans DELETE (import non destructif)
  - [ ] Test gestion codes dupliques (code_imp1, code_imp2...)
  - [ ] Test signature HMAC (generation et verification)
  - [ ] Test validation fichier import (extension, taille, signature)
- [ ] Tests functions.php :
  - [ ] Test getMentionsForScale() pour echelles 3/5/7
  - [ ] Test calculateVoteNuanceScore()
  - [ ] Test calculateTiebreakers()
- [ ] Tests scrutin-create.php :
  - [ ] Test validation donnees scrutin
  - [ ] Test creation avec differentes echelles
- [ ] Integrer tests dans workflow CI/CD (GitHub Actions)

## Notes techniques

### Deploiement FTP
```
Serveur: 86412.ftp.infomaniak.com
User: 86412_claude
Site: app.decision-collective.fr
```

### Git
```
Remote: git@github.com:AlexJade23/VoteNuance.git
Branche: main
```

### Echelle 7 mentions
1. Absolument Contre (AC) - #D32F2F
2. Franchement Contre (FC) - #F57C00
3. Plutot Contre (PC) - #FBC02D
4. Sans Avis (SA) - #9E9E9E
5. Plutot Pour (PP) - #C0CA33
6. Franchement Pour (FP) - #7CB342
7. Absolument Pour (AP) - #388E3C

### Calcul Vote Nuance
```
Classement = AP + FP + PP + (SA / 2)

Departage en cas d'egalite :
- Niveau 1 : AP - AC (avis absolus)
- Niveau 2 : FP - FC (avis francs)
- Niveau 3 : PP - PC (avis normaux)

Taux partisans net = (Pour - Contre) / Total
```

### Difference avec Jugement Majoritaire
Le Jugement Majoritaire utilise la mediane, ignorant 50% des votes.
Le Vote Nuance prend en compte TOUS les votants ayant un avis.
