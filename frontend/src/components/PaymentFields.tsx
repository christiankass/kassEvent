"use client";

import { useState } from "react";
import { CreditCard, DeviceMobile } from "@phosphor-icons/react";
import { Field } from "@/components/ui";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { guessOperator, OPERATORS, type Operator, type PaymentMethod } from "@/lib/orders";
import styles from "./payment.module.css";

export type PaymentChoice = { method: PaymentMethod; operator: Operator | null; phone: string };

/**
 * Choix du moyen de paiement : mobile money (numéro + opérateur, deviné d'après le numéro) ou carte.
 * Composant contrôlé : la valeur vit chez le parent, qui l'envoie à l'API.
 */
export function PaymentFields({
  value,
  onChange,
  error,
}: {
  value: PaymentChoice;
  onChange: (value: PaymentChoice) => void;
  error: (field: string) => string | undefined;
}) {
  const { t } = useI18n();
  // Tant que la personne n'a pas choisi elle-même, l'opérateur suit le numéro saisi.
  const [operatorTouched, setOperatorTouched] = useState(false);

  const methods = [
    { id: "mobile_money" as const, label: t.checkout.mobileMoney, hint: t.checkout.mobileMoneyHint, Icon: DeviceMobile },
    { id: "card" as const, label: t.checkout.card, hint: t.checkout.cardHint, Icon: CreditCard },
  ];

  return (
    <div className={styles.stack}>
      <div role="radiogroup" aria-label={t.checkout.paymentTitle} className={styles.methods}>
        {methods.map(({ id, label, hint, Icon }) => (
          <label key={id} className={styles.method} data-checked={value.method === id}>
            <input
              type="radio"
              name="method"
              value={id}
              checked={value.method === id}
              onChange={() => onChange({ ...value, method: id })}
            />
            <Icon size={28} aria-hidden />
            <span>
              <span className={styles.methodLabel}>{label}</span>
              <span className={styles.methodHint}>{hint}</span>
            </span>
          </label>
        ))}
      </div>
      {error("payment.method") && <p className={styles.error}>{error("payment.method")}</p>}

      {value.method === "mobile_money" && (
        <>
          <Field
            label={t.checkout.phone}
            hint={t.checkout.phoneHint}
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            required
            value={value.phone}
            error={error("payment.phone")}
            onChange={(e) => {
              const phone = e.target.value;
              const guessed = operatorTouched ? null : guessOperator(phone);
              onChange({ ...value, phone, operator: guessed ?? value.operator });
            }}
          />
          <fieldset className={styles.operators}>
            <legend className={styles.legend}>{t.checkout.operator}</legend>
            <div className={styles.chips}>
              {OPERATORS.map((operator) => (
                <label key={operator} className={styles.chip} data-checked={value.operator === operator}>
                  <input
                    type="radio"
                    name="operator"
                    value={operator}
                    required
                    checked={value.operator === operator}
                    onChange={() => {
                      setOperatorTouched(true);
                      onChange({ ...value, operator });
                    }}
                  />
                  {t.operators[operator]}
                </label>
              ))}
            </div>
            {error("payment.operator") && <p className={styles.error}>{error("payment.operator")}</p>}
          </fieldset>
        </>
      )}
    </div>
  );
}

/** Corps de la requête de paiement attendu par l'API. */
export function paymentBody(choice: PaymentChoice) {
  return choice.method === "card"
    ? { method: "card" }
    : { method: "mobile_money", operator: choice.operator, phone: choice.phone };
}
