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

Exception unique : `tenant_keys` (module Tenancy, `TenantKeys`) n'a ni RLS ni trait. C'est un index global
« identifiant aléatoire → organisation » pour les requêtes qui arrivent sans organisation (lien de commande
de l'acheteur, notifications pawaPay, Stripe et Meta). Il ne sert qu'à ouvrir le bon contexte : la ligne elle-même
est ensuite lue sous les deux verrous, et l'accès est encore contrôlé (clé de la commande, statut redemandé au prestataire).

Les tests tournent sur PostgreSQL avec un rôle sans `BYPASSRLS` (voir `phpunit.xml` et la CI).

## Connexion (module Identity)

Le site Next.js se connecte par cookie de session (Sanctum SPA) : il appelle `GET /sanctum/csrf-cookie`,
puis `POST /api/v1/auth/login` en envoyant ses cookies (`credentials: "include"`) et l'en-tête `X-XSRF-TOKEN`.
Aucun jeton n'est stocké dans le navigateur. L'en-tête `Accept-Language` (fr ou en) choisit la langue des messages.

## Paiements (modules Ticketing et Payments)

- Ticketing : commandes sans compte (places réservées `ORDER_HOLD_MINUTES`, 45 minutes au plus avec les nouveaux essais),
  billets émis au paiement et envoyés par e-mail (file `notifications`).
- Payments : pawaPay (mobile money) et Stripe Checkout (carte), ou mode `simulated` sans compte (refusé en production).
  Une notification de prestataire ne valide jamais rien seule : le statut est redemandé au prestataire, montant et devise contrôlés.
- Un paiement réussi émet `PaymentSucceeded`, écouté par Ticketing (`ConfirmOrder`).

## Billets par WhatsApp et Messenger (module Communication)

- Table `deliveries` : un envoi par commande et par canal. Lancé à l'émission des billets (événement `TicketsIssued` de Ticketing).
- Messenger passe par un lien m.me à usage unique (une page ne peut écrire qu'à qui lui a écrit) ; notifications Meta signées sur `/webhooks/meta`.
- Détails et passage en réel : `app/Modules/Communication/README.md`.

## Statistiques (module Analytics)

- Table `event_visits` : visites de la page publique, un compteur par jour (heure du lieu) et par source. Aucune donnée sur le visiteur. Le navigateur ne signale qu'une visite par session (30 par minute au plus par adresse IP) : un ordre de grandeur, pas un chiffre certifié.
- Source : le lien partagé porte `?src=whatsapp` (facebook, tiktok, qr…). La page la retient 30 jours dans le navigateur et la joint à la commande (`orders.referral`). Liste fermée dans `Analytics\Support\Referral` : une valeur inconnue devient `other`.
- `GET /events/{id}/stats` et `GET /events/{id}/stats/export` (CSV comptable) : permission `reports.view`, double authentification pour les rôles argent. Le CSV neutralise les cellules qui commencent par `=`, `+`, `-` ou `@` (formules de tableur).

## Carnet de contacts et campagnes e-mail (modules Audience et Campaigns)

- `contacts` : acheteurs et invités d'une organisation. Seuls ceux qui ont coché « Recevoir les nouvelles » (jamais par défaut) et ne se sont pas désinscrits sont joignables (`Contact::reachable`).
- `campaigns` et `campaign_messages` : une campagne fige ses destinataires au départ (un envoi par contact joignable), puis part par lots de 50 dans la file `notifications`. Chaque message est réservé avant l'envoi : un lot relancé ne renvoie jamais un e-mail. Les chiffres (envoyés, ouvertures, clics) se comptent sur `campaign_messages`.
- Programmation : `campaigns:dispatch` (chaque minute) lance les campagnes arrivées à l'heure ; un événement dépublié entre-temps renvoie la campagne en brouillon.
- Chaque e-mail porte le lien de désinscription et l'en-tête `List-Unsubscribe` (un clic, RFC 8058). Le bouton passe par `/public/campaigns/{id}/messages/{id}/click` puis mène à la page de l'événement avec `?src=email&cmp=…` : la commande qui suit porte `orders.campaign_id` (ventes rapportées).
- Sans service d'envoi (`MAIL_MAILER=log`), l'écran Campagnes affiche « mode démo ». Pour envoyer vraiment : un service SMTP ou API (Brevo, Mailgun, Amazon SES) dans `MAIL_*`, avec SPF, DKIM et DMARC configurés sur le domaine d'envoi.
