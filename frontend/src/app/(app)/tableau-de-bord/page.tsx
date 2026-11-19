"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Alert, Button } from "@/components/ui";
import { api, isUnauthenticated, type Organization } from "@/lib/api";
import { getCurrentOrganizationId, setCurrentOrganizationId } from "@/lib/current-organization";
import { formErrorMessage } from "@/lib/errors";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useUser } from "@/lib/user-context";
import { PageSkeleton } from "../PageSkeleton";
import styles from "../app.module.css";

type Current = { data: Organization; meta: { roles: string[]; permissions: string[] } };

export default function DashboardPage() {
  const { t } = useI18n();
  const user = useUser();
  const router = useRouter();
  const [organizations, setOrganizations] = useState<Organization[] | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [current, setCurrent] = useState<Current | null>(null);
  const [error, setError] = useState<unknown>(null);

  // Session expirée en cours de route : retour à la connexion.
  const fail = useCallback(
    (e: unknown) => (isUnauthenticated(e) ? router.replace("/connexion") : setError(e)),
    [router],
  );

  useEffect(() => {
    api<{ data: Organization[] }>("/organizations")
      .then(({ data }) => {
        if (data.length === 0) {
          router.replace("/organisation/nouvelle");
          return;
        }
        setOrganizations(data);
        const stored = getCurrentOrganizationId();
        setSelectedId(data.some((o) => o.id === stored) ? stored : data[0].id);
      })
      .catch(fail);
  }, [router, fail]);

  useEffect(() => {
    if (!selectedId) return;
    let cancelled = false; // une réponse arrivée après un changement d'organisation est ignorée
    api<Current>("/organization", { organizationId: selectedId })
      .then((result) => !cancelled && setCurrent(result))
      .catch((e) => !cancelled && fail(e));
    return () => {
      cancelled = true;
    };
  }, [selectedId, fail]);

  function select(id: string) {
    setCurrentOrganizationId(id);
    setCurrent(null);
    setSelectedId(id);
  }

  if (error) {
    return (
      <main className={styles.main}>
        <Alert tone="error">{formErrorMessage(error, t)}</Alert>
      </main>
    );
  }

  if (!organizations || !current) {
    return <PageSkeleton />;
  }

  const role = current.meta.roles[0] as keyof typeof t.dashboard.roles | undefined;

  return (
    <main className={styles.main}>
      <h1 className={styles.title}>{format(t.dashboard.hello, { name: user.name.split(" ")[0] })}</h1>
      <p className={styles.lede}>
        {format(t.dashboard.roleIntro, {
          organization: current.data.name,
          role: role ? t.dashboard.roles[role] ?? role : "",
        })}
      </p>

      {organizations.length > 1 && (
        <p>
          <label>
            {t.app.switchOrganization}{" "}
            <select className={styles.select} value={current.data.id} onChange={(e) => select(e.target.value)}>
              {organizations.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.name}
                </option>
              ))}
            </select>
          </label>
        </p>
      )}

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
