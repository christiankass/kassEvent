import type { CanvasElement, CanvasFont, IconName, TextElement } from "./canvas";
import { backdrop, caps, fit, icon, pageColor, photo, seeded, shape, text, title, type Box, type Frame, type Layout, type TemplateContext, type Theme } from "./canvas-kit";

/**
 * Compositions de la bibliothèque : chacune a sa structure, sa hiérarchie et son motif propres
 * (grille éditoriale, arche, monogramme, bandes wax, gros chiffre, billet rétro…), et reste finie
 * sans photo. Portrait, carré et story partagent le dessin vertical, mis à l'échelle de la place
 * libre (`f.bottom`, au-dessus du talon d'un billet) ; le paysage a sa propre mise en page.
 */

// --- Outils ---------------------------------------------------------------------------------------

/** Marge extérieure, en pixels de la page (1080 de large). */
const M = 72;

/** Petit titre en capitales espacées (organisateur, rubrique). */
function kicker(box: Box, value: string, color: string, font: CanvasFont, size: number, style: Partial<TextElement> = {}): TextElement {
  return text(box, value.toUpperCase(), { font, fontSize: size, bold: true, letterSpacing: Math.round(size * 0.28), fill: color, lineHeight: 1.1, ...style });
}

/** Filet horizontal. */
const rule = (x: number, y: number, width: number, color: string, weight = 2, opacity = 1) =>
  shape("line", { x, y: y - 3, width, height: 6 }, { stroke: color, strokeWidth: weight, opacity });

/** Texte d'une ligne réduit pour tenir dans sa largeur. */
function line(box: Box, value: string, font: CanvasFont, size: number, fill: string, style: Partial<TextElement> = {}): TextElement {
  const fontSize = fit(value, size, box.width, 1, font, false, box.height);
  return text(box, value, { font, fontSize, bold: true, fill, lineHeight: 1.1, ...style });
}

/** Initiales d'un titre (« Mariage de Grace et Patrick » → « G & P », « Gala 2026 » → « G »). */
function initials(value: string): string {
  const names = value.split(/[\s,]+/).filter((word) => /^\p{Lu}/u.test(word));
  const picked = names.length >= 3 ? names.slice(-2) : names.slice(0, 2);
  if (picked.length === 0) return value.trim().charAt(0).toUpperCase();
  return picked.map((name) => name.charAt(0)).join(picked.length === 2 ? " & " : "");
}

/** Hauteur disponible pour le dessin vertical et facteur d'échelle des textes. */
function room(f: Frame) {
  const b = f.bottom;
  return { b, k: f.k, W: f.w };
}

/** Bande wax : grands cercles concentriques sur trois couleurs. */
function waxBand(area: Box, t: Theme, seed: number): CanvasElement[] {
  const random = seeded(seed);
  const size = area.height;
  const count = Math.ceil(area.width / size) + 1;
  const colors = [t.accent, t.accent2, t.card];
  const out: CanvasElement[] = [shape("rect", area, { fill: t.ink === "#FFFFFF" ? t.accent2 : t.ink })];
  for (let i = 0; i < count; i++) {
    const x = area.x + i * size - size * 0.1 * random();
    const [a, b, c] = [colors[i % 3], colors[(i + 1) % 3], colors[(i + 2) % 3]];
    out.push(
      shape("ellipse", { x, y: area.y, width: size, height: size }, { fill: a }),
      shape("ellipse", { x: x + size * 0.18, y: area.y + size * 0.18, width: size * 0.64, height: size * 0.64 }, { fill: b }),
      shape("ellipse", { x: x + size * 0.34, y: area.y + size * 0.34, width: size * 0.32, height: size * 0.32 }, { fill: c }),
    );
  }
  return out;
}

/** Losanges réguliers (carrés tournés de 45°). */
function diamonds(area: Box, step: number, color: string, opacity: number): CanvasElement[] {
  const out: CanvasElement[] = [];
  const side = step * 0.42;
  for (let row = 0, y = area.y; y < area.y + area.height && out.length < 90; y += step / 2, row++) {
    for (let x = area.x + (row % 2) * (step / 2); x < area.x + area.width; x += step) {
      out.push(shape("rect", { x, y, width: side, height: side }, { fill: color, opacity, rotation: 45 }));
    }
  }
  return out;
}

/** Bandes de couleur (kente), verticales ou horizontales. */
function stripes(area: Box, colors: string[], count: number, vertical = true): CanvasElement[] {
  const size = (vertical ? area.width : area.height) / count;
  return Array.from({ length: count }, (_, i) =>
    shape(
      "rect",
      vertical ? { x: area.x + i * size, y: area.y, width: size + 1, height: area.height } : { x: area.x, y: area.y + i * size, width: area.width, height: size + 1 },
      { fill: colors[i % colors.length] },
    ),
  );
}

/** Trame de points dont la taille décroît (demi-teinte), dans un coin. */
function halftone(area: Box, step: number, color: string, fromRight = true): CanvasElement[] {
  const out: CanvasElement[] = [];
  const cols = Math.floor(area.width / step);
  const rows = Math.floor(area.height / step);
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const share = 1 - (fromRight ? cols - 1 - c : c) / cols;
      const size = step * 0.8 * share * (1 - r / rows / 2);
      if (size < 3 || out.length > 120) continue;
      out.push(shape("ellipse", { x: area.x + c * step + (step - size) / 2, y: area.y + r * step + (step - size) / 2, width: size, height: size }, { fill: color }));
    }
  }
  return out;
}

/** Lueur douce : cercles concentriques de plus en plus transparents. */
function softGlow(cx: number, cy: number, size: number, color: string, steps = 5): CanvasElement[] {
  return Array.from({ length: steps }, (_, i) => {
    const s = size * (1 - i / steps);
    return shape("ellipse", { x: cx - s / 2, y: cy - s / 2, width: s, height: s }, { fill: color, opacity: 0.12 + i * 0.05 });
  });
}

/** Rayons fins autour d'un centre (soleil, gloire). */
function sunRays(cx: number, cy: number, length: number, count: number, color: string, opacity: number, width = 10): CanvasElement[] {
  return Array.from({ length: count }, (_, i) => {
    const angle = (360 / count) * i;
    const rad = (angle * Math.PI) / 180;
    // Le rectangle tourne autour de son coin : on le décale pour qu'il parte du centre.
    return shape("rect", { x: cx + Math.sin(rad) * (width / 2), y: cy - Math.cos(rad) * (width / 2), width: length, height: width }, { fill: color, opacity, rotation: angle });
  });
}

/** Coins ornés : deux filets en équerre à chaque coin. */
function corners(box: Box, size: number, color: string, weight = 3): CanvasElement[] {
  const { x, y, width: w, height: h } = box;
  const l = (bx: number, by: number, bw: number, bh: number) => shape("rect", { x: bx, y: by, width: bw, height: bh }, { fill: color });
  return [
    l(x, y, size, weight), l(x, y, weight, size),
    l(x + w - size, y, size, weight), l(x + w - weight, y, weight, size),
    l(x, y + h - weight, size, weight), l(x, y + h - size, weight, size),
    l(x + w - size, y + h - weight, size, weight), l(x + w - weight, y + h - size, weight, size),
  ];
}

/** Infos en colonnes : petit libellé au-dessus, valeur en gras. */
function facts(box: Box, items: [string, string][], o: { label: string; value: string; font: CanvasFont; size: number; rules?: string }): CanvasElement[] {
  const width = box.width / items.length;
  return items.flatMap(([label, value], i) => [
    ...(o.rules && i > 0 ? [shape("rect", { x: box.x + i * width - 1, y: box.y, width: 2, height: box.height }, { fill: o.rules, opacity: 0.5 })] : []),
    kicker({ x: box.x + i * width + (i > 0 ? 24 : 0), y: box.y, width: width - 30, height: o.size * 0.9 }, label, o.label, o.font, Math.round(o.size * 0.55)),
    text({ x: box.x + i * width + (i > 0 ? 24 : 0), y: box.y + o.size * 1.05, width: width - 30, height: box.height - o.size }, value, {
      font: o.font,
      fontSize: fit(value, o.size, width - 30, 2, o.font, false, box.height - o.size * 1.05),
      bold: true,
      fill: o.value,
      lineHeight: 1.12,
    }),
  ]);
}

/** Composition paysage commune : colonne de textes à gauche, motif du modèle à droite. */
function wide(t: Theme, ctx: TemplateContext, art: (area: Box) => CanvasElement[]): CanvasElement[] {
  const col = { x: 60, y: 50, width: 560, height: 530 };
  return [
    ...art({ x: 640, y: 0, width: 560, height: 630 }),
    kicker({ x: col.x, y: col.y + 10, width: col.width, height: 30 }, ctx.organizer, t.accent, t.body, 22),
    title({ x: col.x, y: col.y + 60, width: col.width, height: 300 }, ctx, t, 92, 3),
    rule(col.x, 440, 120, t.accent, 4),
    text({ x: col.x, y: 462, width: col.width, height: 40 }, ctx.date, { font: t.body, fontSize: 26, bold: true, fill: t.ink }),
    text({ x: col.x, y: 506, width: col.width, height: 40 }, ctx.venue, { font: t.body, fontSize: 24, fill: t.soft }),
  ];
}

// --- Compositions ---------------------------------------------------------------------------------

