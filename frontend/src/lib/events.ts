import { API_URL, type Currency } from "./api";
import type { Dictionary, Locale } from "./i18n/dictionaries";

/** Adresse publique de l'application (liens partagés, aperçus WhatsApp et Facebook). */
export const APP_URL = process.env.NEXT_PUBLIC_APP_URL ?? "http://localhost:3000";

/** Côté serveur, l'API peut avoir une adresse interne (réseau Docker) différente de celle du navigateur. */
export const SERVER_API_URL = process.env.API_INTERNAL_URL ?? API_URL;

/** Événement tel que l'API le renvoie : dates « 2026-12-24T20:00 » à l'heure du lieu, prix en centimes. */
export type TicketType = {
  id: string;
  name: string;
  description: string | null;
  price: number;
  free: boolean;
  quantity: number;
  max_per_order: number;
  sales_start_at: string | null;
  sales_end_at: string | null;
  on_sale: boolean;
};

export type EventStatus = "draft" | "published";

export type PublicEvent = {
  slug: string;
  title: string;
  description: string | null;
  starts_at: string;
  ends_at: string | null;
  starts_at_utc: string;
  timezone: string;
  venue_name: string;
  venue_address: string | null;
  city: string;
  currency: Currency;
  cover_url: string | null;
  ticket_types: TicketType[];
  organization?: { name: string };
};

export type EventData = PublicEvent & {
  id: string;
  capacity: number | null;
  status: EventStatus;
  published_at: string | null;
};

/** Fuseaux proposés (mêmes que Event::TIMEZONES côté serveur), libellés identiques en français et en anglais. */
export const TIMEZONES = {
  "Africa/Kinshasa": "Kinshasa (UTC+1)",
  "Africa/Lubumbashi": "Lubumbashi (UTC+2)",
  "Africa/Brazzaville": "Brazzaville (UTC+1)",
  "Africa/Douala": "Douala (UTC+1)",
  "Africa/Abidjan": "Abidjan (UTC+0)",
  "Africa/Dakar": "Dakar (UTC+0)",
  "Africa/Kigali": "Kigali (UTC+2)",
} as const;

/** Prix lisible : « 25 $ », « 49,99 $ », « 15 000 FC » (le franc congolais s'affiche sans décimales). */
export function formatPrice(minor: number, currency: string, locale: Locale): string {
  const amount = minor / 100;
  // Intl écrit « CDF » : on garde l'abréviation d'usage à Kinshasa.
  if (currency === "CDF") return `${new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(amount)} FC`;
  return new Intl.NumberFormat(locale, {
    style: "currency",
    currency,
    currencyDisplay: "narrowSymbol",
    minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
  }).format(amount);
}

/** Prix d'un billet, « Gratuit » pour 0. */
export function ticketPrice(minor: number, currency: string, locale: Locale, t: Dictionary): string {
  return minor === 0 ? t.events.free : formatPrice(minor, currency, locale);
}

/** Prix en unités pour un champ de saisie (2500 → « 25 », 4999 → « 49.99 »). */
export function priceInput(minor: number): string {
  return Number.isInteger(minor / 100) ? String(minor / 100) : (minor / 100).toFixed(2);
}

/** Date et heure de l'événement, à l'heure de son lieu quel que soit le fuseau du visiteur. */
export function formatEventDate(event: Pick<PublicEvent, "starts_at_utc" | "timezone">, locale: Locale): string {
  return new Intl.DateTimeFormat(locale, {
    dateStyle: "full",
    timeStyle: "short",
    timeZone: event.timezone,
  }).format(new Date(event.starts_at_utc));
}

/** Adresse publique de la page de vente. */
export function publicEventUrl(slug: string): string {
  return `${APP_URL}/e/${slug}`;
}
