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

---

## Epic 3 : Types de questions avances

### US-006 : Question "Prefere du lot"
**Statut** : Done | **Priorite** : Moyenne | **Estimation** : M

**En tant que** votant
**Je veux** pouvoir designer mon prefere parmi un groupe d'options
**Afin de** exprimer ma preference relative dans un lot

#### Criteres d'acceptation
- [x] Type de question "prefere_lot" dans le formulaire creation
- [x] Interface vote : selection unique parmi les options du lot
- [x] Resultats : classement par nombre de votes
- [x] Affichage barres avec gagnant en vert
- [x] Melange aleatoire des questions d'un lot > 0 (anti-biais d'ordre)
- [x] Options generees automatiquement depuis les titres des questions Vote Nuance du lot
- [x] Validation : lot > 0 n'accepte que type 0 (Vote Nuance) et type 3 (Prefere du lot)
- [x] Resultats affiches par lot (un graphe de classement par lot)

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

#### Dependances
- Necessite consentement email de l'utilisateur
- Configuration SMTP serveur

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

---

### US-014 : Graphique evolution de la participation dans le temps
**Statut** : Done | **Priorite** : Moyenne | **Estimation** : M

**En tant que** organisateur de scrutin
**Je veux** voir un graphique montrant l'evolution du nombre d'emargements dans le temps
**Afin de** suivre la dynamique de participation et identifier les pics de vote

#### Criteres d'acceptation
- [x] Graphique Chart.js sur la page resultats (visible uniquement par le createur)
- [x] Select pour choisir le mode d'affichage : Cumul (courbe) / Par periode (barres)
- [x] Granularite automatique selon la duree du scrutin
- [x] Axe X : echelle de temps proportionnelle
- [x] Axe Y : nombre d'emargements (cumule ou par periode)
- [x] Affichage du nombre total de participants

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

---

## Epic 7 : Export/Import XLS

### US-016 : Export scrutin en XLS
**Statut** : Done | **Priorite** : Moyenne | **Estimation** : M

**En tant que** organisateur de scrutin
**Je veux** exporter la structure de mon scrutin en fichier Excel
**Afin de** le partager avec d'autres utilisateurs ou le sauvegarder

#### Criteres d'acceptation
- [x] Bouton "Exporter XLS" sur la page du scrutin
- [x] Fichier XML Spreadsheet multi-onglets (sans dependance externe)
- [x] Images exportees via URL (colonne image_url)
- [x] Nom fichier : scrutin_CODE_DATE.xls

---

### US-017 : Import scrutin depuis XLS
**Statut** : Done | **Priorite** : Moyenne | **Estimation** : M

**En tant que** utilisateur
**Je veux** importer un scrutin depuis un fichier Excel
**Afin de** creer un scrutin a partir d'un modele partage

#### Criteres d'acceptation
- [x] Page d'import avec upload de fichier XLS
- [x] Previsualisation avant creation
- [x] Creation d'un NOUVEAU scrutin (jamais d'ecrasement)
- [x] L'importateur devient proprietaire du nouveau scrutin
- [x] Validation du format (colonnes attendues presentes)

---

### US-018 : Export votes/resultats en XLS avec formules
**Statut** : Done | **Priorite** : Moyenne | **Estimation** : L

**En tant que** organisateur de scrutin
**Je veux** exporter les resultats en Excel avec des formules
**Afin de** comprendre les calculs et simuler des scenarios

#### Criteres d'acceptation
- [x] Bouton "Exporter XLS" sur la page resultats
- [x] Fichier XML Spreadsheet multi-onglets avec FORMULES
- [x] Aspect pedagogique : modification des votes bruts = recalcul instantane

---

### US-019 : Import votes depuis XLS (fusion offline/online)
**Statut** : Done | **Priorite** : Moyenne | **Estimation** : L

**En tant que** organisateur de scrutin
**Je veux** importer des votes depuis un fichier Excel
**Afin de** fusionner des votes hors ligne avec les votes en ligne