/** 1. Éditorial : grille suisse, gros titre ferré à gauche, bloc date, infos en colonnes. */
export const editorial: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [shape("rect", a, { fill: t.accent }), line({ x: a.x + 40, y: 120, width: a.width - 80, height: 300 }, ctx.dayNumber, t.title, 300, t.onAccent, { align: "right", lineHeight: 1 }), kicker({ x: a.x + 40, y: 440, width: a.width - 80, height: 40 }, `${ctx.month} ${ctx.year}`, t.onAccent, t.body, 30, { align: "right" })]);
  const { b, k, W } = room(f);
  const block = 230 * k;
  return [
    kicker({ x: M, y: 64 * k, width: 600, height: 34 }, ctx.organizer, t.ink, t.body, 24 * k),
    kicker({ x: W - M - 300, y: 64 * k, width: 300, height: 34 }, ctx.year, t.soft, t.body, 24 * k, { align: "right" }),
    rule(M, 118 * k, W - M * 2, t.ink, 3),
    shape("rect", { x: W - M - block, y: 150 * k, width: block, height: block }, { fill: t.accent }),
    line({ x: W - M - block, y: 150 * k + block * 0.08, width: block, height: block * 0.66 }, ctx.dayNumber, t.title, block * 0.62, t.onAccent, { align: "center", lineHeight: 1 }),
    kicker({ x: W - M - block, y: 150 * k + block * 0.74, width: block, height: block * 0.2 }, ctx.month, t.onAccent, t.body, 30 * k, { align: "center" }),
    title({ x: M, y: 150 * k + block + 40 * k, width: W - M * 2, height: b * 0.4 }, ctx, t, 170 * k, 3, { lineHeight: 0.98 }),
    rule(M, b - 210 * k, W - M * 2, t.ink, 2),
    ...facts({ x: M, y: b - 180 * k, width: W - M * 2, height: 130 * k }, [[ctx.shortDate, ctx.time], [ctx.city, ctx.venue]], { label: t.soft, value: t.ink, font: t.body, size: 46 * k, rules: t.soft }),
  ];
};

/** 2. Arche : photo en arche, invitation centrée, filets fins (mariage, dot, culte). */
export const arch: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [photo({ x: a.x + 100, y: 60, width: 360, height: 530 }, ctx.photoLabel, { cornerRadius: 180 }), shape("rect", { x: a.x + 80, y: 40, width: 400, height: 570 }, { stroke: t.accent, strokeWidth: 3, cornerRadius: 200 })]);
  const { b, k, W } = room(f);
  const aw = Math.min(560, b * 0.42);
  const ah = b * 0.5;
  const ax = (W - aw) / 2;
  const top = 70 * k;
  return [
    shape("rect", { x: ax - 22, y: top - 22, width: aw + 44, height: ah + 300 }, { stroke: t.accent, strokeWidth: 3, cornerRadius: (aw + 44) / 2 }),
    photo({ x: ax, y: top, width: aw, height: ah + 260 }, ctx.photoLabel, { cornerRadius: aw / 2 }),
    // Le bas de l'arche passe sous le panneau de texte : seule la voûte reste visible.
    backdrop(f, t, { x: 0, y: top + ah, width: W, height: b - top - ah }),
    rule(ax - 22, top + ah, aw + 44, t.accent, 3),
    text({ x: M, y: top + ah + 36 * k, width: W - M * 2, height: 60 * k }, ctx.labels.invite, { font: t.body, fontSize: 32 * k, italic: true, fill: t.soft, align: "center" }),
    title({ x: M, y: top + ah + 100 * k, width: W - M * 2, height: b - 170 * k - (top + ah + 100 * k) }, ctx, t, 120 * k, 2, { align: "center" }),
    kicker({ x: M, y: b - 150 * k, width: W - M * 2, height: 40 * k }, ctx.day, t.accent, t.body, 28 * k, { align: "center" }),
    text({ x: M, y: b - 100 * k, width: W - M * 2, height: 50 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.soft, align: "center" }),
  ];
};

/** 3. Monogramme : initiales dans un double cercle, faire-part classique. */
export const monogram: Layout = (f, t, ctx) => {
  const letters = initials(ctx.title);
  if (f.wide) return wide(t, ctx, (a) => [shape("ellipse", { x: a.x + 60, y: 95, width: 440, height: 440 }, { stroke: t.accent, strokeWidth: 4 }), shape("ellipse", { x: a.x + 80, y: 115, width: 400, height: 400 }, { stroke: t.accent, strokeWidth: 1.5 }), line({ x: a.x + 100, y: 240, width: 360, height: 150 }, letters, t.title, 140, t.accent, { align: "center" })]);
  const { b, k, W } = room(f);
  const d = Math.min(460, b * 0.36);
  const cy = 90 * k;
  return [
    shape("rect", { x: 40, y: 40, width: W - 80, height: b - 80 }, { stroke: t.accent, strokeWidth: 2 }),
    ...corners({ x: 56, y: 56, width: W - 112, height: b - 112 }, 60, t.accent, 4),
    shape("ellipse", { x: (W - d) / 2, y: cy, width: d, height: d }, { stroke: t.accent, strokeWidth: 4 }),
    shape("ellipse", { x: (W - d) / 2 + 18, y: cy + 18, width: d - 36, height: d - 36 }, { stroke: t.accent, strokeWidth: 1.5 }),
    line({ x: (W - d) / 2 + 40, y: cy + d * 0.3, width: d - 80, height: d * 0.4 }, letters, t.title, d * 0.34, t.accent, { align: "center", lineHeight: 1 }),
    text({ x: M, y: cy + d + 40 * k, width: W - M * 2, height: 50 * k }, ctx.labels.invite, { font: t.body, fontSize: 30 * k, italic: true, fill: t.soft, align: "center" }),
    title({ x: M + 20, y: cy + d + 100 * k, width: W - M * 2 - 40, height: b * 0.2 }, ctx, t, 110 * k, 2, { align: "center" }),
    icon("heart", W / 2 - 18 * k, b - 230 * k, 36 * k, t.accent),
    kicker({ x: M, y: b - 170 * k, width: W - M * 2, height: 40 * k }, ctx.day, t.ink, t.body, 28 * k, { align: "center" }),
    text({ x: M, y: b - 120 * k, width: W - M * 2, height: 50 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.soft, align: "center" }),
  ];
};

/** 4. Bandes wax : motif en haut et en bas, titre condensé au centre (concert, soirée, fête). */
export const waxBands: Layout = (f, t, ctx, o) => {
  if (f.wide) return wide(t, ctx, (a) => waxBand({ x: a.x, y: 0, width: a.width, height: 210 }, t, o.seed).concat(waxBand({ x: a.x, y: 420, width: a.width, height: 210 }, t, o.seed + 1)));
  const { b, k, W } = room(f);
  const band = Math.min(220, b * 0.17);
  return [
    ...waxBand({ x: 0, y: 0, width: W, height: band }, t, o.seed),
    ...waxBand({ x: 0, y: b - band, width: W, height: band }, t, o.seed + 7),
    kicker({ x: M, y: band + 50 * k, width: W - M * 2, height: 36 }, ctx.organizer, t.accent, t.body, 26 * k, { align: "center" }),
    title({ x: M, y: band + 110 * k, width: W - M * 2, height: b - band * 2 - 400 * k }, ctx, t, 190 * k, 3, { align: "center", lineHeight: 0.95 }),
    shape("rect", { x: (W - 640) / 2, y: b - band - 220 * k, width: 640, height: 80 * k }, { fill: t.accent, cornerRadius: 40 * k }),
    line({ x: (W - 600) / 2, y: b - band - 200 * k, width: 600, height: 44 * k }, `${ctx.shortDate}  ·  ${ctx.time}`, t.body, 34 * k, t.onAccent, { align: "center" }),
    text({ x: M, y: b - band - 110 * k, width: W - M * 2, height: 50 * k }, ctx.venue, { font: t.body, fontSize: 32 * k, bold: true, fill: t.ink, align: "center" }),
  ];
};

/** 5. Gros chiffre : le jour en immense, le titre posé dessus (concert, anniversaire, soirée). */
export const bigDate: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [line({ x: a.x, y: 20, width: a.width, height: 520 }, ctx.dayNumber, t.title, 520, t.accent, { align: "center", lineHeight: 1 }), kicker({ x: a.x, y: 540, width: a.width, height: 40 }, `${ctx.month} ${ctx.year}`, t.ink, t.body, 30, { align: "center" })]);
  const { b, k, W } = room(f);
  return [
    line({ x: -20, y: 120 * k, width: W + 40, height: b * 0.6 - 130 * k }, ctx.dayNumber, t.title, b * 0.62, t.accent, { align: "center", lineHeight: 0.9 }),
    kicker({ x: M, y: 60 * k, width: 500, height: 36 }, ctx.month, t.ink, t.body, 30 * k),
    kicker({ x: W - M - 400, y: 60 * k, width: 400, height: 36 }, ctx.year, t.ink, t.body, 30 * k, { align: "right" }),
    title({ x: M, y: b * 0.6, width: W - M * 2, height: b * 0.22 }, ctx, t, 140 * k, 2),
    rule(M, b * 0.85, W - M * 2, t.ink, 2),
    text({ x: M, y: b * 0.87, width: (W - M * 2) / 2, height: 50 * k }, ctx.time, { font: t.body, fontSize: 34 * k, bold: true, fill: t.ink }),
    text({ x: W / 2, y: b * 0.87, width: W / 2 - M, height: 90 * k }, ctx.venue, { font: t.body, fontSize: 30 * k, fill: t.soft, align: "right" }),
  ];
};

