# PRD – kassEvent

## Problème

En RDC et en Afrique francophone, les organisateurs d'événements gèrent leurs ventes avec WhatsApp, des tableurs et des paiements mobile money vérifiés à la main. Les billets sont falsifiés ou revendus, des paiements se perdent entre une capture d'écran et un relevé, les files d'attente s'allongent à l'entrée et l'organisateur n'a aucune vue claire de ce qu'il a vendu et encaissé. Les plateformes de billetterie étrangères n'acceptent pas le mobile money, qui est le moyen de paiement de la majorité des participants. Les particuliers vivent le même problème pour leurs fêtes : les mariés ou la famille qui organise un anniversaire, un baptême ou une remise de dot invitent des centaines de personnes par messages WhatsApp, sans savoir qui vient vraiment, et des inconnus entrent avec une invitation transférée. Faire appel à une agence pour gérer les invitations coûte trop cher pour la plupart des familles. Le marché de l'événementiel grandit, et personne ne propose aujourd'hui un outil pensé pour ces conditions : paiement mobile, téléphone comme outil de travail principal, réseau instable le jour J.

## Solution

kassEvent est une plateforme en ligne où chaque organisation (organisateur indépendant, agence, entreprise, institution) dispose de son propre espace, isolé des autres. L'organisateur y crée ses événements, définit ses billets et ses prix, et publie une page de vente. Les participants achètent en mobile money ou par carte, et reçoivent leur billet avec QR code par e-mail, WhatsApp et Messenger. Le jour de l'événement, l'équipe d'accueil scanne les billets depuis un téléphone, même sans connexion Internet, et l'organisateur suit ses ventes et ses entrées en temps réel. Un même participant peut acheter des billets auprès de plusieurs organisations et les retrouve tous dans son espace personnel. Les particuliers (mariés, familles) créent aussi leur événement privé sans passer par une agence : ils envoient des invitations nominatives par WhatsApp, suivent les réponses de leurs invités et contrôlent l'entrée avec le même scan. La plateforme est disponible en français et en anglais.

La première version (V1) se concentre sur vendre, encaisser et contrôler les billets. Le marketing et les statistiques avancées arrivent en V2, l'application mobile, le SSO, les événements en ligne et la revente encadrée en V3.

## Utilisateur cible

Un organisateur indépendant ou une petite agence à Kinshasa, qui organise 2 à 10 événements payants par an (concerts, conférences, soirées, formations) de 100 à 2 000 personnes. Il travaille surtout depuis son téléphone, avec 2 ou 3 collaborateurs (un associé, une personne qui gère l'argent, des agents d'accueil le jour J). Ses participants paient majoritairement en mobile money et communiquent par WhatsApp.

Deuxième cible dès la V1 : un couple qui prépare son mariage, ou une famille qui organise un anniversaire, à Kinshasa. Ils invitent 100 à 800 personnes, ne connaissent rien à l'événementiel, font tout depuis le téléphone et veulent savoir combien d'invités viendront et éviter les intrus.

Utilisateurs secondaires : les participants qui achètent les billets, les agents d'accueil qui scannent, et plus tard les entreprises et institutions.

## User Stories

**Compte et organisation**

1. US-1 : En tant qu'organisateur, je veux créer un compte avec mon e-mail et un mot de passe, afin d'accéder à la plateforme.
2. US-2 : En tant qu'organisateur, je veux créer mon organisation (nom, logo, devise par défaut, langue), afin d'avoir mon espace séparé des autres.
3. US-3 : En tant qu'utilisateur membre de plusieurs organisations, je veux passer de l'une à l'autre, afin de gérer chacune sans mélanger leurs données.
4. US-4 : En tant que propriétaire, je veux inviter un collaborateur par e-mail en lui donnant un rôle, afin qu'il m'aide sans avoir tous les droits.
5. US-5 : En tant que collaborateur invité, je veux accepter l'invitation par un lien, afin de rejoindre l'organisation.
6. US-6 : En tant que collaborateur dont l'invitation a expiré, je veux voir un message clair et pouvoir en demander une nouvelle, afin de ne pas rester bloqué.
7. US-7 : En tant que propriétaire, je veux changer le rôle d'un membre ou le retirer, afin de contrôler qui fait quoi.
8. US-8 : En tant que propriétaire, administrateur ou responsable finance, je veux activer la double authentification, afin de protéger l'argent et les données.
9. US-9 : En tant qu'utilisateur, je veux réinitialiser mon mot de passe oublié, afin de récupérer mon compte.

