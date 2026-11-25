"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState, type CSSProperties, type FormEvent } from "react";
import { CalendarBlank, CheckCircle, Confetti, LockSimple } from "@phosphor-icons/react";
import { Alert, Field, LocaleSwitch, TextArea, Wordmark } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { FONT_STACKS, textOn } from "@/lib/designs";
import { formErrorMessage } from "@/lib/errors";
import { formatEventDate } from "@/lib/events";
import { ticketFontVariables } from "@/lib/ticket-fonts";
import { formatWishDate, type PublicWishBook } from "@/lib/wishes";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "./wishes.module.css";

/** Livre de vœux public : les proches écrivent leur nom et leur message, sans compte. */
export function WishBookView() {
  const { t } = useI18n();
  const { id } = useParams<{ id: string }>();
  const [book, setBook] = useState<PublicWishBook | null>(null);
  const [error, setError] = useState<unknown>(null);

  const load = useCallback(
    () =>
      api<{ data: PublicWishBook }>(`/public/wish-books/${id}`)
        .then(({ data }) => setBook(data))
        .catch(setError),
    [id],
  );

  useEffect(() => {
    void load();
  }, [load]);

  const notFound = error instanceof ApiError && error.status === 404;
  const design = book?.design;
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
            <h1 className={styles.title}>{t.wishes.page.notFoundTitle}</h1>
            <p>{t.wishes.page.notFoundBody}</p>
          </section>
        ) : !book ? (
          error ? <Alert tone="error">{formErrorMessage(error, t)}</Alert> : <p className={styles.muted}>{t.wishes.page.loading}</p>
        ) : (
          <>
            <Hero book={book} />
            {book.open ? <WishForm bookId={id} onSent={load} /> : <section className={styles.card}><p>{t.wishes.page.closed}</p></section>}
            <Wall book={book} />
          </>
        )}
        <p className={styles.footer}>
          {t.publicEvent.poweredBy} <Link href="/">kassEvent</Link>
        </p>
      </main>
    </div>
  );
}

function Hero({ book }: { book: PublicWishBook }) {
  const { t, locale } = useI18n();
  const { event, design } = book;
  const banner = design.banner_url ?? event.cover_url;

  return (
    <section className={styles.hero} aria-labelledby="wishes-title">
      {banner && (
        // eslint-disable-next-line @next/next/no-img-element -- image de l'organisateur, de taille inconnue
        <img src={banner} alt="" className={styles.banner} />
      )}
      <div className={styles.heroBody}>
        <p className={styles.from}>
          <Confetti size={18} aria-hidden /> {format(t.wishes.page.from, { organization: event.organization.name })}
        </p>
        <h1 id="wishes-title" className={styles.eventTitle}>
          {event.title}
        </h1>
        <p className={styles.date}>
          <CalendarBlank size={20} aria-hidden /> {formatEventDate(event, locale)}
        </p>
        {design.message && <p className={styles.message}>{design.message}</p>}
      </div>
    </section>
  );
}

function WishForm({ bookId, onSent }: { bookId: string; onSent: () => Promise<void> }) {
  const { t } = useI18n();
  const p = t.wishes.page;
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [sent, setSent] = useState(false);

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (pending) return;
    const form = new FormData(e.currentTarget);
    setPending(true);
    setError(null);
    try {
      await api(`/public/wish-books/${bookId}/wishes`, {
        method: "POST",
        body: { name: form.get("name"), message: form.get("message"), website: form.get("website") },
      });
      setSent(true);
      await onSent();
    } catch (err) {
      setError(err);
    }
    setPending(false);
  }

  if (sent) {
    return (
      <section className={`${styles.card} ${styles.thanks}`} aria-live="polite">
        <CheckCircle size={40} weight="fill" aria-hidden className={styles.okIcon} />
        <h2 className={styles.title}>{p.thanksTitle}</h2>
        <p>{p.thanksBody}</p>
        <button type="button" className={styles.linkButton} onClick={() => setSent(false)}>
          {p.another}
        </button>
      </section>
    );
  }

  const fieldError = (name: string) => (error instanceof ApiError ? error.field(name) : undefined);
  return (
    <form className={styles.card} onSubmit={submit} aria-labelledby="wish-form-title">
      <h2 id="wish-form-title" className={styles.title}>
        {p.intro}
      </h2>
      <Field label={p.name} hint={p.nameHint} name="name" autoComplete="name" required minLength={2} maxLength={60} error={fieldError("name")} />
      <TextArea label={p.message} name="message" required minLength={2} maxLength={1000} rows={5} error={fieldError("message")} />
      {/* Champ piège pour les robots : invisible et ignoré des lecteurs d'écran. */}
      <div className={styles.honeypot} aria-hidden="true">
        <label>
          {p.honeypot}
          <input type="text" name="website" tabIndex={-1} autoComplete="off" />
        </label>
      </div>
      {formErrorMessage(error, t) && <Alert tone="error">{formErrorMessage(error, t)}</Alert>}
      <button type="submit" className={styles.primary} disabled={pending}>
        {pending ? p.sending : p.submit}
      </button>
    </form>
  );
}

function Wall({ book }: { book: PublicWishBook }) {
  const { t, locale } = useI18n();
  const p = t.wishes.page;

  if (!book.public) {
    return (
      <p className={styles.private}>
        <LockSimple size={18} aria-hidden /> {p.private}
      </p>
    );
  }

  return (
    <section aria-labelledby="wall-title" className={styles.wall}>
      <h2 id="wall-title" className={styles.wallTitle}>
        {p.wall} <span className={styles.muted}>{format(p.wallCount, { count: String(book.count) })}</span>
      </h2>
      {book.wishes.length === 0 ? (
        <p className={styles.muted}>{p.wallEmpty}</p>
      ) : (
        <ul className={styles.wishes}>
          {book.wishes.map((wish, index) => (
            <li key={`${wish.created_at}-${index}`} className={styles.wish}>
              <p className={styles.wishMessage}>{wish.message}</p>
              <p className={styles.wishMeta}>
                <strong>{wish.name}</strong> · {formatWishDate(wish.created_at, locale)}
              </p>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
