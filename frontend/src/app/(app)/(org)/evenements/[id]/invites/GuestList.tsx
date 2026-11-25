"use client";

import { useMemo, useState, useSyncExternalStore } from "react";
import { AddressBook, CopySimple, EnvelopeSimple, PencilSimple, ShareNetwork, Trash, WhatsappLogo } from "@phosphor-icons/react";
import { Alert, Button, Field } from "@/components/ui";
import { api } from "@/lib/api";
import { actionErrorMessage } from "@/lib/errors";
import { formatEventDate, type EventData } from "@/lib/events";
import { useFormSubmit } from "@/lib/form";
import { canPickContacts, parseGuestList, pickContacts, whatsAppLink, type Guest, type GuestInput, type GuestPage } from "@/lib/guests";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import type { Notice } from "./page";
import styles from "./guests.module.css";

const subscribe = () => () => {};
/** Possibilité du navigateur, lue après l'hydratation (fausse côté serveur). */
const useBrowserSupport = (check: () => boolean) => useSyncExternalStore(subscribe, check, () => false);

type Filter = "all" | "pending" | "confirmed" | "declined" | "notInvited";

type Props = { event: EventData; page: GuestPage; canManage: boolean; reload: () => Promise<void>; onNotice: (notice: Notice | null) => void };

