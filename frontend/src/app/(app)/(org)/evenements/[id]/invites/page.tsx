"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft } from "@phosphor-icons/react";
import { Alert } from "@/components/ui";
import { api } from "@/lib/api";
import { formErrorMessage } from "@/lib/errors";
import type { EventData } from "@/lib/events";
import type { GuestPage } from "@/lib/guests";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { PageSkeleton } from "../../../../PageSkeleton";
import app from "../../../../app.module.css";
import events from "../../events.module.css";
import { GuestList } from "./GuestList";
import { SeatingBoard } from "./SeatingBoard";
import styles from "./guests.module.css";

export type Notice = { tone: "success" | "error"; text: string };

/** Invités d'un événement (réponses, envoi des invitations) et plan de salle (tables, placement). */
export default function GuestsPage() {
  const { t } = useI18n();
  const { id } = useParams<{ id: string }>();
  const { organization, permissions } = useOrganization();
  const organizationId = organization.id;
  const canManage = permissions.includes("events.manage");

  const [event, setEvent] = useState<EventData | null>(null);
  const [page, setPage] = useState<GuestPage | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [tab, setTab] = useState<"guests" | "seating">("guests");
  const [notice, setNotice] = useState<Notice | null>(null);

  const reload = useCallback(
    () => api<GuestPage>(`/events/${id}/guests`, { organizationId }).then(setPage),
    [id, organizationId],
  );

  useEffect(() => {
    Promise.all([api<{ data: EventData }>(`/events/${id}`, { organizationId }).then(({ data }) => setEvent(data)), reload()]).catch(setLoadError);
  }, [id, organizationId, reload]);

  if (loadError) {
    return (
      <main className={app.main}>
        <Alert tone="error">{formErrorMessage(loadError, t)}</Alert>
      </main>
    );
  }
  if (!event || !page) return <PageSkeleton />;

  const { summary } = page;

  return (
    <main className={app.main}>
      <Link href={`/evenements/${event.id}`} className={events.back}>
        <ArrowLeft size={18} aria-hidden /> {t.guests.back}
      </Link>
      <h1 className={app.title}>{t.guests.title}</h1>
      <p className={app.lede}>{event.title}</p>

      <dl className={styles.stats}>
        <div data-tone="ok">
          <dt>{t.guests.stats.confirmed}</dt>
          <dd>{summary.confirmed}</dd>
        </div>
        <div data-tone="wait">
          <dt>{t.guests.stats.pending}</dt>
          <dd>{summary.pending}</dd>
        </div>
        <div data-tone="no">
          <dt>{t.guests.stats.declined}</dt>
          <dd>{summary.declined}</dd>
        </div>
        <div>
          <dt>{t.guests.stats.people}</dt>
          <dd>{summary.people_expected}</dd>
          <p className={styles.statHint}>
            {format(t.guests.stats.peopleHint, { confirmed: String(summary.people_confirmed), expected: String(summary.people_expected) })}
          </p>
        </div>
      </dl>

      <div className={styles.tabs} role="tablist" aria-label={t.guests.title}>
        {(["guests", "seating"] as const).map((name) => (
          <button
            key={name}
            type="button"
            role="tab"
            id={`tab-${name}`}
            aria-selected={tab === name}
            aria-controls={`panel-${name}`}
            className={styles.tab}
            onClick={() => {
              setTab(name);
              setNotice(null);
            }}
          >
            {name === "guests" ? t.guests.tabGuests : t.guests.tabSeating}
          </button>
        ))}
      </div>

      <div className={app.notice} aria-live="polite">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      </div>
      {!canManage && <p className={app.lede}>{t.guests.readOnly}</p>}

      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
        {tab === "guests" ? (
          <GuestList event={event} page={page} canManage={canManage} reload={reload} onNotice={setNotice} />
        ) : (
          <SeatingBoard event={event} page={page} canManage={canManage} reload={reload} onNotice={setNotice} />
        )}
      </div>
    </main>
  );
}