**Événements et billets**

10. US-10 : En tant qu'organisateur sans aucun événement, je veux voir un écran d'accueil qui m'explique comment créer le premier, afin de démarrer sans aide.
11. US-11 : En tant qu'organisateur, je veux créer un événement (titre, description, image, date, heure, lieu, capacité), afin de le préparer avant publication.
12. US-12 : En tant qu'organisateur, je veux créer plusieurs types de billets (Standard, VIP…) avec un prix, une quantité et une période de vente, afin d'adapter mon offre.
13. US-13 : En tant qu'organisateur, je veux choisir la devise de mon événement (USD ou CDF), afin de vendre dans la monnaie de mon public.
14. US-14 : En tant qu'organisateur, je veux créer des codes promo (pourcentage ou montant, nombre d'utilisations, date limite), afin d'attirer des participants.
15. US-15 : En tant qu'organisateur, je veux proposer des billets gratuits sur inscription, afin d'organiser aussi des événements sans paiement.
16. US-16 : En tant qu'organisateur, je veux prévisualiser puis publier mon événement, afin de vérifier la page avant de la rendre publique.
17. US-17 : En tant qu'organisateur, je veux partager le lien de ma page de vente sur WhatsApp et les réseaux sociaux en un geste, afin de diffuser mon événement.
18. US-18 : En tant qu'organisateur, je veux annuler un événement et prévenir tous les participants, afin de gérer un imprévu proprement.

**Achat (participant)**

19. US-19 : En tant que participant, je veux voir la page de l'événement sur mon téléphone, en français ou en anglais, afin de décider d'acheter.
20. US-20 : En tant que participant, je veux acheter un ou plusieurs billets sans créer de compte, afin d'aller vite.
21. US-21 : En tant que participant, je veux payer en mobile money (M-Pesa, Orange Money, Airtel Money) ou par carte, afin d'utiliser le moyen que j'ai.
22. US-22 : En tant que participant qui paie en mobile money, je veux voir clairement que je dois valider sur mon téléphone et que ma commande attend cette validation, afin de ne pas payer deux fois.
23. US-23 : En tant que participant dont le paiement échoue ou expire, je veux un message clair et pouvoir réessayer, afin de ne pas perdre ma place.
24. US-24 : En tant que participant, je veux que mes places soient réservées pendant que je paie, afin qu'elles ne soient pas vendues à quelqu'un d'autre.
25. US-25 : En tant que participant, quand un type de billet est épuisé, je veux le voir avant de payer, afin de ne pas payer pour rien.
26. US-26 : En tant que participant, je veux recevoir mon billet avec QR code par e-mail, WhatsApp et Messenger, afin de l'avoir toujours sur moi.
27. US-27 : En tant que participant qui achète pour d'autres, je veux indiquer le nom de chaque personne, afin que chacun reçoive son billet.
28. US-28 : En tant que participant, je veux retrouver tous mes billets, toutes organisations confondues, dans mon espace personnel, afin de ne rien perdre.
29. US-29 : En tant que participant, je veux renvoyer mon billet sur mon e-mail ou WhatsApp, afin de le récupérer si je l'ai supprimé.

**Jour J (accueil)**

30. US-30 : En tant qu'agent d'accueil, je veux scanner les billets avec la caméra de mon téléphone, afin de contrôler les entrées rapidement.
31. US-31 : En tant qu'agent d'accueil, je veux une réponse claire et colorée (valide, déjà utilisé, invalide, autre événement), afin de décider en une seconde.
32. US-32 : En tant qu'agent d'accueil, je veux continuer à scanner sans connexion Internet, afin que l'entrée ne s'arrête jamais.
33. US-33 : En tant qu'agent d'accueil, je veux que mes scans hors ligne se synchronisent dès le retour du réseau, et être alerté si un même billet a été scanné ailleurs, afin d'empêcher une double entrée.
34. US-34 : En tant qu'agent d'accueil, je veux chercher un participant par nom ou téléphone, afin de gérer quelqu'un qui n'a plus son billet.
35. US-35 : En tant qu'agent d'accueil, je veux n'accéder qu'aux événements qui me sont attribués, afin de ne voir que ce dont j'ai besoin.

