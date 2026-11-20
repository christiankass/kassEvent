"use client";

import Link from "next/link";
import {
  useId,
  useState,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from "react";
import { Eye, EyeSlash } from "@phosphor-icons/react";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "./ui.module.css";

type FieldProps = InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  hint?: string;
  error?: string;
};

/** Champ avec libellé au-dessus, aide et erreur en dessous, reliés pour les lecteurs d'écran. */
export function Field({ label, hint, error, type = "text", className, ...input }: FieldProps) {
  const id = useId();
  const { t } = useI18n();
  const [visible, setVisible] = useState(false);
  const isPassword = type === "password";
  const describedBy = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(" ") || undefined;

  return (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
      </label>
      <div className={styles.control}>
        <input
          id={id}
          type={isPassword && visible ? "text" : type}
          className={[styles.input, isPassword && styles.withToggle, className].filter(Boolean).join(" ")}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          {...input}
        />
        {isPassword && (
          <button
            type="button"
            className={styles.toggle}
            onClick={() => setVisible((v) => !v)}
            aria-label={visible ? t.common.hidePassword : t.common.showPassword}
            aria-pressed={visible}
          >
            {visible ? <EyeSlash size={22} weight="regular" /> : <Eye size={22} weight="regular" />}
          </button>
        )}
      </div>
      {hint && (
        <p id={`${id}-hint`} className={styles.hint}>
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className={styles.error}>
          {error}
        </p>
      )}
    </div>
  );
}

type Described = { label: string; hint?: string; error?: string };

/** Libellé, aide et erreur autour d'un contrôle, reliés pour les lecteurs d'écran (comme `Field`). */
function Labelled({ label, hint, error, children }: Described & { children: (a11y: object) => React.ReactNode }) {
  const id = useId();
  const describedBy = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(" ") || undefined;
  return (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
      </label>
      {children({ id, "aria-describedby": describedBy, "aria-invalid": error ? true : undefined })}
      {hint && (
        <p id={`${id}-hint`} className={styles.hint}>
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className={styles.error}>
          {error}
        </p>
      )}
    </div>
  );
}

export function TextArea({ label, hint, error, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & Described) {
  return (
    <Labelled label={label} hint={hint} error={error}>
      {(a11y) => <textarea className={`${styles.input} ${styles.textarea}`} rows={5} {...a11y} {...props} />}
    </Labelled>
  );
}

export function Select({ label, hint, error, ...props }: SelectHTMLAttributes<HTMLSelectElement> & Described) {
  return (
    <Labelled label={label} hint={hint} error={error}>
      {(a11y) => <select className={styles.input} {...a11y} {...props} />}
    </Labelled>
  );
}

/** Classes d'un bouton, pour donner la même allure à un lien. */
export function buttonClass(variant: "primary" | "secondary" | "danger" = "primary", className?: string) {
  return [styles.button, variant !== "primary" && styles[variant], className].filter(Boolean).join(" ");
}

/** Champ du code à 6 chiffres d'une application d'authentification. */
export function OtpField({ label, error, autoFocus }: { label: string; error?: string; autoFocus?: boolean }) {
  return (
    <Field
      label={label}
      name="code"
      inputMode="numeric"
      autoComplete="one-time-code"
      maxLength={7}
      required
      autoFocus={autoFocus}
      error={error}
    />
  );
}

export function Button({
  variant = "primary",
  className,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "secondary" | "danger" }) {
  return (
    <button
      className={buttonClass(variant, className)}
      {...props}
    />
  );
}

export function Alert({ tone, children }: { tone: "error" | "success"; children: React.ReactNode }) {
  return (
    <p role={tone === "error" ? "alert" : "status"} className={`${styles.alert} ${tone === "error" ? styles.alertError : styles.alertSuccess}`}>
      {children}
    </p>
  );
}

export function Checkbox({ label, ...input }: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  return (
    <label className={styles.check}>
      <input type="checkbox" {...input} />
      {label}
    </label>
  );
}

export function LocaleSwitch() {
  const { locale, t, setLocale } = useI18n();
  const other = locale === "fr" ? "en" : "fr";
  return (
    <button
      type="button"
      className={styles.localeSwitch}
      lang={other}
      aria-label={t.common.switchLanguageLabel}
      onClick={() => setLocale(other)}
    >
      {t.common.switchLanguage}
    </button>
  );
}

export function Wordmark({ href = "/" }: { href?: string }) {
  return (
    <Link href={href} className={styles.wordmark}>
      kass<mark>Event</mark>
    </Link>
  );
}
