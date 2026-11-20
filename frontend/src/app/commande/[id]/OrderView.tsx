"use client";

import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { CheckCircle, DeviceMobile, Printer, WarningCircle } from "@phosphor-icons/react";
import { PaymentFields, paymentBody, type PaymentChoice } from "@/components/PaymentFields";
import { Alert, Wordmark } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { formatEventDate, formatPrice } from "@/lib/events";
import { format, type Dictionary } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import type { Order } from "@/lib/orders";
import styles from "./order.module.css";

/** Toutes les 3 secondes tant qu'un paiement attend la confirmation de l'opérateur ou de la carte. */
const POLL_MS = 3000;

export function OrderView() {
  const { t } = useI18n();
  const { id } = useParams<{ id: string }>();
  const key = useSearchParams().get("cle") ?? "";
  const [order, setOrder] = useState<Order | null>(null);
  const [error, setError] = useState<unknown>(null);

  const load = useCallback(
    () =>
      api<{ data: Order }>(`/public/orders/${id}?key=${encodeURIComponent(key)}`)
        .then(({ data }) => {
          setOrder(data);
          setError(null);
        })
        .catch(setError),
    [id, key],
  );

  useEffect(() => {
    void load();
  }, [load]);

  const waiting = order?.status === "pending" && order.payment?.status === "pending";
  useEffect(() => {
    if (!waiting) return;
    const timer = window.setInterval(() => void load(), POLL_MS);
    return () => window.clearInterval(timer);
  }, [waiting, load]);

  const notFound = error instanceof ApiError && error.status === 404;

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <Wordmark />
      </header>
      <main className={styles.main}>
        {notFound ? (
          <section className={styles.card}>
            <h1 className={styles.title}>{t.order.notFoundTitle}</h1>
            <p>{t.order.notFoundBody}</p>
          </section>
        ) : !order ? (
          error ? <Alert tone="error">{formErrorMessage(error, t)}</Alert> : <p className={styles.muted}>{t.order.loading}</p>
        ) : (
          <>
            <p className={styles.reference}>{format(t.order.reference, { reference: order.reference })}</p>
            <OrderState order={order} orderKey={key} onUpdate={setOrder} />
            {order.payment?.simulated && order.status === "pending" && <p className={styles.demo}>{t.order.simulated}</p>}
            {order.status === "paid" && <Tickets order={order} />}
            <Summary order={order} />
          </>
        )}
        {error && order ? <p className={styles.muted}>{formErrorMessage(error, t)}</p> : null}
        <p className={styles.footer}>
          {t.publicEvent.poweredBy} <Link href="/">kassEvent</Link>
        </p>
      </main>
    </div>
  );
}

function OrderState({ order, orderKey, onUpdate }: { order: Order; orderKey: string; onUpdate: (order: Order) => void }) {
  const { t, locale } = useI18n();
  const total = formatPrice(order.total, order.currency, locale);
  const payment = order.payment;
  const holdUntil = new Intl.DateTimeFormat(locale, { timeStyle: "short" }).format(new Date(order.expires_at));

  if (order.status === "paid") {
    return (
      <section className={`${styles.card} ${styles.success}`} aria-live="polite">
        <CheckCircle size={40} weight="fill" aria-hidden className={styles.icon} />
        <h1 className={styles.title}>{t.order.paidTitle}</h1>
        <p>{format(t.order.paidBody, { email: order.buyer_email })}</p>
        <button type="button" className={styles.secondary} onClick={() => window.print()}>
          <Printer size={18} aria-hidden /> {t.order.print}
        </button>
      </section>
    );
  }

  if (order.status === "refund_due") {
    return (
      <section className={styles.card} aria-live="polite">
        <WarningCircle size={40} aria-hidden className={styles.iconWarn} />
        <h1 className={styles.title}>{t.order.refundTitle}</h1>
        <p>{t.order.refundBody}</p>
      </section>
    );
  }

  if (order.status === "expired") {
    return (
      <section className={styles.card} aria-live="polite">
        <WarningCircle size={40} aria-hidden className={styles.iconWarn} />
        <h1 className={styles.title}>{t.order.expiredTitle}</h1>
        <p>{t.order.expiredBody}</p>
        <Link href={`/e/${order.event.slug}/acheter`} className={styles.primary}>
          {t.order.restart}
        </Link>
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
        <h1 className={styles.title}>{t.order.waitingTitle}</h1>
        <p>
          {format(t.order.waitingBody, {
            total,
            phone: payment.phone ?? "",
            operator: payment.operator ? t.operators[payment.operator] : "",
          })}
        </p>
        <p className={styles.strong}>{t.order.waitingHint}</p>
        <p className={styles.muted}>{t.order.waitingNoPrompt}</p>
        <p className={styles.muted}>{format(t.order.holdUntil, { time: holdUntil })}</p>
      </section>
    );
  }

  if (payment?.status === "pending" && payment.method === "card" && payment.redirect_url) {
    return (
      <section className={styles.card} aria-live="polite">
        <h1 className={styles.title}>{t.order.cardWaitingTitle}</h1>
        <p>{t.order.cardWaitingBody}</p>
        <a href={payment.redirect_url} className={styles.payButton}>
          {t.order.resumeCard}
        </a>
        <p className={styles.muted}>{format(t.order.holdUntil, { time: holdUntil })}</p>
        <Retry order={order} orderKey={orderKey} onUpdate={onUpdate} />
      </section>
    );
  }

  return (
    <section className={styles.card} aria-live="polite">
      {payment?.status === "failed" && (
        <>
          <WarningCircle size={40} aria-hidden className={styles.iconErr} />
          <h1 className={styles.title}>{t.order.failedTitle}</h1>
          <p className={styles.strong}>{failureMessage(payment.failure_code, t)}</p>
          <p>{t.order.failedBody}</p>
        </>
      )}
      <p className={styles.muted}>{format(t.order.holdUntil, { time: holdUntil })}</p>
      <Retry order={order} orderKey={orderKey} onUpdate={onUpdate} open />
    </section>
  );
}

