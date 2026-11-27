import type { Currency } from "./api";
import type { Operator, PaymentMethod } from "./orders";
import type { WishTone } from "./wishes";

/** Frais d'un moyen de paiement : pourcentage du total payé + fixe par paiement (unités mineures). */
export type FeeRate = { percent: number; fixed: Record<Currency, number> };

/** Contributions proposées sur la page publique du livre d'or (null : fermées). */
export type ContributionOptions = {
  beneficiary: string | null;
  default_currency: Currency;
  currencies: Currency[];
  limits: Record<Currency, { min: number; max: number }>;
  fees: Record<PaymentMethod, FeeRate>;
};

/**
 * Frais payés par le contributeur en plus de son don : le prestataire retient son pourcentage sur le
 * total, et il reste le montant donné. Même calcul que le serveur (ContributionFees.php), au centime près.
 */
export function contributionFee(amount: number, currency: Currency, rate: FeeRate): number {
  const basisPoints = Math.min(Math.max(Math.round(rate.percent * 100), 0), 5000);
  const divisor = 10000 - basisPoints;
  const total = Math.ceil(((amount + (rate.fixed[currency] ?? 0)) * 10000) / divisor);
  const fee = total - amount;
  // Franc congolais sans centimes : frais arrondis au franc supérieur.
  return currency === "CDF" ? Math.ceil(fee / 100) * 100 : fee;
}

/** Montants proposés en un geste, en unités (le reste se tape). */
export const PRESETS: Record<Currency, number[]> = {
  USD: [5, 10, 20, 50],
  CDF: [5000, 10000, 20000, 50000],
};

/** « 12,50 » ou « 12.5 » → 1250 ; null si ce n'est pas un montant (ou des centimes en francs). */
export function parseAmount(text: string, currency: Currency): number | null {
  const clean = text.replace(/[\s  ]/g, "");
  const pattern = currency === "CDF" ? /^\d{1,9}$/ : /^\d{1,9}([.,]\d{1,2})?$/;
  if (!pattern.test(clean)) return null;
  const [units, fraction = ""] = clean.split(/[.,]/);
  return Number(units) * 100 + Number(fraction.padEnd(2, "0"));
}

/** Contribution vue par son auteur (lien personnel). */
export type Contribution = {
  id: string;
  reference: string;
  status: "pending" | "paid";
  amount: number;
  currency: Currency;
  contributor_name: string | null;
  paid_at: string | null;
  book: { id: string; tone: WishTone; beneficiary: string | null; event_title: string; organization: string };
  /** Frais de chaque moyen, pour un nouvel essai. */
  fees: Record<PaymentMethod, number>;
  payment: {
    method: PaymentMethod;
    status: "pending" | "succeeded" | "failed";
    amount: number;
    operator: Operator | null;
    phone: string | null;
    failure_code: string | null;
    redirect_url: string | null;
    simulated: boolean;
  } | null;
};

/** Contribution reçue, vue par l'organisateur. */
export type ReceivedContribution = {
  id: string;
  reference: string;
  contributor_name: string | null;
  message: string | null;
  amount: number;
  currency: Currency;
  paid_at: string;
};

export type ContributionsPage = {
  data: ReceivedContribution[];
  totals: { currency: Currency; amount: number; count: number }[];
  has_payout_account: boolean;
};

/** Lien personnel de la contribution (la clé n'est jamais stockée ailleurs que dans ce lien). */
export function contributionPath(id: string, key: string): string {
  return `/contribution/${id}?cle=${encodeURIComponent(key)}`;
}
