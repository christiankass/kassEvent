import { format } from "./i18n/dictionaries";
import {
  FORMATS,
  newId,
  type CanvasDoc,
  type CanvasElement,
  type CanvasFont,
  type FieldElement,
  type FieldName,
  type FormatId,
  type IconElement,
  type IconName,
  type ImageElement,
  type ShapeElement,
  type TextElement,
  type Usage,
} from "./canvas";
import { PALETTES, type PaletteId } from "./canvas-palettes";

/**
 * Bibliothèque de modèles du studio. Un modèle = une composition (photo pleine page, personnage
 * détouré, citation, titre centré, carte d'infos, programme, intervenants…) habillée d'un thème
 * (couleurs, polices, décor). Chaque modèle se dessine pour l'affiche comme pour le billet, et dans
 * tous les formats : portrait 4:5, carré, story 9:16, paysage. Tout reste modifiable ensuite.
 */

export const CATEGORIES = ["all", "concert", "party", "wedding", "birthday", "worship", "gala", "conference", "online", "sport", "business"] as const;
export type Category = (typeof CATEGORIES)[number];

/** Filtres de style de la galerie. */
export const STYLES = ["photo", "typo", "elegant", "colorful", "dark"] as const;
export type TemplateStyle = (typeof STYLES)[number];

/** Textes de l'événement et libellés traduits placés dans les modèles. */
export type TemplateContext = {
  title: string;
  date: string;
  day: string;
  /** Date courte : « 19 DÉC 2026 ». */
  shortDate: string;
  time: string;
  dayNumber: string;
  month: string;
  year: string;
  venue: string;
  city: string;
  organizer: string;
  price: string;
  guestLabel: string;
  photoLabel: string;
  labels: {
    program: string;
    points: string[];
    book: string;
    entryPrice: string;
    with: string;
    speaker: string;
    logo: string;
    live: string;
    online: string;
    quote: string;
    author: string;
    invite: string;
    saveDate: string;
    rsvp: string;
    contact: string;
    versus: string;
  };
};

// --- Cadre de page --------------------------------------------------------------------------------

/** Page en cours de dessin : `bottom` est le bas de la zone libre (au-dessus du talon d'un billet). */
type Frame = { w: number; h: number; usage: Usage; format: FormatId; wide: boolean; bottom: number; k: number };

/** Hauteur du talon d'un billet (nom de l'invité, type, code, QR code), selon le format. */
const STUB: Record<FormatId, number> = { portrait: 410, square: 330, story: 460, landscape: 0 };

function frame(usage: Usage, format: FormatId): Frame {
  const [w, h] = FORMATS[format];
  const wide = format === "landscape";
  const bottom = usage === "ticket" ? h - STUB[format] : h;
  return { w, h, usage, format, wide, bottom, k: wide ? 0.62 : Math.min(1.25, Math.max(0.68, bottom / 1000)) };
}

// --- Thèmes ---------------------------------------------------------------------------------------

export type Theme = {
  bg: string;
  /** Seconde couleur du fond : dégradé de haut en bas. */
  bg2?: string;
  ink: string;
  soft: string;
  accent: string;
  accent2: string;
  /** Texte posé sur l'accent. */
  onAccent: string;
  card: string;
  cardInk: string;
  cardSoft: string;
  title: CanvasFont;
  body: CanvasFont;
  upper?: boolean;
  italic?: boolean;
  stub: "card" | "band" | "open";
};

// --- Briques --------------------------------------------------------------------------------------

type Box = { x: number; y: number; width: number; height: number };

const text = (box: Box, value: string, style: Partial<TextElement> = {}): TextElement => ({
  id: newId(),
  type: "text",
  rotation: 0,
  font: "instrument",
  fontSize: 40,
  fill: "#FFFFFF",
  align: "left",
  lineHeight: 1.15,
  ...round(box),
  ...style,
  text: value,
});

const field = (box: Box, name: FieldName, style: Partial<FieldElement> = {}): FieldElement => ({
  id: newId(),
  type: "field",
  rotation: 0,
  font: "instrument",
  fontSize: 44,
  fill: "#FFFFFF",
  align: "left",
  lineHeight: 1.2,
  ...round(box),
  ...style,
  field: name,
});

const shape = (type: ShapeElement["type"], box: Box, style: Partial<ShapeElement> = {}): ShapeElement => ({
  id: newId(),
  type,
  rotation: 0,
  ...round(box),
  ...style,
});

const photo = (box: Box, name: string, style: Partial<ImageElement> = {}): ImageElement => ({
  id: newId(),
  type: "image",
  rotation: 0,
  asset: null,
  cornerRadius: 0,
  name,
  ...round(box),
  ...style,
});

const icon = (name: IconName, x: number, y: number, size: number, fill: string, style: Partial<IconElement> = {}): IconElement => ({
  id: newId(),
  type: "icon",
  rotation: 0,
  icon: name,
  fill,
  ...round({ x, y, width: size, height: size }),
  ...style,
});

const qr = (x: number, y: number, size: number): CanvasElement => ({ id: newId(), type: "qr", rotation: 0, ...round({ x, y, width: size, height: size }) });

function round(box: Box): Box {
  return { x: Math.round(box.x), y: Math.round(box.y), width: Math.max(1, Math.round(box.width)), height: Math.max(1, Math.round(box.height)) };
}

/** Largeur moyenne d'un caractère, en part de la taille de police : pour choisir une taille qui tient. */
const GLYPH: Record<CanvasFont, number> = {
  fraunces: 0.56,
  playfair: 0.54,
  instrument: 0.53,
  poppins: 0.64,
  anton: 0.45,
  fredoka: 0.56,
  cinzel: 0.74,
  script: 0.4,
  pacifico: 0.6,
  mono: 0.6,
};

/**
 * Plus grande taille (≤ `size`) pour que `value` tienne en `lines` lignes de `width` pixels, et,
 * avec `height`, dans cette hauteur.
 */
function fit(value: string, size: number, width: number, lines: number, font: CanvasFont, upper = false, height = Infinity): number {
  const glyph = GLYPH[font] * (upper ? 1.12 : 1);
  const words = value.split(/\s+/).filter(Boolean);
  let current = size;
  while (current > 24) {
    const needed = measure
      ? measuredLines(words, width * 0.96, (word) => measure!(word, font, current))
      : wrappedLines(words, Math.max(1, Math.floor(width / (current * glyph))));
    if (needed <= lines && needed * current * 1.05 <= height) break;
    current -= 4;
  }
  return Math.round(current);
}

/** Largeur réelle d'un texte en gras, en pixels : posée par le studio une fois ses polices chargées. */
export type TextMeasure = (value: string, font: CanvasFont, size: number) => number;
let measure: TextMeasure | null = null;

/**
 * Avec les vraies largeurs, chaque titre tient dans son cadre quelle que soit la paire de polices du
 * modèle ; sans elles (avant le chargement des polices), une estimation par caractère.
 */
export function setTextMeasure(value: TextMeasure | null): void {
  measure = value;
}

/** Nombre de lignes d'un texte coupé aux espaces, mesuré mot à mot (Infinity : mot trop long). */
function measuredLines(words: string[], width: number, widthOf: (value: string) => number): number {
  let lines = 1;
  let line = "";
  for (const word of words) {
    if (widthOf(word) > width) return Infinity;
    const next = line ? `${line} ${word}` : word;
    if (!line || widthOf(next) <= width) line = next;
    else {
      lines += 1;
      line = word;
    }
  }
  return lines;
}

/** Nombre de lignes d'un texte coupé aux espaces, `perLine` caractères par ligne (Infinity : mot trop long). */
function wrappedLines(words: string[], perLine: number): number {
  let lines = 1;
  let used = 0;
  for (const word of words) {
    if (word.length > perLine) return Infinity;
    if (used === 0) used = word.length;
    else if (used + 1 + word.length <= perLine) used += 1 + word.length;
    else {
      lines += 1;
      used = word.length;
    }
  }
  return lines;
}

const caps = (value: string, upper?: boolean) => (upper ? value.toUpperCase() : value);

/** Titre de l'événement qui tient dans son cadre. */
function title(box: Box, ctx: TemplateContext, t: Theme, size: number, lines: number, style: Partial<TextElement> = {}): TextElement {
  const font = style.font ?? t.title;
  const upper = style.font ? false : t.upper;
  const value = caps(ctx.title, upper);
  const fontSize = fit(value, size, box.width, lines, font, upper, box.height);
  return text(box, value, {
    font,
    fontSize,
    bold: true,
    italic: t.italic,
    fill: t.ink,
    lineHeight: 1.02,
    ...style,
  });
}

/** Pastille arrondie avec un texte centré. */
function pill(box: Box, value: string, fill: string, ink: string, font: CanvasFont, size: number, style: Partial<TextElement> = {}): CanvasElement[] {
  return [
    shape("rect", box, { fill, cornerRadius: box.height / 2 }),
    text({ x: box.x + 16, y: box.y + (box.height - size * 1.15) / 2, width: box.width - 32, height: size * 1.2 }, value, {
      font,
      fontSize: size,
      bold: true,
      fill: ink,
      align: "center",
      ...style,
    }),
  ];
}

/** Bandeau d'infos : pictogramme + texte, en colonnes égales (ou en lignes avec `stack`). */
function infoRow(
  box: Box,
  items: { icon: IconName; text: string }[],
  o: { ink: string; iconColor: string; font: CanvasFont; size: number; stack?: boolean; bold?: boolean },
): CanvasElement[] {
  const iconSize = Math.round(o.size * 1.35);
  return items.flatMap((item, i) => {
    const cell = o.stack
      ? { x: box.x, y: box.y + i * (box.height / items.length), width: box.width, height: box.height / items.length }
      : { x: box.x + i * (box.width / items.length), y: box.y, width: box.width / items.length, height: box.height };
    const top = cell.y + (Math.min(cell.height, iconSize * 1.6) - iconSize) / 2;
    return [
      icon(item.icon, cell.x, top, iconSize, o.iconColor),
      text({ x: cell.x + iconSize + 14, y: top + (iconSize - o.size * 1.2) / 2, width: cell.width - iconSize - 24, height: o.size * 2.5 }, item.text, {
        font: o.font,
        fontSize: o.size,
        bold: o.bold ?? true,
        fill: o.ink,
        lineHeight: 1.15,
      }),
    ];
  });
}

