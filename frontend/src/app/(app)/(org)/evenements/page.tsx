"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { CalendarBlank, MapPin, Plus } from "@phosphor-icons/react";
import { Alert, buttonClass } from "@/components/ui";
import { api } from "@/lib/api";
import { formErrorMessage } from "@/lib/errors";
import { formatEventDate, type EventData } from "@/lib/events";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { PageSkeleton } from "../../PageSkeleton";
import app from "../../app.module.css";
import { StatusBadge } from "./forms";
import styles from "./events.module.css";

export default function EventsPage() {
  const { t, locale } = useI18n();
  const { organization, permissions } = useOrganization();
  const canManage = permissions.includes("events.manage");
  const [events, setEvents] = useState<EventData[] | null>(null);
  const [error, setError] = useState<unknown>(null);

  useEffect(() => {
    api<{ data: EventData[] }>("/events", { organizationId: organization.id })
      .then(({ data }) => setEvents(data))
      .catch(setError);
  }, [organization.id]);

  if (error) {
    return (
      <main className={app.main}>
        <Alert tone="error">{formErrorMessage(error, t)}</Alert>
      </main>
    );
  }
  if (!events) return <PageSkeleton />;

  const createLink = canManage && (
    <Link href="/evenements/nouveau" className={buttonClass()}>
      <Plus size={20} weight="bold" aria-hidden /> {t.events.create}
    </Link>
  );

  return (
    <main className={app.main}>
      <div className={styles.pageHead}>
        <div>
          <h1 className={app.title}>{t.events.title}</h1>
          <p className={app.lede}>{t.events.lede}</p>
        </div>
        {events.length > 0 && createLink}
      </div>

      {events.length === 0 ? (
        <section className={app.empty} aria-labelledby="empty-title">
          <h2 id="empty-title" className={app.emptyTitle}>
            {t.events.emptyTitle}
          </h2>
          <p className={app.emptyBody}>{t.events.emptyBody}</p>
          {createLink}
        </section>
      ) : (
        <ul className={styles.eventList}>
          {events.map((event) => {
            const past = new Date(event.starts_at_utc) < new Date();
            return (
              <li key={event.id}>
                <Link href={`/evenements/${event.id}`} className={styles.eventCard}>
                  <div className={styles.thumb} aria-hidden>
                    {event.cover_url ? (
                      // eslint-disable-next-line @next/next/no-img-element -- vignette de l'affiche servie par kassEvent
                      <img src={event.cover_url} alt="" />
                    ) : (
                      <span>{event.title.charAt(0)}</span>
                    )}
                  </div>
                  <div className={styles.eventInfo}>
                    <span className={styles.eventTitle}>{event.title}</span>
                    <span className={styles.meta}>
                      <CalendarBlank size={16} aria-hidden /> {formatEventDate(event, locale)}
                    </span>
                    <span className={styles.meta}>
                      <MapPin size={16} aria-hidden /> {event.venue_name}, {event.city}
                    </span>
                  </div>
                  <div className={styles.eventSide}>
                    <StatusBadge status={past ? "past" : event.status} />
                    <span className={styles.metaSmall}>
                      {event.ticket_types.length === 0
                        ? t.events.noTickets
                        : format(t.events.ticketsCount, { count: String(event.ticket_types.length) })}
                    </span>
                  </div>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </main>
  );
}
