import { FORMATS, type CanvasDoc, type CanvasFont, type FormatId, type Usage } from "./canvas";
import { frame, shape, stub, type Decor, type Layout, type TemplateContext, type Theme } from "./canvas-kit";
import * as classic from "./canvas-layouts-classic";
import * as pro from "./canvas-layouts";
import { PALETTES, type PaletteId } from "./canvas-palettes";

export { setTextMeasure, type TemplateContext, type TextMeasure, type Theme } from "./canvas-kit";

/**
 * Bibliothèque de modèles du studio. Un modèle = une composition qui a sa propre structure (grille
 * éditoriale, arche, monogramme, bandes wax, gros chiffre, billet rétro, couverture de magazine…),
 * déclinée sur quelques palettes et paires de polices choisies pour elle. Peu de variantes par
 * composition : deux modèles de la galerie ne se ressemblent jamais. Chaque modèle se dessine pour
 * l'affiche comme pour le billet, dans tous les formats, et reste modifiable ensuite.
 */

export const CATEGORIES = ["all", "concert", "party", "wedding", "birthday", "worship", "gala", "conference", "online", "sport", "business"] as const;
export type Category = (typeof CATEGORIES)[number];

/** Filtres de style de la galerie. */
export const STYLES = ["photo", "typo", "elegant", "colorful", "dark"] as const;
export type TemplateStyle = (typeof STYLES)[number];

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

type LayoutMeta = { draw: Layout; photo: boolean; tone?: "dark" | "light"; fonts: number[] };

/** Compositions : dessin, présence d'une photo, fond imposé, polices qui leur vont. */
const LAYOUTS = {
  editorial: { draw: pro.editorial, photo: false, fonts: [3, 14, 13, 2] },
  arch: { draw: pro.arch, photo: true, fonts: [8, 6, 4, 5] },
  monogram: { draw: pro.monogram, photo: false, fonts: [6, 8, 5] },
  waxBands: { draw: pro.waxBands, photo: false, fonts: [0, 11, 2] },
  bigDate: { draw: pro.bigDate, photo: false, fonts: [0, 3, 5, 2] },
  retroTicket: { draw: pro.retroTicket, photo: false, fonts: [0, 13, 7] },
  swissBlocks: { draw: pro.swissBlocks, photo: true, fonts: [2, 14, 0] },
  sunburst: { draw: pro.sunburst, photo: false, fonts: [0, 7, 11] },
  diagonal: { draw: pro.diagonal, photo: true, fonts: [1, 0] },
  magazine: { draw: pro.magazine, photo: true, fonts: [5, 6, 0] },
  classicInvite: { draw: pro.classicInvite, photo: false, tone: "light", fonts: [8, 6, 9, 4] },
  minimal: { draw: pro.minimal, photo: false, fonts: [6, 5, 14] },
  kente: { draw: pro.kente, photo: false, fonts: [13, 7, 11] },
  polaroid: { draw: pro.polaroid, photo: true, fonts: [9, 8, 11] },
  memphis: { draw: pro.memphis, photo: false, fonts: [10, 11, 0] },
  light: { draw: pro.light, photo: false, fonts: [6, 7, 4] },
  agenda: { draw: pro.agenda, photo: false, fonts: [3, 12, 13] },
  keynote: { draw: pro.keynote, photo: true, fonts: [2, 13, 12] },
  duo: { draw: pro.duo, photo: true, fonts: [8, 4, 0] },
  patternCard: { draw: pro.patternCard, photo: false, fonts: [6, 8, 4] },
  stacked: { draw: pro.stacked, photo: false, fonts: [0, 1] },
  priceBurst: { draw: pro.priceBurst, photo: false, fonts: [0, 10, 2] },
  calendar: { draw: pro.calendar, photo: false, fonts: [4, 6, 13] },
  vinyl: { draw: pro.vinyl, photo: false, fonts: [0, 5, 2] },
  waveform: { draw: pro.waveform, photo: false, tone: "dark", fonts: [2, 12, 0] },
  award: { draw: pro.award, photo: false, fonts: [5, 6, 7] },
  balloons: { draw: pro.balloons, photo: false, fonts: [10, 9, 11] },
  corporate: { draw: pro.corporate, photo: false, fonts: [12, 3, 13] },
  checklist: { draw: pro.checklist, photo: false, fonts: [12, 2, 11] },
  orbit: { draw: pro.orbit, photo: true, fonts: [5, 0, 13] },
  halftoneBold: { draw: pro.halftoneBold, photo: false, fonts: [0, 2, 14] },
  stainedGlass: { draw: pro.stainedGlass, photo: false, fonts: [6, 7, 4] },
  heroPhoto: { draw: classic.heroPhoto, photo: true, fonts: [0, 3, 5] },
  cutout: { draw: classic.cutout, photo: true, fonts: [2, 0, 13] },
  speakers: { draw: classic.speakers, photo: true, fonts: [12, 2] },
  infoCard: { draw: classic.infoCard, photo: true, fonts: [12, 2, 3] },
  framed: { draw: classic.framed, photo: true, fonts: [8, 6] },
  luxe: { draw: classic.luxe, photo: false, fonts: [5, 6] },
  live: { draw: classic.live, photo: true, tone: "dark", fonts: [2, 11] },
  versus: { draw: classic.versus, photo: true, fonts: [1, 0] },
  ribbon: { draw: classic.ribbon, photo: false, fonts: [3, 13, 12] },
} satisfies Record<string, LayoutMeta>;
export type LayoutId = keyof typeof LAYOUTS;

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

