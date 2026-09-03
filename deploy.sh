#!/usr/bin/env bash

set -Eeuo pipefail

REMOTE_HOST="jdr-compagnon@ssh-jdr-compagnon.alwaysdata.net"
REMOTE_HOME="/home/jdr-compagnon"
REMOTE_REPOSITORY="$REMOTE_HOME/www/jdr-companion"
REMOTE_FRONTEND="$REMOTE_HOME/www/jdr-compagnon-public"

FRONTEND_ARCHIVE="jdr-companion-frontend.tar.gz"
FRONTEND_BUILD_DIRECTORY="frontend/dist/jdr-companion/browser"

echo "Vérification de la branche..."
CURRENT_BRANCH="$(git branch --show-current)"

if [[ "$CURRENT_BRANCH" != "main" ]]; then
  echo "Déploiement annulé : la branche courante est '$CURRENT_BRANCH'."
  echo "Le déploiement doit être lancé depuis main."
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Déploiement annulé : le dépôt contient des modifications non commitées."
  exit 1
fi

echo "Construction du frontend Angular..."
docker compose exec -T frontend npm run build

echo "Création de l’archive frontend..."
tar -czf "$FRONTEND_ARCHIVE" \
  -C "$FRONTEND_BUILD_DIRECTORY" \
  .

echo "Envoi du frontend..."
scp "$FRONTEND_ARCHIVE" \
  "$REMOTE_HOST:$REMOTE_HOME/$FRONTEND_ARCHIVE"

echo "Mise à jour du backend et installation du frontend..."
ssh "$REMOTE_HOST" "
  set -e

  cd '$REMOTE_REPOSITORY'
  git pull --ff-only

  cd '$REMOTE_REPOSITORY/backend'

  APP_ENV=prod composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

  APP_ENV=prod php bin/console doctrine:migrations:migrate \
    --no-interaction

  APP_ENV=prod php bin/console cache:clear

  mkdir -p '$REMOTE_FRONTEND'

  tar -xzf '$REMOTE_HOME/$FRONTEND_ARCHIVE' \
    -C '$REMOTE_FRONTEND'

  rm '$REMOTE_HOME/$FRONTEND_ARCHIVE'
"

rm "$FRONTEND_ARCHIVE"

echo "Vérification de l’API..."
curl --fail --silent --show-error \
  "https://jdr-compagnon.alwaysdata.net/api/health"

echo
echo "Déploiement terminé."
