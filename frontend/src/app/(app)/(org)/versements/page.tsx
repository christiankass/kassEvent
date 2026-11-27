"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { Bank, DeviceMobile, ShieldCheck } from "@phosphor-icons/react";
import { Alert, Button, buttonClass, Field, OtpField } from "@/components/ui";
import { api, ApiError, type Currency } from "@/lib/api";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { formatPrice } from "@/lib/events";
import { useFormSubmit } from "@/lib/form";
import { format, type Dictionary } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { guessOperator, OPERATORS, type Operator } from "@/lib/orders";
import type { Balance, LedgerEntry, Payout, PayoutAccount, PayoutMethod, PayoutsOverview } from "@/lib/payouts";
import { PageSkeleton } from "../../PageSkeleton";
import app from "../../app.module.css";
import payment from "@/components/payment.module.css";
import styles from "./payouts.module.css";

/** Pendant un reversement, on redemande son statut toutes les 5 s. */
const POLL_MS = 5000;

/**
 * Compte de perception de l'organisation : soldes par devise, compte de reversement,
 * reversements et opérations (ventes, commissions, contributions des livres d'or).
 */
export default function PayoutsPage() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const { organization, permissions } = useOrganization();
  const canView = permissions.includes("payouts.view");
  const canManage = permissions.includes("payouts.manage");
  const [overview, setOverview] = useState<PayoutsOverview | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "error"; text: string } | null>(null);
  const [paying, setPaying] = useState<Currency | null>(null);

  const load = useCallback(
    () => api<{ data: PayoutsOverview }>("/payouts", { organizationId: organization.id }).then(({ data }) => setOverview(data)),
    [organization.id],
  );

  useEffect(() => {
    if (!canView) {
      router.replace("/tableau-de-bord");
      return;
    }
    load().catch(setError);
  }, [canView, load, router]);

  const pending = overview?.payouts.some((payout) => payout.status === "pending" && payout.method === "mobile_money");
  useEffect(() => {
    if (!pending) return;
    const timer = setInterval(() => load().catch(() => {}), POLL_MS);
    return () => clearInterval(timer);
  }, [pending, load]);

  if (error instanceof ApiError && error.code === "two_factor_required") {
    return (
      <main className={app.main}>
        <h1 className={app.title}>{t.payouts.title}</h1>
        <section className={`${app.panel} ${styles.gate}`} aria-labelledby="gate-title">
          <ShieldCheck size={32} aria-hidden />
          <h2 id="gate-title" className={app.sectionTitle}>
            {t.sales.twoFactorTitle}
          </h2>
          <p className={app.muted}>{format(t.payouts.twoFactorBody, { organization: organization.name })}</p>
          <Link href="/compte/securite" className={buttonClass()}>
            {t.sales.twoFactorCta}
          </Link>
        </section>
      </main>
    );
  }
  if (error) {
    return (
      <main className={app.main}>
        <Alert tone="error">{formErrorMessage(error, t)}</Alert>
      </main>
    );
  }
  if (!overview) return <PageSkeleton />;

  const money = (minor: number, currency: Currency) => formatPrice(minor, currency, locale);
  const { settings, account } = overview;

  async function payNow(currency: Currency) {
    setNotice(null);
    setPaying(currency);
    try {
      const { data } = await api<{ data: Payout }>("/payouts", { method: "POST", organizationId: organization.id, body: { currency } });
      const amount = money(data.amount, data.currency);
      setNotice({ tone: "success", text: format(data.method === "bank" ? t.payouts.sentBank : t.payouts.sent, { amount }) });
    } catch (e) {
      setNotice({ tone: "error", text: e instanceof ApiError && e.status === 422 ? e.message : actionErrorMessage(e, t) });
    }
    setPaying(null);
    await load().catch(setError);
  }

  return (
    <main className={app.main}>
      <h1 className={app.title}>{t.payouts.title}</h1>
      <p className={app.lede}>{t.payouts.lede}</p>

      <div className={app.notice} aria-live="polite">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      </div>

      <div className={styles.layout}>
        <div className={`${styles.column} ${styles.balances}`}>
          {overview.balances.map((balance) => (
            <BalanceCard
              key={balance.currency}
              balance={balance}
              overview={overview}
              money={money}
              canPay={canManage && account?.active === true}
              paying={paying === balance.currency}
              onPay={() => payNow(balance.currency)}
            />
          ))}
        </div>

        <AccountPanel
          account={account}
          canManage={canManage}
          delayHours={settings.account_change_delay_hours}
          onSaved={async () => {
            setNotice({ tone: "success", text: t.payouts.saved });
            await load().catch(setError);
          }}
        />

        <div className={`${styles.column} ${styles.history}`}>
          <section aria-labelledby="history-title">
            <h2 id="history-title" className={app.sectionTitle}>
              {t.payouts.historyTitle}
            </h2>
            {overview.payouts.length === 0 ? (
              <p className={styles.empty}>{t.payouts.historyEmpty}</p>
            ) : (
              <ul className={styles.list}>
                {overview.payouts.map((payout) => (
                  <PayoutRow key={payout.id} payout={payout} money={money} />
                ))}
              </ul>
            )}
          </section>

          <section aria-labelledby="entries-title">
            <h2 id="entries-title" className={app.sectionTitle}>
              {t.payouts.entriesTitle}
            </h2>
            {overview.entries.length === 0 ? (
              <p className={styles.empty}>{t.payouts.entriesEmpty}</p>
            ) : (
              <ul className={styles.list}>
                {overview.entries.map((entry) => (
                  <EntryRow key={entry.id} entry={entry} money={money} />
                ))}
              </ul>
            )}
          </section>
        </div>
      </div>
    </main>
  );
}