**Suivi et argent**

36. US-36 : En tant qu'organisateur, je veux voir en temps réel les billets vendus, le montant encaissé et le nombre d'entrées, afin de piloter mon événement.
37. US-37 : En tant qu'organisateur, je veux voir la liste des participants et des commandes, afin de répondre aux questions.
38. US-38 : En tant que responsable finance, je veux rembourser une commande, totalement ou en partie, afin de gérer une annulation.
39. US-39 : En tant qu'organisateur, je veux voir clairement la commission de la plateforme et le montant qui me revient, afin de savoir ce que je gagne.
40. US-40 : En tant que propriétaire, je veux choisir et payer mon abonnement, afin d'utiliser la plateforme.

**Événements privés (mariages, anniversaires)**

44. US-44 : En tant que particulier, je veux créer un événement privé sans créer d'agence, afin d'organiser mon mariage ou mon anniversaire moi-même.
45. US-45 : En tant que particulier, je veux importer mes invités depuis les contacts de mon téléphone ou les saisir à la main, afin de constituer ma liste rapidement.
46. US-46 : En tant que particulier, je veux indiquer pour chaque invité le nombre d'accompagnants autorisés, afin de maîtriser le nombre de personnes.
47. US-47 : En tant que particulier, je veux personnaliser mon invitation (photo, texte, programme, lieu, code vestimentaire), afin qu'elle me ressemble.
48. US-48 : En tant que particulier, je veux envoyer les invitations par WhatsApp, Messenger et e-mail, afin de toucher chaque invité là où il est.
49. US-49 : En tant qu'invité, je veux répondre « je viens » ou « je ne viens pas » et préciser mes accompagnants, sans créer de compte, afin de confirmer en un geste.
50. US-50 : En tant que particulier, je veux voir en temps réel qui a confirmé, refusé ou pas encore répondu, afin de prévoir le repas et les places.
51. US-51 : En tant que particulier, je veux relancer en un geste les invités qui n'ont pas répondu, afin de compléter ma liste.
52. US-52 : En tant qu'invité qui a confirmé, je veux recevoir mon invitation avec QR code, afin de la présenter à l'entrée.
53. US-53 : En tant que particulier, je veux confier le scan des invitations à un proche le jour J, afin de ne pas m'en occuper moi-même.
54. US-54 : En tant qu'agent d'accueil d'un événement privé, je veux voir le nom de l'invité et son nombre d'accompagnants au scan, afin de laisser entrer le bon nombre de personnes.

**Sécurité et confiance**

55. US-55 : En tant qu'organisation, je veux qu'aucune autre organisation ne puisse voir mes événements, participants ou ventes, afin de protéger mon activité.
56. US-56 : En tant qu'utilisateur, si j'ouvre un lien vers une ressource d'une autre organisation, je veux voir « introuvable », afin que son existence ne soit pas révélée.
57. US-57 : En tant que participant, je veux pouvoir demander la suppression de mes données personnelles, afin de garder le contrôle sur elles.

## Critères de succès

1. Un organisateur crée son compte et publie un événement payant en moins de 15 minutes, seul, depuis son téléphone.
2. Un participant achète un billet en mobile money ou par carte en moins de 2 minutes, et reçoit son billet avec QR code par e-mail, WhatsApp et Messenger.
3. À l'entrée, un scan affiche le résultat en moins de 2 secondes, avec ou sans connexion.
4. Un même billet n'est jamais accepté deux fois, y compris quand deux agents scannent hors ligne.
5. Zéro survente : le nombre de billets vendus ne dépasse jamais la quantité prévue.
6. Un premier événement réel et payant se déroule entièrement sur kassEvent, de la vente au contrôle d'accès.
7. Un couple crée son mariage et envoie ses 200 invitations par WhatsApp en moins de 30 minutes, sans aide.
8. Le tableau des réponses (confirmés, refusés, sans réponse) est à jour en moins d'une minute après chaque réponse d'invité.
9. Aucun utilisateur ne peut afficher une donnée d'une autre organisation : tout essai renvoie « introuvable ».

