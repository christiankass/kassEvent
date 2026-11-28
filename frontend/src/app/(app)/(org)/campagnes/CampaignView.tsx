"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft } from "@phosphor-icons/react";
import { Alert, Button, Field, Select, TextArea } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import {
  AUDIENCES,
  CHANNELS,
  isEditable,
  rate,
  WHATSAPP_MAX,
  type Campaign,
  type CampaignChannel,
  type CampaignDraft,
  type CampaignsIndex,
} from "@/lib/campaigns";
import { actionErrorMessage } from "@/lib/errors";
import { formatPrice, type EventData } from "@/lib/events";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { PageSkeleton } from "../../PageSkeleton";
import app from "../../app.module.css";
import { AccessGate } from "../AccessGate";
import book from "../contacts/contacts.module.css";
import styles from "./campaigns.module.css";

const POLL_MS = 5000;
const EMPTY: CampaignDraft = { channel: "email", subject: "", body: "", event_id: null, button_label: "", audience: "all" };

const toDraft = (campaign: Campaign): CampaignDraft => ({
  channel: campaign.channel,
  subject: campaign.subject,
  body: campaign.body,
  event_id: campaign.event_id,
  button_label: campaign.button_label ?? "",
  audience: campaign.audience,
});

/**
 * Une campagne : rédaction (brouillon ou programmée), puis résultats une fois partie. `id` absent :
 * nouvelle campagne, créée au premier enregistrement.
 */
