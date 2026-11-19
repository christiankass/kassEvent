"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { Alert, Button, Field } from "@/components/ui";
import { api } from "@/lib/api";
import { useFormSubmit } from "@/lib/form";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "../auth.module.css";

export default function RegisterPage() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    await api("/auth/register", {
      method: "POST",
      body: {
        name: form.get("name"),
        email: form.get("email"),
        password: form.get("password"),
        password_confirmation: form.get("password_confirmation"),
        locale,
      },
    });
    router.replace("/organisation/nouvelle");
  });

  return (
    <div className={styles.card}>
      <div>
        <h1 className={styles.title}>{t.register.title}</h1>
        <p className={styles.lede}>{t.register.lede}</p>
      </div>
      <form className={styles.form} onSubmit={submit} noValidate>
        {message && <Alert tone="error">{message}</Alert>}
        <Field label={t.register.name} name="name" autoComplete="name" required error={fieldError("name")} />
        <Field label={t.common.email} name="email" type="email" autoComplete="email" inputMode="email" required error={fieldError("email")} />
        <Field
          label={t.common.password}
          name="password"
          type="password"
          autoComplete="new-password"
          required
          hint={t.register.passwordHint}
          error={fieldError("password")}
        />
        <Field label={t.register.passwordConfirmation} name="password_confirmation" type="password" autoComplete="new-password" required />
        <Button type="submit" disabled={pending}>
          {pending ? t.common.loading : t.register.submit}
        </Button>
      </form>
      <p className={styles.footer}>
        {t.register.hasAccount} <Link href="/connexion">{t.register.toLogin}</Link>
      </p>
    </div>
  );
}
