"use client";

import Link from "next/link";
import { ShieldCheck } from "@phosphor-icons/react";
import { Alert, buttonClass } from "@/components/ui";
import { ApiError } from "@/lib/api";
import { formErrorMessage } from "@/lib/errors";
import { useI18n } from "@/lib/i18n/I18nProvider";
import app from "../app.module.css";
import styles from "./gate.module.css";

/**
 * Page refusée au premier chargement : double authentification à activer, rôle sans accès, ou erreur.
 * Rend null si l'erreur n'est pas de ce type (la page s'affiche alors normalement).
 */
export function AccessGate({ error, twoFactor, forbidden }: { error: unknown; twoFactor: string; forbidden: string }) {
  const { t } = useI18n();
  if (error instanceof ApiError && error.code === "two_factor_required") {
    return (
      <main className={app.main}>
        <section className={`${app.panel} ${styles.gate}`} aria-labelledby="gate-title">
          <ShieldCheck size={32} aria-hidden />
          <h2 id="gate-title" className={app.sectionTitle}>
            {t.sales.twoFactorTitle}
          </h2>
          <p className={app.muted}>{twoFactor}</p>
          <Link href="/compte/securite" className={buttonClass()}>
            {t.sales.twoFactorCta}
          </Link>
        </section>
      </main>
    );
  }
  return (
    <main className={app.main}>
      <Alert tone="error">{error instanceof ApiError && error.status === 403 ? forbidden : formErrorMessage(error, t)}</Alert>
    </main>
  );
}