type Money = (minor: number, currency: Currency) => string;

function BalanceCard({
  balance,
  overview,
  money,
  canPay,
  paying,
  onPay,
}: {
  balance: Balance;
  overview: PayoutsOverview;
  money: Money;
  canPay: boolean;
  paying: boolean;
  onPay: () => void;
}) {
  const { t, locale } = useI18n();
  const { currency } = balance;
  const { settings } = overview;
  const minimum = settings.minimum[currency];
  const percent = `${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(settings.commission_percent)} %`;
  const fixed = settings.commission_fixed[currency];
  const rate = fixed > 0 ? format(t.payouts.commissionFixed, { percent, fixed: money(fixed, currency) }) : percent;
  const ready = balance.available >= minimum && balance.in_progress === 0;

  return (
    <section className={styles.balance} aria-label={`${t.payouts.available} ${currency}`}>
      <div className={styles.balanceHead}>
        <div>
          <p className={styles.balanceLabel}>
            {t.payouts.available} · {currency}
          </p>
          <p className={styles.balanceAmount}>{money(balance.available, currency)}</p>
        </div>
        {canPay && (
          <Button type="button" onClick={onPay} disabled={!ready || paying}>
            {paying ? t.payouts.paying : t.payouts.payNow}
          </Button>
        )}
      </div>
      <p className={styles.balanceNote}>
        {balance.available < minimum
          ? format(t.payouts.belowMinimum, { missing: money(minimum - balance.available, currency) })
          : format(t.payouts.automatic, { minimum: money(minimum, currency) })}
      </p>

      <dl className={styles.figures}>
        <div>
          <dt>{t.payouts.onHold}</dt>
          <dd>{money(balance.on_hold, currency)}</dd>
          <dd className={styles.figureHint}>{format(t.payouts.onHoldHint, { hours: String(settings.hold_hours) })}</dd>
        </div>
        <div>
          <dt>{t.payouts.inProgress}</dt>
          <dd>{money(balance.in_progress, currency)}</dd>
        </div>
        <div>
          <dt>{t.payouts.paidOut}</dt>
          <dd>{money(balance.paid_out, currency)}</dd>
        </div>
      </dl>

      <dl className={styles.split}>
        <div>
          <dt>{t.payouts.sales}</dt>
          <dd>{money(balance.sales, currency)}</dd>
        </div>
        <div>
          <dt>{format(t.payouts.commission, { rate })}</dt>
          <dd>− {money(balance.commission, currency)}</dd>
        </div>
        {balance.contributions > 0 && (
          <div>
            <dt>{t.payouts.contributions}</dt>
            <dd>{money(balance.contributions, currency)}</dd>
          </div>
        )}
        <div className={styles.splitTotal}>
          <dt>{t.payouts.net}</dt>
          <dd>{money(balance.sales - balance.commission + balance.contributions, currency)}</dd>
        </div>
      </dl>
    </section>
  );
}

