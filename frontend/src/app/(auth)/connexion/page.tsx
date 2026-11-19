"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { Alert, Button, Checkbox, Field, OtpField } from "@/components/ui";
import { api } from "@/lib/api";
import { useFormSubmit } from "@/lib/form";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { invitationPath, readInvitationLink, withInvitation } from "@/lib/invitation-link";
import styles from "../auth.module.css";

export default function LoginPage() {
  const router = useRouter();
  const params = useSearchParams();
  const invitation = readInvitationLink(params);
  // Double authentification activée : après le mot de passe, on demande le code.
  const [needsCode, setNeedsCode] = useState(false);
  const goOn = () => router.replace(invitation ? invitationPath(invitation) : "/tableau-de-bord");

  // Chaque étape a son propre formulaire : en revenant au mot de passe, il repart de zéro.
  return needsCode ? (
    <CodeStep onSignedIn={goOn} onRestart={() => setNeedsCode(false)} />
  ) : (
    <PasswordStep onSignedIn={goOn} onNeedsCode={() => setNeedsCode(true)} />
  );
}

function PasswordStep({ onSignedIn, onNeedsCode }: { onSignedIn: () => void; onNeedsCode: () => void }) {
  const { t } = useI18n();
  const params = useSearchParams();
  const invitation = readInvitationLink(params);
  const { submit, pending, message, fieldError } = useFormSubmit(
    async (form) => {
      const result = await api<{ two_factor?: boolean }>("/auth/login", {
        method: "POST",
        body: { email: form.get("email"), password: form.get("password"), remember: form.get("remember") === "on" },
      });
      if (result.two_factor) onNeedsCode();
      else onSignedIn();
    },
    { navigates: true },
  );

  return (
    <div className={styles.card}>
      <div>
        <h1 className={styles.title}>{t.login.title}</h1>
        <p className={styles.lede}>{t.login.lede}</p>
      </div>
      <form className={styles.form} onSubmit={submit} noValidate>
        {message && <Alert tone="error">{message}</Alert>}
        <Field
          label={t.common.email}
          name="email"
          type="email"
          autoComplete="email"
          inputMode="email"
          required
          defaultValue={params.get("email") ?? undefined}
          error={fieldError("email")}
        />
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

/** Second facteur : code de l'application, ou code de secours sans téléphone. */
function CodeStep({ onSignedIn, onRestart }: { onSignedIn: () => void; onRestart: () => void }) {
  const { t } = useI18n();
  const [recovery, setRecovery] = useState(false);

  return (
    <div className={styles.card}>
      <div>
        <h1 className={styles.title}>{t.login.codeTitle}</h1>
        <p className={styles.lede}>{recovery ? t.login.recoveryLede : t.login.codeLede}</p>
      </div>
      {/* Une clé par mode : en changeant de mode, le formulaire et ses erreurs repartent de zéro. */}
      <CodeForm key={recovery ? "recovery" : "app"} recovery={recovery} onSignedIn={onSignedIn} />
      <div className={styles.row}>
        <button type="button" className={styles.linkButton} onClick={() => setRecovery((r) => !r)}>
          {recovery ? t.login.useApp : t.login.useRecovery}
        </button>
        <button type="button" className={styles.linkButton} onClick={onRestart}>
          {t.login.restart}
        </button>
      </div>
    </div>
  );
}

function CodeForm({ recovery, onSignedIn }: { recovery: boolean; onSignedIn: () => void }) {
  const { t } = useI18n();
  const field = recovery ? "recovery_code" : "code";
  const { submit, pending, message, fieldError } = useFormSubmit(
    async (form) => {
      await api("/auth/two-factor-challenge", { method: "POST", body: { [field]: form.get(field) } });
      onSignedIn();
    },
    { navigates: true },
  );
  // Connexion expirée : le serveur répond sur « code », quel que soit le mode.
  const error = fieldError(field) ?? fieldError("code");

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      {message && <Alert tone="error">{message}</Alert>}
      {recovery ? (
        <Field label={t.login.recoveryCode} name="recovery_code" autoComplete="off" autoCapitalize="characters" required autoFocus error={error} />
      ) : (
        <OtpField label={t.login.code} autoFocus error={error} />
      )}
      <Button type="submit" disabled={pending}>
        {pending ? t.common.loading : t.login.verify}
      </Button>
    </form>
  );
}
