# Design System — kassEvent

## Product Context
- **Quoi** : plateforme de billetterie et d'invitations pour les événements : vente de billets en mobile money et par carte, envoi par e-mail, WhatsApp et Messenger, contrôle d'accès par QR code, même hors ligne.
- **Pour qui** : organisateurs indépendants et petites agences à Kinshasa, puis entreprises et institutions ; particuliers (mariés, familles) pour leurs événements privés ; participants et invités.
- **Espace** : billetterie événementielle (Fnac Spectacles, Eventbrite, Dice, Tix Africa, Quicket), adaptée au mobile money et au réseau instable.
- **Type** : web app (tableau de bord organisateur, pages publiques d'achat et d'invitation, écran de scan), mobile d'abord.
- **Memorable thing** : fiable comme une banque, vivant comme un concert.

## Aesthetic Direction
- **Direction** : Industrial/Utilitarian pour l'application, avec une touche affiche de concert sur les titres et le billet.
- **Décoration** : intentionnelle — le billet jaune perforé est l'objet signature ; le reste reste sobre.
- **Mood** : sérieux et rassurant pour l'argent, chaleureux et festif pour l'événement.
- **Références** : Dice (billet comme objet central), Eventbrite et Fnac Spectacles (bouton d'achat toujours visible sur mobile). Aperçu : `docs/design-preview.html`.

## Typography
- **Display/Hero** : Fraunces (axe SOFT à 100, graisses 700–900) — chaleur d'affiche de concert, réservée aux titres et aux noms d'événement.
- **Body** : Instrument Sans — très lisible sur petit écran, neutre sans être froide.
- **Data/Tables** : Geist, avec `font-variant-numeric: tabular-nums` pour les montants et compteurs.
- **Code** : JetBrains Mono — codes de billet, références de paiement, numéros de commande.
- **Loading** : Google Fonts, `https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT@9..144,600..900,100&family=Instrument+Sans:wght@400;500;600;700&family=Geist:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap`
- **Scale** : 12 / 13 / 15 / 16 / 20 / 24 / 32 / 44 / 64 px

## Color
- **Approche** : restrained — le vert porte l'interface, le jaune est rare et signifiant.
- **Primary** : `#0B3B3C` « Fleuve » — navigation, boutons principaux, titres.
- **Secondary** : `#FFC83D` « Billet » — billet, bouton de paiement, surlignage de ce qui compte ; texte dessus `#2A2100`.
- **Neutrals** : `#FFFFFF` → `#F3F6F5` → `#D5DEDC` → `#5B6E6C` → `#0E1B1C` (lightest → darkest, teintés vers le vert).
- **Semantic** : success `#1E8E4E`, warning `#B87300`, error `#C62828`, info `#1F6FB2`.
- **Dark mode** : fond `#0B1414`, surfaces `#122020`, primary éclairci `#5FC4BC`, jaune Billet inchangé, sémantiques éclaircies (`#4CC27D`, `#F2B24C`, `#F06A6A`, `#6AAFEA`). Le billet reste toujours sur fond blanc/jaune pour être lisible et scannable.

## Spacing
- **Base** : 4px
- **Densité** : confortable sur mobile, compacte dans les tableaux du tableau de bord.
- **Scale** : 2xs(2) xs(4) sm(8) md(16) lg(24) xl(32) 2xl(48) 3xl(64)

## Layout
- **Approche** : hybrid — grille disciplinée pour l'application, plus libre pour les pages publiques d'événement et d'invitation.
- **Grid** : 4 colonnes < 640px, 8 colonnes 640–1024px, 12 colonnes > 1024px ; navigation latérale de 220px au-delà de 820px, en haut en dessous.
- **Max content width** : 1120px
- **Border radius** : sm:6px, md:10px, lg:16px, billet:20px, full:9999px
- **Cibles tactiles** : 44px minimum.

## Composants signature
- **Billet / invitation** : en-tête vert avec logo de l'organisateur, titre en Fraunces, grille Date / Heure / Lieu / Accès, ligne perforée, QR code sur fond blanc dans une zone jaune pâle, code lisible en JetBrains Mono, nom du titulaire, bande de microtexte (nom + code), conditions en pied. Version PDF paysage avec talon jaune.
- **Écran de scan** : plein écran coloré — vert « Valide », orange « Déjà utilisé » (avec heure et porte du premier scan), rouge « Invalide ».
- **Bouton de paiement** : seul bouton jaune de l'interface.

## Motion
- **Approche** : minimal-fonctionnel — uniquement ce qui aide à comprendre (confirmation de paiement, résultat de scan).
- **Easing** : enter(ease-out) exit(ease-in) move(ease-in-out)
- **Duration** : micro(50-100ms) court(150-250ms) moyen(250-400ms) long(400-700ms)
- Respect de `prefers-reduced-motion`.

## Decisions Log
| Date | Décision | Rationale |
|------|----------|-----------|
| 2026-11-14 | Création initiale | /design — billetterie et invitations mobile-first pour la RDC ; signature « billet jaune » et scan plein écran |