/** Liste à puces (programme). */
function bullets(box: Box, items: string[], o: { ink: string; iconColor: string; font: CanvasFont; size: number; icon?: IconName; rule?: string }): CanvasElement[] {
  const step = box.height / items.length;
  return items.flatMap((item, i) => {
    const y = box.y + i * step;
    return [
      icon(o.icon ?? "chevrons", box.x, y + 2, o.size * 1.2, o.iconColor),
      text({ x: box.x + o.size * 1.6, y, width: box.width - o.size * 1.6, height: step * 0.9 }, item, { font: o.font, fontSize: o.size, bold: true, fill: o.ink }),
      ...(o.rule && i < items.length - 1 ? [shape("line", { x: box.x + o.size * 1.6, y: y + step - 12, width: box.width - o.size * 1.6, height: 4 }, { stroke: o.rule, strokeWidth: 2 })] : []),
    ];
  });
}

/** Bande de logos des partenaires. */
function sponsors(box: Box, count: number, ctx: TemplateContext, bg: string): CanvasElement[] {
  const gap = 18;
  const size = Math.min(box.height - 24, (box.width - gap * (count + 1)) / count);
  const start = box.x + (box.width - (size * count + gap * (count - 1))) / 2;
  return [
    shape("rect", box, { fill: bg }),
    ...Array.from({ length: count }, (_, i) =>
      photo({ x: start + i * (size + gap), y: box.y + (box.height - size) / 2, width: size, height: size }, ctx.labels.logo, { fit: "contain" }),
    ),
  ];
}

/** Suite pseudo-aléatoire fixe : un modèle se dessine toujours pareil. */
function seeded(seed: number) {
  return () => {
    seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

// --- Décors ---------------------------------------------------------------------------------------

const glow = (x: number, y: number, size: number, color: string, opacity = 0.5) => shape("ellipse", { x: x - size / 2, y: y - size / 2, width: size, height: size }, { fill: color, opacity });

function confetti(area: Box, colors: string[], count: number, seed: number): CanvasElement[] {
  const random = seeded(seed);
  return Array.from({ length: count }, (_, i) => {
    const size = 14 + random() * 26;
    const x = area.x + random() * area.width;
    const y = area.y + random() * area.height;
    const color = colors[i % colors.length];
    return i % 3 === 0
      ? shape("ellipse", { x, y, width: size, height: size }, { fill: color })
      : shape("rect", { x, y, width: size * 0.5, height: size * 1.4 }, { fill: color, rotation: random() * 360, cornerRadius: 4 });
  });
}

/** Cercles concentriques façon wax. */
function waxCircles(area: Box, colors: string[], ring: string, count: number, seed: number): CanvasElement[] {
  const random = seeded(seed);
  return Array.from({ length: count }, (_, i) => {
    const size = 140 + random() * 260;
    const x = area.x + random() * area.width - size / 2;
    const y = area.y + random() * area.height - size / 2;
    return [
      shape("ellipse", { x, y, width: size, height: size }, { fill: colors[i % colors.length], opacity: 0.92 }),
      shape("ellipse", { x: x + size * 0.25, y: y + size * 0.25, width: size / 2, height: size / 2 }, { stroke: ring, strokeWidth: 14 }),
    ];
  }).flat();
}

/** Rayons de soleil partant d'un point. */
function rays(cx: number, cy: number, length: number, count: number, color: string, opacity: number): CanvasElement[] {
  return Array.from({ length: count }, (_, i) =>
    shape("rect", { x: cx, y: cy - 22, width: length, height: 44 }, { fill: color, opacity, rotation: (360 / count) * i }),
  );
}

/** Pétales (ellipses tournées), pour les mariages. */
function petals(cx: number, cy: number, size: number, colors: string[], count: number): CanvasElement[] {
  return Array.from({ length: count }, (_, i) =>
    shape("ellipse", { x: cx, y: cy, width: size, height: size * 0.42 }, { fill: colors[i % colors.length], opacity: 0.85, rotation: (360 / count) * i }),
  );
}

/** Points réguliers. */
function dots(area: Box, step: number, size: number, color: string, opacity: number): CanvasElement[] {
  const out: CanvasElement[] = [];
  for (let y = area.y; y < area.y + area.height; y += step) {
    for (let x = area.x; x < area.x + area.width; x += step) out.push(shape("ellipse", { x, y, width: size, height: size }, { fill: color, opacity }));
  }
  return out.slice(0, 48);
}

/** Double filet d'encadrement. */
function frameLines(f: Frame, color: string, inset = 30): CanvasElement[] {
  return [
    shape("rect", { x: inset, y: inset, width: f.w - inset * 2, height: f.h - inset * 2 }, { stroke: color, strokeWidth: 4 }),
    shape("rect", { x: inset + 16, y: inset + 16, width: f.w - inset * 2 - 32, height: f.h - inset * 2 - 32 }, { stroke: color, strokeWidth: 1.5 }),
  ];
}

// --- Talon du billet ------------------------------------------------------------------------------

/** Bas du billet : nom de l'invité, type de billet, code et QR code. */
function stub(f: Frame, t: Theme, ctx: TemplateContext): CanvasElement[] {
  const top = f.bottom;
  const size = { portrait: 270, square: 230, story: 300, landscape: 0 }[f.format];
  const holder = f.format === "square" ? 44 : 50;
  const lines = (x: number, y: number, ink: string, soft: string) => [
    text({ x, y, width: 520, height: 36 }, ctx.guestLabel.toUpperCase(), { fontSize: 26, bold: true, letterSpacing: 3, fill: soft }),
    field({ x, y: y + 42, width: 540, height: holder * 1.25 }, "holder", { font: t.title === "script" || t.title === "pacifico" ? t.title : t.body, fontSize: holder, bold: true, fill: ink }),
    field({ x, y: y + 42 + holder * 1.5, width: 540, height: 44 }, "ticket_type", { font: t.body, fontSize: 32, fill: ink }),
    field({ x, y: y + 42 + holder * 1.5 + 56, width: 540, height: 40 }, "code", { font: "mono", fontSize: 28, fill: soft }),
  ];
  const middle = top + (f.h - top - size) / 2;
  switch (t.stub) {
    case "card":
      return [
        shape("rect", { x: 50, y: top + 20, width: f.w - 100, height: f.h - top - 60 }, { fill: t.card, cornerRadius: 36 }),
        ...lines(100, middle + 10, t.cardInk, t.cardSoft),
        qr(f.w - 90 - size, middle, size),
      ];
    case "band":
      return [
        shape("rect", { x: 0, y: top, width: f.w, height: f.h - top }, { fill: t.card }),
        shape("ellipse", { x: -40, y: top - 40, width: 80, height: 80 }, { fill: t.bg2 ?? t.bg }),
        shape("ellipse", { x: f.w - 40, y: top - 40, width: 80, height: 80 }, { fill: t.bg2 ?? t.bg }),
        shape("line", { x: 70, y: top + 18, width: f.w - 140, height: 6 }, { stroke: t.accent, strokeWidth: 3 }),
        ...lines(80, middle + 20, t.cardInk, t.cardSoft),
        qr(f.w - 80 - size, middle + 14, size),
      ];
    case "open":
      return [
        shape("line", { x: 80, y: top + 6, width: f.w - 160, height: 6 }, { stroke: t.accent, strokeWidth: 2 }),
        ...lines(80, middle, t.ink, t.soft),
        shape("rect", { x: f.w - 80 - size - 14, y: middle - 14, width: size + 28, height: size + 28 }, { fill: "#FFFFFF", cornerRadius: 18 }),
        qr(f.w - 80 - size, middle, size),
      ];
  }
}

// --- Compositions ---------------------------------------------------------------------------------

type Layout = (f: Frame, t: Theme, ctx: TemplateContext, o: LayoutOptions) => CanvasElement[];
type LayoutOptions = { seed: number; decor?: Decor; label?: string };
type Decor = "confetti" | "wax" | "glow" | "rays" | "petals" | "dots" | "stars" | "halo" | "none";

/** Décor de fond d'une composition, dans la zone donnée. */
function decor(kind: Decor | undefined, area: Box, t: Theme, seed: number): CanvasElement[] {
  const colors = [t.accent, t.accent2, t.soft];
  switch (kind) {
    case "confetti":
      return confetti(area, colors, 40, seed);
    case "wax":
      return waxCircles(area, [t.accent, t.accent2, t.card, t.soft], t.bg, 7, seed);
    case "glow":
      return [glow(area.x + area.width * 0.1, area.y + area.height * 0.12, area.width * 0.7, t.accent, 0.55), glow(area.x + area.width * 0.9, area.y + area.height * 0.7, area.width * 0.6, t.accent2, 0.5)];
    case "rays":
      return rays(area.x + area.width / 2, area.y + area.height * 0.4, Math.max(area.width, area.height), 18, t.accent, 0.14);
    case "petals":
      return [
        ...petals(area.x + 60, area.y + 60, 150, [t.accent, t.accent2], 6),
        ...petals(area.x + area.width - 60, area.y + area.height - 60, 150, [t.accent, t.accent2], 6),
      ];
    case "dots":
      return dots({ x: area.x + area.width - 300, y: area.y + 40, width: 280, height: 280 }, 40, 10, t.accent, 0.6);
    case "stars": {
      const random = seeded(seed);
      return Array.from({ length: 14 }, () => {
        const size = 16 + random() * 34;
        return shape("star", { x: area.x + random() * area.width, y: area.y + random() * area.height, width: size, height: size }, { fill: t.accent, opacity: 0.4 + random() * 0.5 });
      });
    }
    case "halo":
      return [0.95, 0.75, 0.55].map((share, i) =>
        shape("ellipse", { x: area.x + (area.width * (1 - share)) / 2, y: area.y + area.height * 0.06 + (area.height * (1 - share)) / 3, width: area.width * share, height: area.width * share }, {
          fill: null,
          fill2: i === 0 ? t.accent : t.accent2,
          opacity: 0.35,
        }),
      );
    default:
      return [];
  }
}

/** A. Grande photo qui s'efface dans le fond, titre massif en bas (campagne grand public). */
const heroPhoto: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      photo({ x: 470, y: 0, width: 730, height: 630 }, ctx.photoLabel),
      shape("rect", { x: 330, y: -60, width: 220, height: 760 }, { fill: t.bg, rotation: 8 }),
      shape("rect", { x: 0, y: 0, width: 420, height: 630 }, { fill: t.bg }),
      ...pill({ x: 60, y: 56, width: 300, height: 52 }, ctx.organizer, t.accent, t.onAccent, t.body, 22),
      title({ x: 60, y: 140, width: 520, height: 260 }, ctx, t, 92, 3),
      ...infoRow({ x: 60, y: 450, width: 460, height: 110 }, [{ icon: "calendar", text: ctx.date }, { icon: "pin", text: ctx.venue }], { ink: t.ink, iconColor: t.accent, font: t.body, size: 22, stack: true }),
    ];
  }
  const b = f.bottom;
  const poster = f.usage === "poster";
  return [
    photo({ x: 0, y: 0, width: f.w, height: b * 0.66 }, ctx.photoLabel),
    shape("rect", { x: 0, y: b * 0.36, width: f.w, height: b * 0.31 }, { fill: null, fill2: t.bg }),
    ...pill({ x: 60, y: 56, width: 360, height: 60 }, ctx.organizer, t.accent, t.onAccent, t.body, 26),
    title({ x: 70, y: b * (poster ? 0.5 : 0.55), width: 940, height: b * 0.22 }, ctx, t, 150 * f.k, 3),
    ...infoRow({ x: 70, y: b * (poster ? 0.76 : 0.83), width: 940, height: 60 * f.k }, [{ icon: "calendar", text: ctx.date }], { ink: t.ink, iconColor: t.accent, font: t.body, size: 34 * f.k }),
    ...infoRow({ x: 70, y: b * (poster ? 0.81 : 0.9), width: 940, height: 60 * f.k }, [{ icon: "pin", text: ctx.venue }], { ink: t.soft, iconColor: t.accent, font: t.body, size: 30 * f.k, bold: false }),
    ...(poster ? pill({ x: 70, y: b - 150 * f.k, width: 940, height: 96 * f.k }, ctx.labels.book, t.accent, t.onAccent, t.body, 38 * f.k) : []),
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b * 0.3 }, t, o.seed),
  ];
};