type Recipe = { layouts: LayoutId[]; palettes: PaletteId[]; decors: Decor[]; photos: PhotoTheme[]; labels?: string[] };

/** Compositions de chaque catégorie, de la plus représentative à la plus originale. */
const RECIPES: Record<Exclude<Category, "all">, Recipe> = {
  concert: {
    layouts: ["vinyl", "waxBands", "magazine", "bigDate", "halftoneBold", "stacked", "orbit", "diagonal", "retroTicket", "sunburst", "heroPhoto"],
    palettes: ["stageYellow", "neon", "waxYellow", "ember", "electric", "kente", "gospelViolet", "plumNeon", "charcoalCoral", "cleanRed", "congo", "peach"],
    decors: ["glow", "stars", "wax"],
    photos: ["concertCrowd", "singer", "guitarist", "dj", "gospelChoir", "drums", "stageLights", "saxophone", "rapper", "band"],
  },
  party: {
    layouts: ["memphis", "stacked", "waxBands", "halftoneBold", "polaroid", "priceBurst", "bigDate", "vinyl", "diagonal", "magazine", "sunburst"],
    palettes: ["candy", "electric", "sunny", "plumNeon", "peach", "neon", "waxYellow", "tealCoral", "lavender", "ember", "kente", "mint"],
    decors: ["confetti", "glow", "stars"],
    photos: ["dancing", "nightclub", "cocktails", "dj", "rooftop", "fashion", "karaoke", "danceCouple", "toast", "champagne"],
  },
  wedding: {
    layouts: ["arch", "monogram", "classicInvite", "patternCard", "calendar", "duo", "minimal", "polaroid", "stainedGlass", "editorial", "framed"],
    palettes: ["ivoryGold", "sage", "blush", "emeraldNight", "pearlNavy", "terracotta", "roseGold", "wine", "sand", "navyGold", "lavender", "sky"],
    decors: ["petals", "none", "dots"],
    photos: ["weddingCouple", "bride", "rings", "bouquet", "reception", "traditionalWedding", "weddingCake", "groom", "danceCouple", "churchWedding"],
  },
  birthday: {
    layouts: ["balloons", "polaroid", "memphis", "bigDate", "calendar", "arch", "stacked", "monogram", "priceBurst", "luxe"],
    palettes: ["candy", "skyBalloon", "midnightGold", "sunny", "aqua", "roseGold", "lavender", "peach", "wine", "mint", "tealCoral", "kente"],
    decors: ["confetti", "stars", "glow"],
    photos: ["birthdayCake", "balloons", "kidsParty", "birthdayWoman", "birthdayMan", "toast", "gifts", "family", "champagne", "dancing"],
    labels: ["18", "30", "40", "50", "25", "60", "20", "70"],
  },
  worship: {
    layouts: ["light", "stainedGlass", "sunburst", "keynote", "agenda", "arch", "minimal", "editorial", "orbit", "speakers"],
    palettes: ["royalPurple", "sunrise", "navyGold", "sky", "gospelViolet", "ivoryGold", "emeraldNight", "pearlNavy", "cocoa", "terracotta", "cobalt", "sand"],
    decors: ["halo", "rays", "glow"],
    photos: ["worshipHands", "choir", "preacher", "bible", "church", "prayer", "worshipCrowd", "candles", "cross", "baptism"],
  },
  gala: {
    layouts: ["award", "minimal", "monogram", "luxe", "magazine", "classicInvite", "editorial", "patternCard", "bigDate", "orbit"],
    palettes: ["midnightGold", "ivoryGold", "navyGold", "royalPurple", "wine", "emeraldNight", "pearlNavy", "cocoa", "sand", "roseGold", "ocean", "stageYellow"],
    decors: ["stars", "halo", "none"],
    photos: ["galaDinner", "trophy", "redCarpet", "eveningGown", "champagne", "chandelier", "tuxedo", "applause", "awardStage", "reception"],
  },
  conference: {
    layouts: ["keynote", "agenda", "editorial", "swissBlocks", "corporate", "checklist", "speakers", "halftoneBold", "duo", "infoCard", "bigDate"],
    palettes: ["paperNavy", "cobalt", "cleanRed", "slateLime", "tealCream", "ocean", "mint", "fleuve", "minimal", "navyGold", "lavender", "kente"],
    decors: ["dots", "none", "glow"],
    photos: ["speaker", "audience", "workshop", "classroom", "teamLaptop", "businessWoman", "businessMan", "graduation", "panel", "notebook"],
  },
  online: {
    layouts: ["waveform", "live", "keynote", "checklist", "swissBlocks", "halftoneBold", "duo", "cutout", "editorial"],
    palettes: ["ocean", "electric", "slateLime", "cobalt", "plumNeon", "charcoalCoral", "neon", "tealCoral", "paperNavy", "mint", "lavender", "candy"],
    decors: ["glow", "dots", "none"],
    photos: ["videoCall", "laptop", "smartphone", "podcast", "streaming", "onlineClass", "headphones", "homeOffice", "parenting", "teamLaptop"],
    labels: ["online"],
  },
  sport: {
    layouts: ["diagonal", "versus", "stacked", "retroTicket", "bigDate", "halftoneBold", "magazine", "swissBlocks", "heroPhoto", "sunburst"],
    palettes: ["redFight", "stadium", "limeCourt", "congo", "stageYellow", "cobalt", "ember", "cleanRed", "electric", "slateLime", "kente", "ocean"],
    decors: ["stars", "glow", "none"],
    photos: ["football", "basketball", "boxing", "running", "stadium", "athlete", "tennis", "gym", "martialArts", "cycling"],
  },
  business: {
    layouts: ["ribbon", "corporate", "editorial", "swissBlocks", "checklist", "kente", "agenda", "keynote", "priceBurst", "minimal", "infoCard"],
    palettes: ["fleuve", "paperNavy", "tealCream", "cleanRed", "navyGold", "minimal", "slateLime", "cobalt", "sand", "kente", "mint", "ocean"],
    decors: ["dots", "none", "glow"],
    photos: ["meeting", "office", "handshake", "launch", "skyline", "entrepreneur", "coworking", "market", "store", "networking"],
  },
};

