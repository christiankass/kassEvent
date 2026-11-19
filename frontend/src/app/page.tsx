import Link from "next/link";
import { LocaleSwitch } from "@/components/ui";
import { dictionaries } from "@/lib/i18n/dictionaries";
import { resolveLocale } from "@/lib/i18n/server";
import styles from "./page.module.css";

export default async function Home() {
  const t = dictionaries[await resolveLocale()].home;
  return (
    <main className={styles.main}>
      <div className={styles.locale}>
        <LocaleSwitch />
      </div>
      <h1 className={styles.title}>
        kass<mark>Event</mark>
      </h1>
      <p className={styles.lede}>{t.lede}</p>
      <p className={styles.actions}>
        <Link href="/inscription" className={styles.primary}>
          {t.register}
        </Link>
        <Link href="/connexion">{t.login}</Link>
      </p>
    </main>
  );
}