/** B. Personnage détouré à droite, titre et programme à gauche, bandeau d'infos en bas. */
const cutout: Layout = (f, t, ctx, o) => {
  const bar = (box: Box, size: number) => [
    shape("rect", box, { fill: t.accent2 }),
    ...infoRow({ x: box.x + 40, y: box.y + (box.height - size * 1.6) / 2, width: box.width - 60, height: size * 1.6 }, [
      { icon: "calendar", text: ctx.shortDate },
      { icon: "clock", text: ctx.time },
      { icon: o.label === "online" ? "live" : "pin", text: o.label === "online" ? ctx.labels.online : ctx.city },
    ], { ink: t.onAccent, iconColor: t.onAccent, font: t.body, size }),
  ];
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      text({ x: 60, y: 40, width: 640, height: 30 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 22, bold: true, letterSpacing: 6, fill: t.soft }),
      title({ x: 60, y: 90, width: 660, height: 200 }, ctx, t, 72, 3, { fill: t.accent }),
      ...bullets({ x: 60, y: 330, width: 600, height: 160 }, ctx.labels.points, { ink: t.ink, iconColor: t.accent2, font: t.body, size: 26 }),
      photo({ x: 760, y: 40, width: 420, height: 590 }, ctx.photoLabel, { fit: "contain" }),
      ...bar({ x: 0, y: 530, width: 1200, height: 100 }, 22),
    ];
  }
  const b = f.bottom;
  const barH = 130 * f.k;
  const room = b - barH;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: room }, t, o.seed),
    text({ x: 70, y: room * 0.06, width: 940, height: 40 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 26 * f.k, bold: true, letterSpacing: 8, fill: t.soft, align: "center" }),
    title({ x: 70, y: room * 0.12, width: 940, height: room * 0.24 }, ctx, t, 112 * f.k, 3, { fill: t.accent }),
    photo({ x: 500, y: room * 0.38, width: 580, height: room * 0.62 }, ctx.photoLabel, { fit: "contain" }),
    ...pill({ x: 70, y: room * 0.41, width: 420, height: 76 * f.k }, ctx.labels.program, t.accent, t.onAccent, t.body, 32 * f.k),
    ...bullets({ x: 70, y: room * 0.56, width: 400, height: room * 0.4 }, ctx.labels.points, { ink: t.ink, iconColor: t.accent2, font: t.body, size: 34 * f.k }),
    ...bar({ x: 0, y: room, width: f.w, height: barH }, 28 * f.k),
  ];
};

/** C. Citation sur photo sombre, guillemets géants, pied de page avec logo. */
const quote: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      photo({ x: 0, y: 0, width: f.w, height: f.h }, ctx.photoLabel),
      shape("rect", { x: 0, y: 0, width: f.w, height: f.h }, { fill: null, fill2: t.bg }),
      shape("rect", { x: 0, y: 0, width: 760, height: 630 }, { fill: t.bg, opacity: 0.55 }),
      icon("quote", 60, 60, 110, t.accent),
      text({ x: 60, y: 200, width: 660, height: 220 }, ctx.labels.quote, { font: t.title, fontSize: 46, bold: true, italic: true, fill: t.ink, lineHeight: 1.08 }),
      text({ x: 60, y: 440, width: 660, height: 36 }, ctx.labels.author, { font: t.body, fontSize: 24, fill: t.soft }),
      photo({ x: 900, y: 70, width: 220, height: 220 }, ctx.labels.speaker, { cornerRadius: 110 }),
      shape("ellipse", { x: 894, y: 64, width: 232, height: 232 }, { stroke: t.accent, strokeWidth: 6 }),
      title({ x: 60, y: 520, width: 760, height: 60 }, ctx, t, 34, 1, { font: t.body, fill: t.accent }),
    ];
  }
  const b = f.bottom;
  const foot = f.usage === "poster" ? 170 * f.k : 0;
  return [
    photo({ x: 0, y: 0, width: f.w, height: b }, ctx.photoLabel),
    shape("rect", { x: 0, y: b * 0.25, width: f.w, height: b * 0.75 }, { fill: null, fill2: t.bg }),
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    photo({ x: 720, y: b * 0.1, width: 280, height: 280 }, ctx.labels.speaker, { cornerRadius: 140 }),
    shape("ellipse", { x: 712, y: b * 0.1 - 8, width: 296, height: 296 }, { stroke: t.accent, strokeWidth: 6 }),
    icon("quote", 80, b * 0.38, 170 * f.k, t.accent),
    text({ x: 80, y: b * 0.38 + 190 * f.k, width: 920, height: 300 * f.k }, ctx.labels.quote, { font: t.title, fontSize: 64 * f.k, bold: true, italic: true, fill: t.ink, lineHeight: 1.06 }),
    text({ x: 80, y: b - foot - 120 * f.k, width: 920, height: 44 }, ctx.labels.author, { font: t.body, fontSize: 30 * f.k, fill: t.soft }),
    ...(foot
      ? [
          shape("rect", { x: 0, y: b - foot, width: f.w, height: foot }, { fill: t.card }),
          photo({ x: 60, y: b - foot + 25 * f.k, width: 200, height: foot - 50 * f.k }, ctx.labels.logo, { fit: "contain" }),
          shape("line", { x: 285, y: b - foot + 25 * f.k, width: foot - 50 * f.k, height: 4 }, { stroke: t.cardSoft, strokeWidth: 3, rotation: 90 }),
          title({ x: 310, y: b - foot + 30 * f.k, width: 700, height: foot - 60 }, ctx, t, 40 * f.k, 2, { font: t.body, fill: t.cardInk }),
        ]
      : [title({ x: 80, y: b - 80 * f.k, width: 920, height: 50 }, ctx, t, 38 * f.k, 1, { font: t.body, fill: t.accent })]),
  ];
};

/** D. Grand titre centré en deux tons, décor en halo, adresse dans un ruban. */
const centerTitle: Layout = (f, t, ctx, o) => {
  const words = caps(ctx.title, t.upper).split(/\s+/);
  const half = Math.ceil(words.length / 2);
  const [first, second] = [words.slice(0, half).join(" "), words.slice(half).join(" ")];
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 200, y: -150, width: 800, height: 900 }, t, o.seed),
      text({ x: 100, y: 40, width: 1000, height: 30 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 22, bold: true, letterSpacing: 6, fill: t.soft, align: "center" }),
      text({ x: 100, y: 100, width: 1000, height: 140 }, first, { font: t.title, fontSize: fit(first, 120, 1000, 1, t.title, t.upper), bold: true, fill: t.ink, align: "center", lineHeight: 1 }),
      text({ x: 100, y: 240, width: 1000, height: 140 }, second || ctx.year, { font: t.title, fontSize: fit(second || ctx.year, 120, 1000, 1, t.title, t.upper), bold: true, fill: t.accent, align: "center", lineHeight: 1 }),
      ...pill({ x: 250, y: 420, width: 700, height: 64 }, ctx.date, t.card, t.cardInk, t.body, 26),
      ...pill({ x: 200, y: 510, width: 800, height: 60 }, ctx.venue, t.accent, t.onAccent, t.body, 24),
    ];
  }
  const b = f.bottom;
  // Deux moitiés du titre sur une ligne chacune, empilées dans la place disponible.
  const room = b * 0.5;
  const size1 = fit(first, 190 * f.k, 960, 1, t.title, t.upper, room * 0.48);
  const size2 = fit(second || ctx.year, 190 * f.k, 960, 1, t.title, t.upper, room * 0.48);
  const top = b * 0.14;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    text({ x: 80, y: b * 0.06, width: 920, height: 40 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 26 * f.k, bold: true, letterSpacing: 8, fill: t.soft, align: "center" }),
    text({ x: 60, y: top, width: 960, height: size1 * 1.05 }, first, { font: t.title, fontSize: size1, bold: true, fill: t.ink, align: "center", lineHeight: 1 }),
    text({ x: 60, y: top + size1 * 1.05, width: 960, height: size2 * 1.05 }, second || ctx.year, { font: t.title, fontSize: size2, bold: true, fill: t.accent, align: "center", lineHeight: 1 }),
    ...(second ? [text({ x: 60, y: top + (size1 + size2) * 1.05 + 10, width: 960, height: 70 }, ctx.year, { font: t.body, fontSize: 52 * f.k, bold: true, fill: t.soft, align: "center" })] : []),
    shape("rect", { x: 90, y: b * 0.71, width: 900, height: 130 * f.k }, { fill: t.card, cornerRadius: 30 }),
    ...infoRow({ x: 130, y: b * 0.71 + 28 * f.k, width: 860, height: 74 * f.k }, [{ icon: "calendar", text: ctx.day }, { icon: "clock", text: ctx.time }], { ink: t.cardInk, iconColor: t.accent, font: t.body, size: 32 * f.k }),
    icon("chevrons", 516, b * 0.71 + 145 * f.k, 48 * f.k, t.accent, { rotation: 90, x: 564 }),
    ...pill({ x: 110, y: b * 0.86, width: 860, height: 80 * f.k }, ctx.venue, t.accent, t.onAccent, t.body, 30 * f.k),
  ];
};

