"use client";

import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useState, type CSSProperties } from "react";
import { CalendarBlank, CheckCircle, HandWaving, MapPin, Minus, Plus, Ticket } from "@phosphor-icons/react";
import { Alert, LocaleSwitch, Wordmark } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { FONT_STACKS, textOn } from "@/lib/designs";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { formatEventDate } from "@/lib/events";
import { ticketFontVariables } from "@/lib/ticket-fonts";
import type { Rsvp } from "@/lib/guests";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "./rsvp.module.css";

/** Réponse de l'invité par son lien personnel : je viens (avec combien d'accompagnants), ou non. */
export function RsvpView() {
  const { t } = useI18n();
  const { id } = useParams<{ id: string }>();
  const key = useSearchParams().get("cle") ?? "";
  const [rsvp, setRsvp] = useState<Rsvp | null>(null);
  const [error, setError] = useState<unknown>(null);

  const load = useCallback(
    () =>
      api<{ data: Rsvp }>(`/public/guests/${id}?key=${encodeURIComponent(key)}`)
        .then(({ data }) => setRsvp(data))
        .catch(setError),
    [id, key],
  );

  useEffect(() => {
    void load();
  }, [load]);

  const notFound = error instanceof ApiError && error.status === 404;
  const design = rsvp?.design;
  const theme = design
    ? ({
        "--invite-primary": design.primary_color,
        "--invite-on-primary": textOn(design.primary_color),
        "--invite-accent": design.accent_color,
        "--invite-on-accent": textOn(design.accent_color),
        "--invite-font": FONT_STACKS[design.font],
      } as CSSProperties)
    : undefined;

  return (
    <div className={`${styles.page} ${ticketFontVariables}`} style={theme}>
      <header className={styles.header}>
        <Wordmark />
        <LocaleSwitch />
      </header>
      <main className={styles.main}>
        {notFound ? (
          <section className={styles.card}>
            <h1 className={styles.title}>{t.rsvp.notFoundTitle}</h1>
            <p>{t.rsvp.notFoundBody}</p>
          </section>
        ) : !rsvp ? (
          error ? <Alert tone="error">{formErrorMessage(error, t)}</Alert> : <p className={styles.muted}>{t.rsvp.loading}</p>
        ) : (
          <>
            <Invitation rsvp={rsvp} />
            <Answer rsvp={rsvp} guestId={id} guestKey={key} onUpdate={setRsvp} />
            <p className={styles.muted}>{t.rsvp.personal}</p>
          </>
        )}
        <p className={styles.footer}>
          {t.publicEvent.poweredBy} <Link href="/">kassEvent</Link>
        </p>
      </main>
    </div>
  );
}

