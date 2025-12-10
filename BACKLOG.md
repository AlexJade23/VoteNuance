# Vote Nuance - Backlog Agile

## Legende

**Priorite** : Critique > Haute > Moyenne > Basse
**Estimation** : XS (1h) | S (2-4h) | M (1 jour) | L (2-3 jours) | XL (1 semaine+)
**Statut** : A faire | En cours | Done | Bloque

---

## Epic 1 : Gestion des scrutins prives

### US-001 : Verification jeton scrutin prive
**Statut** : Done | **Priorite** : Critique | **Estimation** : M

**En tant que** organisateur de scrutin prive
**Je veux** que seuls les detenteurs d'un jeton valide puissent voter
**Afin de** garantir que seules les personnes invitees participent

#### Criteres d'acceptation
- [x] Le scrutin prive demande un jeton a l'acces
- [x] Jeton invalide = message d'erreur clair
- [x] Jeton deja utilise = refus avec explication
- [x] Un jeton = un vote maximum
- [x] L'URL avec jeton fonctionne : /CODE?jeton=XXX

#### Taches techniques
- [x] Modifier vote.php : verifier jeton si scrutin prive
- [x] Ajouter formulaire de saisie jeton si absent de l'URL
- [x] Verifier dans table `jetons` : existe + non utilise
- [x] Marquer jeton comme utilise apres vote

#### Fichiers concernes
- vote.php
- functions.php (fonction verification jeton)

---

### US-002 : Generation de jetons par l'organisateur
**Statut** : Done | **Priorite** : Critique | **Estimation** : M

**En tant que** organisateur de scrutin prive
**Je veux** pouvoir generer des jetons d'invitation
**Afin de** distribuer des droits de vote aux participants

#### Criteres d'acceptation
- [x] Bouton "Generer des jetons" sur la page du scrutin
- [x] Choix du nombre de jetons a generer (1 a 500)
- [x] Jetons uniques et non predictibles (format court, ex: 8 caracteres alphanumeriques)
- [x] Liste des jetons generes affichee + copiable
- [x] Export CSV des jetons avec URLs completes

#### Taches techniques
- [x] Ajouter section jetons dans scrutin-view.php
- [x] Fonction generateTokens($scrutin_id, $count) dans functions.php
- [x] Insertion batch dans table `jetons`
- [x] Interface affichage liste jetons avec statut (utilise/disponible)
- [x] Bouton "Copier tous les liens"
- [x] Export CSV

#### Fichiers concernes
- scrutin-view.php
- functions.php

---

### US-003 : Suivi des jetons distribues
**Statut** : Done | **Priorite** : Haute | **Estimation** : S

**En tant que** organisateur de scrutin prive
**Je veux** voir le statut de chaque jeton (utilise ou non)
**Afin de** suivre la participation sans connaitre l'identite des votants

#### Criteres d'acceptation
- [x] Tableau listant tous les jetons du scrutin
- [x] Colonnes : jeton (masque partiellement), statut, date utilisation
- [x] Compteur : X jetons utilises / Y total
- [x] Possibilite de revoquer un jeton non utilise

#### Taches techniques
- [x] Ajouter vue liste jetons dans scrutin-view.php
- [x] Fonction getTokensStats($scrutin_id) -> countTokens()
- [x] Action POST pour revoquer un jeton

#### Fichiers concernes
- scrutin-view.php
- functions.php

---

## Epic 2 : Export des resultats

### US-004 : Export CSV des resultats
**Statut** : Done | **Priorite** : Haute | **Estimation** : S

**En tant que** organisateur de scrutin
**Je veux** exporter les resultats en CSV
**Afin de** les analyser dans un tableur ou les archiver

#### Criteres d'acceptation
- [x] Bouton "Exporter CSV" sur la page resultats
- [x] CSV contient : question, mentions (AC a AP), total, classement
- [x] Encodage UTF-8 avec BOM (compatibilite Excel)
- [x] Nom fichier : resultats_CODE_DATE.csv

