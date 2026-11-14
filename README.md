# kassEvent

Plateforme SaaS de billetterie et d'invitations pour les événements, multi-organisation.

## Démarrer en local

```bash
cp .env.example .env                      # puis choisir un mot de passe MinIO
cp backend/.env.example backend/.env
docker compose run --rm api php artisan key:generate --show   # copier la clé dans APP_KEY de backend/.env
docker compose up -d --build
docker compose exec api php artisan migrate
```

- API : http://localhost:8080/api/v1/health
- Interface : http://localhost:3000

Voir `CLAUDE.md` pour la structure et les règles, `docs/` pour le PRD et le design.
