import {
  FORMATS,
  newId,
  type CanvasDoc,
  type CanvasElement,
  type FieldElement,
  type FieldName,
  type ImageElement,
  type ShapeElement,
  type TextElement,
  type Usage,
} from "./canvas";

/**
 * Modèles du studio, par occasion : un point de départ complet (fond, décor, textes de l'événement,
 * emplacement photo, QR code et nom de l'invité pour un billet), que l'organisateur modifie ensuite.
 * Chaque modèle se dessine au format du billet (1080 × 1350) et de l'affiche (1200 × 630).
 */

export const OCCASIONS = ["all", "concert", "wedding", "birthday", "corporate"] as const;
export type Occasion = (typeof OCCASIONS)[number];

export const TEMPLATE_IDS = ["fleuve", "neon", "wax", "elegant", "photoWedding", "confetti", "conference", "gala"] as const;
export type TemplateId = (typeof TEMPLATE_IDS)[number];

/** Textes de l'événement placés dans le modèle. */
export type TemplateContext = { title: string; date: string; venue: string; organizer: string; guestLabel: string; photoLabel: string };

type Template = { occasion: Exclude<Occasion, "all">; build: (usage: Usage, ctx: TemplateContext) => CanvasDoc };

// --- Briques ---------------------------------------------------------------------------------------

type Rect = { x: number; y: number; width: number; height: number };

const text = (box: Rect, value: string, style: Partial<TextElement> = {}): TextElement => ({
  id: newId(),
  type: "text",
  rotation: 0,
  font: "instrument",
  fontSize: 40,
  fill: "#FFFFFF",
  align: "left",
  lineHeight: 1.15,
  ...box,
  ...style,
  text: value,
});

const field = (box: Rect, name: FieldName, style: Partial<FieldElement> = {}): FieldElement => ({
  id: newId(),
  type: "field",
  rotation: 0,
  font: "instrument",
  fontSize: 44,
  fill: "#FFFFFF",
  align: "left",
  lineHeight: 1.2,
  ...box,
  ...style,
  field: name,
});

const shape = (type: ShapeElement["type"], box: Rect, style: Partial<ShapeElement> = {}): ShapeElement => ({
  id: newId(),
  type,
  rotation: 0,
  ...box,
  ...style,
});

const photo = (box: Rect, cornerRadius = 0, name?: string): ImageElement => ({ id: newId(), type: "image", rotation: 0, asset: null, cornerRadius, name, ...box });

const qr = (x: number, y: number, size: number): CanvasElement => ({ id: newId(), type: "qr", rotation: 0, x, y, width: size, height: size });

const page = (usage: Usage, color: string, elements: CanvasElement[]): CanvasDoc => {
  const [width, height] = FORMATS[usage];
  return { width, height, background: { color, image: null }, elements };
};