function destination(
  t: Dictionary,
  item: Pick<PayoutAccount, "method" | "operator" | "phone" | "bank_name" | "account_last4">,
): string {
  return item.method === "mobile_money"
    ? `${item.operator ? t.operators[item.operator] : ""} · ${item.phone ?? ""}`
    : format(t.payouts.bankEnding, { bank: item.bank_name ?? "", last4: item.account_last4 ?? "" });
}

function PayoutRow({ payout, money }: { payout: Payout; money: Money }) {
  const { t, locale } = useI18n();
  const date = new Intl.DateTimeFormat(locale, { dateStyle: "medium", timeStyle: "short" });
  return (
    <li className={styles.row}>
      <div className={styles.rowMain}>
        <span className={styles.rowTitle}>{destination(t, payout)}</span>
        <span className={styles.rowMeta}>
          {t.payouts.trigger[payout.trigger]} · {date.format(new Date(payout.created_at))}
        </span>
        {payout.status === "failed" && payout.failure_code && (
          <span className={styles.rowError}>{format(t.payouts.failure, { code: payout.failure_code })}</span>
        )}
      </div>
      <div className={styles.rowSide}>
        <span className={styles.amount}>{money(payout.amount, payout.currency)}</span>
        <span className={styles.status} data-status={payout.status}>
          {t.payouts.status[payout.status]}
        </span>
      </div>
    </li>
  );
}

function EntryRow({ entry, money }: { entry: LedgerEntry; money: Money }) {
  const { t, locale } = useI18n();
  const date = new Intl.DateTimeFormat(locale, { dateStyle: "medium", timeStyle: "short" });
  const held = (entry.type === "sale" || entry.type === "contribution") && new Date(entry.available_at) > new Date();
  const details = [
    entry.label,
    entry.reference,
    entry.tickets ? format(t.payouts.tickets, { count: String(entry.tickets) }) : null,
    date.format(new Date(entry.created_at)),
    held ? format(t.payouts.availableOn, { date: date.format(new Date(entry.available_at)) }) : null,
  ].filter(Boolean);

  return (
    <li className={styles.row}>
      <div className={styles.rowMain}>
        <span className={styles.rowTitle}>{t.payouts.entry[entry.type]}</span>
        <span className={styles.rowMeta}>{details.join(" · ")}</span>
      </div>
      <div className={styles.rowSide}>
        <span className={styles.amount} data-sign={entry.amount < 0 ? "minus" : "plus"}>
          {entry.amount < 0 ? "− " : "+ "}
          {money(Math.abs(entry.amount), entry.currency)}
        </span>
      </div>
    </li>
  );
}

function AccountPanel({
  account,
  canManage,
  delayHours,
  onSaved,
}: {
  account: PayoutAccount | null;
  canManage: boolean;
  delayHours: number;
  onSaved: () => Promise<void>;
}) {
  const { t, locale } = useI18n();
  const { organization } = useOrganization();
  const [editing, setEditing] = useState(false);
  const date = new Intl.DateTimeFormat(locale, { dateStyle: "long", timeStyle: "short" });

  return (
    <section className={`${app.panel} ${styles.account}`} aria-labelledby="account-title">
      <h2 id="account-title" className={app.sectionTitle}>
        {t.payouts.accountTitle}
      </h2>

      {account && !editing && (
        <div className={styles.destination}>
          {account.method === "mobile_money" ? <DeviceMobile size={28} aria-hidden /> : <Bank size={28} aria-hidden />}
          <div className={styles.rowMain}>
            <span className={styles.rowTitle}>{account.method === "mobile_money" ? t.payouts.methodMobile : t.payouts.methodBank}</span>
            <span className={styles.rowMeta}>{destination(t, account)}</span>
            {account.account_holder && <span className={styles.rowMeta}>{account.account_holder}</span>}
          </div>
          {account.active && (
            <span className={styles.status} data-status="succeeded">
              {t.payouts.accountActive}
            </span>
          )}
        </div>
      )}
      {account && !account.active && !editing && (
        <p className={styles.pendingNote}>{format(t.payouts.accountPending, { date: date.format(new Date(account.active_from)) })}</p>
      )}
      {!account && !editing && <p className={styles.empty}>{t.payouts.accountEmpty}</p>}

      {editing ? (
        <AccountForm
          organizationId={organization.id}
          initial={account}
          delayHours={account ? delayHours : 0}
          onCancel={() => setEditing(false)}
          onSaved={async () => {
            setEditing(false);
            await onSaved();
          }}
        />
      ) : canManage ? (
        <div>
          <Button type="button" variant={account ? "secondary" : "primary"} onClick={() => setEditing(true)}>
            {account ? t.payouts.edit : t.payouts.add}
          </Button>
        </div>
      ) : (
        <p className={app.muted}>{t.payouts.accountReadOnly}</p>
      )}
    </section>
  );
}

