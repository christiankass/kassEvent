"use client";

import { Button } from "@/components/ui";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { useUser } from "@/lib/user-context";
import styles from "../../app.module.css";

export default function DashboardPage() {
  const { t } = useI18n();
  const user = useUser();
  const { organization, role } = useOrganization();

  return (
    <main className={styles.main}>
      <h1 className={styles.title}>{format(t.dashboard.hello, { name: user.name.split(" ")[0] })}</h1>
      <p className={styles.lede}>
        {format(t.dashboard.roleIntro, {
          organization: organization.name,
          role: role ? t.roles[role][0].toLowerCase() : "",
        })}
      </p>

      <section className={styles.empty} aria-labelledby="empty-title">
        <h2 id="empty-title" className={styles.emptyTitle}>
          {t.dashboard.emptyTitle}
        </h2>
        <p className={styles.emptyBody}>{t.dashboard.emptyBody}</p>
        <Button type="button" disabled>
          {t.dashboard.emptyCta}
        </Button>
      </section>
    </main>
  );
}
