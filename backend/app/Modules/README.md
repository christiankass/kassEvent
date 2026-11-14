# Modules métier

Chaque module vit dans `app/Modules/<Module>` avec ses modèles, contrôleurs, services, politiques, événements et tests.
Voir le dossier de conception technique (section 3) pour la liste et les règles de dépendance :

- Core / Tenancy, Identity, Access : modules socles.
- Events, Ticketing, Registration, Participants, Payments, Check-in, Invitations, Communication, Analytics, Billing, Audit : modules métier.
- Un module n'accède jamais aux tables d'un autre module : il passe par un contrat publié ou un événement Laravel.