#### Taches techniques
- [x] Ajouter bouton export dans scrutin-results.php
- [x] Generer CSV via JavaScript (pas besoin d'endpoint separe)
- [x] Generer CSV avec headers corrects
- [x] Inclure resultats QCM et questions ouvertes

#### Fichiers concernes
- scrutin-results.php
- export-csv.php (nouveau)
- functions.php

---

### US-005 : Export PDF des resultats
**Statut** : Done | **Priorite** : Moyenne | **Estimation** : L

**En tant que** organisateur de scrutin
**Je veux** exporter les resultats en PDF
**Afin de** les partager ou les imprimer proprement

#### Criteres d'acceptation
- [x] Bouton "Exporter PDF" sur la page resultats
- [x] PDF contient : titre scrutin, date, tableau resultats
- [x] Mise en page propre format A4
- [x] Nom fichier : via impression navigateur

#### Implementation
Solution retenue : page HTML optimisee pour impression (CSS @media print)
plutot qu'une librairie PDF externe. Avantages :
- Pas de dependance externe
- Maintenance simplifiee
- Qualite native du navigateur

#### Fichiers concernes
- scrutin-results.php (bouton Export PDF)
- export-pdf.php (nouveau - page optimisee impression)
- .htaccess (route /CODE/pdf/)

---

## Epic 3 : Types de questions avances

### US-006 : Question "Prefere du lot"
**Statut** : Done | **Priorite** : Moyenne | **Estimation** : M

**En tant que** votant
**Je veux** pouvoir designer mon prefere parmi un groupe d'options
**Afin de** exprimer ma preference relative dans un lot

#### Criteres d'acceptation
- [x] Type de question "prefere_lot" dans le formulaire creation (deja existant type=3)
- [x] Interface vote : selection unique parmi les options du lot
- [x] Resultats : classement par nombre de votes
- [x] Affichage barres avec gagnant en vert
- [x] Melange aleatoire des questions d'un lot > 0 (anti-biais d'ordre)
- [x] Options generees automatiquement depuis les titres des questions Vote Nuance du lot
- [x] Validation : lot > 0 n'accepte que type 0 (Vote Nuance) et type 3 (Prefere du lot)
- [x] Resultats affiches par lot (un graphe de classement par lot)
- [x] Pas de graphe "ordre initial" pour lots > 0 (car ordre aleatoire)

#### Taches techniques
- [x] Type "prefere_lot" deja present dans scrutin-create.php
- [x] Modifier vote.php pour gerer ce type (radio buttons)
- [x] Modifier scrutin-results.php pour afficher resultats
- [x] Export CSV inclut les resultats "prefere du lot"
- [x] Fonction shuffleQuestionsInLots() pour melange aleatoire
- [x] Fonction getQuestionTitlesForLot() pour generer les options
- [x] Validation des lots dans scrutin-create.php et scrutin-edit.php
- [x] Groupement des resultats par lot dans scrutin-results.php
- [x] Conservation des questions saisies en cas d'erreur

#### Fichiers concernes
- scrutin-create.php
- scrutin-edit.php
- vote.php
- scrutin-results.php
- functions.php

---

## Epic 4 : Monetisation

### US-013 : Paiement Stripe pour les jetons
**Statut** : Done | **Priorite** : Haute | **Estimation** : L

**En tant que** organisateur de scrutin prive
**Je veux** acheter des jetons de vote via Stripe
**Afin de** financer le service et obtenir mes jetons instantanement

#### Criteres d'acceptation
- [x] Panier affichant le nombre de jetons et le prix (1EUR/jeton)
- [x] Integration Stripe Checkout pour le paiement
- [x] Confirmation de paiement avant generation des jetons
- [x] Historique des achats par utilisateur
- [x] Gestion des erreurs de paiement

#### Taches techniques
- [x] Creer compte Stripe et obtenir cles API (test puis production)
- [x] Configurer webhook Stripe pour confirmation paiement
- [x] Ajouter table `achats` en base (user_id, scrutin_id, nb_jetons, montant, stripe_session_id, status, created_at)
- [x] Modifier scrutin-view.php : panier avant generation
- [x] Creer stripe-checkout.php : creation session Stripe
- [x] Creer stripe-webhook.php : reception confirmation paiement
- [x] Creer stripe-success.php : page de succes post-paiement
- [x] Generer les jetons uniquement apres confirmation webhook

