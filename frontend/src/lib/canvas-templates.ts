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
    entry: string;
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
    free: string;
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

type Theme = {
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
    const perLine = Math.max(1, Math.floor(width / (current * glyph)));
    const needed = wrappedLines(words, perLine);
    if (needed <= lines && needed * current * 1.05 <= height) break;
    current -= 4;
  }
  return Math.round(current);
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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
      photo({ x: 0, y: 0, width: 1200, height: 630 }, ctx.photoLabel),
      shape("rect", { x: 0, y: 0, width: 1200, height: 630 }, { fill: null, fill2: t.bg }),
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
      photo({ x: 0, y: 0, width: 1200, height: 630 }, ctx.photoLabel),
      shape("rect", { x: 0, y: 0, width: 1200, height: 630 }, { fill: t.bg, opacity: 0.55 }),
      title({ x: 60, y: 50, width: 640, height: 150 }, ctx, t, 76, 2),
      shape("rect", { x: 60, y: 230, width: 560, height: 340 }, { fill: t.card, cornerRadius: 28 }),
      text({ x: 100, y: 260, width: 480, height: 90 }, ctx.date, { font: t.body, fontSize: 32, bold: true, fill: t.cardInk }),
      ...pill({ x: 100, y: 370, width: 400, height: 56 }, ctx.labels.program, t.accent, t.onAccent, t.body, 24),
      ...bullets({ x: 100, y: 450, width: 480, height: 110 }, ctx.labels.points.slice(0, 2), { ink: t.cardInk, iconColor: t.accent, font: t.body, size: 22, icon: "arrow" }),
      ...pill({ x: 760, y: 470, width: 380, height: 100 }, `${ctx.labels.entry} : ${ctx.price}`, t.accent, t.onAccent, t.body, 32),
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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

/** Q. Page vide, aux couleurs du thème. */
const blank: Layout = () => [];