/** Nouvel essai de paiement, avec le même moyen ou un autre. */
function Retry({ order, orderKey, onUpdate, open = false }: { order: Order; orderKey: string; onUpdate: (order: Order) => void; open?: boolean }) {
  const { t, locale } = useI18n();
  const [choice, setChoice] = useState<PaymentChoice>({
    method: order.payment?.method === "card" ? "mobile_money" : (order.payment?.method ?? "mobile_money"),
    operator: order.payment?.operator ?? null,
    phone: "",
  });
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const fieldError = (name: string) => (error instanceof ApiError && error.status === 422 ? error.field(name) : undefined);

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    try {
      const { data } = await api<{ data: Order }>(`/public/orders/${order.id}/payments`, {
        method: "POST",
        body: { key: orderKey, payment: paymentBody(choice) },
      });
      if (data.payment?.redirect_url) {
        window.location.assign(data.payment.redirect_url);
        return;
      }
      onUpdate(data);
    } catch (caught) {
      setError(caught);
    }
    setPending(false);
  }

  const body = (
    <form className={styles.retry} onSubmit={submit}>
      <div aria-live="polite">{error != null && !fieldError("payment.phone") && <Alert tone="error">{actionErrorMessage(error, t)}</Alert>}</div>
      <PaymentFields value={choice} onChange={setChoice} error={fieldError} />
      <button type="submit" className={styles.payButton} disabled={pending}>
        {format(t.order.retry, { total: formatPrice(order.total, order.currency, locale) })}
      </button>
    </form>
  );

  return open ? (
    <>
      <h2 className={styles.subtitle}>{t.order.retryTitle}</h2>
      {body}
    </>
  ) : (
    <details className={styles.details}>
      <summary>{t.order.retryTitle}</summary>
      {body}
    </details>
  );
}

function failureMessage(code: string | null, t: Dictionary): string {
  const failures = t.order.failures as Record<string, string>;
  return (code && failures[code]) || failures.other;
}

/** Billets payés : le billet signature (en-tête vert, ligne perforée, QR code sur fond jaune pâle). */
function Tickets({ order }: { order: Order }) {
  const { t, locale } = useI18n();
  const when = formatEventDate(order.event, locale);

  return (
    <ol className={styles.tickets}>
      {order.tickets.map((ticket) => (
        <li key={ticket.code} className={styles.ticket}>
          <div className={styles.ticketHead}>
            <span className={styles.organizer}>{order.event.organization.name}</span>
            <span className={styles.eventTitle}>{order.event.title}</span>
          </div>
          <dl className={styles.ticketGrid}>
            <div>
              <dt>{t.order.ticketWhen}</dt>
              <dd className={styles.capitalize}>{when}</dd>
            </div>
            <div>
              <dt>{t.order.ticketWhere}</dt>
              <dd>
                {order.event.venue_name}, {order.event.city}
              </dd>
            </div>
            <div>
              <dt>{t.order.ticketType}</dt>
              <dd>{ticket.name}</dd>
            </div>
            <div>
              <dt>{t.order.ticketHolder}</dt>
              <dd>{ticket.holder_name}</dd>
            </div>
          </dl>
          <div className={styles.stub}>
            {/* QR code généré par kassEvent (SVG en data URL) : pas d'optimisation d'image à faire. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={ticket.qr} alt={format(t.order.qrAlt, { code: ticket.code })} width={200} height={200} className={styles.qr} />
            <span className={styles.code}>{ticket.code}</span>
          </div>
        </li>
      ))}
    </ol>
  );
}

function Summary({ order }: { order: Order }) {
  const { t, locale } = useI18n();
  const price = (minor: number) => formatPrice(minor, order.currency, locale);

  return (
    <section className={styles.summary} aria-labelledby="summary-title">
      <h2 id="summary-title" className={styles.subtitle}>
        {t.order.summary}
      </h2>
      <p className={styles.summaryEvent}>
        <strong>{order.event.title}</strong>
        <br />
        <span className={`${styles.muted} ${styles.capitalize}`}>{formatEventDate(order.event, locale)}</span>
      </p>
      <ul className={styles.lines}>
        {order.items.map((item) => (
          <li key={item.name}>
            <span>
              {item.quantity} × {item.name}
            </span>
            <span className={styles.amount}>{price(item.quantity * item.unit_price)}</span>
          </li>
        ))}
        <li className={styles.totalLine}>
          <span>{t.order.total}</span>
          <span className={styles.amount}>{price(order.total)}</span>
        </li>
      </ul>
    </section>
  );
}
