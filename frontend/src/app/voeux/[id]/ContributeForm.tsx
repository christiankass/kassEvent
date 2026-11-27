"use client";

import { useRouter } from "next/navigation";
import { useMemo, useRef, useState } from "react";
import { Gift, HandHeart, HandCoins, LockSimple } from "@phosphor-icons/react";
import { PaymentFields, paymentBody, type PaymentChoice } from "@/components/PaymentFields";
import { Alert, Field, TextArea } from "@/components/ui";
import { api, ApiError, type Currency } from "@/lib/api";
import { contributionFee, contributionPath, parseAmount, PRESETS, type Contribution, type ContributionOptions } from "@/lib/contributions";
import { formatPrice } from "@/lib/events";
import { useFormSubmit } from "@/lib/form";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { randomUuid } from "@/lib/uuid";
import type { WishTone } from "@/lib/wishes";
import styles from "./wishes.module.css";

const TONE_ICONS = { celebration: Gift, tribute: HandHeart, guestbook: HandCoins };

/**
 * Contribution (soutien pour des obsèques, cadeau d'anniversaire) depuis la page du livre : montant,
 * devise, nom facultatif, moyen de paiement. Les frais du moyen choisi s'ajoutent et sont affichés
 * avant le paiement ; le bénéficiaire reçoit exactement le montant choisi.
 */
