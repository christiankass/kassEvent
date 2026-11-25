"use client";

import { FORMATS } from "@/lib/canvas";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { CheckCircle, DeviceMobile, Printer, WarningCircle } from "@phosphor-icons/react";
import { TicketCard } from "@/components/TicketCard";
import { PaymentFields, paymentBody, type PaymentChoice } from "@/components/PaymentFields";
import { Alert, Wordmark } from "@/components/ui";
import { api, ApiError, API_URL } from "@/lib/api";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { formatEventDate, formatPrice } from "@/lib/events";
import { format, type Dictionary } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import type { TicketDesign } from "@/lib/designs";
import { DELIVERY_IN_PROGRESS, type Order } from "@/lib/orders";
import { Deliveries } from "./Deliveries";
import styles from "./order.module.css";

/** Toutes les 3 secondes tant qu'un paiement attend la confirmation de l'opérateur ou de la carte. */
const POLL_MS = 3000;
/** Envoi WhatsApp ou Messenger en cours : plus lent (il peut attendre de nouveaux essais). */
const DELIVERY_POLL_MS = 10000;

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

  // Paiement en attente, ou envoi WhatsApp / Messenger en cours (Messenger : la personne ouvre la conversation).
  const paying = order?.status === "pending" && order.payment?.status === "pending";
  const delivering = order?.status === "paid" && order.deliveries.some((delivery) => DELIVERY_IN_PROGRESS.includes(delivery.status));
  const pollMs = paying ? POLL_MS : delivering ? DELIVERY_POLL_MS : null;
  useEffect(() => {
    if (pollMs === null) return;
    const timer = window.setInterval(() => void load(), pollMs);
    return () => window.clearInterval(timer);
  }, [pollMs, load]);

  // Retour sur la page depuis Messenger : statut de l'envoi à jour.
  const awaitingMessenger = order?.deliveries.some((delivery) => delivery.status === "awaiting_contact") ?? false;
  useEffect(() => {
    if (!awaitingMessenger) return;
    const refresh = () => {
      if (document.visibilityState === "visible") void load();
    };
    document.addEventListener("visibilitychange", refresh);
    return () => document.removeEventListener("visibilitychange", refresh);
  }, [awaitingMessenger, load]);

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
            {order.status === "paid" && <Deliveries order={order} orderKey={key} onUpdate={setOrder} />}
            {order.status === "paid" && order.design && <Tickets order={order} design={order.design} orderKey={key} />}
            {!(order.invitation && order.total === 0) && <Summary order={order} />}
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
        <h1 className={styles.title}>{order.invitation ? t.order.invitationTitle : t.order.paidTitle}</h1>
        <p>{order.buyer_email ? format(t.order.paidBody, { email: order.buyer_email }) : t.order.paidBodyNoEmail}</p>
        <button type="button" className={styles.secondary} onClick={() => void printTickets()}>
          <Printer size={18} aria-hidden /> {t.order.print}
        </button>
        <p className={styles.muted}>{t.order.printHint}</p>
      </section>
    );
  }

  if (order.status === "cancelled") {
    return (
      <section className={styles.card}>
        <WarningCircle size={40} aria-hidden className={styles.iconWarn} />
        <h1 className={styles.title}>{t.order.cancelledTitle}</h1>
        <p>{t.order.cancelledBody}</p>
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
    method: "mobile_money",
    operator: order.payment?.operator ?? null,
    phone: "",
  });
  const [pending, setPending] = useState(false);
  // Verrou synchrone : un double clic part avant que React ait désactivé le bouton.
  const busy = useRef(false);
  const [error, setError] = useState<unknown>(null);
  const fieldError = (name: string) => (error instanceof ApiError && error.status === 422 ? error.field(name) : undefined);

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (busy.current) return;
    busy.current = true;
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
    busy.current = false;
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

/** Les billets du studio se chargent à la demande : on les charge tous avant d'ouvrir l'impression. */
async function printTickets() {
  const images = Array.from(document.querySelectorAll<HTMLImageElement>("#billets img[loading='lazy']"));
  for (const image of images) image.loading = "eager";
  await Promise.all(
    images
      .filter((image) => !image.complete)
      .map((image) => new Promise((done) => {
        image.addEventListener("load", done, { once: true });
        image.addEventListener("error", done, { once: true });
      })),
  );
  window.print();
}

function failureMessage(code: string | null, t: Dictionary): string {
  const failures = t.order.failures as Record<string, string>;
  return (code && failures[code]) || failures.other;
}

/** Billets payés, aux couleurs choisies par l'organisateur dans l'atelier. */
function Tickets({ order, design, orderKey }: { order: Order; design: TicketDesign; orderKey: string }) {
  const { t, locale } = useI18n();
  const title = design.kind === "invitation" ? t.order.invitationsTitle : t.order.ticketsTitle;

  return (
    <section id="billets" aria-labelledby="tickets-title" className={styles.ticketsSection}>
      <h2 id="tickets-title" className={styles.subtitle}>
        {format(title, { count: String(order.tickets.length) })}
      </h2>
      <ol className={styles.tickets}>
        {order.tickets.map((ticket) => (
          <li key={ticket.code}>
            {design.mode === "canvas" ? (
              // Billet du studio : l'image vient du serveur, QR code et nom de l'invité compris.
              // eslint-disable-next-line @next/next/no-img-element -- image privée, propre à chaque billet
              <img
                className={styles.ticketImage}
                src={`${API_URL}/public/orders/${order.id}/tickets/${ticket.code}?key=${encodeURIComponent(orderKey)}`}
                alt={format(t.order.ticketImageAlt, { holder: ticket.holder_name, code: ticket.code })}
                width={(design.canvas_size ?? FORMATS.portrait)[0]}
                height={(design.canvas_size ?? FORMATS.portrait)[1]}
                loading="lazy"
                decoding="async"
              />
            ) : (
              <TicketCard
                design={design}
                organization={order.event.organization.name}
                title={order.event.title}
                when={formatEventDate(order.event, locale)}
                where={`${order.event.venue_name}, ${order.event.city}`}
                type={ticket.name}
                holder={ticket.holder_name}
                qr={ticket.qr}
                code={ticket.code}
              />
            )}
          </li>
        ))}
      </ol>
    </section>
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
        {order.items.map((item, index) => (
          <li key={`${index}-${item.name}`}>
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