export function CampaignView({ id }: { id?: string }) {
  const { t, locale } = useI18n();
  const c = t.campaigns;
  const e = c.editor;
  const router = useRouter();
  const { organization } = useOrganization();
  const organizationId = organization.id;

  const [campaign, setCampaign] = useState<Campaign | null>(null);
  const [draft, setDraft] = useState<CampaignDraft>(EMPTY);
  const [index, setIndex] = useState<CampaignsIndex | null>(null);
  const [events, setEvents] = useState<EventData[]>([]);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [error, setError] = useState<unknown>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [preview, setPreview] = useState<string | null>(null);
  const [at, setAt] = useState("");

  const load = useCallback(
    () => api<{ data: Campaign }>(`/campaigns/${id}`, { organizationId }).then(({ data }) => data),
    [id, organizationId],
  );

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      api<CampaignsIndex>("/campaigns", { organizationId }),
      api<{ data: EventData[] }>("/events", { organizationId }),
      id ? load() : Promise.resolve(null),
    ])
      .then(([campaigns, { data }, found]) => {
        if (cancelled) return;
        setIndex(campaigns);
        // Événements publiés, plus celui du brouillon s'il ne l'est plus (sinon le choix paraîtrait vide).
        setEvents(data.filter((event) => event.status === "published" || event.id === found?.event_id));
        if (found) {
          setCampaign(found);
          setDraft(toDraft(found));
        }
      })
      .catch((err) => !cancelled && setLoadError(err));
    return () => {
      cancelled = true;
    };
  }, [id, load, organizationId]);

  // Envoi en cours : les compteurs avancent.
  const sending = campaign?.status === "sending";
  useEffect(() => {
    if (!sending) return;
    const timer = setInterval(() => load().then(setCampaign).catch(() => {}), POLL_MS);
    return () => clearInterval(timer);
  }, [sending, load]);

  if (loadError) return <AccessGate error={loadError} twoFactor={c.twoFactor} forbidden={c.forbidden} />;
  if (!index || (id && !campaign)) return <PageSkeleton />;

  const set = <K extends keyof CampaignDraft>(key: K, value: CampaignDraft[K]) => setDraft((d) => ({ ...d, [key]: value }));
  const fieldError = (name: string) => (error instanceof ApiError ? error.field(name) : undefined);
  const date = new Intl.DateTimeFormat(locale, { dateStyle: "full", timeStyle: "short" });

  /** Enregistre le brouillon (création au premier passage) et rend la campagne à jour. */
  async function save(): Promise<Campaign> {
    const body = { ...draft, button_label: draft.channel === "email" && draft.event_id ? draft.button_label : "" };
    const { data } = campaign
      ? await api<{ data: Campaign }>(`/campaigns/${campaign.id}`, { method: "PATCH", body, organizationId })
      : await api<{ data: Campaign }>("/campaigns", { method: "POST", body, organizationId });
    setCampaign(data);
    // Nouvelle campagne : l'adresse devient la sienne, sans recharger l'écran (aperçu, message gardés).
    if (!campaign) window.history.replaceState(null, "", `/campagnes/${data.id}`);
    return data;
  }

  async function run(action: () => Promise<string | null>) {
    setPending(true);
    setError(null);
    setNotice(null);
    try {
      setNotice(await action());
    } catch (err) {
      setError(err);
    }
    setPending(false);
  }

  const onSave = () => run(async () => (await save(), e.saved));

  const onPreview = () =>
    run(async () => {
      if (preview) {
        setPreview(null);
        return null;
      }
      const sent = campaign && !isEditable(campaign.status);
      // WhatsApp : la bulle se construit ici, à partir du texte (le modèle de Meta est fixe).
      if ((sent ? campaign.channel : draft.channel) === "whatsapp") {
        setPreview("");
        return null;
      }
      // Campagne partie : plus rien à enregistrer, on montre l'e-mail tel qu'il est parti.
      const shown = sent ? campaign : await save();
      const { data } = await api<{ data: { html: string } }>(`/campaigns/${shown.id}/preview`, { organizationId });
      setPreview(data.html);
      return null;
    });

  const onTest = () =>
    run(async () => {
      const saved = await save();
      const { data } = await api<{ data: { email: string } }>(`/campaigns/${saved.id}/test`, { method: "POST", organizationId });
      return format(e.testSent, { email: data.email });
    });

  const onSend = (scheduledAt: string | null) =>
    run(async () => {
      if (!scheduledAt && !window.confirm(format(e.sendConfirm, { subject: draft.subject, count: String(index.audiences[draft.channel][draft.audience]) }))) return null;
      const saved = await save();
      const { data } = await api<{ data: Campaign }>(`/campaigns/${saved.id}/send`, {
        method: "POST",
        body: { scheduled_at: scheduledAt },
        organizationId,
      });
      setCampaign(data);
      return data.status === "scheduled" ? e.scheduled : null;
    });

  const onCancel = () =>
    run(async () => {
      const { data } = await api<{ data: Campaign }>(`/campaigns/${campaign!.id}/cancel`, { method: "POST", organizationId });
      setCampaign(data);
      return e.cancelled;
    });

  const onRemove = () =>
    run(async () => {
      if (!window.confirm(e.removeConfirm)) return null;
      await api(`/campaigns/${campaign!.id}`, { method: "DELETE", organizationId });
      router.push("/campagnes");
      return null;
    });

  const header = (
    <header>
      <Link href="/campagnes" className={styles.back}>
        <ArrowLeft size={18} aria-hidden />
        {c.back}
      </Link>
    </header>
  );
  const messages = (
    <>
      {notice && <Alert tone="success">{notice}</Alert>}
      {error ? <Alert tone="error">{actionErrorMessage(error, t)}</Alert> : null}
    </>
  );

  if (campaign && !isEditable(campaign.status)) {
    return (
      <main className={`${app.main} ${book.page}`}>
        {header}
        <div>
          <h1 className={app.sectionTitle}>{campaign.subject}</h1>
          <p className={book.lede}>
            {[
              c.status[campaign.status],
              c.channels[campaign.channel],
              c.audienceNames[campaign.audience],
              campaign.event?.title,
              campaign.sent_at && format(c.sentOn, { date: date.format(new Date(campaign.sent_at)) }),
            ]
              .filter(Boolean)
              .join(" · ")}
          </p>
        </div>
        {sending && <p className={styles.hint}>{c.report.sending}</p>}
        <Report campaign={campaign} />
        <p className={styles.hint}>{campaign.channel === "whatsapp" ? c.report.readHint : c.report.openedHint}</p>
        {messages}
        <div className={styles.actions}>
          <Button type="button" variant="secondary" onClick={onPreview} disabled={pending}>
            {preview !== null ? e.hidePreview : c.report[campaign.channel]}
          </Button>
        </div>
        {preview !== null && <MessagePreview channel={campaign.channel} html={preview} title={c.report[campaign.channel]} text={campaign.body} />}
      </main>
    );
  }

  const scheduled = campaign?.status === "scheduled";
  const whatsapp = draft.channel === "whatsapp";
  const reachable = index.audiences[draft.channel][draft.audience];
  const demo = index.channels[draft.channel].demo;

  return (
    <main className={`${app.main} ${book.page}`}>
      {header}
      <h1 className={app.sectionTitle}>{campaign ? e.editTitle : e.newTitle}</h1>
      {demo && <p className={styles.demo}>{whatsapp ? c.demoWhatsapp : c.demo}</p>}
      {scheduled && campaign.scheduled_at && (
        <p className={styles.demo}>{format(c.scheduledFor, { date: date.format(new Date(campaign.scheduled_at)) })}</p>
      )}

      <form
        className={`${app.panel} ${styles.form}`}
        onSubmit={(event) => {
          event.preventDefault();
          onSave();
        }}
      >
        <fieldset className={app.fieldset}>
          <legend className={app.legend}>{e.channel}</legend>
          <div className={app.choices}>
            {CHANNELS.map((channel) => (
              <label key={channel} className={app.choice}>
                <input
                  type="radio"
                  name="channel"
                  checked={draft.channel === channel}
                  disabled={!index.channels[channel].available}
                  onChange={() => {
                    set("channel", channel);
                    setPreview(null);
                  }}
                />
                <span className={app.choiceTitle}>{c.channels[channel]}</span>
                <span className={app.choiceHint}>{index.channels[channel].available ? c.channelHints[channel] : c.unavailable}</span>
              </label>
            ))}
          </div>
        </fieldset>
        <Field
          label={whatsapp ? e.title : e.subject}
          hint={whatsapp ? e.titleHint : e.subjectHint}
          value={draft.subject}
          onChange={(ev) => set("subject", ev.target.value)}
          maxLength={150}
          required
          error={fieldError("subject")}
        />
        <TextArea
          label={e.body}
          hint={whatsapp ? format(e.bodyHintWhatsapp, { max: String(WHATSAPP_MAX), count: String(draft.body.length) }) : e.bodyHint}
          value={draft.body}
          onChange={(ev) => set("body", ev.target.value)}
          rows={8}
          maxLength={whatsapp ? WHATSAPP_MAX : 5000}
          required
          error={fieldError("body")}
        />
        <Select
          label={e.event}
          hint={whatsapp ? e.eventHintWhatsapp : e.eventHint}
          value={draft.event_id ?? ""}
          onChange={(ev) => set("event_id", ev.target.value || null)}
          error={fieldError("event_id")}
        >
          <option value="">{whatsapp ? e.chooseEvent : e.noEvent}</option>
          {events.map((event) => (
            <option key={event.id} value={event.id}>
              {event.status === "published" ? event.title : format(e.unpublished, { title: event.title })}
            </option>
          ))}
        </Select>
        {draft.event_id && !whatsapp && (
          <Field
            label={e.button}
            hint={e.buttonHint}
            value={draft.button_label}
            onChange={(ev) => set("button_label", ev.target.value)}
            maxLength={40}
            error={fieldError("button_label")}
          />
        )}

        <fieldset className={app.fieldset}>
          <legend className={app.legend}>{e.audience}</legend>
          <div className={app.choices}>
            {AUDIENCES.map((audience) => (
              <label key={audience} className={app.choice}>
                <input type="radio" name="audience" checked={draft.audience === audience} onChange={() => set("audience", audience)} />
                <span className={app.choiceTitle}>
                  {c.audienceNames[audience]} · {format(c.people, { count: String(index.audiences[draft.channel][audience]) })}
                </span>
                <span className={app.choiceHint}>{c.audienceHints[audience]}</span>
              </label>
            ))}
          </div>
          {fieldError("audience") && <p className={styles.hint}>{fieldError("audience")}</p>}
        </fieldset>

        {messages}

        <div className={styles.actions}>
          <Button type="submit" variant="secondary" disabled={pending}>
            {e.save}
          </Button>
          <Button type="button" variant="secondary" onClick={onPreview} disabled={pending}>
            {preview !== null ? e.hidePreview : e.preview}
          </Button>
          {!whatsapp && (
            <Button type="button" variant="secondary" onClick={onTest} disabled={pending}>
              {e.test}
            </Button>
          )}
        </div>
        {preview !== null && <MessagePreview channel={draft.channel} html={preview} title={e.preview} text={draft.body} />}

        <div className={styles.actions}>
          <Button type="button" onClick={() => onSend(null)} disabled={pending || reachable === 0}>
            {e.sendNow} · {format(c.people, { count: String(reachable) })}
          </Button>
        </div>
        <div className={styles.schedule}>
          <Field label={e.scheduleAt} type="datetime-local" value={at} onChange={(ev) => setAt(ev.target.value)} error={fieldError("scheduled_at")} />
          <Button type="button" variant="secondary" onClick={() => onSend(new Date(at).toISOString())} disabled={pending || !at || reachable === 0}>
            {e.schedule}
          </Button>
        </div>
        {campaign && (
          <div className={styles.actions}>
            {scheduled && (
              <Button type="button" variant="secondary" onClick={onCancel} disabled={pending}>
                {e.cancel}
              </Button>
            )}
            {campaign && (
              <Button type="button" variant="danger" onClick={onRemove} disabled={pending}>
                {e.remove}
              </Button>
            )}
          </div>
        )}
      </form>
    </main>
  );
}