/** E. Carte blanche d'infos sur photo : dates, ruban, programme, prix (formation, cours). */
const infoCard: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      photo({ x: 0, y: 0, width: f.w, height: f.h }, ctx.photoLabel),
      shape("rect", { x: 0, y: 0, width: f.w, height: f.h }, { fill: t.bg, opacity: 0.55 }),
      title({ x: 60, y: 50, width: 640, height: 150 }, ctx, t, 76, 2),
      shape("rect", { x: 60, y: 230, width: 560, height: 340 }, { fill: t.card, cornerRadius: 28 }),
      text({ x: 100, y: 260, width: 480, height: 90 }, ctx.date, { font: t.body, fontSize: 32, bold: true, fill: t.cardInk }),
      ...pill({ x: 100, y: 370, width: 400, height: 56 }, ctx.labels.program, t.accent, t.onAccent, t.body, 24),
      ...bullets({ x: 100, y: 450, width: 480, height: 110 }, ctx.labels.points.slice(0, 2), { ink: t.cardInk, iconColor: t.accent, font: t.body, size: 22, icon: "arrow" }),
      ...pill({ x: 760, y: 470, width: 380, height: 100 }, format(ctx.labels.entryPrice, { price: ctx.price }), t.accent, t.onAccent, t.body, 32),
    ];
  }
  const b = f.bottom;
  const poster = f.usage === "poster";
  return [
    photo({ x: 0, y: 0, width: f.w, height: b }, ctx.photoLabel),
    shape("rect", { x: 0, y: 0, width: f.w, height: b * 0.5 }, { fill: t.bg, fill2: t.bg2 ?? t.bg, opacity: 0.9 }),
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b * 0.4 }, t, o.seed),
    shape("rect", { x: 60, y: 56, width: 230, height: 110 * f.k }, { fill: t.card, cornerRadius: 22 }),
    photo({ x: 75, y: 66, width: 200, height: 90 * f.k }, ctx.labels.logo, { fit: "contain" }),
    title({ x: 70, y: b * 0.17, width: 940, height: b * 0.16 }, ctx, t, 128 * f.k, 2, { align: "center" }),
    shape("rect", { x: 60, y: b * 0.39, width: 620, height: b * (poster ? 0.42 : 0.5) }, { fill: t.card, cornerRadius: 30 }),
    text({ x: 100, y: b * 0.42, width: 560, height: 120 * f.k }, ctx.day, { font: t.body, fontSize: 46 * f.k, bold: true, fill: t.cardInk, lineHeight: 1.08 }),
    ...pill({ x: 100, y: b * 0.42 + 140 * f.k, width: 540, height: 70 * f.k }, ctx.labels.program, t.accent, t.onAccent, t.body, 30 * f.k),
    ...bullets({ x: 100, y: b * 0.42 + 240 * f.k, width: 560, height: b * (poster ? 0.18 : 0.24) }, ctx.labels.points, { ink: t.cardInk, iconColor: t.accent, font: t.body, size: 30 * f.k, icon: "arrow", rule: t.cardSoft }),
    ...(poster
      ? [
          ...pill({ x: 60, y: b * 0.85, width: 380, height: 120 * f.k }, ctx.price, t.accent, t.onAccent, t.body, 50 * f.k),
          ...infoRow({ x: 470, y: b * 0.85, width: 560, height: 120 * f.k }, [{ icon: "clock", text: ctx.time }, { icon: "pin", text: ctx.venue }], { ink: t.ink, iconColor: t.accent, font: t.body, size: 26 * f.k, stack: true }),
        ]
      : []),
  ];
};

/** F. Photo à gauche, titre doré et programme à droite, partenaires en bas (soirée, remise de prix). */
const sideProgram: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      photo({ x: 0, y: 0, width: 470, height: 630 }, ctx.photoLabel, { fit: "contain" }),
      text({ x: 500, y: 40, width: 660, height: 30 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 22, bold: true, letterSpacing: 4, fill: t.soft }),
      title({ x: 500, y: 90, width: 660, height: 200 }, ctx, t, 80, 2, { fill: t.accent }),
      ...bullets({ x: 500, y: 310, width: 640, height: 170 }, ctx.labels.points, { ink: t.ink, iconColor: t.accent, font: t.body, size: 24, rule: t.soft }),
      text({ x: 500, y: 500, width: 660, height: 40 }, `${ctx.time}  ·  ${ctx.day}`, { font: t.body, fontSize: 26, bold: true, fill: t.ink }),
      text({ x: 500, y: 550, width: 660, height: 40 }, ctx.venue, { font: t.body, fontSize: 22, fill: t.soft }),
    ];
  }
  const b = f.bottom;
  const strip = f.usage === "poster" ? 130 * f.k : 0;
  const room = b - strip;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: room }, t, o.seed),
    photo({ x: 0, y: room * 0.22, width: 520, height: room * 0.78 }, ctx.photoLabel, { fit: "contain" }),
    text({ x: 420, y: room * 0.05, width: 600, height: 40 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 26 * f.k, bold: true, letterSpacing: 4, fill: t.soft, align: "right" }),
    title({ x: 400, y: room * 0.12, width: 620, height: room * 0.26 }, ctx, t, 116 * f.k, 3, { fill: t.accent, align: "right" }),
    text({ x: 540, y: room * 0.44, width: 480, height: 50 }, ctx.labels.program, { font: t.body, fontSize: 40 * f.k, bold: true, fill: t.ink }),
    ...bullets({ x: 540, y: room * 0.52, width: 480, height: room * 0.28 }, ctx.labels.points, { ink: t.ink, iconColor: t.accent, font: t.body, size: 32 * f.k, rule: t.soft }),
    text({ x: 540, y: room * 0.83, width: 480, height: 50 }, `${ctx.time}  ·  ${ctx.day}`, { font: t.body, fontSize: 30 * f.k, bold: true, fill: t.ink }),
    ...infoRow({ x: 540, y: room * 0.9, width: 480, height: 70 }, [{ icon: "pin", text: ctx.venue }], { ink: t.soft, iconColor: t.accent, font: t.body, size: 26 * f.k, bold: false }),
    ...(strip ? sponsors({ x: 0, y: room, width: f.w, height: strip }, 6, ctx, "#FFFFFF") : []),
  ];
};

/** G. Photo ronde au centre, cartes autour, boutons en bas (lancement, salon, atelier). */
const circleTiles: Layout = (f, t, ctx, o) => {
  const tile = (box: Box, iconName: IconName, value: string, size: number) => [
    shape("rect", box, { fill: t.card, cornerRadius: 26 }),
    icon(iconName, box.x + (box.width - size * 1.6) / 2, box.y + box.height * 0.16, size * 1.6, t.accent),
    text({ x: box.x + 10, y: box.y + box.height * 0.16 + size * 1.9, width: box.width - 20, height: size * 2.6 }, value, { font: t.body, fontSize: size, bold: true, fill: t.cardInk, align: "center" }),
  ];
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      title({ x: 60, y: 60, width: 560, height: 220 }, ctx, t, 72, 3),
      text({ x: 60, y: 320, width: 540, height: 80 }, ctx.date, { font: t.body, fontSize: 26, fill: t.soft }),
      ...pill({ x: 60, y: 450, width: 400, height: 76 }, ctx.labels.book, t.accent, t.onAccent, t.body, 28),
      shape("ellipse", { x: 680, y: 70, width: 490, height: 490 }, { fill: t.accent2 }),
      photo({ x: 700, y: 90, width: 450, height: 450 }, ctx.photoLabel, { cornerRadius: 225 }),
    ];
  }
  const b = f.bottom;
  const poster = f.usage === "poster";
  const circle = Math.min(560, b * 0.46);
  // Photo centrée entre le titre et les boutons.
  const cy = Math.max(b * 0.24, (b * 0.22 + (poster ? b - 240 * f.k : b)) / 2 - circle / 2);
  const tileH = circle / 2 - 12;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    text({ x: 80, y: b * 0.03, width: 920, height: 40 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 26 * f.k, bold: true, letterSpacing: 6, fill: t.soft, align: "center" }),
    title({ x: 70, y: b * 0.08, width: 940, height: b * 0.14 }, ctx, t, 96 * f.k, 2, { align: "center" }),
    shape("ellipse", { x: (f.w - circle - 40) / 2, y: cy - 20, width: circle + 40, height: circle + 40 }, { stroke: t.accent2, strokeWidth: 6 }),
    shape("ellipse", { x: (f.w - circle) / 2, y: cy, width: circle, height: circle }, { fill: t.accent2 }),
    photo({ x: (f.w - circle) / 2, y: cy, width: circle, height: circle }, ctx.photoLabel, { cornerRadius: circle / 2 }),
    ...tile({ x: 40, y: cy + 10, width: 210, height: tileH }, "calendar", ctx.shortDate, 24 * f.k),
    ...tile({ x: 40, y: cy + tileH + 34, width: 210, height: tileH }, "clock", ctx.time, 24 * f.k),
    ...tile({ x: f.w - 250, y: cy + 10, width: 210, height: tileH }, "pin", ctx.city, 24 * f.k),
    ...tile({ x: f.w - 250, y: cy + tileH + 34, width: 210, height: tileH }, "ticket", ctx.price, 24 * f.k),
    ...(poster
      ? [
          ...pill({ x: 90, y: b - 230 * f.k, width: 900, height: 96 * f.k }, ctx.labels.book, t.accent, t.onAccent, t.body, 38 * f.k),
          text({ x: 90, y: b - 110 * f.k, width: 900, height: 50 }, ctx.labels.contact, { font: t.body, fontSize: 30 * f.k, fill: t.soft, align: "center" }),
        ]
      : []),
  ];
};

