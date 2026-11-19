"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { Alert, Button, Field } from "@/components/ui";
import { api } from "@/lib/api";
import { useFormSubmit } from "@/lib/form";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "../../auth.module.css";

function ResetPasswordForm() {
  const { t } = useI18n();
  const params = useSearchParams();
  const token = params.get("token");
  const email = params.get("email");
  const [done, setDone] = useState(false);
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    await api("/auth/reset-password", {
      method: "POST",
      body: { token, email, password: form.get("password"), password_confirmation: form.get("password_confirmation") },
    });
    setDone(true);
  });

  if (!token || !email) {
    return (
      <>
        <Alert tone="error">{t.reset.invalidLink}</Alert>
        <p className={styles.footer}>
          <Link href="/mot-de-passe/oublie">{t.reset.newLink}</Link>
        </p>
      </>
    );
  }

  if (done) {
    return (
      <>
        <Alert tone="success">{t.reset.done}</Alert>
        <p className={styles.footer}>
          <Link href="/connexion">{t.reset.toLogin}</Link>
        </p>
      </>
    );
  }

  // Lien invalide ou expiré : l'API renvoie l'erreur sur « email ».
  const linkError = fieldError("email");

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      {(linkError ?? message) && <Alert tone="error">{linkError ?? message}</Alert>}
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
        {pending ? t.common.loading : t.reset.submit}
      </Button>
      {linkError && (
        <p className={styles.footer}>
          <Link href="/mot-de-passe/oublie">{t.reset.newLink}</Link>
        </p>
      )}
    </form>
  );
}

export default function ResetPasswordPage() {
  const { t } = useI18n();
  return (
    <div className={styles.card}>
      <div>
        <h1 className={styles.title}>{t.reset.title}</h1>
        <p className={styles.lede}>{t.reset.lede}</p>
      </div>
      <Suspense fallback={<p>{t.common.loading}</p>}>
        <ResetPasswordForm />
      </Suspense>
    </div>
  );
}
