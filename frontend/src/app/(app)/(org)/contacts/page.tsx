"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { MagnifyingGlass, ShieldCheck, Trash } from "@phosphor-icons/react";
import { Alert, buttonClass } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { formatPhone, type Contact, type ContactFilter, type ContactsPage } from "@/lib/contacts";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { PageSkeleton } from "../../PageSkeleton";
import app from "../../app.module.css";
import styles from "./contacts.module.css";

const FILTERS: ContactFilter[] = ["all", "reachable", "no_consent", "unsubscribed"];

/**
 * Carnet de contacts : acheteurs et invités de l'organisation, avec leur accord pour recevoir des
 * nouvelles. Recherche, filtres, suppression à la demande de la personne.
 */
export default function ContactsPage() {
  const { t, locale } = useI18n();
  const a = t.audience;
  const { organization, permissions } = useOrganization();
  const organizationId = organization.id;
  const canDelete = permissions.includes("events.manage");

  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState<ContactFilter>("all");
  const [page, setPage] = useState<ContactsPage | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [error, setError] = useState<unknown>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  // Numéro de la liste affichée : une réponse d'un ancien filtre ou d'une ancienne recherche est ignorée.
  const generation = useRef(0);

  const fetchPage = useCallback(
    (number: number) => {
      const params = new URLSearchParams({ filter, page: String(number) });
      if (query) params.set("search", query);
      return api<ContactsPage>(`/contacts?${params}`, { organizationId });
    },
    [filter, query, organizationId],
  );

  const reload = useCallback(() => {
    const current = ++generation.current;
    return fetchPage(1).then(
      (result) => {
        if (current !== generation.current) return;
        setPage(result);
        setLoadError(null);
      },
      (e) => {
        if (current === generation.current) setLoadError(e);
      },
    );
  }, [fetchPage]);

  useEffect(() => {
    void reload();
  }, [reload]);

  // La recherche part quand on s'arrête de taper.
  useEffect(() => {
    const timer = setTimeout(() => setQuery(search.trim()), 300);
    return () => clearTimeout(timer);
  }, [search]);

  async function more() {
    if (!page || loadingMore) return;
    const current = generation.current;
    setLoadingMore(true);
    try {
      const next = await fetchPage(page.meta.current_page + 1);
      if (current === generation.current) setPage((shown) => shown && { ...next, data: [...shown.data, ...next.data] });
    } catch (e) {
      setError(e);
    }
    setLoadingMore(false);
  }

  async function remove(contact: Contact) {
    if (!window.confirm(format(a.removeConfirm, { name: contact.name }))) return;
    setError(null);
    try {
      await api(`/contacts/${contact.id}`, { method: "DELETE", organizationId });
      await reload();
      setNotice(a.removed);
    } catch (e) {
      setError(e);
    }
  }

  if (loadError instanceof ApiError && loadError.code === "two_factor_required") {
    return (
      <main className={app.main}>
        <section className={`${app.panel} ${styles.gate}`} aria-labelledby="gate-title">
          <ShieldCheck size={32} aria-hidden />
          <h2 id="gate-title" className={app.sectionTitle}>
            {t.sales.twoFactorTitle}
          </h2>
          <p className={app.muted}>{a.twoFactor}</p>
          <Link href="/compte/securite" className={buttonClass()}>
            {t.sales.twoFactorCta}
          </Link>
        </section>
      </main>
    );
  }
  const forbidden = loadError instanceof ApiError && loadError.status === 403;
  if (loadError && (forbidden || !page)) {
    return (
      <main className={app.main}>
        <Alert tone="error">{forbidden ? a.forbidden : formErrorMessage(loadError, t)}</Alert>
      </main>
    );
  }
  if (!page) return <PageSkeleton />;

  const { summary } = page;
  const number = (value: number) => new Intl.NumberFormat(locale).format(value);
  const date = new Intl.DateTimeFormat(locale, { dateStyle: "medium" });

  return (
    <main className={`${app.main} ${styles.page}`}>
      <header>
        <h1 className={app.sectionTitle}>{a.title}</h1>
        <p className={styles.lede}>{a.lede}</p>
      </header>

      <dl className={styles.tiles}>
        {(
          [
            [a.total, summary.total],
            [a.reachable, summary.reachable],
            [a.withPhone, summary.with_phone],
            [a.unsubscribed, summary.unsubscribed],
          ] as const
        ).map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{number(value)}</dd>
          </div>
        ))}
      </dl>

      <div className={styles.toolbar}>
        <label className={styles.search}>
          <MagnifyingGlass size={20} aria-hidden />
          <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={a.search} aria-label={a.search} maxLength={120} />
        </label>
        <div className={styles.filters} role="group" aria-label={a.title}>
          {FILTERS.map((key) => (
            <button key={key} type="button" aria-pressed={filter === key} onClick={() => setFilter(key)}>
              {a.filters[key]}
            </button>
          ))}
        </div>
      </div>

      {/* Échec d'un nouveau filtre ou d'une recherche : la barre reste là pour réessayer. */}
      {loadError ? <Alert tone="error">{formErrorMessage(loadError, t)}</Alert> : null}
      {notice && <Alert tone="success">{notice}</Alert>}
      {error ? <Alert tone="error">{actionErrorMessage(error, t)}</Alert> : null}

      {page.data.length === 0 ? (
        <p className={styles.empty}>{summary.total === 0 ? a.empty : a.noMatch}</p>
      ) : (
        <ul className={styles.list}>
          {page.data.map((contact) => {
            const status = contact.unsubscribed_at ? "unsubscribed" : contact.reachable ? "reachable" : "none";
            return (
              <li key={contact.id} className={styles.row}>
                <div className={styles.main}>
                  <span className={styles.name}>{contact.name}</span>
                  <span className={styles.meta}>{[contact.email, contact.phone && formatPhone(contact.phone)].filter(Boolean).join(" · ")}</span>
                  <span className={styles.meta}>
                    {[
                      contact.orders_count > 0 && format(a.orders, { count: String(contact.orders_count) }),
                      contact.invitations_count > 0 && format(a.invitations, { count: String(contact.invitations_count) }),
                      contact.reachable &&
                        contact.consented_at &&
                        format(a.since, { date: date.format(new Date(contact.consented_at)), source: contact.consent_source ? a.sources[contact.consent_source] : "" }),
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                  </span>
                </div>
                <div className={styles.side}>
                  <span className={styles.status} data-status={status}>
                    {a.status[status]}
                  </span>
                  {canDelete && (
                    <button type="button" className={styles.remove} onClick={() => remove(contact)} aria-label={`${a.remove} · ${contact.name}`}>
                      <Trash size={18} aria-hidden />
                    </button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {page.meta.current_page < page.meta.last_page && (
        <button type="button" className={buttonClass("secondary", styles.more)} onClick={more} disabled={loadingMore}>
          {a.more}
        </button>
      )}
    </main>
  );
}
