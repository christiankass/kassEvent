import {
  FORMATS,
  newId,
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

/**
 * Briques des modèles du studio : cadre de page, thème, éléments (texte, forme, photo, pictogramme),
 * titres qui tiennent dans leur cadre, décors et talon du billet. Les compositions
 * (canvas-layouts*.ts) les assemblent ; le catalogue (canvas-templates.ts) les habille.
 */

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
    admit: string;
    entry: string;
    time: string;
    slots: string[];
    contact: string;
    versus: string;
  };
};

// --- Cadre de page --------------------------------------------------------------------------------

/** Page en cours de dessin : `bottom` est le bas de la zone libre (au-dessus du talon d'un billet). */
export type Frame = { w: number; h: number; usage: Usage; format: FormatId; wide: boolean; bottom: number; k: number };

/** Hauteur du talon d'un billet (nom de l'invité, type, code, QR code), selon le format. */
const STUB: Record<FormatId, number> = { portrait: 410, square: 330, story: 460, landscape: 0 };

export function frame(usage: Usage, format: FormatId): Frame {
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

export type Box = { x: number; y: number; width: number; height: number };

export const text = (box: Box, value: string, style: Partial<TextElement> = {}): TextElement => ({
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

export const field = (box: Box, name: FieldName, style: Partial<FieldElement> = {}): FieldElement => ({
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

export const shape = (type: ShapeElement["type"], box: Box, style: Partial<ShapeElement> = {}): ShapeElement => ({
  id: newId(),
  type,
  rotation: 0,
  ...round(box),
  ...style,
});

export const photo = (box: Box, name: string, style: Partial<ImageElement> = {}): ImageElement => ({
  id: newId(),
  type: "image",
  rotation: 0,
  asset: null,
  cornerRadius: 0,
  name,
  ...round(box),
  ...style,
});

export const icon = (name: IconName, x: number, y: number, size: number, fill: string, style: Partial<IconElement> = {}): IconElement => ({
  id: newId(),
  type: "icon",
  rotation: 0,
  icon: name,
  fill,
  ...round({ x, y, width: size, height: size }),
  ...style,
});

export const qr = (x: number, y: number, size: number): CanvasElement => ({ id: newId(), type: "qr", rotation: 0, ...round({ x, y, width: size, height: size }) });

export function round(box: Box): Box {
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
export function fit(value: string, size: number, width: number, lines: number, font: CanvasFont, upper = false, height = Infinity): number {
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
export function measuredLines(words: string[], width: number, widthOf: (value: string) => number): number {
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

export const caps = (value: string, upper?: boolean) => (upper ? value.toUpperCase() : value);

/** Titre de l'événement qui tient dans son cadre. */
export function title(box: Box, ctx: TemplateContext, t: Theme, size: number, lines: number, style: Partial<TextElement> = {}): TextElement {
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
export function pill(box: Box, value: string, fill: string, ink: string, font: CanvasFont, size: number, style: Partial<TextElement> = {}): CanvasElement[] {
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
export function infoRow(
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
export function bullets(box: Box, items: string[], o: { ink: string; iconColor: string; font: CanvasFont; size: number; icon?: IconName; rule?: string }): CanvasElement[] {
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

/** Suite pseudo-aléatoire fixe : un modèle se dessine toujours pareil. */
export function seeded(seed: number) {
  return () => {
    seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

// --- Décors ---------------------------------------------------------------------------------------

export const glow = (x: number, y: number, size: number, color: string, opacity = 0.5) => shape("ellipse", { x: x - size / 2, y: y - size / 2, width: size, height: size }, { fill: color, opacity });

export function confetti(area: Box, colors: string[], count: number, seed: number): CanvasElement[] {
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
export function rays(cx: number, cy: number, length: number, count: number, color: string, opacity: number): CanvasElement[] {
  return Array.from({ length: count }, (_, i) =>
    shape("rect", { x: cx, y: cy - 22, width: length, height: 44 }, { fill: color, opacity, rotation: (360 / count) * i }),
  );
}

/** Pétales (ellipses tournées), pour les mariages. */
export function petals(cx: number, cy: number, size: number, colors: string[], count: number): CanvasElement[] {
  return Array.from({ length: count }, (_, i) =>
    shape("ellipse", { x: cx, y: cy, width: size, height: size * 0.42 }, { fill: colors[i % colors.length], opacity: 0.85, rotation: (360 / count) * i }),
  );
}

/** Points réguliers. */
export function dots(area: Box, step: number, size: number, color: string, opacity: number): CanvasElement[] {
  const out: CanvasElement[] = [];
  for (let y = area.y; y < area.y + area.height; y += step) {
    for (let x = area.x; x < area.x + area.width; x += step) out.push(shape("ellipse", { x, y, width: size, height: size }, { fill: color, opacity }));
  }
  return out.slice(0, 48);
}

/** Double filet d'encadrement. */
export function frameLines(f: Frame, color: string, inset = 30): CanvasElement[] {
  return [
    shape("rect", { x: inset, y: inset, width: f.w - inset * 2, height: f.h - inset * 2 }, { stroke: color, strokeWidth: 4 }),
    shape("rect", { x: inset + 16, y: inset + 16, width: f.w - inset * 2 - 32, height: f.h - inset * 2 - 32 }, { stroke: color, strokeWidth: 1.5 }),
  ];
}

// --- Talon du billet ------------------------------------------------------------------------------

/** Bas du billet : nom de l'invité, type de billet, code et QR code. */
export function stub(f: Frame, t: Theme, ctx: TemplateContext): CanvasElement[] {
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

export type Layout = (f: Frame, t: Theme, ctx: TemplateContext, o: LayoutOptions) => CanvasElement[];
type LayoutOptions = { seed: number; decor?: Decor; label?: string };
export type Decor = "confetti" | "wax" | "glow" | "rays" | "petals" | "dots" | "stars" | "halo" | "none";

/** Décor de fond d'une composition, dans la zone donnée. */
export function decor(kind: Decor | undefined, area: Box, t: Theme, seed: number): CanvasElement[] {
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