/** 6. Billet rétro : une grande contremarque avec encoches, pointillés et prix. */
export const retroTicket: Layout = (f, t, ctx) => {
  const ticketArt = (a: Box) => {
    const notch = a.width * 0.09;
    return [
      shape("rect", a, { fill: t.card, cornerRadius: 26 }),
      shape("ellipse", { x: a.x - notch / 2, y: a.y + a.height * 0.62 - notch / 2, width: notch, height: notch }, { fill: pageColor(f, t, a.y + a.height * 0.62) }),
      shape("ellipse", { x: a.x + a.width - notch / 2, y: a.y + a.height * 0.62 - notch / 2, width: notch, height: notch }, { fill: pageColor(f, t, a.y + a.height * 0.62) }),
      ...Array.from({ length: 14 }, (_, i) => shape("rect", { x: a.x + notch * 0.7 + i * ((a.width - notch * 1.4) / 14), y: a.y + a.height * 0.62 - 2, width: (a.width - notch * 1.4) / 28, height: 4 }, { fill: t.cardSoft })),
    ];
  };
  if (f.wide) return wide(t, ctx, (a) => [...ticketArt({ x: a.x + 40, y: 60, width: a.width - 100, height: 510 }), kicker({ x: a.x + 80, y: 110, width: a.width - 180, height: 30 }, ctx.labels.admit, t.cardSoft, t.body, 24, { align: "center" }), line({ x: a.x + 80, y: 180, width: a.width - 180, height: 140 }, ctx.price, t.title, 130, t.cardInk, { align: "center" }), line({ x: a.x + 80, y: 420, width: a.width - 180, height: 50 }, ctx.shortDate, t.body, 34, t.cardInk, { align: "center" })]);
  const { b, k, W } = room(f);
  const card = { x: M - 20, y: 70 * k, width: W - (M - 20) * 2, height: b - 140 * k };
  const cut = card.y + card.height * 0.62;
  return [
    ...ticketArt(card),
    kicker({ x: card.x + 50, y: card.y + 50 * k, width: card.width - 100, height: 36 }, `${ctx.labels.admit}  ·  ${ctx.organizer}`, t.cardSoft, t.body, 24 * k, { align: "center" }),
    title({ x: card.x + 60, y: card.y + 110 * k, width: card.width - 120, height: cut - card.y - 260 * k }, ctx, t, 140 * k, 3, { align: "center", fill: t.cardInk }),
    ...facts({ x: card.x + 60, y: cut - 130 * k, width: card.width - 120, height: 110 * k }, [[ctx.month, ctx.dayNumber], [ctx.labels.time, ctx.time], [ctx.city, ctx.year]], { label: t.cardSoft, value: t.cardInk, font: t.body, size: 44 * k, rules: t.cardSoft }),
    kicker({ x: card.x + 60, y: cut + 40 * k, width: card.width - 120, height: 30 }, ctx.labels.entry, t.cardSoft, t.body, 22 * k, { align: "center" }),
    line({ x: card.x + 60, y: cut + 80 * k, width: card.width - 120, height: card.y + card.height - cut - 130 * k }, ctx.price, t.title, 120 * k, t.accent === t.card ? t.cardInk : t.accent, { align: "center" }),
  ];
};

/** 7. Blocs suisses : grille de pavés de couleur, chaque info dans le sien. */
export const swissBlocks: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [shape("rect", { x: a.x, y: 0, width: a.width / 2, height: 315 }, { fill: t.accent }), shape("rect", { x: a.x + a.width / 2, y: 0, width: a.width / 2, height: 315 }, { fill: t.accent2 }), photo({ x: a.x, y: 315, width: a.width, height: 315 }, ctx.photoLabel), line({ x: a.x + 30, y: 90, width: a.width / 2 - 60, height: 160 }, ctx.dayNumber, t.title, 150, t.onAccent), kicker({ x: a.x + a.width / 2 + 30, y: 130, width: a.width / 2 - 60, height: 40 }, ctx.time, t.onAccent, t.body, 36)]);
  const { b, k, W } = room(f);
  const g = 14;
  const top = b * 0.46;
  const cw = (W - M * 2 - g) / 2;
  const ch = (b - top - M - g) / 2;
  return [
    kicker({ x: M, y: 64 * k, width: W - M * 2, height: 34 }, ctx.organizer, t.accent, t.body, 24 * k),
    title({ x: M, y: 120 * k, width: W - M * 2, height: top - 150 * k }, ctx, t, 160 * k, 3, { lineHeight: 0.96 }),
    shape("rect", { x: M, y: top, width: cw, height: ch }, { fill: t.accent }),
    line({ x: M + 30, y: top + ch * 0.12, width: cw - 60, height: ch * 0.55 }, ctx.dayNumber, t.title, ch * 0.6, t.onAccent, { lineHeight: 1 }),
    kicker({ x: M + 30, y: top + ch * 0.72, width: cw - 60, height: ch * 0.2 }, `${ctx.month} ${ctx.year}`, t.onAccent, t.body, 28 * k),
    photo({ x: M + cw + g, y: top, width: cw, height: ch * 2 + g }, ctx.photoLabel),
    shape("rect", { x: M, y: top + ch + g, width: cw, height: ch }, { fill: t.card }),
    kicker({ x: M + 30, y: top + ch + g + 30 * k, width: cw - 60, height: 30 }, ctx.time, t.cardSoft, t.body, 26 * k),
    text({ x: M + 30, y: top + ch + g + 76 * k, width: cw - 60, height: ch - 100 * k }, ctx.venue, { font: t.body, fontSize: fit(ctx.venue, 40 * k, cw - 60, 3, t.body, false, ch - 110 * k), bold: true, fill: t.cardInk, lineHeight: 1.12 }),
  ];
};

/** 8. Soleil : grand disque et rayons derrière le titre (culte, festival, afro). */
export const sunburst: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [...sunRays(a.x + a.width / 2, 315, 420, 24, t.accent, 0.25, 14), shape("ellipse", { x: a.x + a.width / 2 - 150, y: 165, width: 300, height: 300 }, { fill: t.accent }), icon("sparkle", a.x + a.width / 2 - 50, 265, 100, t.onAccent)]);
  const { b, k, W } = room(f);
  const cy = b * 0.34;
  const d = Math.min(W * 0.62, b * 0.42);
  return [
    ...sunRays(W / 2, cy, Math.max(W, b) * 0.75, 18, t.accent, 0.18, 24),
    shape("ellipse", { x: (W - d) / 2, y: cy - d / 2, width: d, height: d }, { fill: t.accent }),
    shape("ellipse", { x: (W - d) / 2 + 26, y: cy - d / 2 + 26, width: d - 52, height: d - 52 }, { stroke: t.onAccent, strokeWidth: 3, opacity: 0.6 }),
    line({ x: (W - d) / 2 + 40, y: cy - d * 0.22, width: d - 80, height: d * 0.3 }, ctx.dayNumber, t.title, d * 0.32, t.onAccent, { align: "center", lineHeight: 1 }),
    kicker({ x: (W - d) / 2 + 40, y: cy + d * 0.12, width: d - 80, height: d * 0.1 }, ctx.month, t.onAccent, t.body, 32 * k, { align: "center" }),
    title({ x: M, y: cy + d / 2 + 50 * k, width: W - M * 2, height: b * 0.22 }, ctx, t, 140 * k, 2, { align: "center" }),
    kicker({ x: M, y: b - 130 * k, width: W - M * 2, height: 40 }, `${ctx.time}  ·  ${ctx.venue}`, t.soft, t.body, 26 * k, { align: "center" }),
  ];
};

/** 9. Diagonale : fond coupé en biais, photo d'un côté, titre penché en capitales (sport, concert). */
export const diagonal: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [photo({ x: a.x - 40, y: 0, width: a.width + 40, height: 630 }, ctx.photoLabel), shape("rect", { x: a.x - 120, y: -60, width: 160, height: 760 }, { fill: t.bg, rotation: 10 }), shape("rect", { x: a.x - 70, y: -60, width: 22, height: 760 }, { fill: t.accent, rotation: 10 })]);
  const { b, k, W } = room(f);
  const cut = b * 0.5;
  return [
    photo({ x: 0, y: 0, width: W, height: cut + 120 }, ctx.photoLabel),
    backdrop(f, t, { x: -100, y: cut, width: W * 1.5, height: b - cut + 200 }, { rotation: -8 }),
    shape("rect", { x: -100, y: cut - 30, width: W * 1.5, height: 26 }, { fill: t.accent, rotation: -8 }),
    shape("rect", { x: M, y: 60 * k, width: 260, height: 60 * k }, { fill: t.accent }),
    line({ x: M + 16, y: 70 * k, width: 228, height: 40 * k }, ctx.shortDate, t.body, 30 * k, t.onAccent, { align: "center" }),
    title({ x: M, y: cut + 90 * k, width: W - M * 2, height: b - cut - 280 * k }, ctx, t, 170 * k, 3, { italic: true, lineHeight: 0.92 }),
    rule(M, b - 140 * k, 160, t.accent, 8),
    text({ x: M, y: b - 110 * k, width: W - M * 2, height: 60 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 32 * k, bold: true, fill: t.ink }),
  ];
};