#### Dependances
- Cles API Stripe (pk_test_*, sk_test_* puis pk_live_*, sk_live_*)
- Cle webhook Stripe (whsec_*)
- Compte Stripe verifie pour les paiements en production

#### Questions ouvertes
- Seuil gratuit avant facturation ? (ex: 10 jetons gratuits)
- TVA a appliquer ? (1EUR TTC ou HT + 20%)
- Facture PDF a generer ?

#### Fichiers concernes
- scrutin-view.php (panier)
- stripe-checkout.php (nouveau)
- stripe-webhook.php (nouveau)
- stripe-success.php (nouveau)
- functions.php (fonctions paiement)
- database.migrations.sql (table achats)
- config.php (cles Stripe)

---

## Epic 5 : Notifications

### US-007 : Email de confirmation de vote
**Statut** : A faire | **Priorite** : Basse | **Estimation** : M

**En tant que** votant
**Je veux** recevoir un email de confirmation apres mon vote
**Afin de** garder une trace avec ma cle de verification

#### Criteres d'acceptation
- [ ] Email envoye apres validation du vote
- [ ] Contient : nom du scrutin, date/heure, cle de verification (ballot_secret)
- [ ] Lien vers la page de verification
- [ ] Option pour l'organisateur d'activer/desactiver cette fonctionnalite

#### Taches techniques
- [ ] Fonction sendVoteConfirmation($email, $scrutin, $ballot_secret)
- [ ] Template email HTML
- [ ] Ajouter option dans scrutin-create.php
- [ ] Gerer le cas ou l'email n'est pas connu (scrutin public)

#### Dependances
- Necessite consentement email de l'utilisateur
- Configuration SMTP serveur

#### Fichiers concernes
- vote.php
- functions.php
- scrutin-create.php
- scrutin-edit.php

---

### US-008 : Email notification nouveaux resultats
**Statut** : A faire | **Priorite** : Basse | **Estimation** : S

**En tant que** organisateur de scrutin
**Je veux** etre notifie quand quelqu'un vote
**Afin de** suivre la participation en temps reel

#### Criteres d'acceptation
- [ ] Option "Me notifier a chaque vote" dans parametres scrutin
- [ ] Email resume : X votes, derniere participation il y a Y minutes
- [ ] Frequence configurable : chaque vote / resume quotidien / jamais

#### Taches techniques
- [ ] Ajouter preferences notification dans scrutin
- [ ] Fonction sendParticipationNotification()
- [ ] Cron job pour resumes quotidiens (optionnel)

#### Fichiers concernes
- scrutin-create.php
- scrutin-edit.php
- vote.php
- functions.php

---

### US-015 : Amelioration UX scrutins prives sans jetons
**Statut** : Done | **Priorite** : Haute | **Estimation** : S

**En tant que** organisateur de scrutin prive
**Je veux** etre clairement informe que je dois generer des jetons pour permettre le vote
**Afin de** ne pas oublier cette etape indispensable

#### Criteres d'acceptation
- [x] Info-bulle explicative a cote de l'option "Scrutin prive" dans le formulaire de creation
- [x] Message de rappel apres la creation d'un scrutin prive (redirection vers page scrutin)
- [x] Alerte visible sur la page du scrutin si prive et 0 jetons disponibles
- [x] Badge d'avertissement dans la liste "Mes scrutins" pour les scrutins prives sans jetons

#### Taches techniques
- [x] scrutin-create.php : ajouter info-bulle sur le champ "est_public"
- [x] scrutin-create.php : rediriger vers scrutin-view.php avec parametre ?created=private
- [x] scrutin-view.php : afficher alerte si est_public=0 et jetons disponibles=0
- [x] mes-scrutins.php : afficher badge "[Aucun jeton]" si scrutin prive sans jetons

