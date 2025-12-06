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
- [x] scrutin-results.php - Page resultats avec graphique barres verticales

### Corrections
- [x] Liens relatifs -> absolus (404 avec URL rewriting)
- [x] deploy.sh retire du git (securite)
- [x] README mis a jour

## A faire

### Graphique resultats (scrutin-results.php)
- [ ] **PRIORITE** : Corriger le graphique en barres verticales
  - [ ] Toutes les barres doivent avoir la meme hauteur totale
  - [ ] Sans Avis : moitie au-dessus, moitie en-dessous de la ligne centrale
  - [ ] La ligne grise ne doit jamais couper un Pour ou un Contre
  - [ ] Si pas de Sans Avis : ligne entre Pour et Contre
  - [ ] **Attente formules utilisateur**

### Fonctionnalites manquantes
- [ ] Verification jeton pour scrutins prives
- [ ] Gestion des lots (prefere du lot)
- [ ] Export resultats (CSV, PDF?)
- [ ] Emails de notification

### Base de donnees
- [ ] Executer migration ordre_mentions sur production

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

### Calcul du score
Score = (AP + FP + PP) - (AC + FC + PC)