export function ContributeForm({ bookId, tone: toneName, options }: { bookId: string; tone: WishTone; options: ContributionOptions }) {
  const { t, locale } = useI18n();
  const c = t.contributions;
  const tone = c.tones[toneName];
  const Icon = TONE_ICONS[toneName];
  const router = useRouter();
  const beneficiary = options.beneficiary || tone.beneficiary;

  const [expanded, setExpanded] = useState(false);
  const [currency, setCurrency] = useState<Currency>(options.default_currency);
  const [preset, setPreset] = useState<number | null>(PRESETS[options.default_currency][1]);
  const [custom, setCustom] = useState("");
  const [payment, setPayment] = useState<PaymentChoice>({ method: "mobile_money", operator: null, phone: "" });
  // Clé d'idempotence : le même envoi répété (réponse perdue) rend la même contribution.
  const attempt = useRef<{ body: string; key: string } | null>(null);

  const limits = options.limits[currency];
  const money = (minor: number) => formatPrice(minor, currency, locale);
  const amount = preset !== null ? preset * 100 : parseAmount(custom, currency);
  const valid = amount !== null && amount >= limits.min && amount <= limits.max;
  const fee = useMemo(
    () => (valid && amount !== null ? contributionFee(amount, currency, options.fees[payment.method]) : 0),
    [valid, amount, currency, options.fees, payment.method],
  );

  const form = useFormSubmit(
    async (data) => {
      if (!valid || amount === null) return;
      const body = {
        amount,
        currency,
        total: amount + fee,
        name: data.get("name") || null,
        message: data.get("message") || null,
        payment: paymentBody(payment),
      };
      const serialized = JSON.stringify(body);
      if (attempt.current?.body !== serialized) attempt.current = { body: serialized, key: randomUuid() };
      const response = await api<{ data: Contribution; access_key: string }>(`/public/wish-books/${bookId}/contributions`, {
        method: "POST",
        body: { ...body, idempotency_key: attempt.current.key },
      }).catch((error: unknown) => {
        if (error instanceof ApiError && error.status < 500) attempt.current = null;
        throw error;
      });
      const redirect = response.data.payment?.redirect_url;
      if (redirect) window.location.assign(redirect);
      else router.push(contributionPath(response.data.id, response.access_key));
    },
    { navigates: true },
  );
  const { submit, pending, fieldError } = form;
  const amountError =
    fieldError("amount") ?? (preset === null && custom !== "" && !valid ? format(c.amountInvalid, { min: money(limits.min), max: money(limits.max) }) : undefined);

  if (!expanded) {
    return (
      <section className={`${styles.card} ${styles.contribute}`} aria-labelledby="contribute-title">
        <h2 id="contribute-title" className={styles.contributeTitle}>
          <Icon size={26} aria-hidden /> {tone.title}
        </h2>
        <p>{format(tone.intro, { beneficiary })}</p>
        <button type="button" className={styles.primary} onClick={() => setExpanded(true)}>
          {tone.button}
        </button>
      </section>
    );
  }

  return (
    <form className={`${styles.card} ${styles.contribute}`} onSubmit={submit} aria-labelledby="contribute-title" noValidate>
      <h2 id="contribute-title" className={styles.contributeTitle}>
        <Icon size={26} aria-hidden /> {tone.title}
      </h2>
      <p>{format(tone.intro, { beneficiary })}</p>

      {options.currencies.length > 1 && (
        <fieldset className={styles.choiceGroup}>
          <legend className={styles.legend}>{c.currency}</legend>
          <div className={styles.chips}>
            {options.currencies.map((value) => (
              <label key={value} className={styles.chip} data-checked={currency === value}>
                <input
                  type="radio"
                  name="currency"
                  checked={currency === value}
                  onChange={() => {
                    setCurrency(value);
                    setPreset(PRESETS[value][1]);
                    setCustom("");
                  }}
                />
                {value === "CDF" ? "FC" : value}
              </label>
            ))}
          </div>
        </fieldset>
      )}

      <fieldset className={styles.choiceGroup}>
        <legend className={styles.legend}>{c.amount}</legend>
        <div className={styles.chips}>
          {PRESETS[currency].map((value) => (
            <label key={value} className={styles.chip} data-checked={preset === value}>
              <input type="radio" name="preset" checked={preset === value} onChange={() => setPreset(value)} />
              {money(value * 100)}
            </label>
          ))}
        </div>
      </fieldset>
      <Field
        label={c.otherAmount}
        hint={format(c.otherAmountHint, { min: money(limits.min), max: money(limits.max) })}
        inputMode={currency === "CDF" ? "numeric" : "decimal"}
        value={custom}
        error={amountError}
        onChange={(e) => {
          setCustom(e.target.value);
          setPreset(e.target.value === "" ? PRESETS[currency][1] : null);
        }}
      />

      <Field label={c.name} hint={c.nameHint} name="name" autoComplete="name" maxLength={60} error={fieldError("name")} />
      <TextArea label={c.message} name="message" maxLength={280} rows={3} error={fieldError("message")} />

      <fieldset className={styles.choiceGroup}>
        <legend className={styles.legend}>{c.payment}</legend>
        <PaymentFields value={payment} onChange={setPayment} error={fieldError} />
      </fieldset>

      {valid && amount !== null && (
        <div className={styles.summary} aria-live="polite">
          <dl>
            <div>
              <dt>{c.summaryAmount}</dt>
              <dd>{money(amount)}</dd>
            </div>
            <div>
              <dt>{c.summaryFee[payment.method]}</dt>
              <dd>{money(fee)}</dd>
            </div>
            <div className={styles.summaryTotal}>
              <dt>{c.summaryTotal}</dt>
              <dd>{money(amount + fee)}</dd>
            </div>
          </dl>
          <p className={styles.muted}>{format(c.noCommission, { beneficiary: capitalize(beneficiary), amount: money(amount) })}</p>
        </div>
      )}

      {(form.message ?? fieldError("total") ?? fieldError("payment")) && (
        <Alert tone="error">{form.message ?? fieldError("total") ?? fieldError("payment")}</Alert>
      )}
      <button type="submit" className={styles.primary} disabled={pending || !valid}>
        <LockSimple size={18} aria-hidden /> {pending ? c.paying : format(c.pay, { total: valid && amount !== null ? money(amount + fee) : "" })}
      </button>
    </form>
  );
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1);
}
