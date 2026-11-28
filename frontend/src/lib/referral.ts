/**
 * Source d'une visite de la page publique (statistiques, d'où viennent les acheteurs) : lue dans le
 * lien partagé (?src=whatsapp), sinon devinée d'après le site d'où vient le visiteur. Retenue
 * 30 jours dans le navigateur, pour être jointe à la commande même si l'achat se fait plus tard.
 * Aucune donnée sur la personne : seulement le nom du canal.
 */
import { api } from "./api";

export const SOURCES = [
  "whatsapp",
  "facebook",
  "instagram",
  "tiktok",
  "linkedin",
  "messenger",
  "email",
  "sms",
  "qr",
  "link",
  "search",
  "other",
  "direct",
] as const;
export type Source = (typeof SOURCES)[number];

/** Canaux proposés en « liens suivis » à l'organisateur, dans l'ordre d'usage à Kinshasa. */
export const SHARE_SOURCES = ["whatsapp", "facebook", "tiktok", "instagram", "messenger", "linkedin", "sms", "email"] as const satisfies readonly Source[];

const KEEP_MS = 30 * 24 * 3600 * 1000;
const storageKey = (slug: string) => `kassevent:ref:${slug}`;

/** Lien de la page publique marqué d'une source. */
export function trackedUrl(url: string, source: Source): string {
  const link = new URL(url);
  link.searchParams.set("src", source);
  return link.toString();
}

const REFERRERS: [RegExp, Source][] = [
  [/(^|\.)(facebook\.com|fb\.com|fb\.me)$/, "facebook"],
  [/(^|\.)messenger\.com$/, "messenger"],
  [/(^|\.)instagram\.com$/, "instagram"],
  [/(^|\.)tiktok\.com$/, "tiktok"],
  [/(^|\.)(linkedin\.com|lnkd\.in)$/, "linkedin"],
  [/(^|\.)(whatsapp\.com|wa\.me)$/, "whatsapp"],
  [/(^|\.)(google\.[a-z.]+|bing\.com|duckduckgo\.com|yahoo\.com)$/, "search"],
];

function fromReferrer(referrer: string): Source | null {
  if (!referrer) return null;
  try {
    const host = new URL(referrer).hostname;
    if (host === window.location.hostname) return null;
    return REFERRERS.find(([pattern]) => pattern.test(host))?.[1] ?? "other";
  } catch {
    return null;
  }
}

function normalize(value: string | null): Source | null {
  if (!value) return null;
  const source = value.trim().toLowerCase();
  return (SOURCES as readonly string[]).includes(source) ? (source as Source) : "other";
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

type Saved = { source?: string; at?: number; campaign?: string };

function saved(slug: string): Saved | null {
  try {
    const value = JSON.parse(window.localStorage.getItem(storageKey(slug)) ?? "null") as Saved | null;
    if (value?.source && typeof value.at === "number" && Date.now() - value.at < KEEP_MS) return value;
  } catch {
    // Stockage bloqué (navigation privée) : la commande partira sans source.
  }
  return null;
}

/** Source retenue pour cet événement (dernier canal connu, 30 jours), sinon « direct ». */
export function storedReferral(slug: string): Source {
  return normalize(saved(slug)?.source ?? null) ?? "direct";
}

/** Campagne e-mail dont le bouton a mené à cette page (?src=email&cmp=…), tant que l'e-mail reste la source retenue. */
export function storedCampaign(slug: string): string | null {
  const value = saved(slug);
  return value?.source === "email" && value.campaign && UUID.test(value.campaign) ? value.campaign : null;
}

/**
 * Visite de la page publique : retient la source et la signale une fois par session. Une visite sans
 * source ne remplace pas un canal déjà retenu (le visiteur revient souvent par l'historique).
 */
export function recordVisit(slug: string): void {
  const params = new URLSearchParams(window.location.search);
  const source = normalize(params.get("src")) ?? fromReferrer(document.referrer);
  const campaign = source === "email" && UUID.test(params.get("cmp") ?? "") ? params.get("cmp") : null;
  try {
    if (source) window.localStorage.setItem(storageKey(slug), JSON.stringify({ source, at: Date.now(), ...(campaign ? { campaign } : {}) }));
    const flag = `kassevent:visit:${slug}`;
    if (window.sessionStorage.getItem(flag)) return;
    window.sessionStorage.setItem(flag, "1");
  } catch {
    // Stockage bloqué : la visite est comptée à chaque affichage, sans gravité.
  }
  api(`/public/events/${slug}/visits`, { method: "POST", body: { source: source ?? storedReferral(slug) } }).catch(() => {});
}