/** 10. Couverture de magazine : photo pleine page, titre de une, accroches. */
export const magazine: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [photo(a, ctx.photoLabel), line({ x: a.x + 30, y: 30, width: a.width - 60, height: 110 }, ctx.organizer.toUpperCase(), t.title, 100, "#FFFFFF", { align: "center", letterSpacing: 6 })]);
  const { b, k, W } = room(f);
  return [
    photo({ x: 0, y: 0, width: W, height: b }, ctx.photoLabel),
    shape("rect", { x: 0, y: b * 0.45, width: W, height: b * 0.55 }, { fill: null, fill2: "#000000", opacity: 0.85 }),
    line({ x: 40, y: 34 * k, width: W - 80, height: 170 * k }, ctx.organizer.toUpperCase(), t.title, 170 * k, t.accent, { align: "center", letterSpacing: 4, lineHeight: 1 }),
    rule(M, 220 * k, W - M * 2, "#FFFFFF", 2),
    kicker({ x: M, y: 236 * k, width: 420, height: 30 }, `${ctx.month} ${ctx.year}`, "#FFFFFF", t.body, 22 * k),
    kicker({ x: W - M - 420, y: 236 * k, width: 420, height: 30 }, ctx.city, "#FFFFFF", t.body, 22 * k, { align: "right" }),
    title({ x: M, y: b * 0.62, width: W * 0.8, height: b * 0.2 }, ctx, t, 140 * k, 2, { fill: "#FFFFFF" }),
    shape("rect", { x: M, y: b * 0.85, width: 10, height: 90 * k }, { fill: t.accent }),
    text({ x: M + 30, y: b * 0.85, width: W * 0.6, height: 44 * k }, ctx.date, { font: t.body, fontSize: 30 * k, bold: true, fill: "#FFFFFF" }),
    text({ x: M + 30, y: b * 0.85 + 46 * k, width: W * 0.6, height: 44 * k }, ctx.venue, { font: t.body, fontSize: 28 * k, fill: "#E6E6E6" }),
    ...Array.from({ length: 18 }, (_, i) => shape("rect", { x: W - M - 200 + i * 11, y: b * 0.86, width: i % 3 === 0 ? 6 : 3, height: 80 * k }, { fill: "#FFFFFF" })),
  ];
};

/** 11. Faire-part : double cadre, coins ornés, typographie centrée et filet à cœur. */
export const classicInvite: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [shape("rect", { x: a.x + 30, y: 40, width: a.width - 70, height: 550 }, { stroke: t.accent, strokeWidth: 3 }), ...corners({ x: a.x + 46, y: 56, width: a.width - 102, height: 518 }, 50, t.accent), icon("rings", a.x + a.width / 2 - 70, 245, 140, t.accent)]);
  const { b, k, W } = room(f);
  const inner = { x: 70, y: 70, width: W - 140, height: b - 140 };
  return [
    shape("rect", { x: 40, y: 40, width: W - 80, height: b - 80 }, { stroke: t.accent, strokeWidth: 4 }),
    shape("rect", inner, { stroke: t.accent, strokeWidth: 1.5 }),
    ...corners({ x: 90, y: 90, width: W - 180, height: b - 180 }, 70, t.accent, 3),
    kicker({ x: M + 40, y: b * 0.12, width: W - M * 2 - 80, height: 36 }, ctx.labels.saveDate, t.accent, t.body, 26 * k, { align: "center" }),
    text({ x: M + 40, y: b * 0.19, width: W - M * 2 - 80, height: 60 * k }, ctx.labels.invite, { font: t.body, fontSize: 32 * k, italic: true, fill: t.soft, align: "center" }),
    title({ x: M + 40, y: b * 0.27, width: W - M * 2 - 80, height: b * 0.3 }, ctx, t, 140 * k, 3, { align: "center" }),
    rule(W / 2 - 220, b * 0.62, 170, t.accent, 2),
    icon("heart", W / 2 - 22, b * 0.62 - 22, 44, t.accent),
    rule(W / 2 + 50, b * 0.62, 170, t.accent, 2),
    kicker({ x: M + 40, y: b * 0.67, width: W - M * 2 - 80, height: 40 }, ctx.day, t.ink, t.body, 30 * k, { align: "center" }),
    text({ x: M + 40, y: b * 0.67 + 56 * k, width: W - M * 2 - 80, height: 50 * k }, ctx.time, { font: t.body, fontSize: 30 * k, fill: t.ink, align: "center" }),
    text({ x: M + 40, y: b * 0.67 + 110 * k, width: W - M * 2 - 80, height: 90 * k }, ctx.venue, { font: t.body, fontSize: 30 * k, italic: true, fill: t.soft, align: "center" }),
    text({ x: M + 40, y: b - 160 * k, width: W - M * 2 - 80, height: 40 * k }, ctx.labels.rsvp, { font: t.body, fontSize: 24 * k, fill: t.soft, align: "center" }),
  ];
};

/** 12. Minimal : grand blanc, petit titre espacé, un point d'accent (gala, lancement chic). */
export const minimal: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [shape("ellipse", { x: a.x + a.width / 2 - 70, y: 245, width: 140, height: 140 }, { fill: t.accent })]);
  const { b, k, W } = room(f);
  return [
    shape("rect", { x: 50, y: 50, width: W - 100, height: b - 100 }, { stroke: t.soft, strokeWidth: 1.5, opacity: 0.6 }),
    shape("ellipse", { x: W / 2 - 40 * k, y: b * 0.22, width: 80 * k, height: 80 * k }, { fill: t.accent }),
    title({ x: M * 2, y: b * 0.36, width: W - M * 4, height: b * 0.24 }, ctx, t, 90 * k, 3, { align: "center", letterSpacing: 6 }),
    rule(W / 2 - 40, b * 0.64, 80, t.accent, 2),
    kicker({ x: M, y: b * 0.68, width: W - M * 2, height: 36 }, ctx.day, t.ink, t.body, 22 * k, { align: "center" }),
    kicker({ x: M, y: b * 0.68 + 44 * k, width: W - M * 2, height: 36 }, `${ctx.time} — ${ctx.venue}`, t.soft, t.body, 20 * k, { align: "center" }),
    kicker({ x: M, y: b - 120 * k, width: W - M * 2, height: 30 }, ctx.organizer, t.soft, t.body, 18 * k, { align: "center" }),
  ];
};

/** 13. Kente : bandes de couleur verticales et carte de textes (fête, culture, entreprise). */
export const kente: Layout = (f, t, ctx) => {
  const colors = [t.accent, t.ink, t.accent2, t.card, t.accent];
  if (f.wide) return wide(t, ctx, (a) => stripes(a, colors, 9));
  const { b, k, W } = room(f);
  const card = { x: M + 20, y: b * 0.16, width: W - (M + 20) * 2, height: b * 0.68 };
  return [
    ...stripes({ x: 0, y: 0, width: W, height: b }, colors, 12),
    ...stripes({ x: 0, y: b * 0.5 - 30, width: W, height: 60 }, [t.bg, t.accent2], 2, false),
    shape("rect", { x: card.x + 16, y: card.y + 16, width: card.width, height: card.height }, { fill: t.ink, opacity: 0.35 }),
    shape("rect", card, { fill: t.card }),
    kicker({ x: card.x + 50, y: card.y + 50 * k, width: card.width - 100, height: 36 }, ctx.organizer, t.cardSoft, t.body, 24 * k, { align: "center" }),
    title({ x: card.x + 50, y: card.y + 110 * k, width: card.width - 100, height: card.height * 0.5 }, ctx, t, 140 * k, 3, { align: "center", fill: t.cardInk }),
    rule(card.x + card.width / 2 - 60, card.y + card.height * 0.72, 120, t.cardSoft, 4),
    text({ x: card.x + 50, y: card.y + card.height * 0.76, width: card.width - 100, height: 50 * k }, ctx.date, { font: t.body, fontSize: 32 * k, bold: true, fill: t.cardInk, align: "center" }),
    text({ x: card.x + 50, y: card.y + card.height * 0.76 + 54 * k, width: card.width - 100, height: 50 * k }, ctx.venue, { font: t.body, fontSize: 28 * k, fill: t.cardSoft, align: "center" }),
  ];
};

/** 14. Polaroids : trois photos inclinées à bords blancs, titre manuscrit (anniversaire, souvenir). */
export const polaroid: Layout = (f, t, ctx, o) => {
  const frames = (a: Box, size: number) => {
    const random = seeded(o.seed);
    return [-8, 5, -3].flatMap((rotation, i) => {
      const x = a.x + i * (a.width - size) / 2;
      const y = a.y + (i === 1 ? 0 : size * 0.18) + random() * 20;
      return [
        shape("rect", { x: x + 8, y: y + 10, width: size, height: size * 1.18 }, { fill: "#000000", opacity: 0.18, rotation }),
        shape("rect", { x, y, width: size, height: size * 1.18 }, { fill: "#FFFFFF", rotation }),
        photo({ x: x + size * 0.06, y: y + size * 0.06, width: size * 0.88, height: size * 0.88 }, ctx.photoLabel, { rotation }),
      ];
    });
  };
  if (f.wide) return wide(t, ctx, (a) => frames({ x: a.x + 10, y: 120, width: a.width - 40, height: 400 }, 230));
  const { b, k, W } = room(f);
  const size = Math.min(380, b * 0.27);
  return [
    ...frames({ x: M - 10, y: 80 * k, width: W - M * 2, height: size * 1.3 }, size),
    title({ x: M, y: 80 * k + size * 1.55, width: W - M * 2, height: b * 0.24 }, ctx, t, 130 * k, 2, { align: "center" }),
    rule(W / 2 - 70, b - 190 * k, 140, t.accent, 4),
    kicker({ x: M, y: b - 160 * k, width: W - M * 2, height: 40 }, ctx.day, t.ink, t.body, 28 * k, { align: "center" }),
    text({ x: M, y: b - 110 * k, width: W - M * 2, height: 50 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.soft, align: "center" }),
  ];
};

