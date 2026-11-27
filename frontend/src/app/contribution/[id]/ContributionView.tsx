"use client";

import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { CheckCircle, DeviceMobile, WarningCircle } from "@phosphor-icons/react";
import { PaymentFields, paymentBody, type PaymentChoice } from "@/components/PaymentFields";
import { Alert, Wordmark } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import type { Contribution } from "@/lib/contributions";
import { formErrorMessage } from "@/lib/errors";
import { formatPrice } from "@/lib/events";
import { useFormSubmit } from "@/lib/form";
import { format, type Dictionary } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "../../commande/[id]/order.module.css";

/** Toutes les 3 secondes tant que le paiement attend l'opérateur ou la carte. */
const POLL_MS = 3000;

/** Suivi d'une contribution par son lien personnel : attente du paiement, merci, ou nouvel essai. */
export function ContributionView() {
  const { t } = useI18n();
  const p = t.contributions.page;
  const { id } = useParams<{ id: string }>();
  const key = useSearchParams().get("cle") ?? "";
  const [contribution, setContribution] = useState<Contribution | null>(null);
  const [error, setError] = useState<unknown>(null);

  const load = useCallback(
    () =>
      api<{ data: Contribution }>(`/public/contributions/${id}?key=${encodeURIComponent(key)}`)
        .then(({ data }) => {
          setContribution(data);
          setError(null);
        })
        .catch(setError),
    [id, key],
  );

  useEffect(() => {
    void load();
  }, [load]);

  const paying = contribution?.status === "pending" && contribution.payment?.status === "pending";
  useEffect(() => {
    if (!paying) return;
    const timer = window.setInterval(() => void load(), POLL_MS);
    return () => window.clearInterval(timer);
  }, [paying, load]);

  const notFound = error instanceof ApiError && error.status === 404;

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <Wordmark />
      </header>
      <main className={styles.main}>
        {notFound ? (
          <section className={styles.card}>
            <h1 className={styles.title}>{p.notFoundTitle}</h1>
            <p>{p.notFoundBody}</p>
          </section>
        ) : !contribution ? (
          error ? <Alert tone="error">{formErrorMessage(error, t)}</Alert> : <p className={styles.muted}>{p.loading}</p>
        ) : (
          <>
            <p className={styles.reference}>{format(p.reference, { reference: contribution.reference })}</p>
            <State contribution={contribution} contributionKey={key} onUpdate={setContribution} />
            {contribution.payment?.simulated && contribution.status === "pending" && <p className={styles.demo}>{p.simulated}</p>}
            <Link href={`/voeux/${contribution.book.id}`} className={styles.secondary}>
              {p.back}
            </Link>
          </>
        )}
        <p className={styles.footer}>
          {t.publicEvent.poweredBy} <Link href="/">kassEvent</Link>
        </p>
      </main>
    </div>
  );
}