/** H. Titre, bandeau des horaires, rangée d'intervenants, adresse (culte, conférence, concert). */
const speakers: Layout = (f, t, ctx, o) => {
  const row = (y: number, x: number, width: number, size: number, count: number) => {
    const gap = (width - size * count) / (count - 1);
    return Array.from({ length: count }, (_, i) => [
      shape("ellipse", { x: x + i * (size + gap) - 6, y: y - 6, width: size + 12, height: size + 12 }, { fill: t.accent }),
      photo({ x: x + i * (size + gap), y, width: size, height: size }, ctx.labels.speaker, { cornerRadius: size / 2 }),
    ]).flat();
  };
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      text({ x: 60, y: 40, width: 1080, height: 30 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 22, bold: true, letterSpacing: 6, fill: t.soft, align: "center" }),
      title({ x: 60, y: 80, width: 1080, height: 120 }, ctx, t, 96, 1, { align: "center" }),
      shape("rect", { x: 150, y: 220, width: 900, height: 90 }, { fill: t.card, cornerRadius: 45 }),
      ...infoRow({ x: 200, y: 240, width: 820, height: 50 }, [{ icon: "calendar", text: ctx.day }, { icon: "clock", text: ctx.time }], { ink: t.cardInk, iconColor: t.accent, font: t.body, size: 24 }),
      ...row(345, 170, 860, 150, 5),
      ...pill({ x: 250, y: 530, width: 700, height: 60 }, ctx.venue, t.accent, t.onAccent, t.body, 24),
    ];
  }
  const b = f.bottom;
  const count = f.format === "square" ? 4 : 5;
  const size = f.format === "square" ? 170 : 160;
  const card = b * 0.4;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    text({ x: 60, y: b * 0.05, width: 960, height: 40 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 26 * f.k, bold: true, letterSpacing: 8, fill: t.soft, align: "center" }),
    title({ x: 60, y: b * 0.11, width: 960, height: b * 0.25 }, ctx, t, 150 * f.k, 2, { align: "center" }),
    shape("rect", { x: 70, y: card, width: 940, height: 150 * f.k }, { fill: t.card, cornerRadius: 40 }),
    ...infoRow({ x: 120, y: card + 40 * f.k, width: 880, height: 70 * f.k }, [{ icon: "calendar", text: ctx.day }, { icon: "clock", text: ctx.time }], { ink: t.cardInk, iconColor: t.accent, font: t.body, size: 34 * f.k }),
    ...pill({ x: 390, y: card + 160 * f.k, width: 300, height: 56 * f.k }, ctx.labels.with, t.accent, t.onAccent, t.body, 26 * f.k),
    ...row(card + 250 * f.k, 70, 940, size * f.k, count),
    ...pill({ x: 90, y: Math.max(card + 290 * f.k + size * f.k, b * 0.86), width: 900, height: 80 * f.k }, ctx.venue, t.accent, t.onAccent, t.body, 30 * f.k),
  ];
};

/** I. Encadré élégant, titre manuscrit, filets (mariage, gala, dîner). */
const framed: Layout = (f, t, ctx, o) => {
  const line = t.accent;
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      ...frameLines(f, line),
      shape("star", { x: 583, y: 76, width: 34, height: 34 }, { fill: line }),
      text({ x: 140, y: 120, width: 920, height: 40 }, ctx.labels.invite, { font: t.body, fontSize: 28, italic: true, fill: t.soft, align: "center" }),
      title({ x: 100, y: 170, width: 1000, height: 200 }, ctx, t, 110, 2, { align: "center", bold: false }),
      shape("line", { x: 450, y: 400, width: 300, height: 10 }, { stroke: line, strokeWidth: 2 }),
      text({ x: 100, y: 430, width: 1000, height: 44 }, ctx.date, { font: t.body, fontSize: 32, fill: t.ink, align: "center" }),
      text({ x: 100, y: 484, width: 1000, height: 44 }, ctx.venue, { font: t.body, fontSize: 26, italic: true, fill: t.soft, align: "center" }),
    ];
  }
  const b = f.bottom;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
    ...frameLines(f, line),
    photo({ x: 390, y: b * 0.07, width: 300, height: 300 * f.k }, ctx.photoLabel, { cornerRadius: 150 * f.k }),
    shape("ellipse", { x: 380, y: b * 0.07 - 10, width: 320, height: 300 * f.k + 20 }, { stroke: line, strokeWidth: 3 }),
    text({ x: 90, y: b * 0.07 + 330 * f.k, width: 900, height: 50 }, ctx.labels.invite, { font: t.body, fontSize: 32 * f.k, italic: true, fill: t.soft, align: "center" }),
    title({ x: 90, y: b * 0.07 + 390 * f.k, width: 900, height: 260 * f.k }, ctx, t, 128 * f.k, 2, { align: "center", bold: false, lineHeight: 1.05 }),
    shape("line", { x: 390, y: b * 0.07 + 680 * f.k, width: 300, height: 10 }, { stroke: line, strokeWidth: 2 }),
    text({ x: 90, y: b * 0.07 + 710 * f.k, width: 900, height: 50 }, ctx.date, { font: t.body, fontSize: 36 * f.k, fill: t.ink, align: "center" }),
    text({ x: 90, y: b * 0.07 + 770 * f.k, width: 900, height: 90 }, ctx.venue, { font: t.body, fontSize: 30 * f.k, italic: true, fill: t.soft, align: "center" }),
    ...(f.usage === "poster" ? [text({ x: 90, y: b - 130 * f.k, width: 900, height: 50 }, ctx.labels.rsvp, { font: t.body, fontSize: 28 * f.k, italic: true, fill: line, align: "center" })] : []),
  ];
};

/** J. Moitié photo, moitié aplat de couleur. */
const split: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      photo({ x: 0, y: 0, width: 600, height: 630 }, ctx.photoLabel),
      shape("rect", { x: 600, y: 0, width: 600, height: 630 }, { fill: t.card }),
      ...decor(o.decor, { x: 600, y: 0, width: 600, height: 630 }, t, o.seed),
      text({ x: 640, y: 90, width: 520, height: 40 }, ctx.labels.saveDate, { font: t.body, fontSize: 26, bold: true, letterSpacing: 4, fill: t.accent, align: "center" }),
      title({ x: 640, y: 150, width: 520, height: 220 }, ctx, t, 88, 3, { align: "center", fill: t.cardInk }),
      shape("line", { x: 780, y: 400, width: 240, height: 10 }, { stroke: t.accent, strokeWidth: 2 }),
      text({ x: 640, y: 430, width: 520, height: 44 }, ctx.date, { font: t.body, fontSize: 28, fill: t.cardInk, align: "center" }),
      text({ x: 640, y: 482, width: 520, height: 80 }, ctx.venue, { font: t.body, fontSize: 24, italic: true, fill: t.cardSoft, align: "center" }),
    ];
  }
  const b = f.bottom;
  const cut = b * 0.55;
  return [
    photo({ x: 0, y: 0, width: f.w, height: cut }, ctx.photoLabel),
    shape("rect", { x: 0, y: cut, width: f.w, height: b - cut }, { fill: t.card }),
    shape("rect", { x: 0, y: cut - 16, width: f.w, height: 16 }, { fill: t.accent }),
    ...decor(o.decor, { x: 0, y: cut, width: f.w, height: b - cut }, t, o.seed),
    ...pill({ x: 340, y: cut - 46, width: 400, height: 64 }, ctx.labels.saveDate, t.accent, t.onAccent, t.body, 26),
    title({ x: 70, y: cut + 50 * f.k, width: 940, height: (b - cut) * 0.45 }, ctx, t, 110 * f.k, 2, { align: "center", fill: t.cardInk }),
    text({ x: 70, y: b - (b - cut) * 0.34, width: 940, height: 50 }, ctx.date, { font: t.body, fontSize: 36 * f.k, bold: true, fill: t.cardInk, align: "center" }),
    text({ x: 70, y: b - (b - cut) * 0.34 + 56 * f.k, width: 940, height: 80 }, ctx.venue, { font: t.body, fontSize: 30 * f.k, italic: true, fill: t.cardSoft, align: "center" }),
  ];
};

/** K. Motif décoratif, photo ronde, gros titre (anniversaire, festival). */
const patterned: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 600, y: 0, width: 600, height: 630 }, t, o.seed),
      shape("ellipse", { x: 780, y: 100, width: 400, height: 400 }, { stroke: t.accent, strokeWidth: 10 }),
      photo({ x: 790, y: 110, width: 380, height: 380 }, ctx.photoLabel, { cornerRadius: 190 }),
      title({ x: 60, y: 100, width: 680, height: 280 }, ctx, t, 92, 3),
      text({ x: 60, y: 420, width: 680, height: 110 }, `${ctx.date}\n${ctx.venue}`, { font: t.body, fontSize: 30, bold: true, fill: t.soft }),
    ];
  }
  const b = f.bottom;
  const size = Math.min(440, b * 0.36);
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    shape("ellipse", { x: (f.w - size - 30) / 2, y: b * 0.05 - 15, width: size + 30, height: size + 30 }, { stroke: t.accent, strokeWidth: 10 }),
    photo({ x: (f.w - size) / 2, y: b * 0.05, width: size, height: size }, ctx.photoLabel, { cornerRadius: size / 2 }),
    title({ x: 60, y: b * 0.05 + size + 40 * f.k, width: 960, height: b * 0.24 }, ctx, t, 120 * f.k, 2, { align: "center" }),
    ...pill({ x: 190, y: b * 0.79, width: 700, height: 76 * f.k }, ctx.day, t.accent, t.onAccent, t.body, 32 * f.k),
    text({ x: 60, y: b * 0.79 + 96 * f.k, width: 960, height: 90 }, `${ctx.time}  ·  ${ctx.venue}`, { font: t.body, fontSize: 32 * f.k, bold: true, fill: t.ink, align: "center" }),
  ];
};