/** 15. Memphis : formes géométriques en bordure, centre libre (soirée, jeunes, anniversaire). */
export const memphis: Layout = (f, t, ctx, o) => {
  const shapes = (a: Box) => {
    const random = seeded(o.seed);
    const colors = [t.accent, t.accent2, t.soft];
    const out: CanvasElement[] = [];
    const spots: [number, number][] = [[0.04, 0.05], [0.8, 0.04], [0.9, 0.3], [0.02, 0.4], [0.86, 0.62], [0.05, 0.75], [0.75, 0.88], [0.3, 0.02], [0.55, 0.9]];
    spots.forEach(([sx, sy], i) => {
      const x = a.x + sx * a.width;
      const y = a.y + sy * a.height;
      const s = 60 + random() * 70;
      const c = colors[i % 3];
      if (i % 4 === 0) out.push(shape("ellipse", { x, y, width: s, height: s }, { fill: c }));
      else if (i % 4 === 1) out.push(shape("rect", { x, y, width: s * 1.4, height: s * 0.36 }, { fill: c, rotation: random() * 60 - 30, cornerRadius: 6 }));
      else if (i % 4 === 2) out.push(shape("ellipse", { x, y, width: s, height: s }, { stroke: c, strokeWidth: 12 }));
      else out.push(...Array.from({ length: 3 }, (_, j) => shape("line", { x, y: y + j * 22, width: s, height: 6 }, { stroke: c, strokeWidth: 8, rotation: -20 })));
    });
    return out;
  };
  if (f.wide) return wide(t, ctx, (a) => shapes(a));
  const { b, k, W } = room(f);
  return [
    ...shapes({ x: 0, y: 0, width: W, height: b }),
    kicker({ x: M * 2, y: b * 0.2, width: W - M * 4, height: 36 }, ctx.organizer, t.soft, t.body, 26 * k, { align: "center" }),
    title({ x: M * 2, y: b * 0.27, width: W - M * 4, height: b * 0.34 }, ctx, t, 160 * k, 3, { align: "center", lineHeight: 0.95 }),
    shape("rect", { x: W / 2 - 290, y: b * 0.65, width: 580, height: 84 * k }, { fill: t.accent, cornerRadius: 12, rotation: -2 }),
    line({ x: W / 2 - 270, y: b * 0.65 + 20 * k, width: 540, height: 46 * k }, `${ctx.shortDate} · ${ctx.time}`, t.body, 36 * k, t.onAccent, { align: "center", rotation: -2 }),
    text({ x: M * 2, y: b * 0.76, width: W - M * 4, height: 60 * k }, ctx.venue, { font: t.body, fontSize: 30 * k, bold: true, fill: t.ink, align: "center" }),
  ];
};

/** 16. Lumière : halo descendant, croix, titre en capitales romaines (culte, veillée, conférence). */
export const light: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [...softGlow(a.x + a.width / 2, 260, 560, t.accent), icon("cross", a.x + a.width / 2 - 60, 200, 120, t.ink)]);
  const { b, k, W } = room(f);
  return [
    ...softGlow(W / 2, b * 0.2, Math.max(W, b) * 0.9, t.accent, 6),
    ...sunRays(W / 2, b * 0.2, b * 0.5, 18, t.accent, 0.12, 10),
    icon("cross", W / 2 - 55 * k, b * 0.12, 110 * k, t.ink),
    kicker({ x: M, y: b * 0.33, width: W - M * 2, height: 36 }, ctx.organizer, t.soft, t.body, 24 * k, { align: "center" }),
    title({ x: M, y: b * 0.39, width: W - M * 2, height: b * 0.28 }, ctx, t, 140 * k, 3, { align: "center" }),
    rule(W / 2 - 100, b * 0.7, 200, t.accent, 3),
    kicker({ x: M, y: b * 0.73, width: W - M * 2, height: 40 }, ctx.day, t.ink, t.body, 28 * k, { align: "center" }),
    text({ x: M, y: b * 0.73 + 52 * k, width: W - M * 2, height: 50 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.soft, align: "center" }),
  ];
};

/** 17. Programme : liste horaire avec heures en accent (conférence, culte, mariage). */
export const agenda: Layout = (f, t, ctx) => {
  const slots = ctx.labels.points.map((point, i) => [ctx.labels.slots[i] ?? "", point] as [string, string]);
  if (f.wide) return wide(t, ctx, (a) => [shape("rect", { x: a.x, y: 0, width: a.width, height: 630 }, { fill: t.card }), ...slots.flatMap(([time, point], i) => [line({ x: a.x + 50, y: 140 + i * 130, width: 140, height: 50 }, time, t.body, 40, t.accent), text({ x: a.x + 210, y: 140 + i * 130, width: a.width - 260, height: 100 }, point, { font: t.body, fontSize: 32, bold: true, fill: t.cardInk })])]);
  const { b, k, W } = room(f);
  const listTop = b * 0.46;
  const step = (b - listTop - 120 * k) / slots.length;
  return [
    kicker({ x: M, y: 64 * k, width: W - M * 2, height: 34 }, `${ctx.organizer}  ·  ${ctx.shortDate}`, t.accent, t.body, 24 * k),
    title({ x: M, y: 120 * k, width: W - M * 2, height: listTop - 220 * k }, ctx, t, 150 * k, 3),
    kicker({ x: M, y: listTop - 70 * k, width: W - M * 2, height: 36 }, ctx.labels.program, t.soft, t.body, 26 * k),
    ...slots.flatMap(([time, point], i) => [
      rule(M, listTop + i * step, W - M * 2, t.soft, 2, 0.6),
      line({ x: M, y: listTop + i * step + 24 * k, width: 220, height: 60 * k }, time, t.title, 52 * k, t.accent),
      text({ x: M + 250, y: listTop + i * step + 26 * k, width: W - M * 2 - 250, height: step - 40 * k }, point, { font: t.body, fontSize: fit(point, 40 * k, W - M * 2 - 250, 2, t.body, false, step - 40 * k), bold: true, fill: t.ink, lineHeight: 1.12 }),
    ]),
    text({ x: M, y: b - 90 * k, width: W - M * 2, height: 50 * k }, ctx.venue, { font: t.body, fontSize: 28 * k, fill: t.soft }),
  ];
};