export function GuestList({ event, page, canManage, reload, onNotice }: Props) {
  const { t } = useI18n();
  const { organization } = useOrganization();
  const [filter, setFilter] = useState<Filter>("all");
  const [query, setQuery] = useState("");
  const [editing, setEditing] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const tables = useMemo(() => new Map(page.tables.map((table) => [table.id, table.label])), [page.tables]);
  const counts: Record<Filter, number> = {
    all: page.summary.guests,
    pending: page.summary.pending,
    confirmed: page.summary.confirmed,
    declined: page.summary.declined,
    notInvited: page.summary.not_invited,
  };
  const needle = query.trim().toLocaleLowerCase();
  const shown = page.data.filter(
    (guest) =>
      (filter === "all" || (filter === "notInvited" ? guest.invited_at === null : guest.status === filter)) &&
      (needle === "" || [guest.name, guest.group_name, guest.phone, guest.email].some((v) => v?.toLocaleLowerCase().includes(needle))),
  );

  async function act(request: () => Promise<unknown>, success?: string) {
    onNotice(null);
    setBusy(true);
    try {
      await request();
      await reload();
      if (success) onNotice({ tone: "success", text: success });
    } catch (e) {
      onNotice({ tone: "error", text: actionErrorMessage(e, t) });
    }
    setBusy(false);
  }

  const post = (path: string, body: unknown) => api(path, { method: "POST", body, organizationId: organization.id });

  async function emailPending() {
    await act(async () => {
      const { sent } = await api<{ sent: number }>(`/events/${event.id}/guests/email`, { method: "POST", body: { pending: true }, organizationId: organization.id });
      onNotice({ tone: "success", text: sent > 0 ? format(t.guests.send.emailSent, { count: String(sent) }) : t.guests.send.emailNone });
    });
  }

  return (
    <div className={styles.stack}>
      {canManage && (
        <>
          <section className={styles.panel} aria-labelledby="send-title">
            <h2 id="send-title" className={styles.panelTitle}>
              {t.guests.send.title}
            </h2>
            <p className={styles.muted}>{t.guests.send.hint}</p>
            <Button type="button" variant="secondary" className={styles.wrapButton} disabled={busy || page.summary.pending === 0} onClick={() => void emailPending()}>
              <EnvelopeSimple size={20} aria-hidden /> {t.guests.send.emailPending}
            </Button>
          </section>

          <div className={styles.twoUp}>
            <details className={styles.panel}>
              <summary className={styles.summary}>{t.guests.add.title}</summary>
              <AddGuest eventId={event.id} onAdded={async (name) => {
                await reload();
                onNotice({ tone: "success", text: format(t.guests.add.added, { name }) });
              }} />
            </details>
            <details className={styles.panel}>
              <summary className={styles.summary}>{t.guests.import.title}</summary>
              <ImportGuests eventId={event.id} onImported={async (count) => {
                await reload();
                onNotice({ tone: "success", text: format(t.guests.import.imported, { count: String(count) }) });
              }} />
            </details>
          </div>
        </>
      )}

      <section aria-labelledby="list-title">
        <h2 id="list-title" className={styles.visuallyHidden}>
          {t.guests.tabGuests}
        </h2>
        <div className={styles.filters} role="group" aria-label={t.guests.tabGuests}>
          {(Object.keys(counts) as Filter[]).map((name) => (
            <button key={name} type="button" className={styles.chip} aria-pressed={filter === name} onClick={() => setFilter(name)}>
              {t.guests.filters[name]} <span className={styles.chipCount}>{counts[name]}</span>
            </button>
          ))}
        </div>
        {page.data.length > 0 && (
          <Field label={t.guests.search} type="search" value={query} onChange={(e) => setQuery(e.target.value)} autoComplete="off" />
        )}

        {page.data.length === 0 ? (
          <p className={styles.callout}>{t.guests.empty}</p>
        ) : shown.length === 0 ? (
          <p className={styles.muted}>{t.guests.noMatch}</p>
        ) : (
          <ul className={styles.guestList}>
            {shown.map((guest) =>
              editing === guest.id ? (
                <li key={guest.id} className={styles.guestRow}>
                  <EditGuest
                    guest={guest}
                    onDone={async (saved) => {
                      setEditing(null);
                      if (saved) {
                        await reload();
                        onNotice({ tone: "success", text: t.guests.saved });
                      }
                    }}
                  />
                </li>
              ) : (
                <li key={guest.id} className={styles.guestRow}>
                  <div className={styles.guestMain}>
                    <p className={styles.guestName}>
                      {guest.name}
                      <span className={styles.status} data-status={guest.status}>
                        {t.guests.status[guest.status]}
                      </span>
                    </p>
                    <p className={styles.guestMeta}>
                      {[
                        format(t.guests.party, { count: String(guest.status === "declined" ? 1 + guest.companions_allowed : guest.party) }),
                        guest.status !== "confirmed" && guest.companions_allowed > 0 ? format(t.guests.allowed, { count: String(guest.companions_allowed) }) : null,
                        guest.group_name,
                        guest.status !== "declined" ? (guest.table_id ? tables.get(guest.table_id) : t.guests.noTable) : null,
                        guest.invited_at ? format(t.guests.invitedOn, { date: shortDate(guest.reminded_at ?? guest.invited_at) }) : t.guests.notInvited,
                      ]
                        .filter(Boolean)
                        .join(" · ")}
                    </p>
                  </div>
                  {canManage && (
                    <div className={styles.guestActions} role="group" aria-label={format(t.guests.actionsFor, { name: guest.name })}>
                      {guest.rsvp_url && guest.status !== "declined" && (
                        <SendButtons guest={guest} event={event} onSent={(channel, notice) => void act(() => post(`/guests/${guest.id}/sent`, { channel }), notice)} />
                      )}
                      <button type="button" className={styles.iconButton} aria-label={t.guests.edit} title={t.guests.edit} onClick={() => setEditing(guest.id)}>
                        <PencilSimple size={20} aria-hidden />
                      </button>
                      <button type="button" className={styles.iconButton} aria-label={t.guests.remove} title={t.guests.remove} onClick={() => setConfirming(guest.id)}>
                        <Trash size={20} aria-hidden />
                      </button>
                    </div>
                  )}
                  {confirming === guest.id && (
                    <div className={styles.confirm} role="alert">
                      <p>{format(t.guests.removeConfirm, { name: guest.name })}</p>
                      <div className={styles.buttons}>
                        <Button
                          type="button"
                          variant="danger"
                          disabled={busy}
                          onClick={() => {
                            setConfirming(null);
                            void act(() => api(`/guests/${guest.id}`, { method: "DELETE", organizationId: organization.id }), format(t.guests.removed, { name: guest.name }));
                          }}
                        >
                          {t.guests.remove}
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => setConfirming(null)}>
                          {t.guests.cancel}
                        </Button>
                      </div>
                    </div>
                  )}
                </li>
              ),
            )}
          </ul>
        )}
      </section>
    </div>
  );
}