#### Fichiers concernes
- scrutin-create.php
- scrutin-view.php
- mes-scrutins.php

---

### US-014 : Graphique evolution de la participation dans le temps
**Statut** : A faire | **Priorite** : Moyenne | **Estimation** : M

**En tant que** organisateur de scrutin
**Je veux** voir un graphique montrant l'evolution du nombre d'emargements dans le temps
**Afin de** suivre la dynamique de participation et identifier les pics de vote

#### Criteres d'acceptation
- [ ] Graphique Chart.js sur la page resultats (visible uniquement par le createur)
- [ ] Select pour choisir le mode d'affichage :
  - Cumul (courbe montante)
  - Par periode (barres)
- [ ] Granularite automatique selon la duree du scrutin :
  - Scrutin < 1 jour : granularite a la minute
  - Scrutin 1-7 jours : granularite a l'heure
  - Scrutin > 7 jours : granularite au jour
- [ ] Axe X : temps (minutes/heures/jours)
- [ ] Axe Y : nombre d'emargements (cumule ou par periode)
- [ ] Affichage du nombre total de participants

#### Taches techniques
- [ ] Requete SQL pour agreger les emargements par periode (GROUP BY)
- [ ] Fonction getParticipationTimeline($scrutinId) dans functions.php
- [ ] Detection automatique de la granularite selon date debut/fin ou premier/dernier vote
- [ ] Integration graphique Chart.js dans scrutin-results.php
- [ ] Format responsive (adapte mobile)

#### Fichiers concernes
- scrutin-results.php
- functions.php

---

## Epic 7 : Export/Import XLSX

### US-016 : Export scrutin en XLSX
**Statut** : A faire | **Priorite** : Moyenne | **Estimation** : M

**En tant que** organisateur de scrutin
**Je veux** exporter la structure de mon scrutin en fichier Excel
**Afin de** le partager avec d'autres utilisateurs ou le sauvegarder

#### Criteres d'acceptation
- [ ] Bouton "Exporter XLSX" sur la page du scrutin
- [ ] Fichier Excel multi-onglets :
  - Onglet "Scrutin" : metadonnees (titre, resume, dates, options)
  - Onglet "Questions" : liste avec type, lot, ordre, image_url
  - Onglet "Reponses QCM" : question (ref), libelle, ordre
- [ ] Images exportees via URL (colonne image_url)
- [ ] Nom fichier : scrutin_CODE_DATE.xlsx

#### Taches techniques
- [ ] Installer PhpSpreadsheet via Composer
- [ ] Creer scrutin-export.php
- [ ] Fonction exportScrutinToXlsx($scrutinId)
- [ ] Generer les 3 onglets avec mise en forme

#### Dependances
- PhpSpreadsheet (composer require phpoffice/phpspreadsheet)

#### Fichiers concernes
- scrutin-export.php (nouveau)
- scrutin-view.php (bouton export)
- functions.php

---

### US-017 : Import scrutin depuis XLSX
**Statut** : A faire | **Priorite** : Moyenne | **Estimation** : M

**En tant que** utilisateur
**Je veux** importer un scrutin depuis un fichier Excel
**Afin de** creer un scrutin a partir d'un modele partage

#### Criteres d'acceptation
- [ ] Page d'import avec upload de fichier XLSX
- [ ] Previsualisation avant creation
- [ ] Creation d'un NOUVEAU scrutin (jamais d'ecrasement)
- [ ] L'importateur devient proprietaire du nouveau scrutin
- [ ] Images : telechargement si URL accessible, sinon ignorees
- [ ] Validation du format (colonnes attendues presentes)
- [ ] Limite taille fichier (ex: 5 Mo)

#### Taches techniques
- [ ] Creer scrutin-import.php
- [ ] Fonction importScrutinFromXlsx($file, $userId)
- [ ] Validation structure du fichier
- [ ] Telechargement images depuis URLs
- [ ] Lien dans navigation "Importer un scrutin"

