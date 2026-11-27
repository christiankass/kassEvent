"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowClockwise, Check, DownloadSimple, LinkSimple, QrCode, ShieldCheck } from "@phosphor-icons/react";
import { Alert, Button, buttonClass } from "@/components/ui";
import { api, apiBlob, ApiError } from "@/lib/api";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { formatPrice } from "@/lib/events";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { SHARE_SOURCES, trackedUrl, type Source } from "@/lib/referral";
import { percent, type EventStats } from "@/lib/stats";
import { PageSkeleton } from "../../../../PageSkeleton";
import app from "../../../../app.module.css";
import events from "../../events.module.css";
import { BarChart } from "./BarChart";
import styles from "./stats.module.css";

type Metric = "revenue" | "tickets" | "visits";

/** Fichier proposé au téléchargement (export CSV, QR code). */
function download(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/**
 * Statistiques d'un événement : chiffres clés, courbe jour par jour, canaux qui font vendre (liens
 * suivis), moyens de paiement, types de billets, entrées heure par heure, export comptable.
 */
export default function StatisticsPage() {
  const { t, locale } = useI18n();
  const s = t.stats;
  const { id } = useParams<{ id: string }>();
  const { organization } = useOrganization();
  const organizationId = organization.id;

  const [stats, setStats] = useState<EventStats | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [error, setError] = useState<unknown>(null);
  const [metric, setMetric] = useState<Metric>("revenue");
  const [exporting, setExporting] = useState(false);
  const [copied, setCopied] = useState<Source | null>(null);

  const load = useCallback(
    () =>
      api<{ data: EventStats }>(`/events/${id}/stats`, { organizationId })
        .then(({ data }) => {
          setStats(data);
          setLoadError(null);
        })
        .catch(setLoadError),
    [id, organizationId],
  );

  useEffect(() => {
    load();
  }, [load]);

  if (loadError instanceof ApiError && loadError.code === "two_factor_required") {
    return (
      <main className={app.main}>
      <section className={`${app.panel} ${events.twoFactor}`} aria-labelledby="stats-title">
        <ShieldCheck size={32} aria-hidden />
        <h1 id="stats-title" className={app.sectionTitle}>
          {s.twoFactorTitle}
        </h1>
        <p className={events.sectionLede}>{format(s.twoFactorBody, { organization: organization.name })}</p>
        <Link href="/compte/securite" className={buttonClass()}>
          {s.twoFactorCta}
        </Link>
      </section>
      </main>
    );
  }
  if (loadError) {
    return (
      <main className={app.main}>
        <Alert tone="error">{loadError instanceof ApiError && loadError.status === 403 ? s.forbidden : formErrorMessage(loadError, t)}</Alert>
      </main>
    );
  }
  if (!stats) return <PageSkeleton />;

  const { summary } = stats;
  const money = (minor: number) => formatPrice(minor, stats.currency, locale);
  const number = (value: number) => new Intl.NumberFormat(locale).format(value);
  const day = new Intl.DateTimeFormat(locale, { day: "numeric", month: "short", timeZone: "UTC" });
  const longDay = new Intl.DateTimeFormat(locale, { weekday: "short", day: "numeric", month: "long", timeZone: "UTC" });
  const slug = stats.url.split("/").pop() ?? "evenement";
  const metricFormat = metric === "revenue" ? money : number;
  const best = stats.sources.find((row) => row.revenue > 0);
  const maxVisits = Math.max(...stats.sources.map((row) => row.visits), 1);
  const maxPaymentOrders = Math.max(...stats.payments.map((row) => row.orders), 1);

  async function exportCsv() {
    if (exporting) return;
    setExporting(true);
    setError(null);
    try {
      download(await apiBlob(`/events/${id}/stats/export`, organizationId), `kassevent-${slug}-ventes.csv`);
    } catch (e) {
      setError(e);
    }
    setExporting(false);
  }

  async function copy(source: Source) {
    const link = trackedUrl(stats!.url, source);
    try {
      await navigator.clipboard.writeText(link);
      setCopied(source);
      setTimeout(() => setCopied(null), 2500);
    } catch {
      window.prompt(s.links.copy, link);
    }
  }

  return (
    <main className={`${app.main} ${styles.page}`}>
      <header className={styles.header}>
        <div>
          <h1 className={app.sectionTitle}>{s.title}</h1>
          <p className={events.sectionLede}>{s.lede}</p>
        </div>
        <div className={styles.actions}>
          <button type="button" className={buttonClass("secondary")} onClick={() => load()}>
            <ArrowClockwise size={20} aria-hidden /> {s.refresh}
          </button>
          <Button type="button" onClick={exportCsv} disabled={exporting} title={s.exportHint}>
            <DownloadSimple size={20} aria-hidden /> {exporting ? s.exporting : s.export}
          </Button>
        </div>
      </header>
      {error ? <Alert tone="error">{actionErrorMessage(error, t)}</Alert> : null}

      <dl className={styles.tiles}>
        <div>
          <dt>{s.tiles.revenue}</dt>
          <dd>{money(summary.revenue)}</dd>
          <dd className={styles.tileNote}>{format(s.tiles.net, { amount: money(summary.net) })}</dd>
          {summary.average_order > 0 && <dd className={styles.tileNote}>{format(s.tiles.average, { amount: money(summary.average_order) })}</dd>}
        </div>
        <div>
          <dt>{s.tiles.tickets}</dt>
          <dd>{number(summary.tickets_sold)}</dd>
          {summary.capacity > 0 && summary.fill_rate !== null && (
            <dd className={styles.tileNote}>{format(s.tiles.ofCapacity, { capacity: number(summary.capacity), rate: percent(summary.fill_rate, locale) })}</dd>
          )}
        </div>
        <div>
          <dt>{s.tiles.visits}</dt>
          <dd>{number(summary.visits)}</dd>
          <dd className={styles.tileNote}>
            {summary.conversion === null ? s.tiles.noConversion : format(s.tiles.conversion, { rate: percent(summary.conversion, locale) })}
          </dd>
        </div>
        <div>
          <dt>{s.tiles.entries}</dt>
          <dd>{number(summary.admitted)}</dd>
          {summary.admitted === 0 || summary.attendance === null ? (
            <dd className={styles.tileNote}>{s.tiles.noEntries}</dd>
          ) : (
            <>
              <dd className={styles.tileNote}>{format(s.tiles.attendance, { rate: percent(summary.attendance, locale) })}</dd>
              {summary.people_admitted > summary.admitted && (
                <dd className={styles.tileNote}>{format(s.tiles.people, { count: number(summary.people_admitted) })}</dd>
              )}
            </>
          )}
        </div>
      </dl>

      <section className={app.panel} aria-labelledby="daily-title">
        <div className={styles.sectionHead}>
          <h2 id="daily-title" className={events.subheading}>
            {s.daily.title}
          </h2>
          <div className={styles.segmented} role="group" aria-label={s.daily.title}>
            {(["revenue", "tickets", "visits"] as const).map((key) => (
              <button key={key} type="button" aria-pressed={metric === key} onClick={() => setMetric(key)}>
                {s.daily[key]}
              </button>
            ))}
          </div>
        </div>
        {stats.daily.length === 0 ? (
          <p className={events.sectionLede}>{s.daily.empty}</p>
        ) : (
          <>
            <BarChart
              label={`${s.daily.title} · ${s.daily[metric]}`}
              formatValue={metricFormat}
              points={stats.daily.map((row) => {
                const date = new Date(`${row.date}T00:00:00Z`);
                return { key: row.date, axis: day.format(date), label: longDay.format(date), value: row[metric] };
              })}
            />
            <details className={styles.tableToggle}>
              <summary>{s.daily.table}</summary>
              <div className={styles.tableScroll}>
                <table className={styles.table}>
                  <thead>
                    <tr>
                      <th scope="col">{s.daily.date}</th>
                      <th scope="col">{s.daily.visits}</th>
                      <th scope="col">{s.daily.orders}</th>
                      <th scope="col">{s.daily.tickets}</th>
                      <th scope="col">{s.daily.revenue}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {[...stats.daily].reverse().map((row) => (
                      <tr key={row.date}>
                        <th scope="row">{longDay.format(new Date(`${row.date}T00:00:00Z`))}</th>
                        <td>{number(row.visits)}</td>
                        <td>{number(row.orders)}</td>
                        <td>{number(row.tickets)}</td>
                        <td>{money(row.revenue)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </details>
          </>
        )}
      </section>

      <div className={styles.columns}>
        <section className={app.panel} aria-labelledby="sources-title">
          <h2 id="sources-title" className={events.subheading}>
            {s.sources.title}
          </h2>
          {best && <p className={styles.insight}>{format(s.sources.best, { source: s.sources.names[best.source], amount: money(best.revenue) })}</p>}
          {stats.sources.length === 0 ? (
            <p className={events.sectionLede}>{s.sources.empty}</p>
          ) : (
            <ul className={styles.rows}>
              {stats.sources.map((row) => (
                <li key={row.source}>
                  <div className={styles.rowHead}>
                    <span className={styles.rowName}>{s.sources.names[row.source]}</span>
                    <span className={events.figures}>{money(row.revenue)}</span>
                  </div>
                  <span className={styles.meter} aria-hidden>
                    <span style={{ width: `${(row.visits / maxVisits) * 100}%` }} />
                  </span>
                  <span className={styles.rowMeta}>
                    {number(row.visits)} {s.sources.visits.toLowerCase()} · {number(row.orders)} {s.sources.orders.toLowerCase()}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className={app.panel} aria-labelledby="links-title">
          <h2 id="links-title" className={events.subheading}>
            {s.links.title}
          </h2>
          <p className={events.sectionLede}>{stats.published ? s.links.lede : s.links.draft}</p>
          <ul className={styles.links}>
            {SHARE_SOURCES.map((source) => (
              <li key={source}>
                <span className={styles.rowName}>{s.sources.names[source]}</span>
                <code className={styles.linkUrl}>?src={source}</code>
                <button type="button" className={buttonClass("secondary", styles.copy)} onClick={() => copy(source)} aria-label={`${s.links.copy} · ${s.sources.names[source]}`}>
                  {copied === source ? <Check size={18} aria-hidden /> : <LinkSimple size={18} aria-hidden />}
                  {copied === source ? s.links.copied : s.links.copy}
                </button>
              </li>
            ))}
          </ul>
          <div className={styles.qr}>
            {/* QR code produit par kassEvent (SVG), affiché tel quel. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(stats.qr_svg)}`} alt="" width={120} height={120} />
            <div>
              <h3 className={styles.qrTitle}>
                <QrCode size={20} aria-hidden /> {s.links.qrTitle}
              </h3>
              <p className={events.sectionLede}>{s.links.qrBody}</p>
              <button
                type="button"
                className={buttonClass("secondary")}
                onClick={() => download(new Blob([stats.qr_svg], { type: "image/svg+xml" }), `kassevent-${slug}-qr.svg`)}
              >
                <DownloadSimple size={18} aria-hidden /> {s.links.qrDownload}
              </button>
            </div>
          </div>
        </section>

        <section className={app.panel} aria-labelledby="payments-title">
          <h2 id="payments-title" className={events.subheading}>
            {s.payments.title}
          </h2>
          {stats.payments.length === 0 ? (
            <p className={events.sectionLede}>{s.payments.empty}</p>
          ) : (
            <ul className={styles.rows}>
              {stats.payments.map((row) => (
                <li key={`${row.method}-${row.operator}`}>
                  <div className={styles.rowHead}>
                    <span className={styles.rowName}>{row.operator ? s.payments.operator[row.operator] : s.payments.method[row.method]}</span>
                    <span className={events.figures}>{money(row.revenue)}</span>
                  </div>
                  <span className={styles.meter} aria-hidden>
                    <span style={{ width: `${(row.orders / maxPaymentOrders) * 100}%` }} />
                  </span>
                  <span className={styles.rowMeta}>{format(s.payments.orders, { count: number(row.orders) })}</span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className={app.panel} aria-labelledby="types-title">
          <h2 id="types-title" className={events.subheading}>
            {s.types.title}
          </h2>
          <ul className={styles.rows}>
            {stats.ticket_types.map((type) => (
              <li key={type.id}>
                <div className={styles.rowHead}>
                  <span className={styles.rowName}>{type.name}</span>
                  <span className={events.figures}>{money(type.revenue)}</span>
                </div>
                <span className={styles.meter} aria-hidden>
                  <span style={{ width: `${type.quantity > 0 ? Math.min(100, (type.sold / type.quantity) * 100) : 0}%` }} />
                </span>
                <span className={styles.rowMeta}>{format(s.types.soldOf, { sold: number(type.sold), quantity: number(type.quantity) })}</span>
              </li>
            ))}
          </ul>
        </section>
      </div>

      <section className={app.panel} aria-labelledby="checkins-title">
        <h2 id="checkins-title" className={events.subheading}>
          {s.checkIns.title}
        </h2>
        {stats.check_ins.length === 0 ? (
          <p className={events.sectionLede}>{s.checkIns.empty}</p>
        ) : (
          <BarChart
            label={s.checkIns.title}
            formatValue={number}
            points={stats.check_ins.map((row) => ({
              key: row.hour,
              axis: row.hour.slice(11, 16),
              label: `${longDay.format(new Date(`${row.hour.slice(0, 10)}T00:00:00Z`))} ${row.hour.slice(11, 16)}`,
              value: row.admitted,
            }))}
          />
        )}
      </section>
    </main>
  );
}