## Hors périmètre

Hors de la V1, planifiés plus tard :

- V2 : campagnes marketing par e-mail, SMS, WhatsApp, Facebook, TikTok et LinkedIn.
- V2 : statistiques avancées et exports comptables.
- V2 : plan de tables pour les événements privés.
- V2 : contributions et cadeaux en mobile money pour les mariages et anniversaires.
- V2 : rôles personnalisés (la V1 n'a que les 6 rôles par défaut).
- V3 : application mobile installable (la V1 est un site web adapté au téléphone).
- V3 : connexion SSO pour les grandes entreprises.
- V3 : événements en ligne (streaming, visioconférence).
- V3 : revente de billets entre participants, uniquement via la plateforme.

Refusés, sans date prévue :

- Plan de salle avec places numérotées.
- Revente de billets en dehors de la plateforme.
- Paiement en espèces enregistré dans la plateforme.
- Domaines personnalisés par organisation.

## Décisions d'implémentation

- Adresse : l'application est sur app.kassevent.com ; chaque événement a une page publique de la forme app.kassevent.com/e/nom-de-l-evenement.
- Interface pensée d'abord pour le téléphone, utilisable aussi sur ordinateur.
- Langues : français et anglais ; la langue suit le choix de l'utilisateur, puis celle de l'organisation par défaut.
- Devises : USD ou CDF, choisie par événement ; les montants s'affichent avec le symbole de la devise.
- Réservation : les places sont bloquées 15 minutes pendant le paiement, puis libérées si le paiement n'aboutit pas.
- Mobile money : la commande reste « en attente de validation » jusqu'à la confirmation du paiement ; le billet n'est jamais envoyé avant.
- Billet : un QR code unique par personne, envoyé par e-mail, WhatsApp et Messenger, et consultable dans l'espace participant.
- Scan : écran vert pour un billet valide, rouge pour un billet invalide ou d'un autre événement, orange pour un billet déjà utilisé (avec l'heure du premier scan).
- Scan hors ligne : l'agent télécharge la liste des billets avant l'événement ; les scans se synchronisent automatiquement au retour du réseau ; en cas de conflit, le premier scan fait foi.
- Rôles par défaut : propriétaire, administrateur, gestionnaire d'événement, finance, agent d'accueil, lecteur.
- Double authentification obligatoire pour le propriétaire, l'administrateur et le responsable finance.
- Invitations valables 7 jours.
- Ressource d'une autre organisation : la page affiche « introuvable », jamais « accès refusé ».
- Événement privé : l'invitation est gratuite pour l'invité ; elle porte son nom, son nombre d'accompagnants et un QR code ; une invitation transférée à quelqu'un d'autre reste au nom du premier invité.
- Réponses des invités : trois états visibles (confirmé, refusé, sans réponse) ; relance possible au plus une fois par jour pour un même invité.
- Modèle économique : abonnement mensuel par organisation et commission sur chaque billet payant vendu ; les billets gratuits ne génèrent pas de commission.
- Tarif des particuliers : prix fixe par événement privé, selon le nombre d'invités (paliers jusqu'à 100, 300 et 800 invités), payé en mobile money ou par carte avant l'envoi des invitations ; pas d'abonnement.

## Notes complémentaires

- Dépendances externes : compte agrégateur mobile money validé (pawaPay), compte de paiement par carte (Stripe), compte WhatsApp Business officiel, page Facebook pour Messenger, nom de domaine kassevent.com. L'ouverture de ces comptes peut prendre plusieurs semaines.
- Hypothèse : les participants ont majoritairement un smartphone avec WhatsApp ; ceux qui n'en ont pas reçoivent le billet par e-mail.
- Risque : un seul serveur héberge toute la plateforme ; les sauvegardes doivent être copiées ailleurs chaque jour.
- Risque : chaque message WhatsApp et SMS envoyé est payant, ce coût doit entrer dans le prix des abonnements.
- Risque : les délais et échecs de validation mobile money peuvent faire perdre des ventes ; le parcours d'attente doit être très clair.
- Référence : dossier de conception technique v1.0 (https://claude.ai/artifact/FihX2PtAD7HKGyjkT5zHWo).
