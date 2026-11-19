"use client";

import Link from "next/link";
import { useState } from "react";
import { Alert, Button, Field } from "@/components/ui";
import { api } from "@/lib/api";
import { useFormSubmit } from "@/lib/form";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "../../auth.module.css";

export default function ForgotPasswordPage() {
  const { t } = useI18n();
  const [sent, setSent] = useState(false);
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    await api("/auth/forgot-password", { method: "POST", body: { email: form.get("email") } });
    setSent(true);
  });

  return (
    <div className={styles.card}>
      <div>
        <h1 className={styles.title}>{t.forgot.title}</h1>
        <p className={styles.lede}>{t.forgot.lede}</p>
      </div>
      {sent ? (
        <Alert tone="success">{t.forgot.sent}</Alert>
      ) : (
        <form className={styles.form} onSubmit={submit} noValidate>
          {message && <Alert tone="error">{message}</Alert>}
          <Field
            label={t.common.email}
            name="email"
            type="email"
            autoComplete="email"
            inputMode="email"
            required
            error={fieldError("email")}
          />
          <Button type="submit" disabled={pending}>
            {pending ? t.common.loading : t.forgot.submit}
          </Button>
        </form>
      )}
      <p className={styles.footer}>
        <Link href="/connexion">{t.forgot.back}</Link>
      </p>
    </div>
  );
}