#### Fichiers concernes
- scrutin-import.php (nouveau)
- functions.php
- mes-scrutins.php (lien vers import)

---

### US-018 : Export votes/resultats en XLSX avec formules
**Statut** : A faire | **Priorite** : Moyenne | **Estimation** : L

**En tant que** organisateur de scrutin
**Je veux** exporter les resultats en Excel avec des formules
**Afin de** comprendre les calculs et simuler des scenarios

#### Criteres d'acceptation
- [ ] Bouton "Exporter XLSX" sur la page resultats
- [ ] Fichier Excel multi-onglets avec FORMULES (pas de valeurs calculees) :
  - Onglet "Resume" : titre, dates, =SOMME() participants
  - Onglet "Votes bruts" : Question, AC, FC, PC, SA, PP, FP, AP
  - Onglet "Calculs" : formules Vote Nuance
    - =AP+FP+PP+(SA/2) → Classement
    - =AP-AC → Niveau 1 departage
    - =FP-FC → Niveau 2 departage
    - =PP-PC → Niveau 3 departage
    - =(AP+FP+PP-AC-FC-PC)/Total → Taux partisans net
  - Onglet "QCM" : question, reponse, nb, =nb/total*100
  - Onglet "Reponses ouvertes" : question, texte
  - Onglet "Prefere du lot" : lot, option, nb, =nb/total*100
- [ ] Aspect pedagogique : modification des votes bruts → recalcul instantane

#### Taches techniques
- [ ] Creer votes-export.php
- [ ] Fonction exportVotesToXlsx($scrutinId)
- [ ] Generer formules Excel (pas de valeurs fixes)
- [ ] Mise en forme conditionnelle (couleurs mentions)

#### Fichiers concernes
- votes-export.php (nouveau)
- scrutin-results.php (bouton export)
- functions.php

---

### US-019 : Import votes depuis XLSX (fusion offline/online)
**Statut** : A faire | **Priorite** : Moyenne | **Estimation** : L

**En tant que** organisateur de scrutin
**Je veux** importer des votes depuis un fichier Excel
**Afin de** fusionner des votes hors ligne avec les votes en ligne

#### Criteres d'acceptation
- [ ] Bouton "Importer votes" sur la page resultats (visible uniquement par le createur)
- [ ] Upload fichier XLSX avec structure attendue :
  - Question (titre exact) | AC | FC | PC | SA | PP | FP | AP
- [ ] Option au choix de l'utilisateur :
  - "Ajouter" : votes importes + votes existants
  - "Remplacer" : votes importes ecrasent les existants
- [ ] Validation bijective : bloquer si une question du fichier n'existe pas dans le scrutin
- [ ] Tracabilite : flag `est_importe` et date d'import sur les bulletins
- [ ] Previsualisation avant import

#### Taches techniques
- [ ] Creer votes-import.php
- [ ] Fonction importVotesFromXlsx($file, $scrutinId, $mode)
- [ ] Validation bijective questions fichier <-> scrutin
- [ ] Ajouter colonne `est_importe` et `imported_at` dans table bulletins
- [ ] Migration SQL pour nouvelles colonnes
- [ ] Gestion mode "ajouter" vs "remplacer"

#### Fichiers concernes
- votes-import.php (nouveau)
- scrutin-results.php (bouton import)
- functions.php
- database.migrations.sql

---

## Epic 6 : Ameliorations UX

### US-009 : Drag & drop upload images
**Statut** : A faire | **Priorite** : Basse | **Estimation** : S

**En tant que** organisateur de scrutin
**Je veux** pouvoir glisser-deposer des images
**Afin de** simplifier l'ajout d'illustrations

#### Criteres d'acceptation
- [ ] Zone de drop visuelle sur les champs image
- [ ] Feedback visuel au survol (bordure, couleur)
- [ ] Meme validation que l'upload classique
- [ ] Fonctionne en parallele du bouton "Choisir fichier"

#### Taches techniques
- [ ] Ajouter event listeners dragover/drop
- [ ] Reutiliser logique upload existante
- [ ] Styling zone de drop