/** R. kassEvent : ruban en biais sur le vert Fleuve. */
const ribbon: Layout = (f, t, ctx, o) => {
  if (f.wide) {
    return [
      ...decor(o.decor, { x: 0, y: 0, width: 1200, height: 630 }, t, o.seed),
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

type Template = { category: Exclude<Category, "all">; styles: TemplateStyle[]; layout: Layout; theme: Theme; options?: Omit<LayoutOptions, "seed"> };

const dark = (overrides: Partial<Theme>): Theme => ({
  bg: "#0E1B1C",
  ink: "#FFFFFF",
  soft: "#C9D3D2",
  accent: "#F2B705",
  accent2: "#17BEBB",
  onAccent: "#0E1B1C",
  card: "#FFFFFF",
  cardInk: "#0E1B1C",
  cardSoft: "#5B6B6B",
  title: "anton",
  body: "poppins",
  stub: "card",
  ...overrides,
});

const light = (overrides: Partial<Theme>): Theme => ({
  bg: "#FFFDF6",
  ink: "#14213D",
  soft: "#5B6478",
  accent: "#E4572E",
  accent2: "#14213D",
  onAccent: "#FFFFFF",
  card: "#14213D",
  cardInk: "#FFFFFF",
  cardSoft: "#C3CAD9",
  title: "poppins",
  body: "poppins",
  stub: "card",
  ...overrides,
});

const TEMPLATES = {
  // Concerts et festivals
  neon: { category: "concert", styles: ["photo", "dark", "colorful"], layout: neonFrame, theme: dark({ bg: "#12002B", bg2: "#3A0CA3", accent: "#F72585", accent2: "#4CC9F0", title: "poppins", upper: true }), options: { decor: "glow" } },
  stage: { category: "concert", styles: ["photo", "dark"], layout: heroPhoto, theme: dark({ bg: "#0A0A0A", accent: "#FFD60A", accent2: "#E63946", soft: "#BDBDBD", upper: true, stub: "band", card: "#FFD60A", cardSoft: "#5A4A00" }) },
  afroSun: { category: "concert", styles: ["typo", "colorful"], layout: typo, theme: light({ bg: "#FF6B1A", bg2: "#FFB703", ink: "#2B0F00", soft: "#5C2A00", accent: "#2B0F00", accent2: "#FFFFFF", onAccent: "#FFB703", title: "anton", card: "#2B0F00", cardInk: "#FFFFFF", cardSoft: "#FFB703" }), options: { decor: "rays" } },
  wax: { category: "concert", styles: ["colorful", "typo"], layout: patterned, theme: light({ bg: "#F2B705", ink: "#0E1B1C", soft: "#0E1B1C", accent: "#E4572E", accent2: "#17BEBB", onAccent: "#FFFFFF", title: "fraunces", body: "instrument", card: "#0E1B1C", cardInk: "#FFFFFF", cardSoft: "#F2B705" }), options: { decor: "wax" } },
  artist: { category: "concert", styles: ["photo", "dark"], layout: cutout, theme: dark({ bg: "#0B1F3A", bg2: "#13315C", accent: "#FFC300", accent2: "#E63946", onAccent: "#0B1F3A", soft: "#A9BCD0", upper: true }), options: { decor: "stars" } },
  gospel: { category: "concert", styles: ["photo", "colorful"], layout: speakers, theme: dark({ bg: "#2D1B69", bg2: "#5B2A86", accent: "#F4C430", accent2: "#F4C430", onAccent: "#2D1B69", title: "cinzel", body: "poppins", card: "#FFFFFF", stub: "band" }), options: { decor: "glow" } },

  // Soirées
  club: { category: "party", styles: ["dark", "colorful", "photo"], layout: neonFrame, theme: dark({ bg: "#03071E", bg2: "#0B3B3C", accent: "#2EC4B6", accent2: "#FF3CAC", title: "anton", upper: true, stub: "open" }), options: { decor: "glow" } },
  pinkParty: { category: "party", styles: ["typo", "colorful"], layout: typo, theme: light({ bg: "#FF3CAC", ink: "#1A0018", soft: "#4A0040", accent: "#1A0018", accent2: "#FFF35C", onAccent: "#FFF35C", title: "anton", card: "#1A0018", cardInk: "#FFFFFF", cardSoft: "#FF9AD5" }), options: { decor: "dots" } },
  sunset: { category: "party", styles: ["photo", "colorful"], layout: heroPhoto, theme: light({ bg: "#FFF4E6", ink: "#3D0C02", soft: "#7A3E2B", accent: "#FF7A00", accent2: "#3D0C02", title: "anton", upper: true, stub: "band", card: "#3D0C02", cardSoft: "#FFB37A" }) },

  // Mariages
  elegant: { category: "wedding", styles: ["elegant"], layout: framed, theme: light({ bg: "#FBF6EE", ink: "#3B2F2F", soft: "#7A6A4F", accent: "#B08D3C", accent2: "#E9DCC3", title: "script", body: "playfair", stub: "open" }) },
  photoWedding: { category: "wedding", styles: ["photo", "elegant"], layout: split, theme: light({ bg: "#FFFFFF", ink: "#3B1020", accent: "#E9C46A", accent2: "#6B1E3A", onAccent: "#3B1020", card: "#6B1E3A", cardInk: "#FFFFFF", cardSoft: "#F3D9E1", title: "script", body: "playfair" }) },
  blush: { category: "wedding", styles: ["elegant", "colorful"], layout: framed, theme: light({ bg: "#FCE8EC", ink: "#5A2A3A", soft: "#9C6B78", accent: "#D88C9A", accent2: "#F6C7CF", title: "pacifico", body: "playfair", stub: "open" }), options: { decor: "petals" } },
  sage: { category: "wedding", styles: ["photo", "elegant"], layout: split, theme: light({ bg: "#F4F1EA", accent: "#8A9A5B", accent2: "#E7E2D3", onAccent: "#FFFFFF", card: "#F4F1EA", cardInk: "#2F3A26", cardSoft: "#6B7458", title: "cinzel", body: "playfair" }), options: { decor: "dots" } },
  royalWedding: { category: "wedding", styles: ["elegant", "dark"], layout: luxe, theme: dark({ bg: "#0F1A2E", bg2: "#1C2D4F", accent: "#D4AF37", soft: "#B8C2D6", title: "script", body: "playfair", stub: "open" }) },
  heroWedding: { category: "wedding", styles: ["photo", "elegant"], layout: heroPhoto, theme: light({ bg: "#FFFBF5", ink: "#4A3426", soft: "#8B6F5A", accent: "#C9A46A", accent2: "#4A3426", onAccent: "#FFFFFF", title: "script", body: "playfair", card: "#4A3426", cardSoft: "#E2CDB0", stub: "band" }) },

  // Anniversaires
  confetti: { category: "birthday", styles: ["colorful", "photo"], layout: patterned, theme: light({ bg: "#FFF0F5", ink: "#C3315C", soft: "#3B1020", accent: "#F72585", accent2: "#FFB703", title: "fraunces", body: "fredoka", card: "#FFFFFF", cardInk: "#3B1020", cardSoft: "#C3315C" }), options: { decor: "confetti" } },
  balloons: { category: "birthday", styles: ["colorful", "photo"], layout: cutout, theme: light({ bg: "#E8F4FF", ink: "#0B3C6B", soft: "#3D6A93", accent: "#FF5D8F", accent2: "#2E86DE", title: "fredoka", body: "fredoka", card: "#0B3C6B", cardSoft: "#9CC6EE" }), options: { decor: "confetti" } },
  sunnyBirthday: { category: "birthday", styles: ["photo", "colorful"], layout: heroPhoto, theme: light({ bg: "#FFE66D", ink: "#2D1E2F", soft: "#5B4A2E", accent: "#FF6B6B", accent2: "#2D1E2F", title: "pacifico", body: "fredoka", card: "#2D1E2F", cardSoft: "#FFE66D", stub: "band" }) },
  fifty: { category: "birthday", styles: ["elegant", "dark"], layout: luxe, theme: dark({ bg: "#0A0A0A", bg2: "#2A2418", accent: "#D4AF37", soft: "#BFB49A", title: "cinzel", body: "playfair", stub: "open" }), options: { label: "50" } },
  kids: { category: "birthday", styles: ["colorful", "typo"], layout: circleTiles, theme: light({ bg: "#7BDFF2", ink: "#1D3557", soft: "#1D3557", accent: "#F25F5C", accent2: "#FFE066", card: "#FFFFFF", cardInk: "#1D3557", title: "fredoka", body: "fredoka" }), options: { decor: "confetti" } },

  // Cultes et cérémonies religieuses
  revival: { category: "worship", styles: ["typo", "colorful"], layout: centerTitle, theme: light({ bg: "#FFF8EE", ink: "#2B2B2B", soft: "#5A4636", accent: "#E8871E", accent2: "#FFB347", card: "#1F1F1F", cardInk: "#FFFFFF", title: "playfair", body: "poppins", stub: "band", cardSoft: "#FFB347" }), options: { decor: "halo" } },
  worshipNight: { category: "worship", styles: ["photo", "colorful"], layout: speakers, theme: light({ bg: "#FFFFFF", bg2: "#F3EEE7", ink: "#1F1F1F", soft: "#5A5A5A", accent: "#E8871E", accent2: "#E8871E", card: "#1F1F1F", cardInk: "#FFFFFF", title: "playfair", body: "poppins", stub: "band", cardSoft: "#E8871E" }) },
  heaven: { category: "worship", styles: ["elegant", "typo"], layout: centerTitle, theme: light({ bg: "#EAF4FB", bg2: "#FFFFFF", ink: "#0D3B66", soft: "#3E6990", accent: "#2E86AB", accent2: "#F6D365", card: "#0D3B66", title: "cinzel", body: "poppins" }), options: { decor: "rays" } },
  communion: { category: "worship", styles: ["elegant"], layout: framed, theme: light({ bg: "#FFFFFF", ink: "#1E2A3A", soft: "#6A7A8C", accent: "#C5A253", accent2: "#E8E2D0", title: "cinzel", body: "playfair", stub: "open" }), options: { decor: "rays" } },

  // Galas et remises de prix
  gala: { category: "gala", styles: ["elegant", "dark"], layout: luxe, theme: dark({ bg: "#0A0A0A", bg2: "#2A2418", accent: "#D4AF37", soft: "#BFB49A", title: "playfair", body: "playfair", upper: true, stub: "open" }) },
  awards: { category: "gala", styles: ["photo", "dark", "elegant"], layout: sideProgram, theme: dark({ bg: "#0B2545", bg2: "#13315C", accent: "#E9B949", accent2: "#E9B949", soft: "#C5D1E0", title: "anton", body: "poppins", upper: true }), options: { decor: "stars" } },
  royal: { category: "gala", styles: ["elegant", "dark"], layout: centerTitle, theme: dark({ bg: "#2A0845", bg2: "#4B1D6B", accent: "#E5C07B", accent2: "#E5C07B", soft: "#D9C8EA", title: "cinzel", body: "playfair", card: "#FFFFFF" }), options: { decor: "halo" } },
  trophy: { category: "gala", styles: ["dark", "typo"], layout: typo, theme: dark({ bg: "#111111", accent: "#C9A227", accent2: "#C9A227", onAccent: "#111111", title: "anton", stub: "band", card: "#C9A227", cardSoft: "#4A3B0A" }), options: { decor: "stars" } },

  // Conférences et formations
  conference: { category: "conference", styles: ["photo"], layout: cutout, theme: light({ bg: "#F5F7FA", ink: "#14213D", soft: "#4A5A78", accent: "#14213D", accent2: "#2EC4B6", onAccent: "#FFFFFF", title: "poppins" }), options: { decor: "dots" } },
  course: { category: "conference", styles: ["photo", "colorful"], layout: infoCard, theme: dark({ bg: "#3C1D78", bg2: "#5B3A9E", accent: "#D7263D", accent2: "#D7263D", onAccent: "#FFFFFF", title: "poppins", card: "#FFFFFF", cardInk: "#2A1458", cardSoft: "#8C7BB0" }) },
  summit: { category: "conference", styles: ["typo"], layout: typo, theme: light({ bg: "#FFFFFF", ink: "#0A2463", soft: "#3E5C9A", accent: "#0A2463", accent2: "#3E92CC", onAccent: "#FFFFFF", title: "anton" }) },
  workshop: { category: "conference", styles: ["photo", "colorful"], layout: circleTiles, theme: light({ bg: "#F6F7FB", ink: "#1B263B", soft: "#5B6478", accent: "#3A86FF", accent2: "#1B3A6B", card: "#FFFFFF", cardInk: "#1B263B" }) },
  panel: { category: "conference", styles: ["photo"], layout: speakers, theme: light({ bg: "#F0F4F8", ink: "#102A43", soft: "#486581", accent: "#F0B429", accent2: "#102A43", onAccent: "#102A43", card: "#102A43", cardInk: "#FFFFFF", cardSoft: "#9FB3C8" }) },

  // En ligne
  webinar: { category: "online", styles: ["dark", "photo"], layout: live, theme: dark({ bg: "#0D1B2A", bg2: "#1B263B", accent: "#00B4D8", accent2: "#90E0EF", title: "poppins", card: "#E0E1DD", stub: "card" }), options: { decor: "glow" } },
  challenge: { category: "online", styles: ["photo", "colorful"], layout: cutout, theme: dark({ bg: "#223042", accent: "#F2C94C", accent2: "#3AA39A", onAccent: "#FFFFFF", title: "fredoka", body: "poppins", soft: "#E8EEF5" }), options: { decor: "petals", label: "online" } },
  quoteCard: { category: "online", styles: ["photo", "dark", "typo"], layout: quote, theme: dark({ bg: "#05121F", accent: "#E63946", accent2: "#FFFFFF", title: "poppins", italic: true, card: "#0B1C2C", cardInk: "#FFFFFF", cardSoft: "#7D8FA3", stub: "band" }) },

  // Sport
  versus: { category: "sport", styles: ["photo", "dark"], layout: versus, theme: dark({ bg: "#0B0B0B", accent: "#E63946", accent2: "#FFFFFF", onAccent: "#FFFFFF", title: "anton", upper: true, italic: true, stub: "band", card: "#E63946", cardInk: "#FFFFFF", cardSoft: "#FFD6D9" }) },
  match: { category: "sport", styles: ["photo", "colorful"], layout: heroPhoto, theme: dark({ bg: "#004E2E", accent: "#FFD000", accent2: "#FFFFFF", title: "anton", upper: true, italic: true, stub: "band", card: "#FFD000", cardSoft: "#5A4A00" }) },

  // Entreprises et lancements
  fleuve: { category: "business", styles: ["typo"], layout: ribbon, theme: dark({ bg: "#0B3B3C", accent: "#F2B705", soft: "#CFE3DF", title: "fraunces", body: "instrument", card: "#FFFDF6", cardSoft: "#5B6B6B" }) },
  launch: { category: "business", styles: ["photo", "colorful"], layout: circleTiles, theme: light({ bg: "#F7F7F7", ink: "#1F1F1F", soft: "#555555", accent: "#2E5BFF", accent2: "#1F3C88", card: "#FFFFFF", cardInk: "#1F1F1F" }) },
  corporate: { category: "business", styles: ["photo"], layout: infoCard, theme: light({ bg: "#0B3B3C", bg2: "#0E5254", ink: "#FFFFFF", soft: "#CFE3DF", accent: "#F2B705", onAccent: "#0E1B1C", card: "#FFFFFF", cardInk: "#0E1B1C", cardSoft: "#5B6B6B" }) },
  blank: { category: "business", styles: ["typo"], layout: blank, theme: light({ bg: "#FFFFFF", stub: "card" }) },
} satisfies Record<string, Template>;

export type TemplateId = keyof typeof TEMPLATES;
export const TEMPLATE_IDS = Object.keys(TEMPLATES) as TemplateId[];

export function templateMatches(id: TemplateId, category: Category, style: TemplateStyle | null): boolean {
  const template: Template = TEMPLATES[id];
  return (category === "all" || template.category === category) && (!style || template.styles.includes(style));
}

/** Nombre de modèles de chaque catégorie. */
export function categoryCount(category: Category): number {
  return TEMPLATE_IDS.filter((id) => templateMatches(id, category, null)).length;
}

export function buildTemplate(id: TemplateId, usage: Usage, format: FormatId, ctx: TemplateContext): CanvasDoc {
  const template: Template = TEMPLATES[id];
  const f = frame(usage, format);
  const t = template.theme;
  const seed = TEMPLATE_IDS.indexOf(id) * 31 + 7;
  const elements = [
    ...(t.bg2 ? [shape("rect", { x: 0, y: 0, width: f.w, height: f.h }, { fill: t.bg, fill2: t.bg2 })] : []),
    ...template.layout(f, t, ctx, { seed, ...template.options }),
    ...(usage === "ticket" ? stub(f, t, ctx) : []),
  ];
  return { width: f.w, height: f.h, background: { color: t.bg, image: null }, elements };
}