/** L. Nuit néon : photo encadrée lumineuse, titre en capitales. */
const neonFrame: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      photo({ x: 700, y: 60, width: 440, height: 510 }, ctx.photoLabel, { cornerRadius: 36 }),
      shape("rect", { x: 700, y: 60, width: 440, height: 510 }, { stroke: t.accent2, strokeWidth: 6, cornerRadius: 36 }),
      title({ x: 60, y: 120, width: 600, height: 260 }, ctx, t, 84, 3, { letterSpacing: 3 }),
      text({ x: 60, y: 420, width: 600, height: 44 }, ctx.date, { font: t.body, fontSize: 30, fill: t.accent, bold: true }),
      text({ x: 60, y: 474, width: 600, height: 80 }, ctx.venue, { font: t.body, fontSize: 28, fill: t.accent2 }),
    ];
  }
  const b = f.bottom;
  const ph = b * 0.5;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    photo({ x: 120, y: b * 0.07, width: 840, height: ph }, ctx.photoLabel, { cornerRadius: 40 }),
    shape("rect", { x: 120, y: b * 0.07, width: 840, height: ph }, { stroke: t.accent, strokeWidth: 6, cornerRadius: 40 }),
    shape("rect", { x: 104, y: b * 0.07 - 16, width: 872, height: ph + 32 }, { stroke: t.accent2, strokeWidth: 2, cornerRadius: 52, opacity: 0.7 }),
    title({ x: 60, y: b * 0.07 + ph + 40 * f.k, width: 960, height: b * 0.2 }, ctx, t, 110 * f.k, 2, { align: "center", letterSpacing: 3 }),
    text({ x: 60, y: b * 0.9, width: 960, height: 50 }, `${ctx.date}  ·  ${ctx.venue}`, { font: t.body, fontSize: 30 * f.k, fill: t.accent2, bold: true, align: "center" }),
  ];
};

/** M. Typographie massive : mots empilés, bande en biais, date géante (affiche suisse). */
const typo: Layout = (f, t, ctx, o) => {
  const words = caps(ctx.title, true).split(/\s+/).slice(0, 4);
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      shape("rect", { x: -100, y: 420, width: 1500, height: 90 }, { fill: t.accent, rotation: -6 }),
      text({ x: 40, y: 418, width: 1100, height: 60 }, `${ctx.day}  —  ${ctx.venue}`.toUpperCase(), { font: t.body, fontSize: 30, bold: true, fill: t.onAccent, rotation: -6 }),
      text({ x: 60, y: 50, width: 820, height: 340 }, words.join("\n"), { font: t.title, fontSize: Math.min(fit(words.join(" "), 130, 820, words.length, t.title, true), Math.floor(340 / (words.length * 0.92))), bold: true, fill: t.ink, lineHeight: 0.92 }),
      text({ x: 900, y: 40, width: 260, height: 220 }, ctx.dayNumber, { font: t.title, fontSize: 200, bold: true, fill: t.accent2, align: "right", lineHeight: 1 }),
      text({ x: 900, y: 250, width: 260, height: 60 }, ctx.month, { font: t.body, fontSize: 44, bold: true, fill: t.ink, align: "right" }),
    ];
  }
  const b = f.bottom;
  const size = Math.min(fit(words.join(" "), 230 * f.k, 940, words.length, t.title, true), Math.floor((b * 0.53) / (words.length * 0.92)));
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    text({ x: 70, y: b * 0.05, width: 600, height: 40 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 26 * f.k, bold: true, letterSpacing: 6, fill: t.soft }),
    text({ x: 70, y: b * 0.11, width: 940, height: size * words.length }, words.join("\n"), { font: t.title, fontSize: size, bold: true, fill: t.ink, lineHeight: 0.92 }),
    shape("rect", { x: -100, y: b * 0.66, width: 1300, height: 110 * f.k }, { fill: t.accent, rotation: -5 }),
    text({ x: 60, y: b * 0.66 + 4, width: 1000, height: 70 }, `${ctx.venue}`.toUpperCase(), { font: t.body, fontSize: 40 * f.k, bold: true, fill: t.onAccent, rotation: -5 }),
    text({ x: 70, y: b * 0.77, width: 400, height: 220 * f.k }, ctx.dayNumber, { font: t.title, fontSize: 210 * f.k, bold: true, fill: t.accent2, lineHeight: 1 }),
    text({ x: 420, y: b * 0.8, width: 590, height: 60 }, `${ctx.month} ${ctx.year}`, { font: t.body, fontSize: 52 * f.k, bold: true, fill: t.ink }),
    text({ x: 420, y: b * 0.8 + 70 * f.k, width: 590, height: 60 }, ctx.time, { font: t.body, fontSize: 44 * f.k, fill: t.soft }),
  ];
};

/** N. Noir et or : coins étoilés, titre en capitales espacées (gala, anniversaire chic). */
const luxe: Layout = (f, t, ctx, o) => {
  const corners = [
    [44, 44],
    [f.w - 114, 44],
    [44, f.h - 114],
    [f.w - 114, f.h - 114],
  ].map(([x, y]) => shape("star", { x, y, width: 70, height: 70 }, { fill: t.accent, opacity: 0.9 }));
  const border = shape("rect", { x: 70, y: 70, width: f.w - 140, height: f.h - 140 }, { stroke: t.accent, strokeWidth: 3 });
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      border,
      ...corners,
      text({ x: 140, y: 130, width: 920, height: 36 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 22, letterSpacing: 8, fill: t.accent, align: "center" }),
      title({ x: 140, y: 190, width: 920, height: 220 }, ctx, t, 76, 2, { fill: t.accent, align: "center", letterSpacing: 4 }),
      text({ x: 140, y: 430, width: 920, height: 44 }, `${ctx.date}  ·  ${ctx.venue}`, { font: t.body, fontSize: 26, fill: t.ink, align: "center" }),
    ];
  }
  const b = f.bottom;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
    border,
    ...corners,
    text({ x: 120, y: b * 0.14, width: 840, height: 40 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 26 * f.k, letterSpacing: 8, fill: t.accent, align: "center" }),
    ...(o.label ? [text({ x: 120, y: b * 0.2, width: 840, height: 300 * f.k }, o.label, { font: t.title, fontSize: 280 * f.k, bold: true, fill: t.accent, align: "center", lineHeight: 1, opacity: 0.9 })] : []),
    title({ x: 120, y: b * (o.label ? 0.47 : 0.25), width: 840, height: b * 0.25 }, ctx, t, 100 * f.k, 3, { fill: t.accent, align: "center", letterSpacing: 4 }),
    shape("line", { x: 440, y: b * 0.76, width: 200, height: 10 }, { stroke: t.accent, strokeWidth: 2 }),
    text({ x: 120, y: b * 0.79, width: 840, height: 50 }, ctx.date, { font: t.body, fontSize: 36 * f.k, fill: t.ink, align: "center" }),
    text({ x: 120, y: b * 0.79 + 60 * f.k, width: 840, height: 90 }, ctx.venue, { font: t.body, fontSize: 30 * f.k, italic: true, fill: t.soft, align: "center" }),
  ];
};

/** O. Événement en ligne : écran avec badge « en direct », bandeau des horaires. */
const live: Layout = (f, t, ctx, o) => {
  const screen = (box: Box) => [
    shape("rect", { x: box.x - 16, y: box.y - 16, width: box.width + 32, height: box.height + 32 }, { fill: t.card, cornerRadius: 28 }),
    photo(box, ctx.labels.speaker, { cornerRadius: 16 }),
    shape("rect", { x: box.x + 24, y: box.y + 24, width: 220, height: 60 }, { fill: "#E63946", cornerRadius: 12 }),
    icon("live", box.x + 38, box.y + 36, 36, "#FFFFFF"),
    text({ x: box.x + 82, y: box.y + 38, width: 150, height: 36 }, ctx.labels.live, { font: t.body, fontSize: 28, bold: true, fill: "#FFFFFF" }),
    shape("rect", { x: box.x + box.width / 2 - 120, y: box.y + box.height + 16, width: 240, height: 40 }, { fill: t.card }),
    shape("rect", { x: box.x + box.width / 2 - 220, y: box.y + box.height + 56, width: 440, height: 20 }, { fill: t.card, cornerRadius: 10 }),
  ];
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      title({ x: 60, y: 80, width: 520, height: 260 }, ctx, t, 72, 3),
      ...infoRow({ x: 60, y: 380, width: 500, height: 170 }, [{ icon: "calendar", text: ctx.day }, { icon: "clock", text: ctx.time }, { icon: "globe", text: ctx.labels.online }], { ink: t.ink, iconColor: t.accent, font: t.body, size: 26, stack: true }),
      ...screen({ x: 640, y: 120, width: 500, height: 300 }),
    ];
  }
  const b = f.bottom;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    text({ x: 70, y: b * 0.05, width: 940, height: 40 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 26 * f.k, bold: true, letterSpacing: 8, fill: t.accent, align: "center" }),
    title({ x: 70, y: b * 0.11, width: 940, height: b * 0.22 }, ctx, t, 116 * f.k, 3, { align: "center" }),
    ...screen({ x: 160, y: b * 0.38, width: 760, height: b * 0.3 }),
    ...infoRow({ x: 90, y: b * 0.82, width: 920, height: 70 * f.k }, [{ icon: "calendar", text: ctx.day }, { icon: "clock", text: ctx.time }, { icon: "globe", text: ctx.labels.online }], { ink: t.ink, iconColor: t.accent, font: t.body, size: 28 * f.k }),
  ];
};

/** P. Duel : deux photos face à face, « VS » au centre (sport, battle, débat). */
const versus: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      photo({ x: 0, y: 0, width: 600, height: 630 }, ctx.photoLabel),
      photo({ x: 600, y: 0, width: 600, height: 630 }, ctx.photoLabel),
      shape("rect", { x: 560, y: -50, width: 80, height: 740 }, { fill: t.accent, rotation: 8 }),
      shape("ellipse", { x: 510, y: 225, width: 180, height: 180 }, { fill: t.bg, stroke: t.accent, strokeWidth: 8 }),
      text({ x: 510, y: 268, width: 180, height: 100 }, ctx.labels.versus, { font: t.title, fontSize: 90, bold: true, fill: t.ink, align: "center" }),
      shape("rect", { x: 0, y: 500, width: 1200, height: 130 }, { fill: t.bg, opacity: 0.85 }),
      title({ x: 40, y: 512, width: 1120, height: 70 }, ctx, t, 60, 1, { align: "center" }),
      text({ x: 40, y: 580, width: 1120, height: 40 }, `${ctx.date}  ·  ${ctx.venue}`, { font: t.body, fontSize: 24, fill: t.accent, bold: true, align: "center" }),
    ];
  }
  const b = f.bottom;
  const ph = b * 0.58;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    photo({ x: 0, y: 0, width: 540, height: ph }, ctx.photoLabel),
    photo({ x: 540, y: 0, width: 540, height: ph }, ctx.photoLabel),
    shape("rect", { x: 510, y: -40, width: 60, height: ph + 80 }, { fill: t.accent, rotation: 6 }),
    shape("rect", { x: 0, y: ph * 0.6, width: f.w, height: ph * 0.4 }, { fill: null, fill2: t.bg }),
    shape("ellipse", { x: 420, y: ph - 130, width: 240, height: 240 }, { fill: t.bg, stroke: t.accent, strokeWidth: 10 }),
    text({ x: 420, y: ph - 70, width: 240, height: 130 }, ctx.labels.versus, { font: t.title, fontSize: 120, bold: true, fill: t.ink, align: "center" }),
    title({ x: 60, y: ph + 120 * f.k, width: 960, height: b * 0.14 }, ctx, t, 120 * f.k, 2, { align: "center" }),
    ...pill({ x: 140, y: b - 120 * f.k, width: 800, height: 80 * f.k }, `${ctx.day}  ·  ${ctx.time}`, t.accent, t.onAccent, t.body, 32 * f.k),
  ];
};

