# Système d'authentification SSO minimal (Google + Microsoft)

Authentification respectueuse de la vie privée avec stockage minimal des données.

## 🎯 Philosophie

- **Zéro dépendance** : PHP natif uniquement
- **Vie privée first** : stockage minimal (seulement l'ID SSO)
- **Consentement explicite** : l'utilisateur choisit quelles données stocker
- **Open Source** : code 100% compréhensible et auditable
- **RGPD compliant** : minimisation des données par défaut

## 📋 Prérequis

- PHP 7.4+ (recommandé : PHP 8.x)
- MariaDB 10.3+ ou MySQL 5.7+
- Apache avec mod_rewrite (ou Nginx)
- HTTPS activé (obligatoire en production)
- Extensions PHP : `curl`, `json`, `pdo_mysql`

## 🚀 Installation

### 1. Configuration de la base de données

```bash
# Se connecter à MariaDB
mysql -u root -p

# Importer le schéma
mysql -u root -p < database.sql
```

Ou manuellement :
```sql
CREATE DATABASE sso_minimal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sso_minimal;
-- Copier le contenu de database.sql
```

### 2. Configuration Google OAuth

1. Aller sur [Google Cloud Console](https://console.cloud.google.com)
2. Créer un nouveau projet (ou sélectionner un projet existant)
3. Activer l'API "Google+ API" ou "Google Identity Services"
4. Aller dans **"Identifiants"** → **"Créer des identifiants"** → **"ID client OAuth 2.0"**
5. Type d'application : **Application Web**
6. Nom : `Mon application SSO`
7. URI de redirection autorisées : 
   - Développement : `http://localhost/callback.php`
   - Production : `https://votresite.com/callback.php`
8. Copier le **Client ID** et le **Client Secret**

### 3. Configuration Microsoft OAuth

1. Aller sur [Azure Portal](https://portal.azure.com)
2. Aller dans **"Azure Active Directory"** → **"App registrations"** → **"New registration"**
3. Nom : `Mon application SSO`
4. Types de comptes pris en charge : **Comptes dans un annuaire organisationnel et comptes personnels Microsoft**
5. URI de redirection :
   - Type : **Web**
   - URL développement : `http://localhost/callback.php`
   - URL production : `https://votresite.com/callback.php`
6. Une fois créé, copier l'**Application (client) ID**
7. Aller dans **"Certificates & secrets"** → **"New client secret"**
8. Copier le **Value** du secret (attention, il ne sera affiché qu'une fois !)

### 4. Configuration du fichier config.php

Éditer `config.php` et remplir les informations :

```php
// Base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'sso_minimal');
define('DB_USER', 'votre_utilisateur');
define('DB_PASS', 'votre_mot_de_passe');

// Google
define('GOOGLE_CLIENT_ID', 'VOTRE_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'VOTRE_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI', 'https://votresite.com/callback.php');

// Microsoft
define('MICROSOFT_CLIENT_ID', 'VOTRE_APPLICATION_ID');
define('MICROSOFT_CLIENT_SECRET', 'VOTRE_CLIENT_SECRET');
define('MICROSOFT_REDIRECT_URI', 'https://votresite.com/callback.php');
```

### 5. Déploiement

**Option A : Serveur Apache**
```bash
# Copier les fichiers dans le dossier web
cp -r sso-minimal/* /var/www/html/

# Vérifier les permissions
chown -R www-data:www-data /var/www/html/
chmod 755 /var/www/html/
```

**Option B : Serveur de développement PHP**
```bash
cd sso-minimal
php -S localhost:8000
```

⚠️ **IMPORTANT** : En production, HTTPS est OBLIGATOIRE !

### 6. Test

1. Ouvrir `https://votresite.com/login.php` dans votre navigateur
2. Cliquer sur "Se connecter avec Google" ou "Se connecter avec Microsoft"
3. Autoriser l'application
4. Vous devriez être redirigé vers le dashboard

## 📁 Structure des fichiers

```
sso-minimal/
├── config.php              # Configuration (credentials, BDD)
├── functions.php           # Fonctions utilitaires (BDD, JWT, etc.)
├── database.sql            # Schéma de la base de données
├── login.php               # Page de connexion
├── oauth-redirect.php      # Redirection vers Google/Microsoft
├── callback.php            # Traitement du retour OAuth
├── dashboard.php           # Page protégée (exemple)
├── logout.php              # Déconnexion
├── my-data.php             # Gestion des données personnelles
├── .htaccess               # Configuration Apache (sécurité)
└── README.md               # Ce fichier
```

## 🔒 Sécurité

### Points importants

✅ **HTTPS obligatoire** en production (Google et Microsoft refusent HTTP)  
✅ **Tokens CSRF** pour toutes les actions sensibles  
✅ **Protection XSS** : `htmlspecialchars()` sur toutes les sorties  
✅ **Requêtes préparées** : protection contre injection SQL  
✅ **Sessions sécurisées** : httponly, secure, samesite  
✅ **Validation des inputs** : vérification côté serveur  

### En production

1. **Activer HTTPS** (Let's Encrypt gratuit)
2. **Décommenter la redirection HTTPS** dans `.htaccess`
3. **Désactiver les erreurs PHP** :
```php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
```
4. **Sauvegardes régulières** de la base de données
5. **Mettre à jour PHP** régulièrement

## 🧪 Test en local

### Avec localhost

Google et Microsoft autorisent `http://localhost` pour le développement :

**Google :**
- URI autorisée : `http://localhost/callback.php` ou `http://localhost:8000/callback.php`

**Microsoft :**
- URI autorisée : `http://localhost/callback.php`

### Avec ngrok (alternative)

Si vous avez besoin de tester avec HTTPS en local :

```bash
# Installer ngrok
npm install -g ngrok

# Lancer votre serveur PHP
php -S localhost:8000

# Créer un tunnel HTTPS
ngrok http 8000

# Utiliser l'URL HTTPS fournie par ngrok dans vos configurations OAuth
```

## 📊 Données stockées

| Donnée | Stockage | Raison |
|--------|----------|--------|
| SSO ID (Google/Microsoft) | ✅ Toujours | Nécessaire pour reconnaître l'utilisateur |
| Hash email (SHA-256) | ⚠️ Si consentement | Éviter les doublons (optionnel) |
| Pseudo | ⚠️ Si fourni | Affichage public (optionnel) |
| Email en clair | ❌ Jamais | Respect vie privée |
| Nom/prénom | ❌ Jamais | Respect vie privée |

### Conformité RGPD

- ✅ Minimisation des données
- ✅ Consentement explicite (opt-in)
- ✅ Droit d'accès (page "Mes données")
- ✅ Droit de suppression (suppression compte + hash email)
- ✅ Droit à la portabilité (export SQL possible)
- ✅ Transparence (utilisateur voit exactement ce qui est stocké)

## 🐛 Debugging

### Erreur "state invalide"

Vérifiez que les sessions PHP fonctionnent :
```php
<?php
session_start();
echo "Session ID: " . session_id();
?>
```

### Erreur "code OAuth manquant"

Vérifiez que l'URI de redirection est **exactement** la même :
- Dans la console Google/Microsoft
- Dans `config.php`
- Attention à `http` vs `https`, aux ports, aux trailing slashes

### Erreur lors de l'échange du code

Activez les logs d'erreurs cURL :
```php
$response = curl_exec($ch);
if (curl_errno($ch)) {
    error_log('cURL Error: ' . curl_error($ch));
}
```

### JWT invalide

Vérifiez le format du token :
```php
var_dump($tokens);
var_dump(decodeJWT($tokens['id_token']));
```

## 🔧 Personnalisation

### Ajouter des champs utilisateur

1. Ajouter la colonne en BDD :
```sql
ALTER TABLE users ADD COLUMN preferences TEXT;
```

2. Modifier les fonctions dans `functions.php`

### Changer les couleurs

Modifier les CSS dans chaque fichier `.php` :
```css
/* Couleur principale */
background: linear-gradient(135deg, #VOTRE_COULEUR 0%, #AUTRE_COULEUR 100%);
```

### Intégration dans une app existante

Copier simplement `functions.php` et `config.php`, puis :

```php
require_once 'functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = getCurrentUser();
// Utiliser $user dans votre application
```

## 📝 TODO / Améliorations possibles

- [ ] Ajouter Apple Sign In
- [ ] Interface d'administration
- [ ] Logs d'audit (connexions, modifications)
- [ ] Rate limiting (protection brute force)
- [ ] 2FA (double authentification)
- [ ] Export des données utilisateur (RGPD)
- [ ] Multilingue
- [ ] Tests unitaires

## 📄 Licence

Code libre, utilisable sans restriction. Partagez, modifiez, améliorez !

## 🤝 Support

Pour toute question ou problème :
1. Vérifier que HTTPS est actif
2. Vérifier les URIs de redirection
3. Consulter les logs PHP (`tail -f /var/log/apache2/error.log`)
4. Vérifier que curl et json sont activés : `php -m | grep curl`

## ⚙️ Configuration avancée

### Nginx

Exemple de configuration Nginx :

```nginx
server {
    listen 443 ssl http2;
    server_name votresite.com;
    
    root /var/www/sso-minimal;
    index login.php;
    
    ssl_certificate /etc/letsencrypt/live/votresite.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/votresite.com/privkey.pem;
    
    location / {
        try_files $uri $uri/ /login.php;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    
    # Protéger les fichiers sensibles
    location ~ (config\.php|functions\.php|database\.sql)$ {
        deny all;
    }
}
```

### Variables d'environnement

Pour encore plus de sécurité, utilisez des variables d'environnement :

```bash
# .env (ne JAMAIS commiter ce fichier)
DB_HOST=localhost
DB_NAME=sso_minimal
GOOGLE_CLIENT_ID=xxx
GOOGLE_CLIENT_SECRET=xxx
```

Puis dans `config.php` :
```php
define('DB_HOST', getenv('DB_HOST'));
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID'));
```

## 🎉 C'est tout !

Vous avez maintenant un système d'authentification SSO minimaliste, sécurisé et respectueux de la vie privée.