function shortDate(iso: string): string {
  return new Intl.DateTimeFormat(document.documentElement.lang || "fr", { day: "numeric", month: "short" }).format(new Date(iso));
}

/**
 * Envoi par l'organisateur lui-même : WhatsApp (message prérempli, depuis son numéro), partage du
 * téléphone (Messenger, SMS…) ou lien copié. Chaque envoi est noté pour le suivi des relances.
 */
function SendButtons({ guest, event, onSent }: { guest: Guest; event: EventData; onSent: (channel: string, notice?: string) => void }) {
  const { t, locale } = useI18n();
  const canShare = useBrowserSupport(() => typeof navigator.share === "function");
  const url = guest.rsvp_url!;
  const message = format(t.guests.message, {
    name: guest.name,
    event: event.title,
    date: formatEventDate(event, locale),
    venue: event.venue_name,
    url,
  });

  async function share() {
    try {
      await navigator.share({ title: event.title, text: message });
      onSent("share");
    } catch {
      // Partage annulé : rien n'est parti.
    }
  }

  async function copy() {
    try {
      await navigator.clipboard.writeText(message);
      onSent("copy", format(t.guests.send.copied, { name: guest.name }));
    } catch {
      // Presse-papiers refusé par le navigateur.
    }
  }

  return (
    <>
      <a
        href={whatsAppLink(guest.phone, message)}
        target="_blank"
        rel="noopener noreferrer"
        className={styles.whatsapp}
        title={guest.can_remind ? undefined : t.guests.send.remindLater}
        onClick={() => onSent("whatsapp")}
      >
        <WhatsappLogo size={20} weight="fill" aria-hidden /> {t.guests.send.whatsapp}
      </a>
      {canShare ? (
        <button type="button" className={styles.iconButton} aria-label={t.guests.send.share} title={t.guests.send.share} onClick={() => void share()}>
          <ShareNetwork size={20} aria-hidden />
        </button>
      ) : (
        <button type="button" className={styles.iconButton} aria-label={t.guests.send.copy} title={t.guests.send.copy} onClick={() => void copy()}>
          <CopySimple size={20} aria-hidden />
        </button>
      )}
    </>
  );
}

function GuestFields({ guest, fieldError }: { guest?: Guest; fieldError: (name: string) => string | undefined }) {
  const { t } = useI18n();
  return (
    <>
      <Field label={t.guests.add.name} name="name" required maxLength={120} defaultValue={guest?.name} error={fieldError("name") ?? fieldError("guests.0.name")} autoComplete="off" />
      <div className={styles.fieldPair}>
        <Field
          label={t.guests.add.phone}
          name="phone"
          type="tel"
          inputMode="tel"
          maxLength={30}
          defaultValue={guest?.phone ? `+${guest.phone}` : undefined}
          hint={t.guests.add.phoneHint}
          error={fieldError("phone") ?? fieldError("guests.0.phone")}
          autoComplete="off"
        />
        <Field
          label={t.guests.add.companions}
          name="companions_allowed"
          type="number"
          inputMode="numeric"
          min={0}
          max={20}
          defaultValue={guest?.companions_allowed ?? 0}
          hint={t.guests.add.companionsHint}
          error={fieldError("companions_allowed") ?? fieldError("guests.0.companions_allowed")}
        />
      </div>
      <div className={styles.fieldPair}>
        <Field label={t.guests.add.email} name="email" type="email" maxLength={254} defaultValue={guest?.email ?? undefined} error={fieldError("email") ?? fieldError("guests.0.email")} autoComplete="off" />
        <Field label={t.guests.add.group} name="group_name" maxLength={60} defaultValue={guest?.group_name ?? undefined} hint={t.guests.add.groupHint} error={fieldError("group_name")} autoComplete="off" />
      </div>
    </>
  );
}

function fromForm(form: FormData): GuestInput {
  const text = (name: string) => String(form.get(name) ?? "").trim() || null;
  return {
    name: String(form.get("name") ?? "").trim(),
    phone: text("phone"),
    email: text("email"),
    group_name: text("group_name"),
    companions_allowed: Number(form.get("companions_allowed") || 0),
  };
}

