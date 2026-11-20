"use client";

import { useState } from "react";
import { Alert, Button, Field, OtpField } from "@/components/ui";
import { api } from "@/lib/api";
import { useFormSubmit } from "@/lib/form";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useSetUser, useUser } from "@/lib/user-context";
import styles from "../../app.module.css";

type Setup = { secret: string; qr_svg: string };

/**
 * Étapes de la double authentification :
 * mot de passe → QR code + premier code → codes de secours → activée.
 * Désactiver ou régénérer les codes redemande aussi le mot de passe.
 */
type Action = "enable" | "disable" | "regenerate";

type Step =
  | { name: "idle" }
  | { name: "password"; action: Action }
  | { name: "setup"; setup: Setup }
  | { name: "codes"; codes: string[] };

export default function SecurityPage() {
  const { t } = useI18n();
  const user = useUser();
  const setUser = useSetUser();
  const [step, setStep] = useState<Step>({ name: "idle" });
  const [notice, setNotice] = useState<string | null>(null);
  const copy = t.security;
  const enabled = user.two_factor_enabled;

  function start(action: Action) {
    setNotice(null);
    setStep({ name: "password", action });
  }

  return (
    <main className={`${styles.main} ${styles.narrow}`}>
      <h1 className={styles.title}>{copy.title}</h1>
      <p className={styles.lede}>{copy.lede}</p>

      <div className={styles.notice} aria-live="polite">
        {notice && <Alert tone="success">{notice}</Alert>}
      </div>

      <section className={`${styles.panel} ${styles.securityPanel}`} aria-labelledby="two-factor-title">
        <div className={styles.sectionHead}>
          <h2 id="two-factor-title" className={styles.sectionTitle}>
            {copy.twoFactorTitle}
          </h2>
          <span className={enabled ? styles.statusOn : styles.statusOff}>{enabled ? copy.on : copy.off}</span>
        </div>
        <p className={styles.lede}>{enabled ? copy.explainOn : copy.explainOff}</p>

        {step.name === "idle" && (
          <div className={`${styles.actions} ${styles.spaced}`}>
            {enabled ? (
              <>
                <Button type="button" variant="secondary" onClick={() => start("regenerate")}>
                  {copy.regenerate}
                </Button>
                <Button type="button" variant="danger" onClick={() => start("disable")}>
                  {copy.disable}
                </Button>
              </>
            ) : (
              <Button type="button" onClick={() => start("enable")}>
                {copy.enable}
              </Button>
            )}
          </div>
        )}

        {step.name === "password" && (
          <PasswordStep
            action={step.action}
            onCancel={() => setStep({ name: "idle" })}
            onEnabled={(setup) => setStep({ name: "setup", setup })}
            onCodes={(codes) => setStep({ name: "codes", codes })}
            onDisabled={() => {
              setUser({ ...user, two_factor_enabled: false });
              setStep({ name: "idle" });
              setNotice(copy.disabled);
            }}
          />
        )}

        {step.name === "setup" && (
          <SetupStep
            setup={step.setup}
            onCancel={() => setStep({ name: "idle" })}
            onConfirmed={(codes) => {
              setUser({ ...user, two_factor_enabled: true });
              setStep({ name: "codes", codes });
              setNotice(copy.enabledNow);
            }}
          />
        )}

        {step.name === "codes" && <RecoveryCodes codes={step.codes} onDone={() => setStep({ name: "idle" })} />}
      </section>
    </main>
  );
}

function PasswordStep({
  action,
  onCancel,
  onEnabled,
  onCodes,
  onDisabled,
}: {
  action: Action;
  onCancel: () => void;
  onEnabled: (setup: Setup) => void;
  onCodes: (codes: string[]) => void;
  onDisabled: () => void;
}) {
  const { t } = useI18n();
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    const body = { password: form.get("password") };
    if (action === "enable") {
      const { data } = await api<{ data: Setup }>("/auth/two-factor", { method: "POST", body });
      onEnabled(data);
    } else if (action === "regenerate") {
      const { data } = await api<{ data: { recovery_codes: string[] } }>("/auth/two-factor/recovery-codes", { method: "POST", body });
      onCodes(data.recovery_codes);
    } else {
      await api("/auth/two-factor", { method: "DELETE", body });
      onDisabled();
    }
  });

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      {message && <Alert tone="error">{message}</Alert>}
      <Field
        label={t.common.password}
        hint={t.security.passwordPrompt}
        name="password"
        type="password"
        autoComplete="current-password"
        required
        autoFocus
        error={fieldError("password")}
      />
      <div className={styles.actions}>
        <Button type="submit" variant={action === "disable" ? "danger" : "primary"} disabled={pending}>
          {pending ? t.common.loading : action === "disable" ? t.security.disable : t.security.continue}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel}>
          {t.security.cancel}
        </Button>
      </div>
    </form>
  );
}

function SetupStep({ setup, onCancel, onConfirmed }: { setup: Setup; onCancel: () => void; onConfirmed: (codes: string[]) => void }) {
  const { t } = useI18n();
  const copy = t.security;
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    const { data } = await api<{ data: { recovery_codes: string[] } }>("/auth/two-factor/confirm", {
      method: "POST",
      body: { code: form.get("code") },
    });
    onConfirmed(data.recovery_codes);
  });
  // Le SVG vient de notre API ; affiché comme image, il ne peut exécuter aucun script.
  const qr = `data:image/svg+xml;base64,${btoa(setup.qr_svg)}`;

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      <ol className={styles.steps}>
        <li>{copy.step1}</li>
        <li>
          {copy.step2}
          {/* eslint-disable-next-line @next/next/no-img-element -- image générée, pas d'optimisation utile */}
          <img className={styles.qr} src={qr} alt={copy.qrAlt} width={200} height={200} />
          <p className={styles.muted}>
            {copy.manual}
            <br />
            <code className={styles.secret}>{setup.secret.match(/.{1,4}/g)?.join(" ")}</code>
          </p>
        </li>
        <li>
          {copy.step3}
          {message && <Alert tone="error">{message}</Alert>}
          <OtpField label={t.login.code} error={fieldError("code")} />
        </li>
      </ol>
      <div className={styles.actions}>
        <Button type="submit" disabled={pending}>
          {pending ? t.common.loading : copy.confirm}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel}>
          {copy.cancel}
        </Button>
      </div>
    </form>
  );
}

function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  const { t } = useI18n();
  const copy = t.security;
  const [copied, setCopied] = useState(false);

  async function copyCodes() {
    try {
      await navigator.clipboard.writeText(codes.join("\n"));
      setCopied(true);
    } catch {
      setCopied(false); // presse-papiers refusé : les codes restent affichés pour être notés
    }
  }

  return (
    <div className={styles.spaced}>
      <h3 className={styles.subTitle}>{copy.codesTitle}</h3>
      <p className={styles.lede}>{copy.codesLede}</p>
      <ul className={styles.codes}>
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ul>
      <div className={styles.actions}>
        <Button type="button" variant="secondary" onClick={copyCodes}>
          {copied ? copy.copied : copy.copy}
        </Button>
        <Button type="button" onClick={onDone}>
          {copy.done}
        </Button>
      </div>
    </div>
  );
}
