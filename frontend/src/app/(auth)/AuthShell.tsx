"use client";

import { LocaleSwitch, Wordmark } from "@/components/ui";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "./auth.module.css";

/** Écran partagé : panneau vert de la marque sur grand écran, barre simple sur téléphone. */
export function AuthShell({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
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
