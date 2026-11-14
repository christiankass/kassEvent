# kassEvent

Plateforme SaaS de billetterie et d'invitations, multi-organisation (multi-tenant).

## Documents de référence
- `docs/PRD.md` : ce que fait le produit et pour qui.
- `docs/DESIGN.md` : couleurs, polices, composants (billet, écran de scan).
- Dossier de conception technique : https://claude.ai/artifact/FihX2PtAD7HKGyjkT5zHWo

## Structure
- `backend/` : API Laravel (REST/JSON sous `/api/v1`), modules métier dans `app/Modules/`.
- `frontend/` : Next.js (App Router, TypeScript).
- `docker/`, `docker-compose.yml` : Nginx, PHP-FPM, workers, PostgreSQL, Redis, MinIO.

## Règles
- Isolation des données : toute table métier porte `organization_id` ; l'isolation est garantie côté serveur et base (scope global + RLS PostgreSQL), jamais seulement par l'interface.
- Une ressource d'une autre organisation renvoie `404`, jamais `403`.
- Langue de l'interface et des messages : français et anglais.

## Commandes
- Backend : `cd backend && composer install && php artisan test && ./vendor/bin/pint --test`
- Frontend : `cd frontend && npm ci && npm run lint && npm run build`
- Tout lancer : `cp backend/.env.example backend/.env && docker compose up -d --build`

## Workflow
- `/branche` avant chaque développement ; `code-review`, `simplify` et `security-review` avant chaque PR.
