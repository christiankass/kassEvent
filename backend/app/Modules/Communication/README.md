# Module Communication : billets par WhatsApp et Messenger

L'e-mail part toujours (module Ticketing). En plus, l'acheteur peut recevoir ses billets (ou invitations) :

- **WhatsApp** : numéro donné à l'achat (facultatif) ou depuis sa page de commande. Un message par billet, image du billet comprise.
- **Messenger** : depuis sa page de commande, bouton « Recevoir sur Messenger ».

Les envois sont dans la table `deliveries` (une ligne par commande et par canal, statut suivi dans le panneau Ventes).
Mode `simulated` par défaut (`WHATSAPP_MODE`, `MESSENGER_MODE`) : rien ne part, refusé en production.

## Pourquoi Messenger passe par un lien

Une page Facebook ne peut écrire qu'à une personne qui lui a écrit dans les dernières 24 heures, et les « tags »
qui permettaient de confirmer un achat ou un événement en dehors de cette fenêtre ont été retirés par Meta.
On ne connaît pas non plus l'identifiant Messenger d'un acheteur à partir de son e-mail ou de son numéro.

Le bouton ouvre donc `m.me/<page>?ref=<envoi>_<secret>` (lien à usage unique, secret stocké haché). Quand la personne
touche « Démarrer » (ou écrit), Meta nous transmet son identifiant avec le `ref` : les billets partent aussitôt,
dans la fenêtre de 24 heures. Un lien déjà utilisé, ou dont le secret est faux, n'envoie rien.

## Passage en réel (`live`)

Prérequis : application Meta (developers.facebook.com) avec les produits WhatsApp et Messenger, entreprise vérifiée.

1. **Notifications** : adresse `{APP_URL}/api/v1/webhooks/meta`, jeton de vérification `META_WEBHOOK_VERIFY_TOKEN`.
   Abonnements : WhatsApp Business Account → `messages` ; Page → `messages`, `messaging_postbacks`, `messaging_referrals`, `messaging_optins`.
   Toutes les notifications sont vérifiées avec `META_APP_SECRET` (en-tête `X-Hub-Signature-256`).
2. **WhatsApp** : `WHATSAPP_TOKEN` (jeton d'utilisateur système permanent), `WHATSAPP_PHONE_NUMBER_ID`.
   Faire approuver deux modèles, catégorie « Utilité », en `fr` et en `en` :
   - `kassevent_billet` et `kassevent_invitation`, en-tête **Image**, bouton **URL** `https://app.kassevent.com/commande/{{1}}` (« Voir ma commande »).
   - Corps (5 variables, dans cet ordre : titulaire, type de billet, événement, date, code), par exemple :
     « Bonjour {{1}}, voici votre billet {{2}} pour {{3}}, le {{4}}. Présentez ce QR code à l'entrée. Code : {{5}} »
     et pour l'invitation : « Bonjour {{1}}, voici votre invitation {{2}} pour {{3}}, le {{4}}. Présentez ce QR code à l'entrée. Code : {{5}} ».
   - Pour les campagnes (module Campaigns) : `kassevent_annonce`, catégorie **« Marketing »**, en `fr` et en `en`.
     Corps (3 variables : contact, organisation, texte de l'organisateur), par exemple :
     « Bonjour {{1}}, une nouvelle de {{2}} : {{3}} ».
     Boutons, dans cet ordre : **URL** `https://app.kassevent.com/api/v1/public/campaigns/{{1}}` (« Voir l'événement »),
     puis **réponse rapide** « Ne plus recevoir » (sa charge utile, fixée à l'envoi, désinscrit le contact).
     Une annonce ne part qu'aux contacts qui ont accepté les nouvelles ; chaque message « Marketing » est facturé par Meta.
3. **Messenger** : `MESSENGER_PAGE_TOKEN` (jeton de la page, permission `pages_messaging`), `MESSENGER_PAGE_USERNAME`.
   Activer le bouton « Démarrer » de la page (sans lui, une première conversation ouverte par le lien ne nous transmet pas le `ref`) :
   `POST https://graph.facebook.com/v23.0/me/messenger_profile` avec `{"get_started": {"payload": "GET_STARTED"}}`.

Chaque message WhatsApp est facturé par Meta (modèle « Utilité ») : un billet = un message.
