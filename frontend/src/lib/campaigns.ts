/** Campagnes e-mail de l'organisation (GET /campaigns, /campaigns/{id}). */
export type CampaignStatus = "draft" | "scheduled" | "sending" | "sent";
export type CampaignAudience = "all" | "buyers" | "guests";
export const AUDIENCES: CampaignAudience[] = ["all", "buyers", "guests"];

export type CampaignSummary = {
  id: string;
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
  audiences: Record<CampaignAudience, number>;
  demo: boolean;
};

export type CampaignDraft = {
  subject: string;
  body: string;
  event_id: string | null;
  button_label: string;
  audience: CampaignAudience;
};

export const isEditable = (status: CampaignStatus) => status === "draft" || status === "scheduled";

/** Part arrondie (« 42 % »), ou un tiret sans dénominateur. */
export function rate(part: number, total: number, locale: string): string {
  return total > 0 ? new Intl.NumberFormat(locale, { style: "percent", maximumFractionDigits: 0 }).format(part / total) : "–";
}
