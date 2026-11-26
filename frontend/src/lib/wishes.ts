import type { TicketDesign } from "./designs";

/** Ton de la page, choisi selon l'événement : fête (vœux), hommage (condoléances, obsèques), livre d'or. */
export const WISH_TONES = ["celebration", "tribute", "guestbook"] as const;
export type WishTone = (typeof WISH_TONES)[number];

export type WishBook = { id: string; open: boolean; public: boolean; tone: WishTone; url: string };

/** Débuts de mots du titre qui font proposer un ton à l'ouverture (titre sans accents, en minuscules). */
const TONE_WORDS: [WishTone, RegExp][] = [
  ["tribute", /\b(obseques|funerailles|deuil|deces|veillee|hommage|in memoriam|condoleances|inhumation|commemoration|requiem|funeral|mourning|memorial)/],
  ["celebration", /\b(anniv|mariage|noces|naissance|bapteme|diplome|birthday|wedding|baby shower|graduation)/],
  ["guestbook", /\b(conference|culte|seminaire|forum|lancement|concert|soiree|gala\b|salon|launch|summit|workshop)/],
];

/** Ton connu, ou la fête si l'API n'en donne pas (ancienne version du serveur). */
export function toneOf(tone: string | undefined): WishTone {
  return WISH_TONES.find((known) => known === tone) ?? "celebration";
}

/** Ton proposé d'après le titre (« Obsèques de Papa Jean » : hommage), la fête sinon ; l'organisateur peut le changer. */
export function suggestedTone(title: string): WishTone {
  const text = title.normalize("NFD").replace(/\p{Diacritic}/gu, "").toLowerCase();
  return TONE_WORDS.find(([, words]) => words.test(text))?.[0] ?? "celebration";
}

/** Vœu vu par l'organisateur. */
export type Wish = { id: string; name: string; message: string; hidden: boolean; created_at: string };

export type WishPage = { book: WishBook | null; data: Wish[]; summary: { total: number; hidden: number } };

/** Page publique du livre de vœux. */
export type PublicWishBook = {
  event: {
    title: string;
    description: string | null;
    starts_at_utc: string;
    timezone: string;
    cover_url: string | null;
    organization: { name: string };
  };
  design: TicketDesign;
  open: boolean;
  public: boolean;
  tone: WishTone;
  count: number;
  wishes: { name: string; message: string; created_at: string }[];
};

/** « 26 nov., 21:40 » à l'heure de l'appareil de la personne qui lit. */
export function formatWishDate(iso: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }).format(new Date(iso));
}
