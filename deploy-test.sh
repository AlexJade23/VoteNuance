#!/bin/bash

# Déploiement VoteNuance-test vers tst.de-co.fr
# Les credentials sont chargés depuis le serveur distant (secret/ftp-test.sh)

# Couleurs pour l'affichage
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
ORANGE='\033[0;33m'
NC='\033[0m' # No Color

echo ""
echo -e "${ORANGE}======================================"
echo "  ⚠️  DEPLOIEMENT ENVIRONNEMENT TEST"
echo "  tst.de-co.fr"
echo "  Branche: feature/us-020-echelles-flexibles"
echo -e "======================================${NC}"
echo ""

# Vérifier que lftp est installé
if ! command -v lftp &> /dev/null; then
    echo -e "${RED}Erreur: lftp n'est pas installé${NC}"
    echo "Installez-le avec: sudo apt-get install lftp"
    exit 1
fi

# Vérifier qu'on est dans le bon dossier
CURRENT_DIR=$(basename "$PWD")
if [ "$CURRENT_DIR" != "VoteNuance-test" ]; then
    echo -e "${RED}Erreur: Ce script doit être lancé depuis VoteNuance-test/${NC}"
    echo "Dossier actuel: $CURRENT_DIR"
    exit 1
fi

# Vérifier la branche git
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$CURRENT_BRANCH" == "main" ]; then
    echo -e "${RED}Erreur: Vous êtes sur la branche main !${NC}"
    echo "Ce script est pour l'environnement de test uniquement."
    exit 1
fi

echo -e "${YELLOW}Branche actuelle: $CURRENT_BRANCH${NC}"
echo ""

# Liste des fichiers à déployer
FILES_TO_UPLOAD="callback.php config.php dashboard.php database.sql database.schema.sql database.migrations.sql functions.php login.php logout.php my-data.php oauth-redirect.php vote.php scrutin-view.php scrutin-create.php scrutin-edit.php scrutin-results.php mes-scrutins.php index.php upload.php export-pdf.php verify.php stripe-checkout.php stripe-webhook.php stripe-success.php scrutin-export.php scrutin-import.php votes-export.php votes-import.php install-db.php .htaccess"

echo -e "${GREEN}Fichiers à uploader:${NC}"
echo "$FILES_TO_UPLOAD" | tr ' ' '\n'
echo ""

# Demander confirmation
read -p "Confirmer le déploiement vers tst.de-co.fr ? (o/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Oo]$ ]]; then
    echo "Déploiement annulé."
    exit 0
fi

# Connexion et upload via lftp
# Note: les credentials sont dans /secret/ftp-test.sh sur ce serveur
echo -e "${YELLOW}Connexion au serveur FTP...${NC}"

# Charger les credentials
source ../secret/ftp-test.sh

lftp -c "
set ftp:ssl-allow no
set ssl:verify-certificate no
open -u $FTP_TEST_USER,$FTP_TEST_PASS $FTP_TEST_HOST
cd $FTP_TEST_DIR
lcd $(dirname "$0")
mput $FILES_TO_UPLOAD
bye
"

# Vérifier le résultat
if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}======================================"
    echo -e "  Déploiement TEST réussi!"
    echo -e "======================================${NC}"
    echo ""
    echo "Application de test accessible sur: https://tst.de-co.fr"
    echo ""
    echo -e "${ORANGE}N'oubliez pas: C'est un environnement de TEST${NC}"
else
    echo ""
    echo -e "${RED}======================================"
    echo -e "  Erreur lors du déploiement"
    echo -e "======================================${NC}"
    exit 1
fi