/** R. kassEvent : ruban en biais sur le vert Fleuve. */
const ribbon: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: f.w, height: f.h }, t, o.seed),
      shape("rect", { x: 760, y: -80, width: 140, height: 900 }, { fill: t.accent, rotation: 18 }),
      shape("rect", { x: 940, y: -80, width: 60, height: 900 }, { fill: t.accent, rotation: 18, opacity: 0.5 }),
      text({ x: 70, y: 60, width: 700, height: 36 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 24, bold: true, letterSpacing: 4, fill: t.accent }),
      title({ x: 70, y: 130, width: 700, height: 280 }, ctx, t, 84, 3),
      text({ x: 70, y: 450, width: 700, height: 44 }, ctx.date, { font: t.body, fontSize: 32, fill: t.accent, bold: true }),
      text({ x: 70, y: 504, width: 700, height: 80 }, ctx.venue, { font: t.body, fontSize: 28, fill: t.soft }),
    ];
  }
  const b = f.bottom;
  return [
    ...decor(o.decor, { x: 0, y: 0, width: f.w, height: b }, t, o.seed),
    shape("rect", { x: -120, y: b * 0.12, width: 1400, height: 90 }, { fill: t.accent, rotation: -8 }),
    text({ x: 80, y: b * 0.12, width: 900, height: 40 }, ctx.organizer.toUpperCase(), { font: t.body, fontSize: 30, bold: true, letterSpacing: 4, fill: t.onAccent, rotation: -8 }),
    title({ x: 80, y: b * 0.3, width: 920, height: b * 0.35 }, ctx, t, 120 * f.k, 3),
    text({ x: 80, y: b * 0.72, width: 920, height: 50 }, ctx.date, { font: t.body, fontSize: 40 * f.k, fill: t.accent, bold: true }),
    text({ x: 80, y: b * 0.72 + 64 * f.k, width: 920, height: 100 }, ctx.venue, { font: t.body, fontSize: 36 * f.k, fill: t.soft }),
  ];
};

// --- Catalogue ------------------------------------------------------------------------------------

/**
 * Les 10 000 modèles ne sont pas écrits un à un : chaque catégorie croise des compositions, des
 * palettes, des paires de polices, des décors et des thèmes de photo. Le numéro d'un modèle
 * (« wedding-0042 ») suffit à le redessiner à l'identique ; rien n'est stocké.
 */

/** Compositions, et si elles demandent une photo ou un fond sombre. */
const LAYOUTS = {
  heroPhoto: { draw: heroPhoto, photo: true },
  cutout: { draw: cutout, photo: true },
  quote: { draw: quote, photo: true },
  centerTitle: { draw: centerTitle, photo: false },
  infoCard: { draw: infoCard, photo: true },
  sideProgram: { draw: sideProgram, photo: true },
  circleTiles: { draw: circleTiles, photo: true },
  speakers: { draw: speakers, photo: true },
  framed: { draw: framed, photo: true },
  split: { draw: split, photo: true },
  patterned: { draw: patterned, photo: true },
  neonFrame: { draw: neonFrame, photo: true, dark: true },
  typo: { draw: typo, photo: false },
  luxe: { draw: luxe, photo: false },
  live: { draw: live, photo: true, dark: true },
  versus: { draw: versus, photo: true },
  ribbon: { draw: ribbon, photo: false },
} satisfies Record<string, { draw: Layout; photo: boolean; dark?: boolean }>;
export type LayoutId = keyof typeof LAYOUTS;
export const LAYOUT_IDS = Object.keys(LAYOUTS) as LayoutId[];

/** Paires de polices : titre, texte, titre en capitales, titre en italique. */
const FONT_PAIRS: [CanvasFont, CanvasFont, boolean?, boolean?][] = [
  ["anton", "poppins", true], // 0
  ["anton", "poppins", true, true], // 1 sport
  ["poppins", "poppins", true], // 2
  ["fraunces", "instrument"], // 3
  ["playfair", "poppins"], // 4
  ["playfair", "playfair", true], // 5 gala
  ["cinzel", "playfair"], // 6
  ["cinzel", "poppins"], // 7
  ["script", "playfair"], // 8
  ["pacifico", "playfair"], // 9
  ["fredoka", "fredoka"], // 10
  ["fredoka", "poppins"], // 11
  ["poppins", "instrument"], // 12
  ["fraunces", "poppins"], // 13
  ["instrument", "instrument", true], // 14
];

/**
 * Thèmes de photo : la recherche envoyée à la banque d'images (en anglais, la langue des mots-clés
 * des banques). Pensés pour un public d'Afrique centrale.
 */
export const PHOTO_THEMES = {
  concertCrowd: "concert crowd lights",
  singer: "african singer stage microphone",
  guitarist: "african guitarist concert",
  dj: "black dj party",
  gospelChoir: "african gospel choir",
  drums: "african drums music",
  stageLights: "concert stage lights",
  saxophone: "jazz saxophone black musician",
  rapper: "black rapper stage",
  band: "live band concert",
  dancing: "african friends dancing party",
  nightclub: "nightclub party lights",
  cocktails: "cocktails party drinks",
  rooftop: "rooftop party friends",
  fashion: "african fashion model",
  karaoke: "karaoke friends singing",
  toast: "friends toast celebration",
  danceCouple: "african couple dancing",
  weddingCouple: "african wedding couple",
  bride: "african bride wedding dress",
  rings: "wedding rings hands",
  bouquet: "wedding bouquet flowers",
  reception: "wedding reception table decoration",
  traditionalWedding: "african traditional wedding",
  weddingCake: "wedding cake",
  groom: "black groom suit",
  churchWedding: "church wedding ceremony",
  birthdayCake: "birthday cake candles",
  balloons: "colorful balloons party",
  kidsParty: "african kids birthday party",
  birthdayWoman: "black woman birthday celebration",
  birthdayMan: "black man birthday celebration",
  gifts: "birthday gifts",
  family: "african family celebration",
  champagne: "champagne glasses celebration",
  worshipHands: "worship hands raised church",
  choir: "church choir singing",
  preacher: "african pastor preaching",
  bible: "bible open light",
  church: "church interior",
  prayer: "black woman praying",
  worshipCrowd: "worship concert crowd",
  candles: "candles light dark",
  cross: "cross sunset",
  baptism: "baptism water",
  galaDinner: "gala dinner table",
  trophy: "gold trophy award",
  redCarpet: "red carpet event",
  eveningGown: "black woman evening gown",
  chandelier: "chandelier luxury hall",
  tuxedo: "black man tuxedo",
  applause: "audience applause",
  awardStage: "award ceremony stage",
  speaker: "african conference speaker",
  audience: "conference audience",
  workshop: "african workshop team",
  classroom: "african students classroom",
  teamLaptop: "african team laptop",
  businessWoman: "african business woman",
  businessMan: "african business man",
  graduation: "african graduation",
  panel: "panel discussion conference",
  notebook: "notebook desk study",
  videoCall: "video call laptop",
  laptop: "black woman laptop",
  smartphone: "black man smartphone",
  podcast: "podcast microphone",
  streaming: "live streaming camera",
  onlineClass: "online class student",
  headphones: "headphones music black",
  homeOffice: "home office desk",
  parenting: "african mother child",
  football: "african football player",
  basketball: "basketball player",
  boxing: "boxing gloves boxer",
  running: "black runner track",
  stadium: "football stadium",
  athlete: "african athlete training",
  tennis: "tennis player",
  gym: "gym fitness black",
  martialArts: "martial arts",
  cycling: "cycling race",
  meeting: "african team meeting",
  office: "modern office",
  handshake: "business handshake",
  launch: "product launch event",
  skyline: "african city skyline",
  entrepreneur: "african entrepreneur",
  coworking: "coworking space",
  market: "african market",
  store: "store opening shop",
  networking: "networking event people",
} as const;
export type PhotoTheme = keyof typeof PHOTO_THEMES;

type Recipe = { layouts: LayoutId[]; palettes: PaletteId[]; fonts: number[]; decors: Decor[]; photos: PhotoTheme[]; labels?: string[] };

