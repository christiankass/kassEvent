/**
 * Document du studio (éditeur libre en glisser-déposer). Même forme que CanvasDocument.php, qui
 * vérifie tout avant l'enregistrement : à changer ensemble.
 */

export const USAGES = ["ticket", "poster"] as const;
export type Usage = (typeof USAGES)[number];

/** Format de page de chaque usage, en pixels : billet 4:5 (téléphone, WhatsApp), affiche 1200 × 630 (partages). */
export const FORMATS: Record<Usage, [number, number]> = { ticket: [1080, 1350], poster: [1200, 630] };

/** Champs remplis pour chaque invité, au moment de l'envoi. */
export const FIELDS = ["holder", "ticket_type", "code"] as const;
export type FieldName = (typeof FIELDS)[number];

export const CANVAS_FONTS = ["fraunces", "playfair", "instrument", "script", "mono"] as const;
export type CanvasFont = (typeof CANVAS_FONTS)[number];

export const MAX_ELEMENTS = 150;
/** Côté minimal du QR code, en part du plus petit côté de la page : il doit rester lisible. */
export const QR_MIN_SHARE = 0.15;

type Box = { id: string; x: number; y: number; width: number; height: number; rotation: number; opacity?: number; name?: string | null };

export type TextStyle = {
  font: CanvasFont;
  fontSize: number;
  bold?: boolean;
  italic?: boolean;
  fill: string;
  align?: "left" | "center" | "right";
  lineHeight?: number;
  letterSpacing?: number;
};

export type TextElement = Box & TextStyle & { type: "text"; text: string };
export type FieldElement = Box & TextStyle & { type: "field"; field: FieldName };
export type ShapeElement = Box & {
  type: "rect" | "ellipse" | "star" | "line";
  fill?: string | null;
  /** Seconde couleur : dégradé de haut en bas. */
  fill2?: string | null;
  stroke?: string | null;
  strokeWidth?: number;
  cornerRadius?: number;
};
/** Photo ; sans `asset`, un emplacement à remplir (invisible sur le billet envoyé). */
export type ImageElement = Box & { type: "image"; asset: string | null; cornerRadius?: number };
export type QrElement = Box & { type: "qr" };

export type CanvasElement = TextElement | FieldElement | ShapeElement | ImageElement | QrElement;
export type ElementType = CanvasElement["type"];

export type CanvasDoc = {
  width: number;
  height: number;
  background: { color: string; image: string | null };
  elements: CanvasElement[];
};

/** Réponse de GET /events/{id}/studio. */
export type StudioData = {
  mode: "classic" | "canvas";
  kind: "ticket" | "invitation";
  ticket: CanvasDoc | null;
  poster: CanvasDoc | null;
  ticket_image_url: string | null;
};

export function newId(): string {
  return Math.random().toString(36).slice(2, 10);
}

/** Éléments calculés pour chaque invité par le serveur : absents de l'image fixe enregistrée. */
export function isDynamic(element: CanvasElement): boolean {
  return element.type === "qr" || element.type === "field" || (element.type === "image" && !element.asset);
}

export function isTextual(element: CanvasElement): element is TextElement | FieldElement {
  return element.type === "text" || element.type === "field";
}

/** Côté minimal du QR code pour un format. */
export function qrMinSize(doc: Pick<CanvasDoc, "width" | "height">): number {
  return Math.ceil(Math.min(doc.width, doc.height) * QR_MIN_SHARE);
}

/** QR code toujours carré, droit, assez grand et dans la page (mêmes règles que le serveur). */
export function constrainQr(element: QrElement, doc: Pick<CanvasDoc, "width" | "height">): QrElement {
  const size = Math.round(Math.min(Math.max(element.width, qrMinSize(doc)), doc.width, doc.height));
  return {
    ...element,
    width: size,
    height: size,
    rotation: 0,
    x: Math.round(Math.min(Math.max(element.x, 0), doc.width - size)),
    y: Math.round(Math.min(Math.max(element.y, 0), doc.height - size)),
  };
}

/** Le document peut-il être enregistré ? Renvoie la raison sinon (clé de traduction). */
export function canvasProblem(doc: CanvasDoc, usage: Usage): "oneQr" | "tooMany" | "posterFields" | null {
  if (doc.elements.length > MAX_ELEMENTS) return "tooMany";
  const qrs = doc.elements.filter((element) => element.type === "qr").length;
  if (usage === "ticket" && qrs !== 1) return "oneQr";
  if (usage === "poster" && doc.elements.some((element) => element.type === "qr" || element.type === "field")) return "posterFields";
  return null;
}