/** Variantes par composition et par catégorie : assez pour choisir, trop peu pour se répéter. */
const VARIANTS = 3;
const TEMPLATE_CATEGORIES = CATEGORIES.filter((category): category is Exclude<Category, "all"> => category !== "all");

/** Un modèle : son identifiant dit tout le reste. */
export type TemplateInfo = {
  id: string;
  /** Numéro affiché dans la galerie. */
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
 * Modèles d'une catégorie : chaque composition sur `VARIANTS` palettes et paires de polices
 * différentes. Rangés variante par variante : la première page montre toutes les compositions.
 */
function catalog(category: Exclude<Category, "all">, offset: number): Omit<TemplateInfo, "number">[] {
  const recipe = RECIPES[category];
  const out: Omit<TemplateInfo, "number">[] = [];
  for (let v = 0; v < VARIANTS; v++) {
    recipe.layouts.forEach((layout, l) => {
      const meta: LayoutMeta = LAYOUTS[layout];
      const tone = meta.tone;
      const palettes = tone ? recipe.palettes.filter((id) => PALETTES[id].tone === tone) : recipe.palettes;
      // Pas de 3 sur la palette : les trois variantes d'une composition et ses voisines changent toutes de couleurs.
      const paletteId = palettes[(l * 5 + v * 3) % palettes.length];
      const palette = PALETTES[paletteId];
      const k = out.length;
      out.push({
        id: `${category}-${String(k + 1).padStart(4, "0")}`,
        category,
        layout,
        palette: paletteId,
        styles: [meta.photo ? "photo" : "typo", ...palette.tags, ...(palette.tone === "dark" ? (["dark"] as const) : [])],
        photo: meta.photo ? { theme: recipe.photos[(l + v * 4) % recipe.photos.length], index: v } : null,
        fonts: meta.fonts[(v + l) % meta.fonts.length],
        decor: recipe.decors[(l + v) % recipe.decors.length],
        label: recipe.labels?.[(l + v * 3) % recipe.labels.length],
        seed: offset + k * 31 + 7,
      });
    });
  }
  return out;
}

let all: TemplateInfo[] | null = null;
let byId: Map<string, TemplateInfo> | null = null;

/** Compositions différentes d'affilée dans la vue « Tous » avant qu'une revienne. */
const SPREAD = 30;

/**
 * Tous les modèles, catégories mêlées une à une ; une composition déjà vue parmi les `SPREAD`
 * derniers modèles est repoussée : la vue « Tous » ne montre pas deux fois la même structure.
 */
export function allTemplates(): TemplateInfo[] {
  if (!all) {
    const lists = TEMPLATE_CATEGORIES.map((category, i) => catalog(category, i * 1000));
    const longest = Math.max(...lists.map((list) => list.length));
    const pool: Omit<TemplateInfo, "number">[] = [];
    for (let r = 0; r < longest; r++) for (const list of lists) if (list[r]) pool.push(list[r]);
    const ordered: TemplateInfo[] = [];
    while (pool.length) {
      const recent = new Set(ordered.slice(-SPREAD).map((info) => info.layout));
      const index = Math.max(0, pool.findIndex((info) => !recent.has(info.layout)));
      ordered.push({ ...pool.splice(index, 1)[0], number: ordered.length + 1 });
    }
    all = ordered;
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

/** Nombre de modèles d'une catégorie (« all » : la bibliothèque entière). */
let counts: Map<Category, number> | null = null;

export function templateCount(category: Category): number {
  if (!counts) {
    counts = new Map([["all", allTemplates().length]]);
    for (const info of allTemplates()) counts.set(info.category, (counts.get(info.category) ?? 0) + 1);
  }
  return counts.get(category) ?? 0;
}

/** Modèle par défaut d'une page neuve : vert Fleuve de kassEvent, ruban. */
export const DEFAULT_TEMPLATE = "business-0001";

/** Modèle sans rien : seulement le talon du billet. */
export const BLANK_TEMPLATE = "blank";

export function buildTemplate(id: string, usage: Usage, format: FormatId, ctx: TemplateContext): CanvasDoc {
  const f = frame(usage, format);
  const [width, height] = FORMATS[format];
  const info = templateInfo(id);
  if (!info) {
    const t: Theme = { ...PALETTES.minimal, bg: "#FFFFFF", title: "poppins", body: "poppins" };
    return { width, height, background: { color: t.bg, image: null }, elements: usage === "ticket" ? stub(f, t, ctx) : [] };
  }
  const [title, body, upper, italic] = FONT_PAIRS[info.fonts];
  const t: Theme = { ...PALETTES[info.palette], title, body, upper, italic };
  const elements = [
    ...(t.bg2 ? [shape("rect", { x: 0, y: 0, width, height }, { fill: t.bg, fill2: t.bg2 })] : []),
    ...LAYOUTS[info.layout].draw(f, t, ctx, { seed: info.seed, decor: info.decor, label: info.label }),
    ...(usage === "ticket" ? stub(f, t, ctx) : []),
  ];
  return { width, height, background: { color: t.bg, image: null }, elements };
}
