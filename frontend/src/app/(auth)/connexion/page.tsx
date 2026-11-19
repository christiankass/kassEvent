"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Alert, Button, Checkbox, Field } from "@/components/ui";
import { api } from "@/lib/api";
import { useFormSubmit } from "@/lib/form";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { invitationPath, readInvitationLink, withInvitation } from "@/lib/invitation-link";
import styles from "../auth.module.css";

export default function LoginPage() {
  const { t } = useI18n();
  const router = useRouter();
  const params = useSearchParams();
  const invitation = readInvitationLink(params);
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    await api("/auth/login", {
      method: "POST",
      body: { email: form.get("email"), password: form.get("password"), remember: form.get("remember") === "on" },
    });
    router.replace(invitation ? invitationPath(invitation) : "/tableau-de-bord");
   }, { navigates: true });

  return (
    <div className={styles.card}>
      <div>
        <h1 className={styles.title}>{t.login.title}</h1>
        <p className={styles.lede}>{t.login.lede}</p>
      </div>
      <form className={styles.form} onSubmit={submit} noValidate>
        {message && <Alert tone="error">{message}</Alert>}
        <Field label={t.common.email} name="email" type="email" autoComplete="email" inputMode="email" required defaultValue={params.get("email") ?? undefined} error={fieldError("email")} />
        <Field label={t.common.password} name="password" type="password" autoComplete="current-password" required error={fieldError("password")} />
        <div className={styles.row}>
          <Checkbox label={t.login.remember} name="remember" />
          <Link href="/mot-de-passe/oublie">{t.login.forgot}</Link>
        </div>
        <Button type="submit" disabled={pending}>
          {pending ? t.common.loading : t.login.submit}
        </Button>
      </form>
      <p className={styles.footer}>
        {t.login.noAccount} <Link href={withInvitation("/inscription", invitation, params.get("email") ?? undefined)}>{t.login.toRegister}</Link>
      </p>
    </div>
  );
}
