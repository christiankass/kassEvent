import type { Currency } from "./api";
import type { Operator } from "./orders";

export type PayoutStatus = "pending" | "succeeded" | "failed";
export type PayoutMethod = "mobile_money" | "bank";

/** Solde d'une devise, en unités mineures. */
export type Balance = {
  currency: Currency;
  available: number;
  on_hold: number;
  sales: number;
  commission: number;
  /** Contributions reçues sur les livres d'or : reversées en entier. */
  contributions: number;
  paid_out: number;
  in_progress: number;
};

/** Compte de reversement, masqué par l'API (jamais le numéro complet). */
export type PayoutAccount = {
  method: PayoutMethod;
  operator: Operator | null;
  phone: string | null;
  bank_name: string | null;
  account_holder: string | null;
  account_last4: string | null;
  active: boolean;
  active_from: string;
  updated_at: string;
};

export type Payout = {
  id: string;
  currency: Currency;
  amount: number;
  status: PayoutStatus;
  trigger: "automatic" | "manual";
  method: PayoutMethod;
  operator: Operator | null;
  phone: string | null;
  bank_name: string | null;
  account_last4: string | null;
  failure_code: string | null;
  created_at: string;
  completed_at: string | null;
};

export type LedgerEntry = {
  id: string;
  type: "sale" | "commission" | "contribution" | "payout" | "payout_reversal";
  currency: Currency;
  amount: number;
  reference: string | null;
  label: string | null;
  tickets: number | null;
  available_at: string;
  created_at: string;
};

export type PayoutsOverview = {
  balances: Balance[];
  account: PayoutAccount | null;
  payouts: Payout[];
  entries: LedgerEntry[];
  settings: {
    commission_percent: number;
    commission_fixed: Record<Currency, number>;
    hold_hours: number;
    minimum: Record<Currency, number>;
    account_change_delay_hours: number;
  };
};
