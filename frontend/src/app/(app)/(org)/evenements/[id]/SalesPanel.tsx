"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ShieldCheck } from "@phosphor-icons/react";
import { Alert, buttonClass } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { formErrorMessage } from "@/lib/errors";
import { formatPrice } from "@/lib/events";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import type { SalesOrder, SalesSummary } from "@/lib/orders";
import app from "../../../app.module.css";
import styles from "../events.module.css";

type Sales = { data: SalesOrder[]; summary: SalesSummary };

/** Ventes de l'événement : montant encaissé, billets vendus par type, dernières commandes. */
export function SalesPanel({ eventId }: { eventId: string }) {
  const { t, locale } = useI18n();
  const { organization } = useOrganization();
  const [sales, setSales] = useState<Sales | null>(null);
  const [error, setError] = useState<unknown>(null);

  useEffect(() => {
    api<Sales>(`/events/${eventId}/orders`, { organizationId: organization.id }).then(setSales).catch(setError);
  }, [eventId, organization.id]);

  if (error instanceof ApiError && error.code === "two_factor_required") {
    return (
      <section className={`${app.panel} ${styles.twoFactor}`} aria-labelledby="sales-title">
        <ShieldCheck size={32} aria-hidden />
        <h2 id="sales-title" className={app.sectionTitle}>
          {t.sales.twoFactorTitle}
        </h2>
        <p className={styles.sectionLede}>{format(t.sales.twoFactorBody, { organization: organization.name })}</p>
        <Link href="/compte/securite" className={buttonClass()}>
          {t.sales.twoFactorCta}
        </Link>
      </section>
    );
  }

  const money = (minor: number) => formatPrice(minor, sales?.summary.currency ?? "USD", locale);
  const date = new Intl.DateTimeFormat(locale, { dateStyle: "short", timeStyle: "short" });

  return (
    <section aria-labelledby="sales-title">
      <h2 id="sales-title" className={app.sectionTitle}>
        {t.sales.title}
      </h2>
      {error ? (
        <Alert tone="error">{formErrorMessage(error, t)}</Alert>
      ) : !sales ? (
        <p className={styles.sectionLede}>{t.common.loading}</p>
      ) : (
        <>
          <dl className={styles.stats}>
            <div>
              <dt>{t.sales.revenue}</dt>
              <dd>{money(sales.summary.revenue)}</dd>
            </div>
            <div>
              <dt>{t.sales.sold}</dt>
              <dd>{sales.summary.tickets_sold}</dd>
            </div>
            <div>
              <dt>{t.sales.orders}</dt>
              <dd>{sales.summary.orders}</dd>
            </div>
          </dl>

          <h3 className={styles.subheading}>{t.sales.byType}</h3>
          <ul className={styles.progressList}>
            {sales.summary.ticket_types.map((type) => (
              <li key={type.id}>
                <div className={styles.progressLabel}>
                  <span>{type.name}</span>
                  <span className={styles.figures}>{format(t.sales.soldOf, { sold: String(type.sold), quantity: String(type.quantity) })}</span>
                </div>
                <progress max={type.quantity} value={type.sold} className={styles.progress} aria-label={type.name} />
              </li>
            ))}
          </ul>

          <h3 className={styles.subheading}>{t.sales.recent}</h3>
          {sales.data.length === 0 ? (
            <p className={styles.sectionLede}>{t.sales.empty}</p>
          ) : (
            <ul className={styles.orders}>
              {sales.data.map((order) => (
                <li key={order.id} className={styles.orderRow}>
                  <div className={styles.orderMain}>
                    <span className={styles.orderBuyer}>{order.buyer_name}</span>
                    <span className={styles.orderMeta}>
                      {order.items.map((item) => `${item.quantity} × ${item.name}`).join(", ")} ·{" "}
                      {t.sales.method[order.total === 0 ? "free" : (order.payment_method ?? "free")]} · {date.format(new Date(order.created_at))}
                    </span>
                    <span className={styles.orderRef}>{order.reference}</span>
                  </div>
                  <div className={styles.orderSide}>
                    <span className={styles.figures}>{money(order.total)}</span>
                    <span className={styles.orderStatus} data-status={order.status}>
                      {t.sales.status[order.status]}
                    </span>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </section>
  );
}
