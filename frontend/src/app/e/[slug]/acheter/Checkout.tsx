"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useRef, useState } from "react";
import { ArrowLeft, LockSimple, Minus, Plus } from "@phosphor-icons/react";
import { PaymentFields, paymentBody, type PaymentChoice } from "@/components/PaymentFields";
import { Alert, Field, Wordmark } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { formatEventDate, formatPrice, ticketPrice, type PublicEvent } from "@/lib/events";
import { useFormSubmit } from "@/lib/form";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { orderPath, type Order } from "@/lib/orders";
import { randomUuid } from "@/lib/uuid";
import styles from "./checkout.module.css";

/** Achat sans compte : billets, coordonnées, moyen de paiement, puis page de la commande (ou page de carte). */
export function Checkout({ event }: { event: PublicEvent }) {
  const { t, locale } = useI18n();
  const router = useRouter();
  const [quantities, setQuantities] = useState<Record<string, number>>(() =>
    // Un seul billet en vente : on en propose un d'office.
    Object.fromEntries(event.ticket_types.map((ticket, _, all) => [ticket.id, all.length === 1 && buyable(ticket) ? 1 : 0])),
  );
  const [payment, setPayment] = useState<PaymentChoice>({ method: "mobile_money", operator: null, phone: "" });
  // Clé d'idempotence : le même envoi répété (réponse perdue, nouvel essai) rend la même commande.
  // Une nouvelle clé dès que le panier ou les coordonnées changent.
  const attempt = useRef<{ body: string; key: string } | null>(null);

  const total = event.ticket_types.reduce((sum, ticket) => sum + ticket.price * (quantities[ticket.id] ?? 0), 0);
  const count = Object.values(quantities).reduce((sum, quantity) => sum + quantity, 0);
  const anyOnSale = event.ticket_types.some(buyable);

  const form = useFormSubmit(
    async (form) => {
      const body = {
        items: Object.entries(quantities).map(([ticket_type_id, quantity]) => ({ ticket_type_id, quantity })),
        name: form.get("name"),
        email: form.get("email"),
        whatsapp: event.delivery?.whatsapp ? form.get("whatsapp") || null : null,
        payment: total > 0 ? paymentBody(payment) : null,
      };
      const serialized = JSON.stringify(body);
      if (attempt.current?.body !== serialized) attempt.current = { body: serialized, key: randomUuid() };
      const response = await api<{ data: Order; access_key: string }>(`/public/events/${event.slug}/orders`, {
        method: "POST",
        body: { ...body, idempotency_key: attempt.current.key },
      }).catch((error: unknown) => {
        // Réponse claire du serveur (refus, réservation expirée) : le prochain envoi est une nouvelle commande.
        // Réseau coupé ou erreur serveur : la commande existe peut-être, on garde la clé.
        if (error instanceof ApiError && error.status < 500) attempt.current = null;
        throw error;
      });
      const redirect = response.data.payment?.redirect_url;
      // Carte : page de paiement sécurisée du prestataire, qui ramène ensuite sur la page de commande.
      if (redirect) window.location.assign(redirect);
      else router.push(orderPath(response.data.id, response.access_key));
    },
    { navigates: true },
  );
  const { submit, pending, fieldError } = form;
  const message = form.message ?? fieldError("items") ?? fieldError("payment");

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <Wordmark />
      </header>

      <main className={styles.main}>
        <Link href={`/e/${event.slug}`} className={styles.back}>
          <ArrowLeft size={18} aria-hidden /> {t.checkout.back}
        </Link>
        <h1 className={styles.title}>{event.title}</h1>
        <p className={styles.when}>
          {formatEventDate(event, locale)} · {event.venue_name}, {event.city}
        </p>

        {!anyOnSale ? (
          <Alert tone="error">{t.checkout.closed}</Alert>
        ) : (
          <form className={styles.form} onSubmit={submit}>
            <div aria-live="polite">{message && <Alert tone="error">{message}</Alert>}</div>

            <section className={styles.section} aria-labelledby="tickets-step">
              <h2 id="tickets-step" className={styles.step}>
                {t.checkout.ticketsTitle}
              </h2>
              <ul className={styles.tickets}>
                {event.ticket_types.map((ticket) => {
                  const quantity = quantities[ticket.id] ?? 0;
                  const max = Math.min(ticket.max_per_order, ticket.available);
                  const open = buyable(ticket);
                  const set = (next: number) => setQuantities((q) => ({ ...q, [ticket.id]: Math.max(0, Math.min(max, next)) }));
                  return (
                    <li key={ticket.id} className={styles.ticket} data-disabled={!open}>
                      <div className={styles.ticketMain}>
                        <span className={styles.ticketName}>{ticket.name}</span>
                        {ticket.description && <span className={styles.muted}>{ticket.description}</span>}
                        <span className={styles.ticketPrice}>{ticketPrice(ticket.price, event.currency, locale, t)}</span>
                        {!ticket.on_sale ? (
                          <span className={styles.note}>{t.publicEvent.notOnSale}</span>
                        ) : ticket.available === 0 ? (
                          <span className={styles.note}>{t.publicEvent.soldOut}</span>
                        ) : (
                          ticket.available <= 10 && <span className={styles.note}>{format(t.publicEvent.left, { count: String(ticket.available) })}</span>
                        )}
                        {fieldError(`items.${ticket.id}`) && <span className={styles.error}>{fieldError(`items.${ticket.id}`)}</span>}
                      </div>
                      {open && (
                        <div className={styles.stepper}>
                          <button
                            type="button"
                            onClick={() => set(quantity - 1)}
                            disabled={quantity === 0}
                            aria-label={format(t.checkout.decrease, { name: ticket.name })}
                          >
                            <Minus size={18} weight="bold" aria-hidden />
                          </button>
                          <output aria-label={format(t.checkout.quantity, { name: ticket.name })} aria-live="polite">
                            {quantity}
                          </output>
                          <button
                            type="button"
                            onClick={() => set(quantity + 1)}
                            disabled={quantity >= max}
                            aria-label={format(t.checkout.increase, { name: ticket.name })}
                          >
                            <Plus size={18} weight="bold" aria-hidden />
                          </button>
                        </div>
                      )}
                    </li>
                  );
                })}
              </ul>
            </section>

            <section className={styles.section} aria-labelledby="buyer-step">
              <h2 id="buyer-step" className={styles.step}>
                {t.checkout.buyerTitle}
              </h2>
              <div className={styles.fields}>
                <Field label={t.checkout.name} hint={t.checkout.nameHint} name="name" autoComplete="name" required minLength={2} maxLength={120} error={fieldError("name")} />
                <Field label={t.checkout.email} hint={t.checkout.emailHint} name="email" type="email" autoComplete="email" required maxLength={254} error={fieldError("email")} />
                {event.delivery?.whatsapp && (
                  <Field
                    label={t.checkout.whatsapp}
                    hint={t.checkout.whatsappHint}
                    name="whatsapp"
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    maxLength={25}
                    error={fieldError("whatsapp")}
                  />
                )}
              </div>
            </section>

            {total > 0 && (
              <section className={styles.section} aria-labelledby="payment-step">
                <h2 id="payment-step" className={styles.step}>
                  {t.checkout.paymentTitle}
                </h2>
                <PaymentFields value={payment} onChange={setPayment} error={fieldError} />
              </section>
            )}

            <div className={styles.payBar}>
              <div className={styles.payInner}>
                <div>
                  <span className={styles.totalLabel}>{t.checkout.total}</span>
                  <span className={styles.total}>{formatPrice(total, event.currency, locale)}</span>
                </div>
                <button type="submit" className={styles.pay} disabled={pending || count === 0}>
                  {count === 0
                    ? t.checkout.chooseFirst
                    : total === 0
                      ? t.checkout.reserveFree
                      : format(t.checkout.pay, { total: formatPrice(total, event.currency, locale) })}
                </button>
              </div>
              <p className={styles.secure}>
                <LockSimple size={14} aria-hidden /> {t.checkout.secure}
              </p>
            </div>
          </form>
        )}
      </main>
    </div>
  );
}

function buyable(ticket: PublicEvent["ticket_types"][number]): boolean {
  return ticket.on_sale && ticket.available > 0;
}