#### Criteres d'acceptation
- [x] Bouton "Importer XLS" sur la page resultats (visible uniquement par le createur)
- [x] Upload fichier XLS avec onglet "Votes bruts"
- [x] Option au choix : "Ajouter" ou "Remplacer"
- [x] Validation bijective : bloquer si question du fichier n'existe pas dans le scrutin
- [x] Tracabilite : flag `est_importe` et date d'import sur les bulletins
- [x] Previsualisation avant import

---

## Epic 8 : Flexibilite des echelles de vote

### US-020 : Centralisation du nombre de mentions par scrutin
**Statut** : A faire | **Priorite** : Moyenne | **Estimation** : L

**En tant que** organisateur de scrutin
**Je veux** choisir le nombre de mentions (3, 5 ou 7) au niveau du scrutin
**Afin de** simplifier la configuration et garantir la coherence entre toutes les questions

#### Criteres d'acceptation
- [ ] Choix du nombre de mentions a la creation du scrutin (defaut : 7)
- [ ] Toutes les questions Vote Nuance du scrutin utilisent la meme echelle
- [ ] 3 echelles disponibles :
  - 3 mentions : Contre / Sans Avis / Pour
  - 5 mentions : Franchement Contre / Contre / Sans Avis / Pour / Franchement Pour
  - 7 mentions : AC / FC / PC / SA / PP / FP / AP (actuel)
- [ ] Interface de vote adaptee au nombre de mentions
- [ ] Graphiques resultats adaptes (couleurs et labels)
- [ ] Export CSV/XLS adapte au nombre de mentions
- [ ] Scrutins existants (7 mentions) non impactes

#### Taches techniques
- [ ] Ajouter colonne `nb_mentions` (TINYINT DEFAULT 7) dans table `scrutins`
- [ ] Migration SQL (Migration 004)
- [ ] Modifier scrutin-create.php : select pour choisir 3/5/7 mentions
- [ ] Modifier scrutin-edit.php : afficher le nombre de mentions (non modifiable si votes)
- [ ] Modifier vote.php : afficher uniquement les mentions de l'echelle choisie
- [ ] Modifier scrutin-results.php : adapter graphiques et calculs
- [ ] Modifier functions.php : fonction getMentionsForScale($nb) retournant les mentions
- [ ] Adapter exports CSV/XLS au nombre de mentions

#### Calcul Vote Nuance adapte par echelle

| Echelle | Formule classement |
|---------|-------------------|
| 3 | P + (SA / 2) |
| 5 | FP + P + (SA / 2) |
| 7 | AP + FP + PP + (SA / 2) |

---

## Epic 9 : Maintenance

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

---

## Resume par statut

### Done (14 US)
- US-001 : Verification jeton scrutin prive
- US-002 : Generation de jetons par l'organisateur
- US-003 : Suivi des jetons distribues
- US-004 : Export CSV des resultats
- US-005 : Export PDF des resultats
- US-006 : Question "Prefere du lot"
- US-013 : Paiement Stripe pour les jetons
- US-014 : Graphique evolution de la participation
- US-015 : Amelioration UX scrutins prives
- US-016 : Export scrutin en XLS
- US-017 : Import scrutin depuis XLS
- US-018 : Export votes en XLS avec formules
- US-019 : Import votes depuis XLS

### A faire (7 US)
| US | Description | Priorite | Estimation |
|----|-------------|----------|------------|
| US-020 | Echelles flexibles (3/5/7 mentions) | Moyenne | L |
| US-007 | Email de confirmation de vote | Basse | M |
| US-008 | Email notification nouveaux resultats | Basse | S |
| US-009 | Drag & drop upload images | Basse | S |
| US-010 | Compression automatique des images | Basse | S |
| US-011 | Mode sombre | Basse | M |
| US-012 | Nettoyage images orphelines | Basse | S |

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
