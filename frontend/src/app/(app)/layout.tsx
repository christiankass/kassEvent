"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { ShieldCheck, SignOut } from "@phosphor-icons/react";
import { Alert, Button, buttonClass, LocaleSwitch, Wordmark } from "@/components/ui";
import { api } from "@/lib/api";
import { clearDesks } from "@/lib/check-in";
import { setCurrentOrganizationId } from "@/lib/current-organization";
import { formErrorMessage } from "@/lib/errors";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useSession } from "@/lib/session";
import { UserContext } from "@/lib/user-context";
import { PageSkeleton } from "./PageSkeleton";
import styles from "./app.module.css";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const session = useSession();
  const router = useRouter();
  const [logoutError, setLogoutError] = useState(false);

  useEffect(() => {
    if (session.status === "guest") router.replace("/connexion");
  }, [session.status, router]);

  async function logout() {
    setLogoutError(false);
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      setLogoutError(true);
      return;
    }
    setCurrentOrganizationId(null);
    await clearDesks(); // listes de participants gardées pour le scan hors ligne
    router.replace("/connexion");
  }

  return (
    <>
      <header className={styles.header}>
        <div className={styles.headerInner}>
          <Wordmark href="/tableau-de-bord" />
          <span className={styles.spacer} />
          <LocaleSwitch />
          {session.status === "signed-in" && (
            <Link href="/compte/securite" className={buttonClass("secondary", styles.iconButton)} aria-label={t.app.security}>
              <ShieldCheck size={20} aria-hidden />
              <span className={styles.headerLabel}>{t.app.security}</span>
            </Link>
          )}
          {session.status === "signed-in" && (
            <Button type="button" variant="secondary" onClick={logout} aria-label={t.app.logout} className={styles.iconButton}>
              <SignOut size={20} aria-hidden />
              <span className={styles.headerLabel}>{t.app.logout}</span>
            </Button>
          )}
        </div>
      </header>
      {logoutError && (
        <div className={styles.main}>
          <Alert tone="error">{t.app.logoutFailed}</Alert>
        </div>
      )}
      {session.status === "signed-in" ? (
        <UserContext.Provider value={{ user: session.user, setUser: session.setUser }}>{children}</UserContext.Provider>
      ) : session.status === "error" ? (
        <main className={styles.main}>
          <Alert tone="error">{formErrorMessage(session.error, t)}</Alert>
          <p>
            <Button type="button" variant="secondary" onClick={session.retry}>
              {t.common.retry}
            </Button>
          </p>
        </main>
      ) : (
        <PageSkeleton />
      )}
    </>
  );
}