const RECIPES: Record<Exclude<Category, "all">, Recipe> = {
  concert: {
    layouts: ["neonFrame", "heroPhoto", "cutout", "patterned", "speakers", "sideProgram", "split", "typo", "circleTiles"],
    palettes: ["neon", "stageYellow", "ember", "electric", "gospelViolet", "plumNeon", "charcoalCoral", "waxYellow", "kente", "cobalt", "tealCoral", "congo", "ocean", "midnightGold", "peach"],
    fonts: [0, 2, 3, 7, 11, 13],
    decors: ["glow", "stars", "wax", "none"],
    photos: ["concertCrowd", "singer", "guitarist", "dj", "gospelChoir", "drums", "stageLights", "saxophone", "rapper", "band"],
  },
  party: {
    layouts: ["neonFrame", "heroPhoto", "cutout", "patterned", "typo", "circleTiles", "split", "quote"],
    palettes: ["electric", "plumNeon", "neon", "peach", "candy", "charcoalCoral", "tealCoral", "ember", "sunrise", "lavender", "kente", "waxYellow", "midnightGold", "wine"],
    fonts: [0, 2, 10, 9, 13, 11],
    decors: ["glow", "confetti", "stars", "dots"],
    photos: ["dancing", "nightclub", "cocktails", "dj", "rooftop", "fashion", "karaoke", "danceCouple", "toast", "champagne"],
  },
  wedding: {
    layouts: ["framed", "split", "heroPhoto", "centerTitle", "luxe", "circleTiles", "quote", "ribbon"],
    palettes: ["ivoryGold", "blush", "sage", "pearlNavy", "roseGold", "sand", "terracotta", "wine", "emeraldNight", "royalPurple", "midnightGold", "lavender", "sky", "navyGold"],
    fonts: [8, 9, 6, 4, 5, 3],
    decors: ["petals", "dots", "rays", "none", "halo"],
    photos: ["weddingCouple", "bride", "rings", "bouquet", "reception", "traditionalWedding", "weddingCake", "groom", "danceCouple", "churchWedding"],
  },
  birthday: {
    layouts: ["patterned", "cutout", "circleTiles", "luxe", "heroPhoto", "centerTitle", "split", "typo"],
    palettes: ["candy", "skyBalloon", "sunny", "aqua", "lavender", "peach", "midnightGold", "wine", "roseGold", "tealCoral", "mint", "kente", "neon", "electric"],
    fonts: [10, 11, 9, 13, 6, 0],
    decors: ["confetti", "stars", "dots", "glow", "petals"],
    photos: ["birthdayCake", "balloons", "kidsParty", "birthdayWoman", "birthdayMan", "toast", "gifts", "family", "champagne", "dancing"],
    labels: ["18", "20", "25", "30", "40", "50", "60", "70"],
  },
  worship: {
    layouts: ["centerTitle", "framed", "heroPhoto", "speakers", "cutout", "sideProgram", "quote", "split"],
    palettes: ["sunrise", "sky", "pearlNavy", "gospelViolet", "royalPurple", "navyGold", "emeraldNight", "cocoa", "ivoryGold", "terracotta", "sand", "cobalt", "ocean", "lavender"],
    fonts: [6, 7, 4, 2, 13, 3],
    decors: ["halo", "rays", "glow", "stars"],
    photos: ["worshipHands", "choir", "preacher", "bible", "church", "prayer", "worshipCrowd", "candles", "cross", "baptism"],
  },
  gala: {
    layouts: ["luxe", "sideProgram", "centerTitle", "typo", "framed", "heroPhoto", "speakers", "split"],
    palettes: ["midnightGold", "navyGold", "royalPurple", "wine", "emeraldNight", "cocoa", "ivoryGold", "pearlNavy", "stageYellow", "ocean", "charcoalCoral", "roseGold"],
    fonts: [5, 6, 0, 4, 7, 3],
    decors: ["stars", "halo", "glow", "rays", "none"],
    photos: ["galaDinner", "trophy", "redCarpet", "eveningGown", "champagne", "chandelier", "tuxedo", "applause", "awardStage", "reception"],
  },
  conference: {
    layouts: ["cutout", "infoCard", "typo", "circleTiles", "speakers", "sideProgram", "split", "heroPhoto", "quote"],
    palettes: ["paperNavy", "cleanRed", "mint", "cobalt", "slateLime", "fleuve", "tealCream", "minimal", "ocean", "kente", "lavender", "navyGold", "tealCoral"],
    fonts: [2, 12, 0, 3, 14, 13],
    decors: ["dots", "none", "glow"],
    photos: ["speaker", "audience", "workshop", "classroom", "teamLaptop", "businessWoman", "businessMan", "graduation", "panel", "notebook"],
  },
  online: {
    layouts: ["live", "cutout", "infoCard", "quote", "circleTiles", "split", "typo", "heroPhoto"],
    palettes: ["ocean", "slateLime", "electric", "neon", "cobalt", "charcoalCoral", "tealCoral", "mint", "paperNavy", "lavender", "plumNeon", "candy"],
    fonts: [2, 11, 12, 0, 10, 13],
    decors: ["glow", "dots", "petals", "none"],
    photos: ["videoCall", "laptop", "smartphone", "podcast", "streaming", "onlineClass", "headphones", "homeOffice", "parenting", "teamLaptop"],
    labels: ["online"],
  },
  sport: {
    layouts: ["versus", "heroPhoto", "cutout", "typo", "split", "quote", "circleTiles", "neonFrame"],
    palettes: ["redFight", "stadium", "congo", "stageYellow", "limeCourt", "cobalt", "ember", "electric", "charcoalCoral", "slateLime", "kente", "ocean"],
    fonts: [1, 0, 2, 14, 11],
    decors: ["stars", "glow", "none", "dots"],
    photos: ["football", "basketball", "boxing", "running", "stadium", "athlete", "tennis", "gym", "martialArts", "cycling"],
  },
  business: {
    layouts: ["ribbon", "infoCard", "circleTiles", "cutout", "typo", "split", "heroPhoto", "sideProgram", "quote", "speakers"],
    palettes: ["fleuve", "tealCream", "paperNavy", "cleanRed", "minimal", "slateLime", "navyGold", "cobalt", "mint", "sand", "kente", "ocean", "charcoalCoral"],
    fonts: [3, 12, 2, 13, 0, 14],
    decors: ["dots", "none", "glow"],
    photos: ["meeting", "office", "handshake", "launch", "skyline", "entrepreneur", "coworking", "market", "store", "networking"],
  },
};

/** Modèles par catégorie : 10 catégories × 1 000 = 10 000 modèles. */
export const TEMPLATES_PER_CATEGORY = 1000;
const TEMPLATE_CATEGORIES = CATEGORIES.filter((category): category is Exclude<Category, "all"> => category !== "all");
export const TEMPLATE_COUNT = TEMPLATE_CATEGORIES.length * TEMPLATES_PER_CATEGORY;
/** Nombre de photos proposées par la banque pour chaque thème (une page de résultats). */
export const PHOTOS_PER_THEME = 30;

/** Un modèle : son numéro dit tout le reste. */
export type TemplateInfo = {
  id: string;
  /** Numéro affiché, de 1 à 10 000. */
  number: number;
  category: Exclude<Category, "all">;
  layout: LayoutId;
  palette: PaletteId;
  styles: TemplateStyle[];
  /** Thème et rang de la photo dans les résultats de la banque ; null : modèle sans photo. */
  photo: { theme: PhotoTheme; index: number } | null;
  /** Rendu : polices, décor, libellé, graine des décors aléatoires. */
  fonts: number;
  decor: Decor;
  label?: string;
  seed: number;
};

/**
 * Combinaisons valides d'une catégorie (pas de composition « nuit » sur fond clair), mélangées une
 * fois pour toutes (graine fixe) : deux modèles voisins dans la galerie ne se ressemblent pas, les
 * 1 000 retenus sont tous différents et gardent leur numéro d'une version à l'autre.
 */
function catalog(category: Exclude<Category, "all">, offset: number): TemplateInfo[] {
  const recipe = RECIPES[category];
  const combos: [number, number, number, number][] = [];
  recipe.palettes.forEach((palette, p) =>
    recipe.layouts.forEach((layout, l) => {
      const needsDark = "dark" in LAYOUTS[layout] && LAYOUTS[layout].dark;
      if (needsDark && PALETTES[palette].tone !== "dark") return;
      recipe.fonts.forEach((_, f) => recipe.decors.forEach((_, d) => combos.push([l, p, f, d])));
    }),
  );
  const random = seeded(offset + 1);
  for (let i = combos.length - 1; i > 0; i--) {
    const j = Math.floor(random() * (i + 1));
    [combos[i], combos[j]] = [combos[j], combos[i]];
  }
  // Le n° 1 de chaque catégorie reste sa première composition sur sa première palette (kassEvent Fleuve en entreprise).
  const signature = combos.findIndex((combo) => combo.every((value) => value === 0));
  if (signature > 0) [combos[0], combos[signature]] = [combos[signature], combos[0]];
  return Array.from({ length: TEMPLATES_PER_CATEGORY }, (_, k) => {
    const [l, p, f, d] = combos[k];
    const layout = recipe.layouts[l];
    const palette = PALETTES[recipe.palettes[p]];
    const theme = recipe.photos[k % recipe.photos.length];
    return {
      id: `${category}-${String(k + 1).padStart(4, "0")}`,
      number: offset + k + 1,
      category,
      layout,
      palette: recipe.palettes[p],
      styles: [
        LAYOUTS[layout].photo ? "photo" : "typo",
        ...palette.tags,
        ...(palette.tone === "dark" ? (["dark"] as const) : []),
      ],
      photo: LAYOUTS[layout].photo ? { theme, index: Math.floor(k / recipe.photos.length) % PHOTOS_PER_THEME } : null,
      fonts: recipe.fonts[f],
      decor: recipe.decors[d],
      label: recipe.labels?.[k % recipe.labels.length],
      seed: offset + k * 31 + 7,
    };
  });
}

let all: TemplateInfo[] | null = null;
let byId: Map<string, TemplateInfo> | null = null;

/** Les 10 000 modèles, calculés à la première demande (quelques millisecondes). */
export function allTemplates(): TemplateInfo[] {
  if (!all) {
    all = TEMPLATE_CATEGORIES.flatMap((category, i) => catalog(category, i * TEMPLATES_PER_CATEGORY));
    byId = new Map(all.map((info) => [info.id, info]));
  }
  return all;
}

export function templateInfo(id: string): TemplateInfo | null {
  allTemplates();
  return byId!.get(id) ?? null;
}

export function templateMatches(info: TemplateInfo, category: Category, style: TemplateStyle | null): boolean {
  return (category === "all" || info.category === category) && (!style || info.styles.includes(style));
}

/** Modèle par défaut d'une page neuve : vert Fleuve de kassEvent, ruban. */
export const DEFAULT_TEMPLATE = "business-0001";

/** Modèle sans rien : seulement le talon du billet. */
export const BLANK_TEMPLATE = "blank";

export function buildTemplate(id: string, usage: Usage, format: FormatId, ctx: TemplateContext): CanvasDoc {
  const f = frame(usage, format);
  const info = templateInfo(id);
  if (!info) {
    const t: Theme = { ...PALETTES.minimal, bg: "#FFFFFF", title: "poppins", body: "poppins" };
    return { width: f.w, height: f.h, background: { color: t.bg, image: null }, elements: usage === "ticket" ? stub(f, t, ctx) : [] };
  }
  const [title, body, upper, italic] = FONT_PAIRS[info.fonts];
  const t: Theme = { ...PALETTES[info.palette], title, body, upper, italic };
  const elements = [
    ...(t.bg2 ? [shape("rect", { x: 0, y: 0, width: f.w, height: f.h }, { fill: t.bg, fill2: t.bg2 })] : []),
    ...LAYOUTS[info.layout].draw(f, t, ctx, { seed: info.seed, decor: info.decor, label: info.label }),
    ...(usage === "ticket" ? stub(f, t, ctx) : []),
  ];
  return { width: f.w, height: f.h, background: { color: t.bg, image: null }, elements };
}