function AddGuest({ eventId, onAdded }: { eventId: string; onAdded: (name: string) => Promise<void> }) {
  const { t } = useI18n();
  const { organization } = useOrganization();
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    const guest = fromForm(form);
    await api(`/events/${eventId}/guests`, { method: "POST", body: { guests: [guest] }, organizationId: organization.id });
    (document.getElementById("add-guest") as HTMLFormElement | null)?.reset();
    await onAdded(guest.name);
  });

  return (
    <form id="add-guest" className={styles.form} onSubmit={submit} noValidate>
      {message && <Alert tone="error">{message}</Alert>}
      <GuestFields fieldError={fieldError} />
      <Button type="submit" disabled={pending}>
        {t.guests.add.submit}
      </Button>
    </form>
  );
}

function EditGuest({ guest, onDone }: { guest: Guest; onDone: (saved: boolean) => Promise<void> }) {
  const { t } = useI18n();
  const { organization } = useOrganization();
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    await api(`/guests/${guest.id}`, { method: "PATCH", body: fromForm(form), organizationId: organization.id });
    await onDone(true);
  });

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      {message && <Alert tone="error">{message}</Alert>}
      <GuestFields guest={guest} fieldError={fieldError} />
      <div className={styles.buttons}>
        <Button type="submit" disabled={pending}>
          {t.guests.save}
        </Button>
        <Button type="button" variant="secondary" onClick={() => void onDone(false)}>
          {t.guests.cancel}
        </Button>
      </div>
    </form>
  );
}

/** Contacts du téléphone (Android) ou liste collée, vérifiés avant l'ajout. */
function ImportGuests({ eventId, onImported }: { eventId: string; onImported: (count: number) => Promise<void> }) {
  const { t } = useI18n();
  const { organization } = useOrganization();
  const [text, setText] = useState("");
  const [picked, setPicked] = useState<GuestInput[]>([]);
  const contacts = useBrowserSupport(canPickContacts);
  const rows = [...picked, ...parseGuestList(text)];

  const { submit, pending, message, fieldError } = useFormSubmit(async () => {
    await api(`/events/${eventId}/guests`, { method: "POST", body: { guests: rows }, organizationId: organization.id });
    setText("");
    setPicked([]);
    await onImported(rows.length);
  });
  // Première ligne refusée par le serveur (« guests.3.phone ») : son nom et le message.
  const rowError = rows
    .map((row, i) => ({ name: row.name, message: ["name", "phone", "email", "companions_allowed"].map((f) => fieldError(`guests.${i}.${f}`)).find(Boolean) }))
    .find((row) => row.message);

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      {message && <Alert tone="error">{message}</Alert>}
      {contacts && (
        <Button
          type="button"
          variant="secondary"
          onClick={() => {
            pickContacts()
              .then((list) => setPicked((current) => [...current, ...list]))
              .catch(() => undefined);
          }}
        >
          <AddressBook size={20} aria-hidden /> {t.guests.import.contacts}
        </Button>
      )}
      <label className={styles.label} htmlFor="guest-list">
        {t.guests.import.pasteLabel}
      </label>
      <textarea
        id="guest-list"
        className={styles.textarea}
        rows={6}
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder={"Papa Jean ; 0812345678 ; 1 ; Famille du marié\nTante Brigitte ; 0991234567 ; 3"}
        aria-describedby="guest-list-hint"
      />
      <p id="guest-list-hint" className={styles.muted}>
        {t.guests.import.pasteHint}
      </p>
      {rowError && (
        <Alert tone="error">
          {rowError.name} : {rowError.message}
        </Alert>
      )}
      {fieldError("guests") && <Alert tone="error">{fieldError("guests")}</Alert>}
      <p className={styles.muted} aria-live="polite">
        {rows.length > 0 ? format(t.guests.import.preview, { count: String(rows.length) }) : text.trim() ? t.guests.import.nothing : null}
      </p>
      <Button type="submit" disabled={pending || rows.length === 0}>
        {t.guests.import.submit}
      </Button>
    </form>
  );
}