function State({ contribution, contributionKey, onUpdate }: { contribution: Contribution; contributionKey: string; onUpdate: (c: Contribution) => void }) {
  const { t, locale } = useI18n();
  const c = t.contributions;
  const tone = c.tones[contribution.book.tone];
  const money = (minor: number) => formatPrice(minor, contribution.currency, locale);
  const payment = contribution.payment;

  if (contribution.status === "paid") {
    return (
      <section className={`${styles.card} ${styles.success}`} aria-live="polite">
        <CheckCircle size={40} weight="fill" aria-hidden className={styles.icon} />
        <h1 className={styles.title}>{tone.thanksTitle}</h1>
        <p>{format(tone.thanksBody, { amount: money(contribution.amount), beneficiary: contribution.book.beneficiary || tone.beneficiary })}</p>
        <p className={styles.muted}>{contribution.book.event_title}</p>
      </section>
    );
  }

  if (payment?.status === "pending" && payment.method === "mobile_money") {
    return (
      <section className={`${styles.card} ${styles.waiting}`} aria-live="polite">
        <div className={styles.phone} aria-hidden>
          <DeviceMobile size={44} />
          <span className={styles.pulse} />
        </div>
        <h1 className={styles.title}>{c.page.waitingTitle}</h1>
        <p>
          {format(c.page.waitingBody, {
            total: money(payment.amount),
            phone: payment.phone ?? "",
            operator: payment.operator ? t.operators[payment.operator] : "",
          })}
        </p>
        <p className={styles.strong}>{c.page.waitingHint}</p>
        <p className={styles.muted}>{t.order.waitingNoPrompt}</p>
      </section>
    );
  }

  if (payment?.status === "pending" && payment.method === "card" && payment.redirect_url) {
    return (
      <section className={styles.card} aria-live="polite">
        <h1 className={styles.title}>{c.page.cardWaitingTitle}</h1>
        <p>{c.page.cardWaitingBody}</p>
        <a href={payment.redirect_url} className={styles.payButton}>
          {c.page.resumeCard}
        </a>
        <Retry contribution={contribution} contributionKey={contributionKey} onUpdate={onUpdate} />
      </section>
    );
  }

  return (
    <section className={styles.card} aria-live="polite">
      {payment?.status === "failed" && (
        <>
          <WarningCircle size={40} aria-hidden className={styles.iconErr} />
          <h1 className={styles.title}>{c.page.failedTitle}</h1>
          <p className={styles.strong}>{failureMessage(payment.failure_code, t)}</p>
          <p>{c.page.failedBody}</p>
        </>
      )}
      <Retry contribution={contribution} contributionKey={contributionKey} onUpdate={onUpdate} open />
    </section>
  );
}

/** Nouvel essai, avec le même moyen ou un autre : le total affiché suit les frais du moyen choisi. */
function Retry({
  contribution,
  contributionKey,
  onUpdate,
  open = false,
}: {
  contribution: Contribution;
  contributionKey: string;
  onUpdate: (c: Contribution) => void;
  open?: boolean;
}) {
  const { t, locale } = useI18n();
  const [choice, setChoice] = useState<PaymentChoice>({ method: "mobile_money", operator: contribution.payment?.operator ?? null, phone: "" });
  const total = contribution.amount + contribution.fees[choice.method];

  const form = useFormSubmit(
    async () => {
      const { data } = await api<{ data: Contribution }>(`/public/contributions/${contribution.id}/payments`, {
        method: "POST",
        body: { key: contributionKey, total, payment: paymentBody(choice) },
      });
      if (data.payment?.redirect_url) {
        window.location.assign(data.payment.redirect_url);
        return;
      }
      onUpdate(data);
    },
  );
  const generalError = form.fieldError("payment") ?? form.fieldError("total") ?? form.message;

  const body = (
    <form className={styles.retry} onSubmit={form.submit}>
      <div aria-live="polite">{generalError && <Alert tone="error">{generalError}</Alert>}</div>
      <PaymentFields value={choice} onChange={setChoice} error={form.fieldError} />
      <p className={styles.muted}>
        {format(t.contributions.page.breakdown, {
          amount: formatPrice(contribution.amount, contribution.currency, locale),
          beneficiary: contribution.book.beneficiary || t.contributions.tones[contribution.book.tone].beneficiary,
          fee: formatPrice(contribution.fees[choice.method], contribution.currency, locale),
        })}
      </p>
      <button type="submit" className={styles.payButton} disabled={form.pending}>
        {format(t.contributions.pay, { total: formatPrice(total, contribution.currency, locale) })}
      </button>
    </form>
  );

  return open ? (
    <>
      <h2 className={styles.subtitle}>{t.contributions.page.retryTitle}</h2>
      {body}
    </>
  ) : (
    <details className={styles.details}>
      <summary>{t.contributions.page.retryTitle}</summary>
      {body}
    </details>
  );
}

function failureMessage(code: string | null, t: Dictionary): string {
  const failures = t.order.failures as Record<string, string>;
  return (code && failures[code]) || failures.other;
}