/** 18. Grand invité : portrait en grand, bandeau nom, sujet en titre (conférence, culte, concert). */
export const keynote: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [photo({ x: a.x + 40, y: 40, width: a.width - 80, height: 550 }, ctx.labels.speaker, { cornerRadius: 30 }), shape("rect", { x: a.x + 40, y: 500, width: 320, height: 70 }, { fill: t.accent }), line({ x: a.x + 60, y: 516, width: 280, height: 40 }, ctx.labels.speaker, t.body, 30, t.onAccent)]);
  const { b, k, W } = room(f);
  const ph = b * 0.52;
  return [
    shape("rect", { x: 0, y: 0, width: W, height: ph * 0.7 }, { fill: t.accent2, opacity: 0.35 }),
    photo({ x: M, y: 60 * k, width: W - M * 2, height: ph }, ctx.labels.speaker, { cornerRadius: 36 }),
    shape("rect", { x: M + 30, y: 60 * k + ph - 50 * k, width: 420, height: 92 * k }, { fill: t.accent, cornerRadius: 10 }),
    kicker({ x: M + 54, y: 60 * k + ph - 38 * k, width: 380, height: 28 }, ctx.labels.with, t.onAccent, t.body, 20 * k),
    line({ x: M + 54, y: 60 * k + ph - 6 * k, width: 380, height: 40 * k }, ctx.labels.speaker, t.body, 34 * k, t.onAccent),
    title({ x: M, y: 60 * k + ph + 80 * k, width: W - M * 2, height: b - ph - 280 * k }, ctx, t, 120 * k, 3),
    rule(M, b - 120 * k, W - M * 2, t.soft, 2, 0.6),
    text({ x: M, y: b - 96 * k, width: W - M * 2, height: 50 * k }, `${ctx.date}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, bold: true, fill: t.ink }),
  ];
};

/** 19. Duo : deux portraits côte à côte et une pastille « & » (couple, featuring, débat). */
export const duo: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => [photo({ x: a.x + 20, y: 90, width: 250, height: 450 }, ctx.photoLabel, { cornerRadius: 125 }), photo({ x: a.x + 290, y: 90, width: 250, height: 450 }, ctx.photoLabel, { cornerRadius: 125 }), shape("ellipse", { x: a.x + 225, y: 270, width: 110, height: 110 }, { fill: t.accent }), line({ x: a.x + 235, y: 295, width: 90, height: 60 }, "&", t.title, 60, t.onAccent, { align: "center" })]);
  const { b, k, W } = room(f);
  const pw = (W - M * 2 - 30) / 2;
  const ph = Math.min(b * 0.48, pw * 1.5);
  const top = b * 0.3;
  return [
    kicker({ x: M, y: 60 * k, width: W - M * 2, height: 34 }, ctx.organizer, t.accent, t.body, 24 * k, { align: "center" }),
    title({ x: M, y: 110 * k, width: W - M * 2, height: top - 140 * k }, ctx, t, 120 * k, 2, { align: "center" }),
    photo({ x: M, y: top, width: pw, height: ph }, ctx.photoLabel, { cornerRadius: pw / 2 }),
    photo({ x: M + pw + 30, y: top, width: pw, height: ph }, ctx.photoLabel, { cornerRadius: pw / 2 }),
    shape("ellipse", { x: W / 2 - 70 * k, y: top + ph / 2 - 70 * k, width: 140 * k, height: 140 * k }, { fill: t.accent, stroke: pageColor(f, t, top + ph / 2), strokeWidth: 10 }),
    line({ x: W / 2 - 50 * k, y: top + ph / 2 - 40 * k, width: 100 * k, height: 80 * k }, "&", t.title, 80 * k, t.onAccent, { align: "center", lineHeight: 1 }),
    kicker({ x: M, y: top + ph + 50 * k, width: W - M * 2, height: 40 }, ctx.day, t.ink, t.body, 28 * k, { align: "center" }),
    text({ x: M, y: top + ph + 100 * k, width: W - M * 2, height: 50 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.soft, align: "center" }),
  ];
};

/** 20. Carte sur motif : fond à losanges, carte d'invitation au centre (mariage, gala, dîner). */
export const patternCard: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => diamonds(a, 90, t.accent, 0.35));
  const { b, k, W } = room(f);
  const card = { x: M, y: b * 0.12, width: W - M * 2, height: b * 0.76 };
  return [
    ...diamonds({ x: -40, y: -40, width: W + 80, height: b + 80 }, 200, t.accent, 0.28),
    shape("rect", card, { fill: t.card, cornerRadius: 8 }),
    shape("rect", { x: card.x + 22, y: card.y + 22, width: card.width - 44, height: card.height - 44 }, { stroke: t.cardSoft, strokeWidth: 2 }),
    kicker({ x: card.x + 60, y: card.y + 70 * k, width: card.width - 120, height: 36 }, ctx.labels.saveDate, t.cardSoft, t.body, 24 * k, { align: "center" }),
    title({ x: card.x + 60, y: card.y + 130 * k, width: card.width - 120, height: card.height * 0.42 }, ctx, t, 130 * k, 3, { align: "center", fill: t.cardInk }),
    rule(card.x + card.width / 2 - 70, card.y + card.height * 0.64, 140, t.cardSoft, 3),
    text({ x: card.x + 60, y: card.y + card.height * 0.68, width: card.width - 120, height: 50 * k }, ctx.day, { font: t.body, fontSize: 32 * k, bold: true, fill: t.cardInk, align: "center" }),
    text({ x: card.x + 60, y: card.y + card.height * 0.68 + 56 * k, width: card.width - 120, height: 90 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.cardSoft, align: "center" }),
  ];
};

/** 21. Mots empilés : un mot par ligne, couleurs alternées, très gros (soirée, concert, sport). */
export const stacked: Layout = (f, t, ctx) => {
  const all = caps(ctx.title, true).split(/\s+/).filter(Boolean);
  // Six lignes au plus : les mots en trop rejoignent la dernière.
  const words = all.length > 6 ? [...all.slice(0, 5), all.slice(5).join(" ")] : all;
  const stack = (a: Box, size: number) => {
    const h = a.height / words.length;
    return words.map((word, i) => line({ x: a.x, y: a.y + i * h, width: a.width, height: h }, word, t.title, Math.min(size, h * 1.05), i % 2 ? t.accent : t.ink, { lineHeight: 0.9 }));
  };
  if (f.wide) return [...stack({ x: 60, y: 40, width: 700, height: 470 }, 160), rule(60, 540, 120, t.accent, 6), text({ x: 60, y: 560, width: 1080, height: 40 }, `${ctx.date}  ·  ${ctx.venue}`, { font: t.body, fontSize: 26, bold: true, fill: t.soft })];
  const { b, k, W } = room(f);
  return [
    kicker({ x: M, y: 60 * k, width: W - M * 2, height: 34 }, ctx.organizer, t.soft, t.body, 24 * k),
    ...stack({ x: M, y: 120 * k, width: W - M * 2, height: b - 380 * k }, 260 * k),
    rule(M, b - 220 * k, 160, t.accent, 10),
    text({ x: M, y: b - 180 * k, width: W - M * 2, height: 50 * k }, ctx.date, { font: "mono", fontSize: 30 * k, bold: true, fill: t.ink }),
    text({ x: M, y: b - 130 * k, width: W - M * 2, height: 50 * k }, ctx.venue, { font: "mono", fontSize: 28 * k, fill: t.soft }),
  ];
};

/** 22. Pastille prix : étoile éclatée avec le prix, titre condensé, bouton de réservation (promo). */
export const priceBurst: Layout = (f, t, ctx) => {
  const burst = (cx: number, cy: number, d: number) => [
    shape("star", { x: cx - d / 2, y: cy - d / 2, width: d, height: d }, { fill: t.accent, rotation: 12 }),
    shape("star", { x: cx - d * 0.44, y: cy - d * 0.44, width: d * 0.88, height: d * 0.88 }, { fill: t.accent2, rotation: -10 }),
    shape("ellipse", { x: cx - d * 0.27, y: cy - d * 0.27, width: d * 0.54, height: d * 0.54 }, { fill: t.accent }),
    kicker({ x: cx - d * 0.25, y: cy - d * 0.16, width: d * 0.5, height: d * 0.08 }, ctx.labels.entry, t.onAccent, t.body, d * 0.05, { align: "center" }),
    line({ x: cx - d * 0.24, y: cy - d * 0.06, width: d * 0.48, height: d * 0.16 }, ctx.price, t.title, d * 0.14, t.onAccent, { align: "center", lineHeight: 1 }),
  ];
  if (f.wide) return wide(t, ctx, (a) => burst(a.x + a.width / 2, 315, 460));
  const { b, k, W } = room(f);
  const d = Math.min(470, b * 0.36);
  return [
    ...halftone({ x: 0, y: 0, width: W, height: b * 0.4 }, 60, t.accent2),
    title({ x: M, y: 80 * k, width: W - M * 2 - d * 0.4, height: b * 0.36 }, ctx, t, 160 * k, 3, { lineHeight: 0.95 }),
    ...burst(W - M - d * 0.4, b * 0.52, d),
    line({ x: M, y: b * 0.5, width: W - M * 2 - d * 0.9, height: 50 * k }, ctx.day, t.body, 32 * k, t.ink),
    text({ x: M, y: b * 0.5 + 60 * k, width: W - M * 2 - d * 0.9, height: 100 * k }, `${ctx.time} · ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.soft, lineHeight: 1.2 }),
    shape("rect", { x: M, y: b - 170 * k, width: W - M * 2, height: 100 * k }, { fill: t.ink, cornerRadius: 50 * k }),
    line({ x: M + 40, y: b - 145 * k, width: W - M * 2 - 80, height: 50 * k }, ctx.labels.book, t.body, 40 * k, t.bg, { align: "center" }),
  ];
};

/** 23. Page de calendrier : le jour entouré sur une feuille d'éphéméride (save the date). */
export const calendar: Layout = (f, t, ctx) => {
  const sheet = (a: Box) => [
    shape("rect", { x: a.x + 12, y: a.y + 14, width: a.width, height: a.height }, { fill: "#000000", opacity: 0.15, cornerRadius: 24 }),
    shape("rect", a, { fill: t.card, cornerRadius: 24 }),
    shape("rect", { x: a.x, y: a.y, width: a.width, height: a.height * 0.24 }, { fill: t.accent, cornerRadius: 24 }),
    shape("rect", { x: a.x, y: a.y + a.height * 0.14, width: a.width, height: a.height * 0.1 }, { fill: t.accent }),
    line({ x: a.x + 30, y: a.y + a.height * 0.05, width: a.width - 60, height: a.height * 0.15 }, `${ctx.month} ${ctx.year}`, t.body, a.height * 0.12, t.onAccent, { align: "center" }),
    line({ x: a.x + 30, y: a.y + a.height * 0.3, width: a.width - 60, height: a.height * 0.5 }, ctx.dayNumber, t.title, a.height * 0.5, t.cardInk, { align: "center", lineHeight: 1 }),
    shape("ellipse", { x: a.x + a.width * 0.2, y: a.y + a.height * 0.28, width: a.width * 0.6, height: a.height * 0.56 }, { stroke: t.accent, strokeWidth: 8, rotation: -4 }),
    kicker({ x: a.x + 30, y: a.y + a.height * 0.84, width: a.width - 60, height: a.height * 0.1 }, ctx.time, t.cardSoft, t.body, a.height * 0.07, { align: "center" }),
  ];
  if (f.wide) return wide(t, ctx, (a) => sheet({ x: a.x + 80, y: 70, width: 400, height: 480 }));
  const { b, k, W } = room(f);
  const sw = Math.min(560, b * 0.4);
  return [
    text({ x: M, y: 64 * k, width: W - M * 2, height: 90 * k }, ctx.labels.saveDate, { font: "script", fontSize: 90 * k, fill: t.accent, align: "center" }),
    ...sheet({ x: (W - sw) / 2, y: 180 * k, width: sw, height: sw * 1.05 }),
    title({ x: M, y: 180 * k + sw * 1.05 + 60 * k, width: W - M * 2, height: b - sw * 1.05 - 380 * k }, ctx, t, 120 * k, 2, { align: "center" }),
    text({ x: M, y: b - 110 * k, width: W - M * 2, height: 50 * k }, ctx.venue, { font: t.body, fontSize: 30 * k, fill: t.soft, align: "center" }),
  ];
};

/** 24. Vinyle : disque noir qui déborde, étiquette avec la date (concert, DJ, soirée). */
export const vinyl: Layout = (f, t, ctx) => {
  const disc = (cx: number, cy: number, d: number) => [
    shape("ellipse", { x: cx - d / 2, y: cy - d / 2, width: d, height: d }, { fill: "#111111" }),
    ...[0.9, 0.78, 0.66, 0.54].map((s) => shape("ellipse", { x: cx - (d * s) / 2, y: cy - (d * s) / 2, width: d * s, height: d * s }, { stroke: "#2A2A2A", strokeWidth: 3 })),
    shape("ellipse", { x: cx - d * 0.19, y: cy - d * 0.19, width: d * 0.38, height: d * 0.38 }, { fill: t.accent }),
    line({ x: cx - d * 0.15, y: cy - d * 0.08, width: d * 0.3, height: d * 0.1 }, ctx.dayNumber, t.title, d * 0.09, t.onAccent, { align: "center", lineHeight: 1 }),
    kicker({ x: cx - d * 0.15, y: cy + d * 0.03, width: d * 0.3, height: d * 0.05 }, ctx.month, t.onAccent, t.body, d * 0.03, { align: "center" }),
    shape("ellipse", { x: cx - d * 0.02, y: cy + d * 0.1, width: d * 0.04, height: d * 0.04 }, { fill: "#111111" }),
  ];
  if (f.wide) return wide(t, ctx, (a) => disc(a.x + a.width * 0.6, 315, 640));
  const { b, k, W } = room(f);
  const d = Math.min(W * 1.05, b * 0.62);
  return [
    ...disc(W * 0.72, b * 0.34, d),
    icon("music", M, 70 * k, 70 * k, t.accent),
    title({ x: M, y: b * 0.66, width: W - M * 2, height: b * 0.18 }, ctx, t, 140 * k, 2),
    rule(M, b * 0.87, W - M * 2, t.soft, 2, 0.7),
    text({ x: M, y: b * 0.89, width: W / 2 - M - 10, height: 50 * k }, `${ctx.shortDate} · ${ctx.time}`, { font: t.body, fontSize: 30 * k, bold: true, fill: t.ink }),
    text({ x: W / 2, y: b * 0.89, width: W / 2 - M, height: 50 * k }, ctx.venue, { font: t.body, fontSize: 28 * k, fill: t.soft, align: "right" }),
  ];
};

