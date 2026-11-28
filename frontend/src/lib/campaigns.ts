import { percent } from "./stats";

/** Campagnes de l'organisation, par e-mail ou WhatsApp (GET /campaigns, /campaigns/{id}). */
export const CHANNELS = ["email", "whatsapp"] as const;
export type CampaignChannel = (typeof CHANNELS)[number];
/** Longueur maximale du texte d'une annonce WhatsApp (variable d'un modèle Meta). */
export const WHATSAPP_MAX = 900;
export type CampaignStatus = "draft" | "scheduled" | "sending" | "sent";
export const AUDIENCES = ["all", "buyers", "guests"] as const;
export type CampaignAudience = (typeof AUDIENCES)[number];

export type CampaignSummary = {
  id: string;
  channel: CampaignChannel;
  subject: string;
  status: CampaignStatus;
  audience: CampaignAudience;
  event: { id: string; title: string } | null;
  scheduled_at: string | null;
  sent_at: string | null;
  created_at: string | null;
  recipients_count: number;
  sent_count: number;
  failed_count: number;
  opened_count: number;
  clicked_count: number;
};

export type Campaign = CampaignSummary & {
  body: string;
  button_label: string | null;
  event_id: string | null;
  sales: { currency: string; orders: number; revenue: number }[];
};

export type CampaignsIndex = {
  data: CampaignSummary[];
  audiences: Record<CampaignChannel, Record<CampaignAudience, number>>;
  channels: Record<CampaignChannel, { available: boolean; demo: boolean }>;
};

export type CampaignDraft = {
  channel: CampaignChannel;
  subject: string;
  body: string;
  event_id: string | null;
  button_label: string;
  audience: CampaignAudience;
};

export const isEditable = (status: CampaignStatus) => status === "draft" || status === "scheduled";

/** Part en pourcentage (comme les statistiques), ou un tiret sans dénominateur. */
export function rate(part: number, total: number, locale: string): string {
  return total > 0 ? percent(part / total, locale) : "–";
}
