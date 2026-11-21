# kassEvent

Plateforme SaaS de billetterie et d'invitations pour les événements, multi-organisation.

## Démarrer en local

```bash
cp .env.example .env                      # puis choisir les mots de passe MinIO et PostgreSQL
cp backend/.env.example backend/.env
docker compose run --rm api php artisan key:generate --show   # copier la clé dans APP_KEY de backend/.env
docker compose up -d --build
docker compose exec api php artisan migrate
```

Tests du backend dans Docker : `docker compose exec api php artisan test` (base `kassevent_test`).

Si une base avait été créée avant le socle multi-organisation (rôle superutilisateur), la recréer une fois :
`docker compose down -v` puis relancer. La Row-Level Security ne protège rien avec un superutilisateur.

Les images envoyées (affiches, photos du studio) sont dans le volume Docker `media`, partagé par l'API, les workers et Nginx (`/storage/...`) : elles survivent aux reconstructions, pas à `docker compose down -v`.

- API : http://localhost:8080/api/v1/health
- Interface : http://localhost:3000

Voir `CLAUDE.md` pour la structure et les règles, `docs/` pour le PRD et le design.
