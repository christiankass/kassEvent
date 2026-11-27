import type { Currency } from "./api";
import type { Source } from "./referral";

/** Statistiques d'un événement (GET /events/{id}/stats). Montants en unités mineures. */
export type EventStats = {
  currency: Currency;
  published: boolean;
  url: string;
  qr_svg: string;
  summary: {
    revenue: number;
    net: number;
    orders: number;
    tickets_sold: number;
    capacity: number;
    fill_rate: number | null;
    average_order: number;
    visits: number;
    conversion: number | null;
    tickets_issued: number;
    admitted: number;
    people_admitted: number;
    attendance: number | null;
  };
  daily: { date: string; orders: number; tickets: number; revenue: number; visits: number }[];
  ticket_types: { id: string; name: string; quantity: number; sold: number; revenue: number }[];
  payments: { method: "mobile_money" | "card" | "free"; operator: "vodacom" | "orange" | "airtel" | null; orders: number; revenue: number }[];
  sources: { source: Source; visits: number; orders: number; revenue: number }[];
  check_ins: { hour: string; admitted: number }[];
};

export function percent(rate: number, locale: string): string {
  return new Intl.NumberFormat(locale, { style: "percent", maximumFractionDigits: rate < 0.1 ? 1 : 0 }).format(rate);
}
