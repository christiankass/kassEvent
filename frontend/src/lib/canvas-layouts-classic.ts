import { format } from "./i18n/dictionaries";
import {
  bullets,
  decor,
  frameLines,
  icon,
  infoRow,
  photo,
  pill,
  shape,
  text,
  title,
  type Box,
  type Layout,
} from "./canvas-kit";

/** Compositions de la première bibliothèque (affiches de Kinshasa), gardées pour les meilleures. */

export const heroPhoto: Layout = (f, t, ctx, o) => {
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
export const cutout: Layout = (f, t, ctx, o) => {
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

/** E. Carte blanche d'infos sur photo : dates, ruban, programme, prix (formation, cours). */
export const infoCard: Layout = (f, t, ctx, o) => {
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

/** H. Titre, bandeau des horaires, rangée d'intervenants, adresse (culte, conférence, concert). */
export const speakers: Layout = (f, t, ctx, o) => {
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
export const framed: Layout = (f, t, ctx, o) => {
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

/** N. Noir et or : coins étoilés, titre en capitales espacées (gala, anniversaire chic). */
export const luxe: Layout = (f, t, ctx, o) => {
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
export const live: Layout = (f, t, ctx, o) => {
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
export const versus: Layout = (f, t, ctx, o) => {
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
export const ribbon: Layout = (f, t, ctx, o) => {
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

