"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, ArrowSquareOut, FrameCorners, QrCode, ImageSquare, MagicWand, PaintBrush, PencilSimple, Trash } from "@phosphor-icons/react";
import { ShareButtons } from "@/components/ShareButtons";
import { Alert, Button, buttonClass } from "@/components/ui";
import { api } from "@/lib/api";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { publicEventUrl, ticketPrice, type EventData } from "@/lib/events";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { IMAGE_ACCEPT, prepareImage } from "@/lib/images";
import { PageSkeleton } from "../../../PageSkeleton";
import app from "../../../app.module.css";
import { EventForm, StatusBadge, TicketForm } from "../forms";
import { SalesPanel } from "./SalesPanel";
import styles from "../events.module.css";

type Notice = { tone: "success" | "error"; text: string };

export default function EventPage() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const { id } = useParams<{ id: string }>();
  const { organization, permissions } = useOrganization();
  const organizationId = organization.id;
  const canManage = permissions.includes("events.manage");
  const canViewSales = permissions.includes("orders.view");
  const canScan = permissions.includes("checkin.scan");

  const [event, setEvent] = useState<EventData | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(
    () => api<{ data: EventData }>(`/events/${id}`, { organizationId }).then(({ data }) => setEvent(data)),
    [id, organizationId],
  );

  useEffect(() => {
    load().catch(setLoadError);
  }, [load]);

  /**
   * Action hors formulaire : annonce le résultat et affiche l'événement à jour,
   * celui que renvoie l'API, ou rechargé si elle ne renvoie rien (suppression).
   */
  async function act(request: () => Promise<{ data: EventData } | void>, success: string) {
    setNotice(null);
    setBusy(true);
    try {
      const result = await request();
      if (result) setEvent(result.data);
      else await load();
      setNotice({ tone: "success", text: success });
    } catch (e) {
      setNotice({ tone: "error", text: actionErrorMessage(e, t) });
    }
    setBusy(false);
    setConfirming(null);
  }

  if (loadError) {
    return (
      <main className={app.main}>
        <Alert tone="error">{formErrorMessage(loadError, t)}</Alert>
      </main>
    );
  }
  if (!event) return <PageSkeleton />;

  const published = event.status === "published";
  const url = publicEventUrl(event.slug);
  const price = (minor: number) => ticketPrice(minor, event.currency, locale, t);

  return (
    <main className={app.main}>
      <Link href="/evenements" className={styles.back}>
        <ArrowLeft size={18} aria-hidden /> {t.events.back}
      </Link>
      <div className={styles.titleRow}>
        <h1 className={app.title}>{event.title}</h1>
        <StatusBadge status={event.status} />
      </div>

      {canScan && published && (
        <Link href={`/evenements/${event.id}/scanner`} className={buttonClass("primary", styles.scanButton)}>
          <QrCode size={22} aria-hidden /> {t.scan.open}
        </Link>
      )}

      <div className={app.notice} aria-live="polite">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      </div>
      {!canManage && <p className={app.lede}>{t.events.readOnly}</p>}

      <div className={styles.layout}>
        <div className={app.stack}>
          {canViewSales && event.published_at && <SalesPanel eventId={event.id} />}

          <section aria-labelledby="tickets-title">
            <h2 id="tickets-title" className={app.sectionTitle}>
              {t.events.ticketsTitle}
            </h2>
            <p className={styles.sectionLede}>{t.events.ticketsLede}</p>
            {event.ticket_types.length === 0 ? (
              <p className={styles.callout}>{t.events.ticketsEmpty}</p>
            ) : (
              <ul className={app.list}>
                {event.ticket_types.map((ticket) =>
                  editing === ticket.id ? (
                    <li key={ticket.id} className={styles.editRow}>
                      <TicketForm
                        ticket={ticket}
                        currency={event.currency}
                        onCancel={() => setEditing(null)}
                        onSubmit={async (body) => {
                          await api(`/ticket-types/${ticket.id}`, { method: "PATCH", organizationId, body });
                          setEditing(null);
                          await load().catch(setLoadError);
                          setNotice({ tone: "success", text: format(t.events.ticketSaved, { name: String(body.name) }) });
                        }}
                      />
                    </li>
                  ) : (
                    <li key={ticket.id} className={app.row}>
                      <div className={app.who}>
                        <span className={app.name}>{ticket.name}</span>
                        <span className={app.email}>
                          {format(t.events.quantityOf, { count: ticket.quantity.toLocaleString(locale) })}
                          {ticket.description && ` · ${ticket.description}`}
                        </span>
                      </div>
                      <span className={styles.price}>{price(ticket.price)}</span>
                      {canManage && (
                        <div className={app.actions}>
                          {confirming === ticket.id ? (
                            <>
                              <Button
                                type="button"
                                variant="danger"
                                disabled={busy}
                                onClick={() =>
                                  act(
                                    () => api<void>(`/ticket-types/${ticket.id}`, { method: "DELETE", organizationId }),
                                    format(t.events.ticketRemoved, { name: ticket.name }),
                                  )
                                }
                              >
                                {t.events.confirmRemove}
                              </Button>
                              <Button type="button" variant="secondary" onClick={() => setConfirming(null)}>
                                {t.events.cancel}
                              </Button>
                            </>
                          ) : (
                            <>
                              <Button type="button" variant="secondary" onClick={() => setEditing(ticket.id)}>
                                <PencilSimple size={18} aria-hidden /> {t.events.edit}
                              </Button>
                              <Button
                                type="button"
                                variant="secondary"
                                aria-label={`${t.events.remove} ${ticket.name}`}
                                onClick={() => setConfirming(ticket.id)}
                              >
                                <Trash size={18} aria-hidden />
                              </Button>
                            </>
                          )}
                        </div>
                      )}
                    </li>
                  ),
                )}
              </ul>
            )}
            {canManage && (
              <div className={`${app.panel} ${styles.addTicket}`}>
                <h3 className={styles.subTitle}>{t.events.newTicket}</h3>
                <TicketForm
                  currency={event.currency}
                  onSubmit={async (body) => {
                    await api(`/events/${event.id}/ticket-types`, { method: "POST", organizationId, body });
                    await load().catch(setLoadError);
                    setNotice({ tone: "success", text: format(t.events.ticketAdded, { name: String(body.name) }) });
                  }}
                />
              </div>
            )}
          </section>

          <section aria-labelledby="details-title">
            <h2 id="details-title" className={app.sectionTitle}>
              {t.events.detailsTitle}
            </h2>
            <fieldset disabled={!canManage} className={styles.plainFieldset}>
              <EventForm
                key={event.timezone + event.currency}
                event={event}
                currency={organization.currency}
                submitLabel={t.events.save}
                onSubmit={async (body) => {
                  setNotice(null);
                  const { data } = await api<{ data: EventData }>(`/events/${event.id}`, {
                    method: "PATCH",
                    organizationId,
                    body,
                  });
                  setEvent(data);
                  setNotice({ tone: "success", text: t.events.saved });
                }}
              />
            </fieldset>
          </section>
        </div>

        <aside className={app.stack}>
          <section className={app.panel} aria-labelledby="publish-title">
            <h2 id="publish-title" className={app.sectionTitle}>
              {t.events.publishTitle}
            </h2>
            <p className={styles.sectionLede}>{published ? t.events.publishedExplain : t.events.draftExplain}</p>
            {published && (
              <>
                <p className={styles.url}>
                  <a href={url} target="_blank" rel="noopener noreferrer">
                    {url.replace(/^https?:\/\//, "")}
                  </a>
                </p>
                <ShareButtons url={url} title={event.title} />
              </>
            )}
            <p className={styles.hintSmall}>{t.events.salesSoon}</p>
            <div className={styles.buttons}>
              {canManage && (
                <Button
                  type="button"
                  variant={published ? "secondary" : "primary"}
                  disabled={busy}
                  onClick={() =>
                    act(
                      () =>
                        api<{ data: EventData }>(`/events/${event.id}/${published ? "unpublish" : "publish"}`, {
                          method: "POST",
                          organizationId,
                        }),
                      published ? t.events.unpublishedNotice : t.events.publishedNotice,
                    )
                  }
                >
                  {published ? t.events.unpublish : t.events.publish}
                </Button>
              )}
              {published && (
                <a href={url} target="_blank" rel="noopener noreferrer" className={buttonClass("secondary")}>
                  <ArrowSquareOut size={18} aria-hidden /> {t.events.preview}
                </a>
              )}
            </div>
          </section>

          <section className={app.panel} aria-labelledby="design-title">
            <h2 id="design-title" className={app.sectionTitle}>
              {t.events.designTitle}
            </h2>
            <p className={styles.sectionLede}>{t.events.designLede}</p>
            <div className={styles.buttons}>
              <Link href={`/evenements/${event.id}/studio`} className={buttonClass("primary")}>
                <MagicWand size={18} aria-hidden /> {t.events.studioTicket}
              </Link>
              <Link href={`/evenements/${event.id}/studio?format=poster`} className={buttonClass("secondary")}>
                <FrameCorners size={18} aria-hidden /> {t.events.studioPoster}
              </Link>
              <Link href={`/evenements/${event.id}/personnaliser`} className={buttonClass("secondary")}>
                <PaintBrush size={18} aria-hidden /> {t.events.designOpen}
              </Link>
            </div>
          </section>

          <section className={app.panel} aria-labelledby="cover-title">
            <h2 id="cover-title" className={app.sectionTitle}>
              {t.events.coverTitle}
            </h2>
            <div className={styles.cover}>
              {event.cover_url ? (
                // eslint-disable-next-line @next/next/no-img-element -- affiche déjà dimensionnée, servie par kassEvent
                <img src={event.cover_url} alt={format(t.events.coverAlt, { title: event.title })} />
              ) : (
                <ImageSquare size={40} aria-hidden />
              )}
            </div>
            {canManage && (
              <>
                <label className={`${buttonClass("secondary")} ${styles.fileButton}`}>
                  {event.cover_url ? t.events.coverReplace : t.events.coverChoose}
                  <input
                    type="file"
                    accept={IMAGE_ACCEPT}
                    disabled={busy}
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      e.target.value = "";
                      if (!file) return;
                      void act(async () => {
                        const body = new FormData();
                        body.append("cover", await prepareImage(file));
                        return api<{ data: EventData }>(`/events/${event.id}/cover`, { method: "POST", organizationId, body });
                      }, t.events.coverSaved);
                    }}
                  />
                </label>
                <p className={styles.hintSmall}>{t.events.coverHint}</p>
              </>
            )}
          </section>

          {canManage && !published && (
            <div>
              {confirming === "event" ? (
                <div className={styles.buttons}>
                  <Button
                    type="button"
                    variant="danger"
                    disabled={busy}
                    onClick={async () => {
                      setBusy(true);
                      try {
                        await api(`/events/${event.id}`, { method: "DELETE", organizationId });
                        router.replace("/evenements");
                      } catch (e) {
                        setNotice({ tone: "error", text: actionErrorMessage(e, t) });
                        setBusy(false);
                      }
                    }}
                  >
                    {t.events.confirmDelete}
                  </Button>
                  <Button type="button" variant="secondary" onClick={() => setConfirming(null)}>
                    {t.events.cancel}
                  </Button>
                </div>
              ) : (
                <Button type="button" variant="secondary" onClick={() => setConfirming("event")}>
                  <Trash size={18} aria-hidden /> {t.events.deleteDraft}
                </Button>
              )}
            </div>
          )}
        </aside>
      </div>
    </main>
  );
}
