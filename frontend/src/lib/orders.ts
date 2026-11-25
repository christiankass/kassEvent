import type { Currency } from "./api";
import type { TicketDesign } from "./designs";

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

export type DeliveryChannel = "whatsapp" | "messenger";
export type DeliveryStatus = "scheduled" | "awaiting_contact" | "queued" | "sent" | "delivered" | "read" | "failed";

/**
 * Envoi en cours : la page se met à jour d'elle-même. Pas « awaiting_contact » (Messenger) : la
 * personne n'ouvrira peut-être jamais le lien ; la page se recharge quand elle revient de Messenger.
 */
export const DELIVERY_IN_PROGRESS: DeliveryStatus[] = ["scheduled", "queued"];

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
  /** Apparence des billets choisie par l'organisateur (atelier), une fois la commande payée. */
  design: TicketDesign | null;
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
  /** Envois sur WhatsApp et Messenger (numéro masqué). */
  deliveries: { channel: DeliveryChannel; status: DeliveryStatus; recipient: string | null; failure_code: string | null }[];
  /** Canaux que l'acheteur peut demander ; simulated : aucun message réel ne part. */
  channels: { whatsapp: boolean; messenger: boolean; simulated: boolean };
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
  deliveries: { channel: DeliveryChannel; status: DeliveryStatus }[];
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
