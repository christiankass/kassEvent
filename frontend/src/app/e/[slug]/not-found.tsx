import Link from "next/link";
import { Wordmark } from "@/components/ui";
import { dictionaries } from "@/lib/i18n/dictionaries";
import { resolveLocale } from "@/lib/i18n/server";
import styles from "./public.module.css";

export default async function EventNotFound() {
  const t = dictionaries[await resolveLocale()];
  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <Wordmark />
      </header>
      <main className={styles.main}>
        <h1 className={styles.title}>{t.publicEvent.notFoundTitle}</h1>
        <p className={styles.buyHint}>{t.publicEvent.notFoundBody}</p>
        <p className={styles.footer}>
          <Link href="/">kassEvent</Link>
        </p>
      </main>
    </div>
  );
}
