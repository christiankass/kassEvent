"use client";

import Link from "next/link";
import { buttonClass } from "@/components/ui";
import type { Role } from "@/lib/api";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { useUser } from "@/lib/user-context";
import styles from "../../app.module.css";

/** Rôles qui touchent à l'argent ou aux accès : la double authentification leur est recommandée. */
const SENSITIVE_ROLES: Role[] = ["owner", "admin", "finance"];

export default function DashboardPage() {
  const { t } = useI18n();
  const user = useUser();
  const { organization, role, permissions } = useOrganization();
  const canManageEvents = permissions.includes("events.manage");

  return (
    <main className={styles.main}>
      <h1 className={styles.title}>{format(t.dashboard.hello, { name: user.name.split(" ")[0] })}</h1>
      <p className={styles.lede}>
        {format(t.dashboard.roleIntro, {
          organization: organization.name,
          role: role ? t.roles[role][0].toLowerCase() : "",
        })}
      </p>

      {!user.two_factor_enabled && role && SENSITIVE_ROLES.includes(role) && (
        <div className={styles.banner}>
          <p>{format(t.security.recommended, { organization: organization.name })}</p>
          <Link href="/compte/securite" className={styles.bannerLink}>
            {t.security.recommendedCta}
          </Link>
        </div>
      )}

      <section className={styles.empty} aria-labelledby="empty-title">
        <h2 id="empty-title" className={styles.emptyTitle}>
          {t.dashboard.emptyTitle}
        </h2>
        <p className={styles.emptyBody}>{t.dashboard.emptyBody}</p>
        {canManageEvents ? (
          <Link href="/evenements/nouveau" className={buttonClass()}>
            {t.dashboard.emptyCta}
          </Link>
        ) : (
          <Link href="/evenements" className={buttonClass("secondary")}>
            {t.dashboard.seeEvents}
          </Link>
        )}
      </section>
    </main>
  );
}