function AccountForm({
  organizationId,
  initial,
  delayHours,
  onCancel,
  onSaved,
}: {
  organizationId: string;
  initial: PayoutAccount | null;
  delayHours: number;
  onCancel: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [method, setMethod] = useState<PayoutMethod>(initial?.method ?? "mobile_money");
  const [operator, setOperator] = useState<Operator | null>(initial?.operator ?? null);
  const [operatorTouched, setOperatorTouched] = useState(false);

  const save = useFormSubmit(async (form) => {
    const field = (name: string) => String(form.get(name) ?? "");
    const body =
      method === "mobile_money"
        ? { method, operator, phone: field("phone") }
        : { method, bank_name: field("bank_name"), account_holder: field("account_holder"), account_number: field("account_number"), swift: field("swift") || null };
    await api("/payouts/account", { method: "PUT", organizationId, body: { ...body, code: field("code") } });
    await onSaved();
  });

  const methods = [
    { id: "mobile_money" as const, label: t.payouts.methodMobile, hint: t.payouts.methodMobileHint },
    { id: "bank" as const, label: t.payouts.methodBank, hint: t.payouts.methodBankHint },
  ];

  return (
    <form className={app.form} onSubmit={save.submit} noValidate>
      {save.message && <Alert tone="error">{save.message}</Alert>}
      <div role="radiogroup" aria-label={t.payouts.accountTitle} className={app.choices}>
        {methods.map(({ id, label, hint }) => (
          <label key={id} className={app.choice}>
            <input type="radio" name="method" value={id} checked={method === id} onChange={() => setMethod(id)} />
            <span className={app.choiceTitle}>{label}</span>
            <span className={app.choiceHint}>{hint}</span>
          </label>
        ))}
      </div>

      {method === "mobile_money" ? (
        <>
          <Field
            label={t.payouts.phone}
            hint={t.payouts.phoneHint}
            name="phone"
            type="tel"
            inputMode="tel"
            autoComplete="off"
            required
            error={save.fieldError("phone")}
            onChange={(e) => {
              const guessed = operatorTouched ? null : guessOperator(e.target.value);
              if (guessed) setOperator(guessed);
            }}
          />
          <fieldset className={app.fieldset}>
            <legend className={app.legend}>{t.payouts.operator}</legend>
            <div className={payment.chips}>
              {OPERATORS.map((id) => (
                <label key={id} className={payment.chip} data-checked={operator === id}>
                  <input
                    type="radio"
                    name="operator"
                    value={id}
                    checked={operator === id}
                    onChange={() => {
                      setOperatorTouched(true);
                      setOperator(id);
                    }}
                  />
                  {t.operators[id]}
                </label>
              ))}
            </div>
            {save.fieldError("operator") && <p className={payment.error}>{save.fieldError("operator")}</p>}
          </fieldset>
        </>
      ) : (
        <>
          <Field label={t.payouts.bankName} name="bank_name" required maxLength={80} defaultValue={initial?.bank_name ?? ""} error={save.fieldError("bank_name")} />
          <Field label={t.payouts.accountHolder} name="account_holder" required maxLength={120} defaultValue={initial?.account_holder ?? ""} error={save.fieldError("account_holder")} />
          <Field label={t.payouts.accountNumber} name="account_number" required maxLength={40} autoComplete="off" error={save.fieldError("account_number")} />
          <Field label={t.payouts.swift} name="swift" maxLength={11} autoComplete="off" error={save.fieldError("swift")} />
        </>
      )}

      <OtpField label={t.payouts.code} error={save.fieldError("code")} />
      {delayHours > 0 && <p className={styles.securityNote}>{format(t.payouts.securityNote, { hours: String(delayHours) })}</p>}

      <div className={app.actions}>
        <Button type="submit" disabled={save.pending}>
          {save.pending ? t.common.loading : t.payouts.save}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel}>
          {t.payouts.cancel}
        </Button>
      </div>
    </form>
  );
}