/** 25. Onde : barres d'égaliseur, micro, badge « en direct » (podcast, live, radio). */
export const waveform: Layout = (f, t, ctx, o) => {
  const bars = (a: Box) => {
    const random = seeded(o.seed);
    const n = 28;
    const w = a.width / n;
    return Array.from({ length: n }, (_, i) => {
      const h = a.height * (0.15 + 0.85 * Math.abs(Math.sin(i * 0.55)) * (0.6 + random() * 0.4));
      return shape("rect", { x: a.x + i * w + w * 0.2, y: a.y + (a.height - h) / 2, width: w * 0.6, height: h }, { fill: i % 5 === 0 ? t.accent2 : t.accent, cornerRadius: w * 0.3 });
    });
  };
  if (f.wide) return wide(t, ctx, (a) => [...bars({ x: a.x + 20, y: 160, width: a.width - 60, height: 310 })]);
  const { b, k, W } = room(f);
  return [
    shape("rect", { x: M, y: 64 * k, width: 230, height: 60 * k }, { fill: t.accent2, cornerRadius: 30 * k }),
    shape("ellipse", { x: M + 26, y: 64 * k + 20 * k, width: 20 * k, height: 20 * k }, { fill: t.onAccent }),
    line({ x: M + 56, y: 64 * k + 12 * k, width: 160, height: 36 * k }, ctx.labels.live, t.body, 28 * k, t.onAccent),
    icon("mic", W - M - 90 * k, 50 * k, 90 * k, t.accent),
    ...bars({ x: M - 20, y: b * 0.17, width: W - M * 2 + 40, height: b * 0.28 }),
    title({ x: M, y: b * 0.52, width: W - M * 2, height: b * 0.26 }, ctx, t, 140 * k, 3),
    text({ x: M, y: b - 150 * k, width: W - M * 2, height: 50 * k }, `${ctx.day} · ${ctx.time}`, { font: t.body, fontSize: 32 * k, bold: true, fill: t.ink }),
    text({ x: M, y: b - 100 * k, width: W - M * 2, height: 50 * k }, ctx.labels.online, { font: t.body, fontSize: 28 * k, fill: t.soft }),
  ];
};

/** 26. Trophée : grand trophée dans un médaillon et lauriers de pétales (remise de prix, gala). */
export const award: Layout = (f, t, ctx) => {
  const medal = (cx: number, cy: number, d: number) => [
    ...Array.from({ length: 9 }, (_, i) => {
      const a = (200 + i * 16) * (Math.PI / 180);
      return shape("ellipse", { x: cx + Math.cos(a) * d * 0.62 - d * 0.07, y: cy - Math.sin(a) * d * 0.62 - d * 0.03, width: d * 0.16, height: d * 0.07 }, { fill: t.accent, rotation: -(200 + i * 16) + 90 });
    }),
    ...Array.from({ length: 9 }, (_, i) => {
      const a = (-20 - i * 16) * (Math.PI / 180);
      return shape("ellipse", { x: cx + Math.cos(a) * d * 0.62 - d * 0.07, y: cy - Math.sin(a) * d * 0.62 - d * 0.03, width: d * 0.16, height: d * 0.07 }, { fill: t.accent, rotation: 20 + i * 16 + 90 });
    }),
    shape("ellipse", { x: cx - d / 2, y: cy - d / 2, width: d, height: d }, { stroke: t.accent, strokeWidth: 6 }),
    icon("trophy", cx - d * 0.3, cy - d * 0.3, d * 0.6, t.accent),
  ];
  if (f.wide) return wide(t, ctx, (a) => medal(a.x + a.width / 2, 300, 300));
  const { b, k, W } = room(f);
  const d = Math.min(380, b * 0.28);
  return [
    ...softGlow(W / 2, b * 0.26, d * 2.6, t.accent2, 4),
    ...medal(W / 2, b * 0.26, d),
    kicker({ x: M, y: b * 0.26 + d * 0.8, width: W - M * 2, height: 36 }, ctx.organizer, t.accent, t.body, 24 * k, { align: "center" }),
    title({ x: M, y: b * 0.26 + d * 0.8 + 56 * k, width: W - M * 2, height: b - 210 * k - (b * 0.26 + d * 0.8 + 56 * k) }, ctx, t, 130 * k, 3, { align: "center", letterSpacing: 2 }),
    rule(W / 2 - 90, b - 190 * k, 180, t.accent, 2),
    kicker({ x: M, y: b - 160 * k, width: W - M * 2, height: 40 }, ctx.day, t.ink, t.body, 26 * k, { align: "center" }),
    text({ x: M, y: b - 112 * k, width: W - M * 2, height: 50 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 26 * k, fill: t.soft, align: "center" }),
  ];
};

/** 27. Ballons : grappe de ballons en haut, âge ou titre (anniversaire, fête d'enfants). */
export const balloons: Layout = (f, t, ctx, o) => {
  const bunch = (a: Box) => {
    const random = seeded(o.seed);
    const colors = [t.accent, t.accent2, t.soft, t.card];
    return Array.from({ length: 7 }, (_, i) => {
      const s = a.width * (0.2 + random() * 0.08);
      const x = a.x + (i / 6) * (a.width - s);
      const y = a.y + (i % 2 ? a.height * 0.2 : 0) + random() * a.height * 0.15;
      const c = colors[i % colors.length];
      return [
        shape("line", { x: x + s / 2, y: y + s * 1.18, width: a.height * 0.5, height: 4 }, { stroke: t.soft, strokeWidth: 2, rotation: 90 - (i - 3) * 6 }),
        shape("ellipse", { x, y, width: s, height: s * 1.18 }, { fill: c }),
        shape("ellipse", { x: x + s * 0.22, y: y + s * 0.18, width: s * 0.16, height: s * 0.26 }, { fill: "#FFFFFF", opacity: 0.45, rotation: 20 }),
      ];
    }).flat();
  };
  const big = o.label ?? ctx.dayNumber;
  if (f.wide) return wide(t, ctx, (a) => [...bunch({ x: a.x + 20, y: 20, width: a.width - 40, height: 330 }), line({ x: a.x, y: 380, width: a.width, height: 220 }, big, t.title, 220, t.accent, { align: "center" })]);
  const { b, k, W } = room(f);
  return [
    ...bunch({ x: M - 30, y: 30 * k, width: W - M * 2 + 60, height: b * 0.32 }),
    line({ x: M, y: b * 0.4, width: W - M * 2, height: b * 0.24 }, big, t.title, b * 0.24, t.accent, { align: "center", lineHeight: 1 }),
    title({ x: M, y: b * 0.65, width: W - M * 2, height: b * 0.16 }, ctx, t, 110 * k, 2, { align: "center" }),
    kicker({ x: M, y: b - 150 * k, width: W - M * 2, height: 40 }, ctx.day, t.ink, t.body, 26 * k, { align: "center" }),
    text({ x: M, y: b - 100 * k, width: W - M * 2, height: 50 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.soft, align: "center" }),
  ];
};

/** 28. Barre d'entreprise : colonne de couleur, logo, titre, trois faits clés (séminaire, lancement). */
export const corporate: Layout = (f, t, ctx) => {
  const rows: [IconName, string][] = [["calendar", ctx.day], ["clock", ctx.time], ["pin", ctx.venue]];
  if (f.wide) return wide(t, ctx, (a) => [shape("rect", { x: a.x + 120, y: 0, width: 440, height: 630 }, { fill: t.accent }), shape("rect", { x: a.x + 100, y: 0, width: 12, height: 630 }, { fill: t.accent2 }), line({ x: a.x + 160, y: 150, width: 360, height: 240 }, ctx.dayNumber, t.title, 240, t.onAccent, { lineHeight: 1 }), kicker({ x: a.x + 160, y: 410, width: 360, height: 40 }, `${ctx.month} ${ctx.year}`, t.onAccent, t.body, 30)]);
  const { b, k, W } = room(f);
  const bar = 150;
  const step = 90 * k;
  return [
    shape("rect", { x: 0, y: 0, width: bar, height: b }, { fill: t.accent }),
    shape("rect", { x: bar, y: 0, width: 10, height: b }, { fill: t.accent2 }),
    photo({ x: bar + 60, y: 60 * k, width: 220, height: 90 * k }, ctx.labels.logo, { fit: "contain" }),
    kicker({ x: bar + 60, y: 200 * k, width: W - bar - 120, height: 34 }, ctx.organizer, t.accent, t.body, 22 * k),
    title({ x: bar + 60, y: 250 * k, width: W - bar - 120, height: b - 250 * k - step * 3 - 140 * k }, ctx, t, 130 * k, 4),
    ...rows.flatMap(([name, value], i) => {
      const y = b - 90 * k - step * (3 - i);
      return [
        shape("ellipse", { x: bar + 60, y, width: 64 * k, height: 64 * k }, { fill: t.accent }),
        icon(name, bar + 60 + 14 * k, y + 14 * k, 36 * k, t.onAccent),
        text({ x: bar + 150, y: y + 12 * k, width: W - bar - 210, height: 60 * k }, value, { font: t.body, fontSize: fit(value, 32 * k, W - bar - 210, 1, t.body), bold: true, fill: t.ink }),
      ];
    }),
  ];
};

