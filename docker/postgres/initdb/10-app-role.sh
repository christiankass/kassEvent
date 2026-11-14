#!/bin/sh
# Crée le rôle applicatif : propriétaire de la base mais ni superutilisateur ni BYPASSRLS,
# pour que la Row-Level Security s'applique à toutes les requêtes de l'application.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  -v app_password="$APP_DB_PASSWORD" <<'SQL'
CREATE ROLE kassevent LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD :'app_password';
CREATE DATABASE kassevent OWNER kassevent;
CREATE DATABASE kassevent_test OWNER kassevent;
SQL
