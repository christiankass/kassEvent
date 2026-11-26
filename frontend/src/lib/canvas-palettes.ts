import type { Theme } from "./canvas-kit";

/**
 * Palettes des modèles du studio. Chacune est lisible partout où les compositions la posent :
 * texte (`ink`, `soft`) et titres en accent sur le fond, texte `onAccent` sur les deux accents,
 * texte du talon sur la carte. Vérifié par `scripts/check-palettes.mjs` (contrastes WCAG).
 */
export type Palette = Omit<Theme, "title" | "body" | "upper" | "italic"> & {
  /** Fond sombre (texte clair) ou clair. */
  tone: "dark" | "light";
  /** Filtres de style de la galerie que la palette ajoute au modèle. */
  tags: ("elegant" | "colorful")[];
};

const p = (tone: Palette["tone"], tags: Palette["tags"], colors: Omit<Palette, "tone" | "tags" | "stub"> & { stub?: Theme["stub"] }): Palette => ({
  tone,
  tags,
  stub: "card",
  ...colors,
});

export const PALETTES = {
  // Sombres
  fleuve: p("dark", [], { bg: "#0B3B3C", ink: "#FFFFFF", soft: "#CFE3DF", accent: "#F2B705", accent2: "#17BEBB", onAccent: "#0E1B1C", card: "#FFFDF6", cardInk: "#0E1B1C", cardSoft: "#5B6B6B" }),
  neon: p("dark", ["colorful"], { bg: "#12002B", bg2: "#3A0CA3", ink: "#FFFFFF", soft: "#D9CCFF", accent: "#F72585", accent2: "#4CC9F0", onAccent: "#12002B", card: "#FFFFFF", cardInk: "#12002B", cardSoft: "#5A4A7A" }),
  midnightGold: p("dark", ["elegant"], { bg: "#0A0A0A", bg2: "#2A2418", ink: "#FFFFFF", soft: "#BFB49A", accent: "#D4AF37", accent2: "#A8862A", onAccent: "#0A0A0A", card: "#D4AF37", cardInk: "#0A0A0A", cardSoft: "#4A3B0A", stub: "open" }),
  royalPurple: p("dark", ["elegant"], { bg: "#2A0845", bg2: "#4B1D6B", ink: "#FFFFFF", soft: "#D9C8EA", accent: "#E5C07B", accent2: "#C77DFF", onAccent: "#2A0845", card: "#FFFFFF", cardInk: "#2A0845", cardSoft: "#6B5A80" }),
  navyGold: p("dark", ["elegant"], { bg: "#0B2545", bg2: "#13315C", ink: "#FFFFFF", soft: "#C5D1E0", accent: "#E9B949", accent2: "#8DA9C4", onAccent: "#0B2545", card: "#FFFFFF", cardInk: "#0B2545", cardSoft: "#5C6F86" }),
  ember: p("dark", ["colorful"], { bg: "#1A0B05", bg2: "#4A1408", ink: "#FFFFFF", soft: "#F2C7B0", accent: "#FF6B1A", accent2: "#FFC93C", onAccent: "#1A0B05", card: "#FFC93C", cardInk: "#1A0B05", cardSoft: "#6B4A00", stub: "band" }),
  electric: p("dark", ["colorful"], { bg: "#03071E", bg2: "#0B3B3C", ink: "#FFFFFF", soft: "#B8E8E4", accent: "#2EC4B6", accent2: "#FF3CAC", onAccent: "#03071E", card: "#FFFFFF", cardInk: "#03071E", cardSoft: "#4A5A66", stub: "open" }),
  stageYellow: p("dark", [], { bg: "#0A0A0A", ink: "#FFFFFF", soft: "#BDBDBD", accent: "#FFD60A", accent2: "#E63946", onAccent: "#0A0A0A", card: "#FFD60A", cardInk: "#0A0A0A", cardSoft: "#5A4A00", stub: "band" }),
  gospelViolet: p("dark", ["colorful"], { bg: "#2D1B69", bg2: "#5B2A86", ink: "#FFFFFF", soft: "#DCD0F5", accent: "#F4C430", accent2: "#F4C430", onAccent: "#2D1B69", card: "#FFFFFF", cardInk: "#2D1B69", cardSoft: "#6B5F8C", stub: "band" }),
  emeraldNight: p("dark", ["elegant"], { bg: "#052E2B", bg2: "#0B4F47", ink: "#FFFFFF", soft: "#BFE3D9", accent: "#E9C46A", accent2: "#52B788", onAccent: "#052E2B", card: "#FFFFFF", cardInk: "#052E2B", cardSoft: "#4F6B66" }),
  wine: p("dark", ["elegant"], { bg: "#3B0A1E", bg2: "#6B1E3A", ink: "#FFFFFF", soft: "#F0C9D6", accent: "#F2C57C", accent2: "#E07A9A", onAccent: "#3B0A1E", card: "#FFF7F0", cardInk: "#3B0A1E", cardSoft: "#7A5060", stub: "open" }),
  stadium: p("dark", ["colorful"], { bg: "#004E2E", ink: "#FFFFFF", soft: "#CDEBDB", accent: "#FFD000", accent2: "#FFFFFF", onAccent: "#00301C", card: "#FFD000", cardInk: "#00301C", cardSoft: "#4A4000", stub: "band" }),
  redFight: p("dark", [], { bg: "#0B0B0B", ink: "#FFFFFF", soft: "#D0D0D0", accent: "#E63946", accent2: "#FFFFFF", onAccent: "#0B0B0B", card: "#E63946", cardInk: "#0B0B0B", cardSoft: "#3A0B10", stub: "band" }),
  ocean: p("dark", [], { bg: "#0D1B2A", bg2: "#1B263B", ink: "#FFFFFF", soft: "#C7D3E0", accent: "#00B4D8", accent2: "#90E0EF", onAccent: "#0D1B2A", card: "#E0E1DD", cardInk: "#0D1B2A", cardSoft: "#4A5868" }),
  cobalt: p("dark", [], { bg: "#0A2463", bg2: "#1E3A8A", ink: "#FFFFFF", soft: "#C9D6F2", accent: "#FFB627", accent2: "#5AA9E6", onAccent: "#0A2463", card: "#FFFFFF", cardInk: "#0A2463", cardSoft: "#56607A" }),
  charcoalCoral: p("dark", ["colorful"], { bg: "#1F1F24", ink: "#FFFFFF", soft: "#C9C9D1", accent: "#FF7A59", accent2: "#FFD166", onAccent: "#1F1F24", card: "#FFFFFF", cardInk: "#1F1F24", cardSoft: "#5E5E6A" }),
  plumNeon: p("dark", ["colorful"], { bg: "#1B0033", bg2: "#43006B", ink: "#FFFFFF", soft: "#E2CCF5", accent: "#00F5D4", accent2: "#FEE440", onAccent: "#1B0033", card: "#00F5D4", cardInk: "#1B0033", cardSoft: "#14524A", stub: "open" }),
  cocoa: p("dark", ["elegant"], { bg: "#2B1A12", bg2: "#4A2C1C", ink: "#FFF8F0", soft: "#E3CBB5", accent: "#E8A33D", accent2: "#D9714E", onAccent: "#2B1A12", card: "#FFF8F0", cardInk: "#2B1A12", cardSoft: "#7A6050" }),
  tealCoral: p("dark", ["colorful"], { bg: "#0E3B43", bg2: "#145A63", ink: "#FFFFFF", soft: "#CBE6E8", accent: "#FF8C61", accent2: "#FFD6A5", onAccent: "#0E3B43", card: "#FFFFFF", cardInk: "#0E3B43", cardSoft: "#54707A" }),
  slateLime: p("dark", [], { bg: "#111827", bg2: "#1F2937", ink: "#FFFFFF", soft: "#CBD5E1", accent: "#A3E635", accent2: "#38BDF8", onAccent: "#111827", card: "#FFFFFF", cardInk: "#111827", cardSoft: "#556070" }),
  congo: p("dark", ["colorful"], { bg: "#005FA8", ink: "#FFFFFF", soft: "#D6ECFF", accent: "#F7D618", accent2: "#E8343F", onAccent: "#111111", card: "#FFFFFF", cardInk: "#003E70", cardSoft: "#4A6A88", stub: "band" }),

  // Claires
  ivoryGold: p("light", ["elegant"], { bg: "#FBF6EE", ink: "#3B2F2F", soft: "#6E5E45", accent: "#93702A", accent2: "#3B2F2F", onAccent: "#FFFFFF", card: "#3B2F2F", cardInk: "#FBF6EE", cardSoft: "#CDBB9A", stub: "open" }),
  blush: p("light", ["elegant", "colorful"], { bg: "#FCE8EC", ink: "#5A2A3A", soft: "#7E4C5A", accent: "#B03F58", accent2: "#5A2A3A", onAccent: "#FFFFFF", card: "#5A2A3A", cardInk: "#FFFFFF", cardSoft: "#E8B9C4", stub: "open" }),
  sage: p("light", ["elegant"], { bg: "#F4F1EA", ink: "#2F3A26", soft: "#56624A", accent: "#5A7437", accent2: "#2F3A26", onAccent: "#FFFFFF", card: "#2F3A26", cardInk: "#F4F1EA", cardSoft: "#B8C4A0" }),
  pearlNavy: p("light", ["elegant"], { bg: "#FFFFFF", ink: "#1E2A3A", soft: "#5A6A7C", accent: "#8F6E22", accent2: "#1E2A3A", onAccent: "#FFFFFF", card: "#1E2A3A", cardInk: "#FFFFFF", cardSoft: "#B5C0CC", stub: "open" }),
  sunrise: p("light", ["colorful"], { bg: "#FFF8EE", ink: "#2B2B2B", soft: "#5A4636", accent: "#C2410C", accent2: "#1F1F1F", onAccent: "#FFFFFF", card: "#1F1F1F", cardInk: "#FFFFFF", cardSoft: "#FFB347", stub: "band" }),
  sky: p("light", ["elegant"], { bg: "#EAF4FB", bg2: "#FFFFFF", ink: "#0D3B66", soft: "#3E6990", accent: "#1D6FA3", accent2: "#0D3B66", onAccent: "#FFFFFF", card: "#0D3B66", cardInk: "#FFFFFF", cardSoft: "#A9C6E0" }),
  candy: p("light", ["colorful"], { bg: "#FFF0F5", ink: "#9B1B4A", soft: "#3B1020", accent: "#E0267A", accent2: "#FFB703", onAccent: "#3B1020", card: "#3B1020", cardInk: "#FFFFFF", cardSoft: "#F9A8C9" }),
  skyBalloon: p("light", ["colorful"], { bg: "#E8F4FF", ink: "#0B3C6B", soft: "#3D6A93", accent: "#D42B62", accent2: "#2272C3", onAccent: "#FFFFFF", card: "#0B3C6B", cardInk: "#FFFFFF", cardSoft: "#9CC6EE" }),
  sunny: p("light", ["colorful"], { bg: "#FFE066", ink: "#1D3557", soft: "#1D3557", accent: "#C1121F", accent2: "#1D3557", onAccent: "#FFFFFF", card: "#FFFFFF", cardInk: "#1D3557", cardSoft: "#5A6A80" }),
  aqua: p("light", ["colorful"], { bg: "#7BDFF2", ink: "#1D3557", soft: "#1D3557", accent: "#9D1B8A", accent2: "#1D3557", onAccent: "#FFFFFF", card: "#FFFFFF", cardInk: "#1D3557", cardSoft: "#5A6A80" }),
  waxYellow: p("light", ["colorful"], { bg: "#F2B705", ink: "#0E1B1C", soft: "#0E1B1C", accent: "#9E1F17", accent2: "#0B5D5B", onAccent: "#FFFFFF", card: "#0E1B1C", cardInk: "#FFFFFF", cardSoft: "#F2B705" }),
  terracotta: p("light", ["colorful", "elegant"], { bg: "#F6E7D8", ink: "#3D1F12", soft: "#6E4A36", accent: "#A63E26", accent2: "#2F5D50", onAccent: "#FFFFFF", card: "#3D1F12", cardInk: "#FFFFFF", cardSoft: "#E8C3A5" }),
  paperNavy: p("light", [], { bg: "#F5F7FA", ink: "#14213D", soft: "#4A5A78", accent: "#1D4ED8", accent2: "#14213D", onAccent: "#FFFFFF", card: "#14213D", cardInk: "#FFFFFF", cardSoft: "#B8C3D9" }),
  cleanRed: p("light", [], { bg: "#FFFFFF", ink: "#1F1F1F", soft: "#555555", accent: "#D7263D", accent2: "#1F1F1F", onAccent: "#FFFFFF", card: "#1F1F1F", cardInk: "#FFFFFF", cardSoft: "#BBBBBB" }),
  mint: p("light", ["colorful"], { bg: "#ECFDF5", ink: "#064E3B", soft: "#2F6B58", accent: "#047857", accent2: "#064E3B", onAccent: "#FFFFFF", card: "#064E3B", cardInk: "#FFFFFF", cardSoft: "#A7F3D0" }),
  lavender: p("light", ["colorful", "elegant"], { bg: "#F3EEFF", ink: "#2E1065", soft: "#5B4B8A", accent: "#7C3AED", accent2: "#2E1065", onAccent: "#FFFFFF", card: "#2E1065", cardInk: "#FFFFFF", cardSoft: "#C4B5FD" }),
  peach: p("light", ["colorful"], { bg: "#FFF4E6", ink: "#3D0C02", soft: "#7A3E2B", accent: "#C2410C", accent2: "#3D0C02", onAccent: "#FFFFFF", card: "#3D0C02", cardInk: "#FFFFFF", cardSoft: "#FFB37A", stub: "band" }),
  limeCourt: p("light", ["colorful"], { bg: "#F7FEE7", ink: "#1A2E05", soft: "#3F5A1E", accent: "#4D7C0F", accent2: "#1A2E05", onAccent: "#FFFFFF", card: "#1A2E05", cardInk: "#FFFFFF", cardSoft: "#BEF264", stub: "band" }),
  tealCream: p("light", [], { bg: "#FFFDF6", ink: "#0B3B3C", soft: "#4F6B6B", accent: "#0B6E6E", accent2: "#0B3B3C", onAccent: "#FFFFFF", card: "#0B3B3C", cardInk: "#FFFFFF", cardSoft: "#F2B705" }),
  roseGold: p("light", ["elegant"], { bg: "#FFF5F0", ink: "#4A2C2A", soft: "#7D5A50", accent: "#9C5640", accent2: "#4A2C2A", onAccent: "#FFFFFF", card: "#4A2C2A", cardInk: "#FFF5F0", cardSoft: "#E8BBA8", stub: "open" }),
  kente: p("light", ["colorful"], { bg: "#FFF7E0", ink: "#1B1B1B", soft: "#4A3B1B", accent: "#C1121F", accent2: "#006D3B", onAccent: "#FFFFFF", card: "#1B1B1B", cardInk: "#FFFFFF", cardSoft: "#FFC300" }),
  minimal: p("light", [], { bg: "#F4F4F5", ink: "#18181B", soft: "#52525B", accent: "#18181B", accent2: "#52525B", onAccent: "#FFFFFF", card: "#18181B", cardInk: "#FFFFFF", cardSoft: "#A1A1AA" }),
  sand: p("light", ["elegant"], { bg: "#EFE6D8", ink: "#2A2118", soft: "#5E5040", accent: "#7F5226", accent2: "#2A2118", onAccent: "#FFFFFF", card: "#2A2118", cardInk: "#EFE6D8", cardSoft: "#C9B79C", stub: "open" }),
} satisfies Record<string, Palette>;

export type PaletteId = keyof typeof PALETTES;
