/**
 * Apparence des billets et invitations (atelier). Les calculs de contraste reprennent ceux de l'e-mail
 * (Color.php) : à changer ensemble, pour que le site et l'e-mail restent identiques.
 */

export const KINDS = ["ticket", "invitation"] as const;
export type DesignKind = (typeof KINDS)[number];
export const FONTS = ["fraunces", "playfair", "instrument", "script"] as const;
export type DesignFont = (typeof FONTS)[number];
export const BANNERS = ["none", "cover", "custom"] as const;
export type BannerSource = (typeof BANNERS)[number];
/** Noms des modèles : la liste et leurs réglages viennent de l'API (meta.templates). */
export type TemplateName = "kassevent" | "concert" | "entreprise" | "mariage" | "anniversaire";

export type TemplatePreset = { kind: DesignKind; primary_color: string; accent_color: string; font: DesignFont };

/** Réglages modifiables, tels qu'envoyés à l'API. */
export type DesignSettings = {
  template: TemplateName;
  kind: DesignKind;
  primary_color: string;
  accent_color: string;
  font: DesignFont;
  headline: string | null;
  message: string | null;
  footer: string | null;
  banner: BannerSource;
};

/** Apparence renvoyée par l'API : réglages et images. */
export type TicketDesign = DesignSettings & {
  /** canvas : billet fait dans le studio, servi en image (QR et nom compris) ; classic : billet de l'atelier. */
  mode: "classic" | "canvas";
  headline_text: string;
  banner_url: string | null;
  custom_banner_url: string | null;
  logo_url: string | null;
};

/** Données fixes de l'atelier : modèles de départ et titres par défaut. */
export type DesignMeta = { templates: Record<TemplateName, TemplatePreset>; headlines: Record<DesignKind, string> };

/** Billet d'exemple : e-mail tel qu'il partira et QR code de l'aperçu (il ne scanne jamais). */
export type DesignPreview = { subject: string; html: string; qr: string; code: string };

export const SETTINGS: (keyof DesignSettings)[] = [
  "template",
  "kind",
  "primary_color",
  "accent_color",
  "font",
  "headline",
  "message",
  "footer",
  "banner",
];

export function settingsOf(design: TicketDesign): DesignSettings {
  return Object.fromEntries(SETTINGS.map((key) => [key, design[key]])) as DesignSettings;
}

/** Police d'affichage du titre (chargée par TicketCard), avec repli. */
export const FONT_STACKS: Record<DesignFont, string> = {
  fraunces: "var(--f-display), Georgia, serif",
  playfair: "var(--f-playfair), Georgia, serif",
  instrument: "var(--f-body), Helvetica, Arial, sans-serif",
  script: "var(--f-script), 'Brush Script MT', cursive",
};

/** Teintes proposées (palette kassEvent, docs/DESIGN.md, plus or et bordeaux pour les mariages). */
export const PRIMARY_SWATCHES = ["#0B3B3C", "#5B3CC4", "#0F6890", "#A3245A", "#B8391C", "#6B1E3A", "#0E1B1C"];
export const ACCENT_SWATCHES = ["#FFC83D", "#FF7A59", "#2BA3D8", "#6D4AFF", "#E5487D", "#D4A24C", "#DCEFEA"];

export const HEX = /^#[0-9A-Fa-f]{6}$/;

const DARK = "#0E1B1C";
const LIGHT = "#FFFFFF";

function rgb(hex: string): [number, number, number] {
  return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)) as [number, number, number];
}

function luminance(hex: string): number {
  const [r, g, b] = rgb(hex).map((channel) => {
    const c = channel / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: string, b: string): number {
  const [x, y] = [luminance(a), luminance(b)];
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

/** Blanc ou encre, selon ce qui contraste le plus avec le fond. */
export function textOn(background: string): string {
  return contrast(background, LIGHT) >= contrast(background, DARK) ? LIGHT : DARK;
}

/** Fond très pâle de la zone du QR code. */
export function pale(hex: string, white = 0.85): string {
  return (
    "#" +
    rgb(hex)
      .map((c) => Math.round(c + (255 - c) * white).toString(16).padStart(2, "0"))
      .join("")
      .toUpperCase()
  );
}

/** Couleurs de texte lisibles et fond de la zone du QR code (mêmes calculs que l'e-mail). */
export function ticketColors(design: Pick<DesignSettings, "primary_color" | "accent_color">) {
  return { onPrimary: textOn(design.primary_color), onAccent: textOn(design.accent_color), stub: pale(design.accent_color) };
}

/** Applique les réglages en cours à l'apparence enregistrée, pour l'aperçu en direct. */
export function previewDesign(saved: TicketDesign, draft: DesignSettings, meta: DesignMeta, coverUrl: string | null): TicketDesign {
  const bannerUrl = { none: null, cover: coverUrl, custom: saved.custom_banner_url }[draft.banner];
  return {
    ...saved,
    ...draft,
    primary_color: HEX.test(draft.primary_color) ? draft.primary_color : saved.primary_color,
    accent_color: HEX.test(draft.accent_color) ? draft.accent_color : saved.accent_color,
    headline_text: draft.headline?.trim() || meta.headlines[draft.kind],
    banner_url: bannerUrl,
  };
}
