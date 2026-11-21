import type { Currency } from "./api";

export const OPERATORS = ["vodacom", "orange", "airtel"] as const;
export type Operator = (typeof OPERATORS)[number];
export type PaymentMethod = "mobile_money" | "card";

/** Préfixes des opérateurs de RDC : servent à proposer un opérateur, pas à l'imposer. */
const PREFIXES: Record<Operator, string[]> = {
  vodacom: ["81", "82", "83"],
  orange: ["80", "84", "85", "89"],
  airtel: ["97", "98", "99"],
};

/** Opérateur probable d'un numéro saisi (« 0812… », « +243 99… ») ; sert à présélectionner, pas à imposer. */
export function guessOperator(phone: string): Operator | null {
  const digits = phone.replace(/\D/g, "").replace(/^(00)?243/, "").replace(/^0/, "");
  const prefix = digits.slice(0, 2);
  return (Object.keys(PREFIXES) as Operator[]).find((operator) => PREFIXES[operator].includes(prefix)) ?? null;
}

export type OrderStatus = "pending" | "paid" | "expired" | "refund_due";

/** Commande telle que la voit son acheteur (lien personnel). */
export type Order = {
  id: string;
  reference: string;
  status: OrderStatus;
  currency: Currency;
  total: number;
  buyer_name: string;
  buyer_email: string;
  expires_at: string;
  paid_at: string | null;
  event: {
    slug: string;
    title: string;
    starts_at_utc: string;
    timezone: string;
    venue_name: string;
    city: string;
    organization: { name: string };
  };
  items: { name: string; quantity: number; unit_price: number }[];
  payment: {
    method: PaymentMethod;
    status: "pending" | "succeeded" | "failed";
    operator: Operator | null;
    phone: string | null;
    failure_code: string | null;
    redirect_url: string | null;
    simulated: boolean;
  } | null;
  tickets: { code: string; name: string; holder_name: string; qr: string }[];
};

/** Commande dans la liste des ventes de l'organisateur. */
export type SalesOrder = {
  id: string;
  reference: string;
  status: OrderStatus;
  total: number;
  buyer_name: string;
  items: { name: string; quantity: number }[];
  payment_method: PaymentMethod | null;
  created_at: string;
};

export type SalesSummary = {
  currency: Currency;
  revenue: number;
  orders: number;
  tickets_sold: number;
  ticket_types: { id: string; name: string; quantity: number; sold: number }[];
};

/** Lien personnel de la commande (la clé n'est jamais stockée ailleurs que dans ce lien). */
export function orderPath(id: string, key: string): string {
  return `/commande/${id}?cle=${encodeURIComponent(key)}`;
}