function Invitation({ rsvp }: { rsvp: Rsvp }) {
  const { t, locale } = useI18n();
  const { event, design, guest } = rsvp;
  const banner = design.banner_url ?? event.cover_url;
  const address = [event.venue_name, event.venue_address, event.city].filter(Boolean).join(", ");

  return (
    <section className={styles.invitation} aria-labelledby="invitation-title">
      {banner && (
        // eslint-disable-next-line @next/next/no-img-element -- image de l'organisateur, de taille inconnue
        <img src={banner} alt="" className={styles.banner} />
      )}
      <div className={styles.invitationBody}>
        <p className={styles.from}>{format(t.rsvp.from, { organization: event.organization.name })}</p>
        <p className={styles.invited}>{format(t.rsvp.invited, { name: guest.name })}</p>
        <h1 id="invitation-title" className={styles.eventTitle}>
          {event.title}
        </h1>
        {design.message && <p className={styles.message}>{design.message}</p>}
        <ul className={styles.facts}>
          <li>
            <CalendarBlank size={22} aria-hidden /> {formatEventDate(event, locale)}
          </li>
          <li>
            <MapPin size={22} aria-hidden />{" "}
            <a href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`} target="_blank" rel="noopener noreferrer">
              {address}
            </a>
          </li>
        </ul>
        {event.description && <p className={styles.description}>{event.description}</p>}
      </div>
    </section>
  );
}

function Answer({ rsvp, guestId, guestKey, onUpdate }: { rsvp: Rsvp; guestId: string; guestKey: string; onUpdate: (rsvp: Rsvp) => void }) {
  const { t } = useI18n();
  const { guest } = rsvp;
  const [changing, setChanging] = useState(guest.status === "pending");
  const [attending, setAttending] = useState<boolean | null>(guest.status === "pending" ? null : guest.status === "confirmed");
  const [companions, setCompanions] = useState(guest.companions_confirmed ?? 0);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function send() {
    if (attending === null || pending) return;
    setPending(true);
    setError(null);
    try {
      const { data } = await api<{ data: Rsvp }>(`/public/guests/${guestId}/rsvp`, {
        method: "POST",
        body: { key: guestKey, attending, companions: attending ? companions : 0 },
      });
      onUpdate(data);
      setChanging(false);
    } catch (e) {
      setError(e);
    }
    setPending(false);
  }

  if (!changing && guest.status === "confirmed") {
    const party = 1 + (guest.companions_confirmed ?? 0);
    return (
      <section className={`${styles.card} ${styles.yes}`} aria-live="polite">
        <CheckCircle size={40} weight="fill" aria-hidden className={styles.okIcon} />
        <h2 className={styles.title}>{t.rsvp.confirmedTitle}</h2>
        <p>{t.rsvp.confirmedBody}</p>
        {party > 1 && <p>{format(t.rsvp.party, { count: String(party) })}</p>}
        {guest.table && <p className={styles.table}>{format(t.rsvp.table, { table: guest.table })}</p>}
        {rsvp.invitation_url && (
          <a href={rsvp.invitation_url} className={styles.primary}>
            <Ticket size={22} aria-hidden /> {t.rsvp.seeInvitation}
          </a>
        )}
        {rsvp.open && (
          <button type="button" className={styles.linkButton} onClick={() => setChanging(true)}>
            {t.rsvp.change}
          </button>
        )}
      </section>
    );
  }

  if (!changing && guest.status === "declined") {
    return (
      <section className={styles.card} aria-live="polite">
        <HandWaving size={40} aria-hidden className={styles.waveIcon} />
        <h2 className={styles.title}>{t.rsvp.declinedTitle}</h2>
        <p>{t.rsvp.declinedBody}</p>
        {rsvp.open && (
          <button type="button" className={styles.linkButton} onClick={() => setChanging(true)}>
            {t.rsvp.change}
          </button>
        )}
      </section>
    );
  }

  if (!rsvp.open) {
    return (
      <section className={styles.card}>
        <p>{t.rsvp.closed}</p>
      </section>
    );
  }

  const max = guest.companions_allowed;
  return (
    <section className={styles.card} aria-labelledby="answer-title">
      <h2 id="answer-title" className={styles.title}>
        {t.rsvp.question}
      </h2>
      <div className={styles.choices} role="radiogroup" aria-labelledby="answer-title">
        <button type="button" role="radio" aria-checked={attending === true} className={styles.choice} onClick={() => setAttending(true)}>
          {t.rsvp.yes}
        </button>
        <button type="button" role="radio" aria-checked={attending === false} className={styles.choice} onClick={() => setAttending(false)}>
          {t.rsvp.no}
        </button>
      </div>

      {attending && max > 0 && (
        <div className={styles.companions}>
          <p className={styles.label} id="companions-label">
            {t.rsvp.companions}
          </p>
          <div className={styles.stepper} role="group" aria-labelledby="companions-label">
            <button type="button" className={styles.step} aria-label="−1" disabled={companions <= 0} onClick={() => setCompanions((c) => Math.max(0, c - 1))}>
              <Minus size={22} aria-hidden />
            </button>
            <output className={styles.count} aria-live="polite">
              {companions === 0 ? t.rsvp.alone : `+${companions}`}
            </output>
            <button type="button" className={styles.step} aria-label="+1" disabled={companions >= max} onClick={() => setCompanions((c) => Math.min(max, c + 1))}>
              <Plus size={22} aria-hidden />
            </button>
          </div>
          <p className={styles.muted}>{format(t.rsvp.companionsHint, { count: String(max) })}</p>
        </div>
      )}

      {error ? <Alert tone="error">{actionErrorMessage(error, t)}</Alert> : null}
      {attending !== null && (
        <button type="button" className={styles.primary} disabled={pending} onClick={() => void send()}>
          {attending ? t.rsvp.confirm : t.rsvp.decline}
        </button>
      )}
    </section>
  );
}
