# Design System — kassEvent

## Product Context
- **Quoi** : plateforme de billetterie et d'invitations pour les événements : vente de billets en mobile money et par carte, envoi par e-mail, WhatsApp et Messenger, contrôle d'accès par QR code, même hors ligne.
- **Pour qui** : organisateurs indépendants et petites agences à Kinshasa, puis entreprises et institutions ; particuliers (mariés, familles) pour leurs événements privés ; participants et invités.
- **Espace** : billetterie événementielle (Fnac Spectacles, Eventbrite, Dice, Tix Africa, Quicket), adaptée au mobile money et au réseau instable.
- **Type** : web app (tableau de bord organisateur, pages publiques d'achat et d'invitation, écran de scan), mobile d'abord.
- **Memorable thing** : fiable comme une banque, vivant comme un concert.

## Aesthetic Direction
- **Direction** : Industrial/Utilitarian pour l'application, avec une touche affiche de concert sur les titres et le billet.
- **Décoration** : intentionnelle — le billet perforé est l'objet signature ; le ruban multicolore et les accents mélangés donnent l'ambiance de fête.
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
- **Approche** : thème clair unique, couleurs mélangées. Le vert « Fleuve » porte l'interface et le jaune « Billet » reste réservé au paiement ; quatre accents vifs (corail, lagon, wax, hibiscus) apportent la fête, à tour de rôle, sur les cartes, les billets, les onglets et les décors.
- **Primary** : `#0B3B3C` « Fleuve » — boutons principaux, titres ; fond pâle `#DCEFEA`.
- **Secondary** : `#FFC83D` « Billet » — billet, bouton de paiement ; texte dessus `#2A2100` ; fond pâle `#FFF4D6`.
- **Accents** (couleur pleine pour les décors et aplats · encre pour du texte sur fond clair · fond pâle) :

  | Nom | Pleine | Encre (texte) | Pâle |
  |-----|--------|---------------|------|
  | Corail | `#FF7A59` | `#B8391C` | `#FFE6DC` |
  | Lagon | `#2BA3D8` | `#0F6890` | `#DCF0F9` |
  | Wax | `#6D4AFF` | `#5B3CC4` | `#ECE6FF` |
  | Hibiscus | `#E5487D` | `#A3245A` | `#FDE4EE` |

- **Règle de contraste** : du texte coloré utilise toujours la teinte « encre » (≥ 4,5:1 sur blanc et sur son fond pâle). Les couleurs pleines corail, lagon et hibiscus ne portent jamais de texte blanc ; wax pleine accepte du texte blanc (5,1:1).
- **Ruban** : bande multicolore (Fleuve → Lagon → Wax → Hibiscus → Corail → Billet) sous l'en-tête, signature visuelle de l'application.
- **Fond** : crème chaud `#FBF8F3` avec de grands halos très pâles lagon, corail et wax dans les coins.
- **Neutrals** : `#FFFFFF` → `#FBF8F3` → `#E3DDD2` → `#9A968C` → `#5B6E6C` → `#0E1B1C`.
- **Semantic** : success `#1E8E4E`, warning `#B87300`, error `#C62828`, info `#0F6890`.
- **Focus** : anneau wax à 35 % d'opacité.
- **Dark mode** : abandonné le 2026-11-20 — l'application reste claire, même si le téléphone est en mode sombre. Le billet reste toujours sur fond blanc/jaune pour être lisible et scannable.

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
| 2026-11-20 | Thème clair aux couleurs mélangées | Demande de l'utilisateur : une interface plus vivante. Ajout de quatre accents (corail, lagon, wax, hibiscus), du ruban, d'un fond crème ; abandon du mode sombre |
