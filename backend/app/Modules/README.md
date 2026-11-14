# Modules métier

Chaque module vit dans `app/Modules/<Module>` avec ses modèles, contrôleurs, services, politiques, événements et tests.
Voir le dossier de conception technique (section 3) pour la liste et les règles de dépendance :

- Core / Tenancy, Identity, Access : modules socles.
- Events, Ticketing, Registration, Participants, Payments, Check-in, Invitations, Communication, Analytics, Billing, Audit : modules métier.
- Un module n'accède jamais aux tables d'un autre module : il passe par un contrat publié ou un événement Laravel.

## Isolation des données (module Tenancy)

Toute nouvelle table métier :

1. porte une colonne `organization_id` (`foreignUuid(...)->constrained()`) ;
2. appelle `RowLevelSecurity::enable('<table>')` dans sa migration (second verrou, PostgreSQL) ;
3. a un modèle qui utilise le trait `BelongsToOrganization` (premier verrou, Eloquent).

Les routes qui agissent dans une organisation passent par le middleware `tenant`
(en-tête `X-Organization-Id`) ; une organisation inaccessible renvoie `404`.
Hors requête HTTP (jobs, commandes), utiliser `app(TenantContext::class)->run($organizationId, fn () => ...)`.

Les tests tournent sur PostgreSQL avec un rôle sans `BYPASSRLS` (voir `phpunit.xml` et la CI).