/** Chiffres d'une campagne partie. */
function Report({ campaign }: { campaign: Campaign }) {
  const { t, locale } = useI18n();
  const r = t.campaigns.report;
  const number = (value: number) => new Intl.NumberFormat(locale).format(value);
  const sent = campaign.sent_count;

  const tiles: [string, string, string | null][] = [
    [
      r.recipients,
      number(campaign.recipients_count),
      [format(r.sent, { count: number(sent) }), campaign.failed_count > 0 && format(r.failed, { count: number(campaign.failed_count) })]
        .filter(Boolean)
        .join(" · "),
    ],
    [campaign.channel === "whatsapp" ? r.read : r.opened, rate(campaign.opened_count, sent, locale), number(campaign.opened_count)],
    [r.clicked, rate(campaign.clicked_count, sent, locale), number(campaign.clicked_count)],
    [
      r.sales,
      campaign.sales.length ? campaign.sales.map((s) => formatPrice(s.revenue, s.currency, locale)).join(" + ") : "–",
      campaign.sales.length
        ? format(r.orders, { count: number(campaign.sales.reduce((sum, s) => sum + s.orders, 0)) })
        : r.noSales,
    ],
  ];

  return (
    <dl className={book.tiles}>
      {tiles.map(([label, value, detail]) => (
        <div key={label}>
          <dt>{label}</dt>
          <dd>{value}</dd>
          {detail && <dd className={styles.sales}>{detail}</dd>}
        </div>
      ))}
    </dl>
  );
}

/** Aperçu du message : l'e-mail rendu par le serveur, ou la bulle WhatsApp du modèle « annonce ». */
function MessagePreview({ channel, html, title, text }: { channel: CampaignChannel; html: string; title: string; text: string }) {
  const { t } = useI18n();
  const w = t.campaigns.whatsapp;
  const { organization } = useOrganization();
  if (channel === "email") return <iframe className={styles.preview} title={title} srcDoc={html} sandbox="" />;
  // Comme Meta : une seule ligne par variable, {nom} remplacé par un exemple.
  const body = text.replace(/\s+/g, " ").trim().replaceAll("{nom}", w.sample).replaceAll("{name}", w.sample);
  return (
    <figure className={styles.whatsapp} aria-label={title}>
      <div className={styles.bubble}>
        <p>{format(w.template, { name: w.sample, organization: organization.name, text: body })}</p>
        <span className={styles.bubbleButton}>{w.view}</span>
        <span className={styles.bubbleButton}>{w.stop}</span>
      </div>
    </figure>
  );
}