#### Fichiers concernes
- scrutin-create.php
- scrutin-edit.php

---

### US-010 : Compression automatique des images
**Statut** : A faire | **Priorite** : Basse | **Estimation** : S

**En tant que** organisateur de scrutin
**Je veux** que les images soient automatiquement optimisees
**Afin de** reduire les temps de chargement

#### Criteres d'acceptation
- [ ] Images redimensionnees si > 1920px de large
- [ ] Compression JPEG qualite 85%
- [ ] Conversion en WebP si supporte par le navigateur
- [ ] Taille finale < 500 Ko si possible

#### Taches techniques
- [ ] Utiliser GD ou Imagick dans upload.php
- [ ] Redimensionner proportionnellement
- [ ] Compresser selon le format
- [ ] Garder l'original en backup (optionnel)

#### Dependances
- Extension PHP GD ou Imagick

#### Fichiers concernes
- upload.php

---

### US-011 : Mode sombre
**Statut** : A faire | **Priorite** : Basse | **Estimation** : M

**En tant que** utilisateur
**Je veux** pouvoir activer un mode sombre
**Afin de** reduire la fatigue visuelle

#### Criteres d'acceptation
- [ ] Toggle mode clair/sombre dans le menu
- [ ] Preference sauvegardee (localStorage ou cookie)
- [ ] Respect de prefers-color-scheme du systeme par defaut
- [ ] Toutes les pages coherentes en mode sombre

#### Taches techniques
- [ ] Definir palette de couleurs sombre
- [ ] Variables CSS pour les couleurs
- [ ] Toggle dans navigation
- [ ] Persistence preference utilisateur

#### Fichiers concernes
- functions.php (navigation)
- Toutes les pages (styles inline a factoriser)

---

## Epic 7 : Maintenance

### US-012 : Nettoyage images orphelines
**Statut** : A faire | **Priorite** : Basse | **Estimation** : S

**En tant que** administrateur
**Je veux** que les images non utilisees soient supprimees automatiquement
**Afin de** liberer de l'espace disque

#### Criteres d'acceptation
- [ ] Script de nettoyage identifie les images non referencees
- [ ] Images orphelines > 7 jours supprimees
- [ ] Log des suppressions
- [ ] Peut etre lance manuellement ou en cron

#### Taches techniques
- [ ] Script cleanup-images.php
- [ ] Scan table scrutins + questions pour images utilisees
- [ ] Comparer avec contenu dossier uploads/
- [ ] Supprimer les fichiers orphelins anciens

#### Fichiers concernes
- cleanup-images.php (nouveau)
- cron configuration (documentation)

---

## Ordre de realisation suggere

### Sprint 1 - Scrutins prives (Priorite Critique)
1. US-001 : Verification jeton scrutin prive
2. US-002 : Generation de jetons par l'organisateur
3. US-003 : Suivi des jetons distribues

### Sprint 2 - Exports (Priorite Haute)
4. US-004 : Export CSV des resultats

### Sprint 3 - Fonctionnalites avancees (Priorite Moyenne)
5. US-006 : Question "Prefere du lot"
6. US-005 : Export PDF des resultats

### Sprint 4 - Monetisation (Priorite Haute)
7. US-013 : Paiement Stripe pour les jetons

### Sprint 5 - Notifications (Priorite Basse)
8. US-007 : Email de confirmation de vote
9. US-008 : Email notification nouveaux resultats

### Sprint 6 - Polish UX (Priorite Basse)
10. US-009 : Drag & drop upload images
11. US-010 : Compression automatique des images
12. US-011 : Mode sombre
13. US-012 : Nettoyage images orphelines

---

## Notes

### Convention de commit
```
[US-XXX] Description courte

Description detaillee si necessaire
```

### Definition of Done
- [ ] Code fonctionnel et teste manuellement
- [ ] Pas de regression sur les fonctionnalites existantes
- [ ] Code securise (XSS, CSRF, SQL injection)
- [ ] Responsive (mobile + desktop)
- [ ] TODO.md mis a jour
