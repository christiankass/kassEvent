"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { SignOut } from "@phosphor-icons/react";
import { Alert, Button, LocaleSwitch, Wordmark } from "@/components/ui";
import { api } from "@/lib/api";
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
            <Button type="button" variant="secondary" onClick={logout} aria-label={t.app.logout} className={styles.logout}>
              <SignOut size={20} aria-hidden />
              <span className={styles.logoutLabel}>{t.app.logout}</span>
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
        <UserContext.Provider value={session.user}>{children}</UserContext.Provider>
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
