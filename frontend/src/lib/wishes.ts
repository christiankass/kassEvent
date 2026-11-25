import type { TicketDesign } from "./designs";

export type WishBook = { id: string; open: boolean; public: boolean; url: string };

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
  count: number;
  wishes: { name: string; message: string; created_at: string }[];
};

/** « 26 nov., 21:40 » à l'heure de l'appareil de la personne qui lit. */
export function formatWishDate(iso: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }).format(new Date(iso));
}
