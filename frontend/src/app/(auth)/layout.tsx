"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { LocaleSwitch, Wordmark } from "@/components/ui";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useSession } from "@/lib/session";
import styles from "./auth.module.css";

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const session = useSession();
  const router = useRouter();

  // Déjà connecté : pas besoin de revoir ces pages.
  useEffect(() => {
    if (session.status === "signed-in") router.replace("/tableau-de-bord");
  }, [session.status, router]);

  return (
    <div className={styles.shell}>
      <aside className={styles.panel}>
        <Wordmark />
        <h2 className={styles.panelTagline}>
          {t.brand.headline[0]}
          <mark>{t.brand.headline[1]}</mark>
          {t.brand.headline[2]}
        </h2>
        <p className={styles.panelNote}>{t.brand.tagline}</p>
      </aside>
      <div className={styles.main}>
        <header className={styles.topbar}>
          <span className={styles.wordmarkMobile}>
            <Wordmark />
          </span>
          <LocaleSwitch />
        </header>
        <main className={styles.content}>{children}</main>
      </div>
    </div>
  );
}