/** 29. Liste à cocher : points forts cochés, étiquette de prix (formation, atelier, webinaire). */
export const checklist: Layout = (f, t, ctx) => {
  const points = ctx.labels.points;
  if (f.wide) return wide(t, ctx, (a) => [shape("rect", { x: a.x, y: 0, width: a.width, height: 630 }, { fill: t.card }), ...points.flatMap((point, i) => [shape("ellipse", { x: a.x + 50, y: 150 + i * 120, width: 60, height: 60 }, { fill: t.accent }), icon("check", a.x + 62, 162 + i * 120, 36, t.onAccent), text({ x: a.x + 140, y: 160 + i * 120, width: a.width - 180, height: 80 }, point, { font: t.body, fontSize: 30, bold: true, fill: t.cardInk })])]);
  const { b, k, W } = room(f);
  const top = b * 0.44;
  const step = (b - top - 230 * k) / points.length;
  return [
    kicker({ x: M, y: 64 * k, width: W - M * 2, height: 34 }, ctx.organizer, t.accent, t.body, 24 * k),
    title({ x: M, y: 120 * k, width: W - M * 2 - 260 * k, height: top - 160 * k }, ctx, t, 140 * k, 3),
    shape("star", { x: W - M - 230 * k, y: 100 * k, width: 230 * k, height: 230 * k }, { fill: t.accent, rotation: 10 }),
    line({ x: W - M - 170 * k, y: 196 * k, width: 110 * k, height: 40 * k }, ctx.price, t.title, 40 * k, t.onAccent, { align: "center" }),
    ...points.flatMap((point, i) => [
      shape("ellipse", { x: M, y: top + i * step, width: 64 * k, height: 64 * k }, { fill: t.accent }),
      icon("check", M + 14 * k, top + i * step + 14 * k, 36 * k, t.onAccent),
      text({ x: M + 96 * k, y: top + i * step + 10 * k, width: W - M * 2 - 96 * k, height: step - 10 * k }, point, { font: t.body, fontSize: fit(point, 38 * k, W - M * 2 - 96 * k, 2, t.body, false, step - 20 * k), bold: true, fill: t.ink, lineHeight: 1.12 }),
    ]),
    shape("rect", { x: M, y: b - 170 * k, width: W - M * 2, height: 100 * k }, { fill: t.accent2, cornerRadius: 16 }),
    line({ x: M + 30, y: b - 145 * k, width: W - M * 2 - 60, height: 50 * k }, `${ctx.shortDate} · ${ctx.time} · ${ctx.city}`, t.body, 34 * k, t.onAccent, { align: "center" }),
  ];
};

/** 30. Orbite : portrait rond au centre d'anneaux fins pointés (artiste, invité d'honneur). */
export const orbit: Layout = (f, t, ctx) => {
  const rings = (cx: number, cy: number, d: number) => [
    ...[1.5, 1.25].map((s, i) => shape("ellipse", { x: cx - (d * s) / 2, y: cy - (d * s) / 2, width: d * s, height: d * s }, { stroke: i ? t.accent2 : t.accent, strokeWidth: 3, opacity: 0.8 })),
    shape("ellipse", { x: cx + d * 0.7 - 16, y: cy - d * 0.25, width: 32, height: 32 }, { fill: t.accent }),
    shape("ellipse", { x: cx - d * 0.62 - 12, y: cy + d * 0.3, width: 24, height: 24 }, { fill: t.accent2 }),
    photo({ x: cx - d / 2, y: cy - d / 2, width: d, height: d }, ctx.photoLabel, { cornerRadius: d / 2 }),
  ];
  if (f.wide) return wide(t, ctx, (a) => rings(a.x + a.width / 2, 315, 330));
  const { b, k, W } = room(f);
  const d = Math.min(470, b * 0.34);
  const cy = 80 * k + d * 0.75;
  return [
    ...rings(W / 2, cy, d),
    kicker({ x: M, y: cy + d * 0.8, width: W - M * 2, height: 36 }, ctx.organizer, t.accent, t.body, 24 * k, { align: "center" }),
    title({ x: M, y: cy + d * 0.8 + 56 * k, width: W - M * 2, height: b - cy - d * 0.8 - 260 * k }, ctx, t, 140 * k, 3, { align: "center" }),
    kicker({ x: M, y: b - 150 * k, width: W - M * 2, height: 40 }, ctx.day, t.ink, t.body, 26 * k, { align: "center" }),
    text({ x: M, y: b - 100 * k, width: W - M * 2, height: 50 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.soft, align: "center" }),
  ];
};

/** 31. Demi-teinte : trame de points en coin, titre massif aligné à gauche (moderne, urbain). */
export const halftoneBold: Layout = (f, t, ctx) => {
  if (f.wide) return wide(t, ctx, (a) => halftone(a, 56, t.accent));
  const { b, k, W } = room(f);
  return [
    ...halftone({ x: W * 0.35, y: 0, width: W * 0.65, height: b * 0.45 }, 56, t.accent),
    shape("rect", { x: M, y: 70 * k, width: 16, height: 120 * k }, { fill: t.accent }),
    kicker({ x: M + 40, y: 74 * k, width: 500, height: 34 }, ctx.organizer, t.ink, t.body, 24 * k),
    kicker({ x: M + 40, y: 124 * k, width: 500, height: 34 }, ctx.shortDate, t.soft, t.body, 24 * k),
    title({ x: M, y: b * 0.42, width: W - M * 2, height: b * 0.34 }, ctx, t, 190 * k, 3, { lineHeight: 0.92 }),
    shape("rect", { x: M, y: b - 150 * k, width: W - M * 2, height: 86 * k }, { fill: t.accent }),
    line({ x: M + 30, y: b - 130 * k, width: W - M * 2 - 60, height: 46 * k }, `${ctx.time}  ·  ${ctx.venue}`, t.body, 32 * k, t.onAccent),
  ];
};

/** 32. Vitrail : arche découpée en vitraux de couleur, titre dessous (culte, chorale, mariage religieux). */
export const stainedGlass: Layout = (f, t, ctx) => {
  const glass = (a: Box) => {
    const r = a.width / 2;
    const inset = 18;
    const inner = { x: a.x + inset, y: a.y + inset, width: a.width - inset * 2, height: a.height - inset };
    const cx = a.x + r;
    const rose = inner.width * 0.62;
    const roseY = a.y + r - rose * 0.42;
    const lead = (box: Box) => backdrop(f, t, box);
    const cols = 4;
    const cw = inner.width / cols;
    const top = a.y + r + rose * 0.3;
    return [
      shape("rect", a, { fill: t.ink, cornerRadius: r }),
      shape("rect", inner, { fill: t.accent, cornerRadius: inner.width / 2 }),
      // Vitraux du bas : colonnes de couleurs alternées séparées par des plombs.
      ...Array.from({ length: cols }, (_, i) => shape("rect", { x: inner.x + i * cw, y: top, width: cw, height: inner.y + inner.height - top }, { fill: [t.accent2, t.accent, t.soft, t.accent2][i] })),
      ...Array.from({ length: cols - 1 }, (_, i) => lead({ x: inner.x + (i + 1) * cw - 3, y: top, width: 6, height: inner.y + inner.height - top })),
      lead({ x: inner.x, y: top - 3, width: inner.width, height: 6 }),
      lead({ x: inner.x, y: top + (inner.y + inner.height - top) * 0.5 - 3, width: inner.width, height: 6 }),
      // Rosace : disque, couronne et rayons.
      shape("ellipse", { x: cx - rose / 2, y: roseY, width: rose, height: rose }, { fill: t.accent2, stroke: pageColor(f, t, roseY + rose / 2), strokeWidth: 6 }),
      ...sunRays(cx, roseY + rose / 2, rose / 2, 8, pageColor(f, t, roseY + rose / 2), 1, 6),
      shape("ellipse", { x: cx - rose * 0.2, y: roseY + rose * 0.3, width: rose * 0.4, height: rose * 0.4 }, { fill: t.accent, stroke: pageColor(f, t, roseY + rose / 2), strokeWidth: 6 }),
    ];
  };
  if (f.wide) return wide(t, ctx, (a) => glass({ x: a.x + 120, y: 60, width: 320, height: 560 }));
  const { b, k, W } = room(f);
  const gw = Math.min(460, b * 0.33);
  const gh = b * 0.5;
  return [
    ...glass({ x: (W - gw) / 2, y: 60 * k, width: gw, height: gh }),
    backdrop(f, t, { x: 0, y: 60 * k + gh, width: W, height: b - 60 * k - gh }),
    rule(M, 60 * k + gh, W - M * 2, t.ink, 3),
    title({ x: M, y: 60 * k + gh + 50 * k, width: W - M * 2, height: b - gh - 260 * k }, ctx, t, 130 * k, 3, { align: "center" }),
    kicker({ x: M, y: b - 140 * k, width: W - M * 2, height: 40 }, ctx.day, t.accent, t.body, 26 * k, { align: "center" }),
    text({ x: M, y: b - 92 * k, width: W - M * 2, height: 50 * k }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 28 * k, fill: t.soft, align: "center" }),
  ];
};