/** Suite pseudo-aléatoire fixe : un modèle se dessine toujours pareil. */
function seeded(seed: number) {
  return () => {
    seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Bas de billet commun : nom de l'invité, type de billet, code et QR code sur une carte claire. */
function ticketStub(ctx: TemplateContext, o: { card: string; ink: string; muted: string; font?: TextElement["font"]; top?: number }): CanvasElement[] {
  const top = o.top ?? 930;
  return [
    shape("rect", { x: 60, y: top, width: 960, height: 1350 - top - 60 }, { fill: o.card, cornerRadius: 36 }),
    text({ x: 110, y: top + 60, width: 520, height: 36 }, ctx.guestLabel.toUpperCase(), { fontSize: 26, bold: true, letterSpacing: 3, fill: o.muted }),
    field({ x: 110, y: top + 104, width: 540, height: 64 }, "holder", { font: o.font ?? "instrument", fontSize: 50, bold: true, fill: o.ink }),
    field({ x: 110, y: top + 186, width: 540, height: 44 }, "ticket_type", { fontSize: 34, fill: o.ink }),
    field({ x: 110, y: top + 254, width: 540, height: 40 }, "code", { font: "mono", fontSize: 30, fill: o.muted, letterSpacing: 2 }),
    qr(1020 - 50 - 260, top + 50, 260),
  ];
}

// --- Modèles ---------------------------------------------------------------------------------------

const TEMPLATES: Record<TemplateId, Template> = {
  /** kassEvent : vert Fleuve, ruban jaune Billet. */
  fleuve: {
    occasion: "corporate",
    build: (usage, ctx) =>
      usage === "ticket"
        ? page(usage, "#0B3B3C", [
            shape("rect", { x: -120, y: 150, width: 1400, height: 90 }, { fill: "#F2B705", rotation: -8 }),
            text({ x: 80, y: 150, width: 900, height: 40 }, ctx.organizer.toUpperCase(), { fontSize: 30, bold: true, letterSpacing: 4, fill: "#0E1B1C", rotation: -8 }),
            text({ x: 80, y: 300, width: 920, height: 330 }, ctx.title, { font: "fraunces", fontSize: 104, bold: true, lineHeight: 1.02 }),
            text({ x: 80, y: 680, width: 920, height: 50 }, ctx.date, { fontSize: 40, fill: "#F2B705", bold: true }),
            text({ x: 80, y: 744, width: 920, height: 100 }, ctx.venue, { fontSize: 36, fill: "#CFE3DF" }),
            ...ticketStub(ctx, { card: "#FFFDF6", ink: "#0E1B1C", muted: "#5B6B6B", font: "fraunces" }),
          ])
        : page(usage, "#0B3B3C", [
            shape("rect", { x: 760, y: -80, width: 140, height: 900 }, { fill: "#F2B705", rotation: 18 }),
            shape("rect", { x: 940, y: -80, width: 60, height: 900 }, { fill: "#F2B705", rotation: 18, opacity: 0.5 }),
            text({ x: 70, y: 60, width: 700, height: 36 }, ctx.organizer.toUpperCase(), { fontSize: 24, bold: true, letterSpacing: 4, fill: "#F2B705" }),
            text({ x: 70, y: 130, width: 700, height: 280 }, ctx.title, { font: "fraunces", fontSize: 84, bold: true, lineHeight: 1.02 }),
            text({ x: 70, y: 450, width: 700, height: 44 }, ctx.date, { fontSize: 34, fill: "#F2B705", bold: true }),
            text({ x: 70, y: 504, width: 700, height: 80 }, ctx.venue, { fontSize: 30, fill: "#CFE3DF" }),
          ]),
  },

  /** Concert : nuit violette, halos néon, photo de l'artiste. */
  neon: {
    occasion: "concert",
    build: (usage, ctx) => {
      const glow = (x: number, y: number, size: number, color: string) => shape("ellipse", { x, y, width: size, height: size }, { fill: color, opacity: 0.55 });
      return usage === "ticket"
        ? page(usage, "#12002B", [
            shape("rect", { x: 0, y: 0, width: 1080, height: 1350 }, { fill: "#12002B", fill2: "#3A0CA3" }),
            glow(-160, -120, 620, "#F72585"),
            glow(640, 360, 560, "#4CC9F0"),
            photo({ x: 140, y: 110, width: 800, height: 520 }, 40, ctx.photoLabel),
            shape("rect", { x: 140, y: 110, width: 800, height: 520 }, { stroke: "#F72585", strokeWidth: 6, cornerRadius: 40 }),
            text({ x: 60, y: 660, width: 960, height: 150 }, ctx.title.toUpperCase(), { fontSize: 80, bold: true, align: "center", letterSpacing: 3, lineHeight: 1 }),
            text({ x: 60, y: 830, width: 960, height: 50 }, `${ctx.date}  ·  ${ctx.venue}`, { fontSize: 32, fill: "#4CC9F0", bold: true, align: "center" }),
            ...ticketStub(ctx, { card: "#FFFFFF", ink: "#12002B", muted: "#6B5B8C", top: 920 }),
          ])
        : page(usage, "#12002B", [
            shape("rect", { x: 0, y: 0, width: 1200, height: 630 }, { fill: "#12002B", fill2: "#3A0CA3" }),
            glow(-120, -160, 520, "#F72585"),
            glow(900, 300, 460, "#4CC9F0"),
            photo({ x: 700, y: 60, width: 440, height: 510 }, 36, ctx.photoLabel),
            shape("rect", { x: 700, y: 60, width: 440, height: 510 }, { stroke: "#4CC9F0", strokeWidth: 6, cornerRadius: 36 }),
            text({ x: 60, y: 120, width: 600, height: 260 }, ctx.title.toUpperCase(), { fontSize: 80, bold: true, letterSpacing: 3, lineHeight: 1 }),
            text({ x: 60, y: 420, width: 600, height: 44 }, ctx.date, { fontSize: 34, fill: "#F72585", bold: true }),
            text({ x: 60, y: 474, width: 600, height: 80 }, ctx.venue, { fontSize: 30, fill: "#4CC9F0" }),
          ]);
    },
  },

  /** Festival : cercles de couleurs façon wax. */
  wax: {
    occasion: "concert",
    build: (usage, ctx) => {
      const colors = ["#E4572E", "#17BEBB", "#C3315C", "#0B3B3C", "#FFFFFF"];
      const random = seeded(7);
      const [width, height] = FORMATS[usage];
      const circles: CanvasElement[] = [];
      for (let i = 0; i < 9; i++) {
        const size = 140 + random() * 260;
        const color = colors[i % colors.length];
        const x = random() * width - size / 2;
        const y = usage === "ticket" ? random() * 420 - size / 3 : random() * height - size / 2;
        circles.push(shape("ellipse", { x, y, width: size, height: size }, { fill: color, opacity: 0.9 }));
        circles.push(shape("ellipse", { x: x + size * 0.25, y: y + size * 0.25, width: size / 2, height: size / 2 }, { stroke: "#F2B705", strokeWidth: 14 }));
      }
      return usage === "ticket"
        ? page(usage, "#F2B705", [
            ...circles,
            text({ x: 80, y: 500, width: 920, height: 260 }, ctx.title, { font: "fraunces", fontSize: 110, bold: true, fill: "#0E1B1C", lineHeight: 1 }),
            text({ x: 80, y: 790, width: 920, height: 100 }, `${ctx.date}\n${ctx.venue}`, { fontSize: 36, bold: true, fill: "#0E1B1C" }),
            ...ticketStub(ctx, { card: "#0E1B1C", ink: "#FFFFFF", muted: "#F2B705" }),
          ])
        : page(usage, "#F2B705", [
            ...circles.map((circle) => ({ ...circle, x: circle.x * 0.45 + 640 })),
            text({ x: 60, y: 90, width: 620, height: 300 }, ctx.title, { font: "fraunces", fontSize: 92, bold: true, fill: "#0E1B1C", lineHeight: 1 }),
            text({ x: 60, y: 430, width: 600, height: 120 }, `${ctx.date}\n${ctx.venue}`, { fontSize: 34, bold: true, fill: "#0E1B1C" }),
          ]);
    },
  },

  /** Mariage élégant : ivoire, filets dorés, écriture manuscrite. */
  elegant: {
    occasion: "wedding",
    build: (usage, ctx) => {
      const gold = "#B08D3C";
      const [width, height] = FORMATS[usage];
      const frame = [
        shape("rect", { x: 30, y: 30, width: width - 60, height: height - 60 }, { stroke: gold, strokeWidth: 4 }),
        shape("rect", { x: 46, y: 46, width: width - 92, height: height - 92 }, { stroke: gold, strokeWidth: 1.5 }),
      ];
      const star = (x: number, y: number) => shape("star", { x, y, width: 34, height: 34 }, { fill: gold });
      return usage === "ticket"
        ? page(usage, "#FBF6EE", [
            ...frame,
            star(523, 110),
            text({ x: 90, y: 180, width: 900, height: 50 }, ctx.organizer, { font: "playfair", fontSize: 32, italic: true, fill: "#7A6A4F", align: "center" }),
            text({ x: 90, y: 250, width: 900, height: 330 }, ctx.title, { font: "script", fontSize: 120, fill: "#3B2F2F", align: "center", lineHeight: 1.05 }),
            shape("line", { x: 390, y: 620, width: 300, height: 10 }, { stroke: gold, strokeWidth: 2 }),
            text({ x: 90, y: 660, width: 900, height: 50 }, ctx.date, { font: "playfair", fontSize: 38, fill: "#3B2F2F", align: "center" }),
            text({ x: 90, y: 720, width: 900, height: 90 }, ctx.venue, { font: "playfair", fontSize: 32, italic: true, fill: "#7A6A4F", align: "center" }),
            shape("line", { x: 120, y: 880, width: 840, height: 10 }, { stroke: gold, strokeWidth: 2 }),
            text({ x: 120, y: 950, width: 520, height: 36 }, ctx.guestLabel, { font: "playfair", fontSize: 30, italic: true, fill: "#7A6A4F" }),
            field({ x: 120, y: 1000, width: 520, height: 80 }, "holder", { font: "script", fontSize: 70, fill: "#3B2F2F" }),
            field({ x: 120, y: 1110, width: 520, height: 44 }, "ticket_type", { font: "playfair", fontSize: 32, fill: "#3B2F2F" }),
            field({ x: 120, y: 1170, width: 520, height: 40 }, "code", { font: "mono", fontSize: 26, fill: "#7A6A4F", letterSpacing: 2 }),
            qr(700, 930, 260),
          ])
        : page(usage, "#FBF6EE", [
            ...frame,
            star(583, 90),
            text({ x: 100, y: 150, width: 1000, height: 220 }, ctx.title, { font: "script", fontSize: 110, fill: "#3B2F2F", align: "center", lineHeight: 1.05 }),
            shape("line", { x: 450, y: 400, width: 300, height: 10 }, { stroke: gold, strokeWidth: 2 }),
            text({ x: 100, y: 430, width: 1000, height: 44 }, ctx.date, { font: "playfair", fontSize: 34, fill: "#3B2F2F", align: "center" }),
            text({ x: 100, y: 484, width: 1000, height: 44 }, ctx.venue, { font: "playfair", fontSize: 28, italic: true, fill: "#7A6A4F", align: "center" }),
          ]);
    },
  },

  /** Mariage moderne : grande photo du couple, bordeaux. */
  photoWedding: {
    occasion: "wedding",
    build: (usage, ctx) =>
      usage === "ticket"
        ? page(usage, "#FFFFFF", [
            photo({ x: 0, y: 0, width: 1080, height: 700 }, 0, ctx.photoLabel),
            shape("rect", { x: 0, y: 560, width: 1080, height: 140 }, { fill: "#6B1E3A", opacity: 0.85 }),
            text({ x: 60, y: 585, width: 960, height: 90 }, ctx.title, { font: "script", fontSize: 80, align: "center", lineHeight: 1 }),
            text({ x: 60, y: 740, width: 960, height: 46 }, ctx.date, { font: "playfair", fontSize: 36, bold: true, fill: "#6B1E3A", align: "center" }),
            text({ x: 60, y: 796, width: 960, height: 80 }, ctx.venue, { font: "playfair", fontSize: 30, italic: true, fill: "#5B4B4B", align: "center" }),
            ...ticketStub(ctx, { card: "#F7EEF1", ink: "#3B1020", muted: "#6B1E3A", font: "playfair", top: 900 }),
          ])
        : page(usage, "#FFFFFF", [
            photo({ x: 0, y: 0, width: 600, height: 630 }, 0, ctx.photoLabel),
            shape("rect", { x: 600, y: 0, width: 600, height: 630 }, { fill: "#6B1E3A" }),
            text({ x: 640, y: 150, width: 520, height: 220 }, ctx.title, { font: "script", fontSize: 88, align: "center", lineHeight: 1.05 }),
            shape("line", { x: 780, y: 400, width: 240, height: 10 }, { stroke: "#E9C46A", strokeWidth: 2 }),
            text({ x: 640, y: 430, width: 520, height: 44 }, ctx.date, { font: "playfair", fontSize: 32, align: "center" }),
            text({ x: 640, y: 482, width: 520, height: 80 }, ctx.venue, { font: "playfair", fontSize: 26, italic: true, fill: "#F3D9E1", align: "center" }),
          ]),
  },

  /** Anniversaire : confettis, rose et corail. */
  confetti: {
    occasion: "birthday",
    build: (usage, ctx) => {
      const [width, height] = FORMATS[usage];
      const random = seeded(42);
      const colors = ["#F72585", "#FFB703", "#17BEBB", "#E4572E", "#7209B7"];
      const confetti: CanvasElement[] = Array.from({ length: 46 }, (_, i) => {
        const size = 14 + random() * 26;
        const x = random() * width;
        const y = usage === "ticket" ? random() * 880 : random() * height;
        const color = colors[i % colors.length];
        return i % 3 === 0
          ? shape("ellipse", { x, y, width: size, height: size }, { fill: color })
          : shape("rect", { x, y, width: size * 0.5, height: size * 1.4 }, { fill: color, rotation: random() * 360, cornerRadius: 4 });
      });
      return usage === "ticket"
        ? page(usage, "#FFF0F5", [
            ...confetti,
            photo({ x: 340, y: 90, width: 400, height: 400 }, 200, ctx.photoLabel),
            shape("ellipse", { x: 330, y: 80, width: 420, height: 420 }, { stroke: "#F72585", strokeWidth: 10 }),
            text({ x: 60, y: 520, width: 960, height: 230 }, ctx.title, { font: "fraunces", fontSize: 92, bold: true, fill: "#C3315C", align: "center", lineHeight: 1.02 }),
            text({ x: 60, y: 770, width: 960, height: 100 }, `${ctx.date}\n${ctx.venue}`, { fontSize: 34, bold: true, fill: "#3B1020", align: "center" }),
            ...ticketStub(ctx, { card: "#FFFFFF", ink: "#3B1020", muted: "#C3315C", font: "fraunces" }),
          ])
        : page(usage, "#FFF0F5", [
            ...confetti,
            photo({ x: 790, y: 110, width: 380, height: 380 }, 190, ctx.photoLabel),
            shape("ellipse", { x: 780, y: 100, width: 400, height: 400 }, { stroke: "#F72585", strokeWidth: 10 }),
            text({ x: 60, y: 110, width: 700, height: 280 }, ctx.title, { font: "fraunces", fontSize: 90, bold: true, fill: "#C3315C", lineHeight: 1.02 }),
            text({ x: 60, y: 420, width: 700, height: 110 }, `${ctx.date}\n${ctx.venue}`, { fontSize: 32, bold: true, fill: "#3B1020" }),
          ]);
    },
  },

  /** Conférence : bandeau marine, photo de l'intervenant. */
  conference: {
    occasion: "corporate",
    build: (usage, ctx) =>
      usage === "ticket"
        ? page(usage, "#F5F7FA", [
            shape("rect", { x: 0, y: 0, width: 1080, height: 860 }, { fill: "#14213D", fill2: "#1F3A68" }),
            shape("rect", { x: 80, y: 90, width: 120, height: 10 }, { fill: "#2EC4B6" }),
            text({ x: 80, y: 130, width: 640, height: 40 }, ctx.organizer.toUpperCase(), { fontSize: 28, bold: true, letterSpacing: 3, fill: "#2EC4B6" }),
            text({ x: 80, y: 210, width: 600, height: 400 }, ctx.title, { fontSize: 80, bold: true, lineHeight: 1.05 }),
            photo({ x: 720, y: 220, width: 280, height: 280 }, 140, ctx.photoLabel),
            text({ x: 80, y: 660, width: 920, height: 46 }, ctx.date, { fontSize: 36, bold: true, fill: "#FFFFFF" }),
            text({ x: 80, y: 716, width: 920, height: 90 }, ctx.venue, { fontSize: 32, fill: "#B8C4D9" }),
            ...ticketStub(ctx, { card: "#FFFFFF", ink: "#14213D", muted: "#4A5A78", top: 920 }),
          ])
        : page(usage, "#F5F7FA", [
            shape("rect", { x: 0, y: 0, width: 760, height: 630 }, { fill: "#14213D", fill2: "#1F3A68" }),
            shape("rect", { x: 70, y: 80, width: 120, height: 10 }, { fill: "#2EC4B6" }),
            text({ x: 70, y: 120, width: 640, height: 36 }, ctx.organizer.toUpperCase(), { fontSize: 24, bold: true, letterSpacing: 3, fill: "#2EC4B6" }),
            text({ x: 70, y: 180, width: 640, height: 250 }, ctx.title, { fontSize: 66, bold: true, lineHeight: 1.05 }),
            text({ x: 70, y: 460, width: 640, height: 44 }, ctx.date, { fontSize: 32, bold: true }),
            text({ x: 70, y: 512, width: 640, height: 80 }, ctx.venue, { fontSize: 28, fill: "#B8C4D9" }),
            photo({ x: 830, y: 165, width: 300, height: 300 }, 150, ctx.photoLabel),
          ]),
  },

  /** Gala : noir profond et or. */
  gala: {
    occasion: "corporate",
    build: (usage, ctx) => {
      const gold = "#D4AF37";
      const [width, height] = FORMATS[usage];
      const corners = [
        [40, 40],
        [width - 40 - 70, 40],
        [40, height - 40 - 70],
        [width - 40 - 70, height - 40 - 70],
      ].map(([x, y]) => shape("star", { x, y, width: 70, height: 70 }, { fill: gold, opacity: 0.9 }));
      return usage === "ticket"
        ? page(usage, "#0A0A0A", [
            shape("rect", { x: 0, y: 0, width, height }, { fill: "#0A0A0A", fill2: "#2A2418" }),
            shape("rect", { x: 70, y: 70, width: width - 140, height: height - 140 }, { stroke: gold, strokeWidth: 3 }),
            ...corners,
            text({ x: 120, y: 170, width: 840, height: 40 }, ctx.organizer.toUpperCase(), { fontSize: 26, letterSpacing: 8, fill: gold, align: "center" }),
            text({ x: 120, y: 250, width: 840, height: 300 }, ctx.title.toUpperCase(), { font: "playfair", fontSize: 92, bold: true, fill: gold, align: "center", letterSpacing: 4, lineHeight: 1.05 }),
            shape("line", { x: 440, y: 600, width: 200, height: 10 }, { stroke: gold, strokeWidth: 2 }),
            text({ x: 120, y: 640, width: 840, height: 46 }, ctx.date, { font: "playfair", fontSize: 36, fill: "#F5E9C8", align: "center" }),
            text({ x: 120, y: 700, width: 840, height: 90 }, ctx.venue, { font: "playfair", fontSize: 30, italic: true, fill: "#BFB49A", align: "center" }),
            field({ x: 140, y: 930, width: 520, height: 70 }, "holder", { font: "playfair", fontSize: 54, bold: true, fill: gold }),
            field({ x: 140, y: 1020, width: 520, height: 44 }, "ticket_type", { font: "playfair", fontSize: 32, fill: "#F5E9C8" }),
            field({ x: 140, y: 1080, width: 520, height: 40 }, "code", { font: "mono", fontSize: 26, fill: "#BFB49A", letterSpacing: 2 }),
            qr(700, 900, 240),
          ])
        : page(usage, "#0A0A0A", [
            shape("rect", { x: 0, y: 0, width, height }, { fill: "#0A0A0A", fill2: "#2A2418" }),
            shape("rect", { x: 70, y: 70, width: width - 140, height: height - 140 }, { stroke: gold, strokeWidth: 3 }),
            ...corners,
            text({ x: 140, y: 130, width: 920, height: 36 }, ctx.organizer.toUpperCase(), { fontSize: 24, letterSpacing: 8, fill: gold, align: "center" }),
            text({ x: 140, y: 190, width: 920, height: 220 }, ctx.title.toUpperCase(), { font: "playfair", fontSize: 76, bold: true, fill: gold, align: "center", letterSpacing: 4, lineHeight: 1.05 }),
            text({ x: 140, y: 430, width: 920, height: 44 }, `${ctx.date}  ·  ${ctx.venue}`, { font: "playfair", fontSize: 30, fill: "#F5E9C8", align: "center" }),
          ]);
    },
  },
};

export function templateOccasion(id: TemplateId): Exclude<Occasion, "all"> {
  return TEMPLATES[id].occasion;
}

export function buildTemplate(id: TemplateId, usage: Usage, ctx: TemplateContext): CanvasDoc {
  return TEMPLATES[id].build(usage, ctx);
}
