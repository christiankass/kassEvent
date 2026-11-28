"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft } from "@phosphor-icons/react";
import { Alert, Button, Field, Select, TextArea } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { AUDIENCES, isEditable, rate, type Campaign, type CampaignDraft, type CampaignsIndex } from "@/lib/campaigns";
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
const EMPTY: CampaignDraft = { subject: "", body: "", event_id: null, button_label: "", audience: "all" };

const toDraft = (campaign: Campaign): CampaignDraft => ({
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
    const body = { ...draft, button_label: draft.event_id ? draft.button_label : "" };
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
      // Campagne partie : plus rien à enregistrer, on montre l'e-mail tel qu'il est parti.
      const shown = campaign && !isEditable(campaign.status) ? campaign : await save();
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
      if (!scheduledAt && !window.confirm(format(e.sendConfirm, { subject: draft.subject, count: String(index.audiences[draft.audience]) }))) return null;
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
        <p className={styles.hint}>{c.report.openedHint}</p>
        {messages}
        <div className={styles.actions}>
          <Button type="button" variant="secondary" onClick={onPreview} disabled={pending}>
            {preview ? e.hidePreview : c.report.email}
          </Button>
        </div>
        {preview && <iframe className={styles.preview} title={c.report.email} srcDoc={preview} sandbox="" />}
      </main>
    );
  }

  const scheduled = campaign?.status === "scheduled";
  const reachable = index.audiences[draft.audience];

  return (
    <main className={`${app.main} ${book.page}`}>
      {header}
      <h1 className={app.sectionTitle}>{campaign ? e.editTitle : e.newTitle}</h1>
      {index.demo && <p className={styles.demo}>{c.demo}</p>}
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
        <Field
          label={e.subject}
          hint={e.subjectHint}
          value={draft.subject}
          onChange={(ev) => set("subject", ev.target.value)}
          maxLength={150}
          required
          error={fieldError("subject")}
        />
        <TextArea
          label={e.body}
          hint={e.bodyHint}
          value={draft.body}
          onChange={(ev) => set("body", ev.target.value)}
          rows={8}
          maxLength={5000}
          required
          error={fieldError("body")}
        />
        <Select
          label={e.event}
          hint={e.eventHint}
          value={draft.event_id ?? ""}
          onChange={(ev) => set("event_id", ev.target.value || null)}
          error={fieldError("event_id")}
        >
          <option value="">{e.noEvent}</option>
          {events.map((event) => (
            <option key={event.id} value={event.id}>
              {event.status === "published" ? event.title : format(e.unpublished, { title: event.title })}
            </option>
          ))}
        </Select>
        {draft.event_id && (
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
                  {c.audienceNames[audience]} · {format(c.people, { count: String(index.audiences[audience]) })}
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
            {preview ? e.hidePreview : e.preview}
          </Button>
          <Button type="button" variant="secondary" onClick={onTest} disabled={pending}>
            {e.test}
          </Button>
        </div>
        {preview && <iframe className={styles.preview} title={e.preview} srcDoc={preview} sandbox="" />}

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
    [r.opened, rate(campaign.opened_count, sent, locale), number(campaign.opened_count)],
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
