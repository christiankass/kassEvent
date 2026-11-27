"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { HandCoins } from "@phosphor-icons/react";
import { Alert, Button, buttonClass, Checkbox, Field } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import type { ContributionsPage } from "@/lib/contributions";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { formatPrice } from "@/lib/events";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { formatWishDate, type WishBook } from "@/lib/wishes";
import styles from "./wishes.module.css";

/**
 * Contributions d'argent du livre (rôles argent, double authentification) : les ouvrir, nommer le
 * bénéficiaire, voir qui a donné combien. L'argent rejoint le compte de perception (page Versements).
 */
export function Contributions({ eventId, book, onChanged }: { eventId: string; book: WishBook; onChanged: () => Promise<unknown> }) {
  const { t, locale } = useI18n();
  const c = t.contributions.settings;
  const { organization, permissions } = useOrganization();
  const organizationId = organization.id;
  const canManage = permissions.includes("payouts.manage");

  const [page, setPage] = useState<ContributionsPage | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [error, setError] = useState<unknown>(null);
  const [pending, setPending] = useState(false);
  const [saved, setSaved] = useState(false);
  const [beneficiary, setBeneficiary] = useState(book.beneficiary ?? "");

  const reload = useCallback(
    () => api<ContributionsPage>(`/events/${eventId}/contributions`, { organizationId }).then(setPage, setLoadError),
    [eventId, organizationId],
  );
  useEffect(() => {
    void reload();
  }, [reload]);

  async function save(body: { open?: boolean; beneficiary?: string | null }) {
    if (pending) return;
    setPending(true);
    setError(null);
    setSaved(false);
    try {
      await api(`/events/${eventId}/contributions/settings`, { method: "PUT", body, organizationId });
      await onChanged();
      setSaved("beneficiary" in body);
    } catch (e) {
      setError(e);
    }
    setPending(false);
  }

  const twoFactor = loadError instanceof ApiError && loadError.code === "two_factor_required";
  return (
    <section className={styles.panel} aria-labelledby="contributions-title">
      <h2 id="contributions-title" className={styles.sectionTitle}>
        <HandCoins size={22} aria-hidden /> {c.title}
      </h2>
      <p className={styles.muted}>{c.lede}</p>

      {twoFactor ? (
        <>
          <p>{c.twoFactor}</p>
          <Link href="/compte/securite" className={buttonClass("secondary")}>
            {c.twoFactorCta}
          </Link>
        </>
      ) : loadError ? (
        <Alert tone="error">{formErrorMessage(loadError, t) ?? c.forbidden}</Alert>
      ) : !page ? null : (
        <>
          {error ? <Alert tone="error">{actionErrorMessage(error, t)}</Alert> : null}
          {!page.has_payout_account && !book.contributions_open ? (
            <>
              <p>{c.noAccount}</p>
              <Link href="/versements" className={buttonClass("secondary")}>
                {c.noAccountCta}
              </Link>
            </>
          ) : (
            canManage && (
              <div className={styles.settings}>
                <Checkbox label={c.open} checked={book.contributions_open} disabled={pending} onChange={(e) => void save({ open: e.target.checked })} />
                <form
                  className={styles.inline}
                  onSubmit={(e) => {
                    e.preventDefault();
                    void save({ beneficiary: beneficiary.trim() || null });
                  }}
                >
                  <Field label={c.beneficiary} hint={c.beneficiaryHint} value={beneficiary} maxLength={80} onChange={(e) => setBeneficiary(e.target.value)} />
                  <Button type="submit" variant="secondary" disabled={pending}>
                    {c.save}
                  </Button>
                </form>
                {saved && <Alert tone="success">{c.saved}</Alert>}
              </div>
            )
          )}

          {page.totals.length > 0 && (
            <p className={styles.strong}>
              {format(c.totals, {
                total: page.totals.map((total) => formatPrice(total.amount, total.currency, locale)).join(" + "),
                count: String(page.totals.reduce((sum, total) => sum + total.count, 0)),
              })}
            </p>
          )}
          {page.data.length === 0 ? (
            <p className={styles.muted}>{c.empty}</p>
          ) : (
            <ul className={styles.list}>
              {page.data.map((item) => (
                <li key={item.id} className={styles.wish}>
                  <p className={styles.meta}>
                    <strong>{item.contributor_name ?? c.anonymous}</strong> · {formatPrice(item.amount, item.currency, locale)} ·{" "}
                    {formatWishDate(item.paid_at, locale)} · {item.reference}
                  </p>
                  {item.message && <p className={styles.message}>{item.message}</p>}
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </section>
  );
}
