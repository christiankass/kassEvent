"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui";
import { api, isUnauthenticated, type Organization, type Role } from "@/lib/api";
import { getCurrentOrganizationId, setCurrentOrganizationId } from "@/lib/current-organization";
import { formErrorMessage } from "@/lib/errors";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { OrganizationContext } from "@/lib/organization-context";
import { PageSkeleton } from "../PageSkeleton";
import styles from "../app.module.css";

type Current = { data: Organization; meta: { roles: Role[]; permissions: string[] } };

/**
 * Pages qui agissent dans une organisation : choisit l'organisation courante (celle retenue
 * sur cet appareil si elle est toujours accessible, sinon la première), charge mon rôle et mes
 * permissions, et affiche la navigation de l'espace.
 */
export default function OrganizationLayout({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const router = useRouter();
  const pathname = usePathname();
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

  if (!organizations || !current) return <PageSkeleton />;

  const canManageTeam = current.meta.permissions.includes("members.manage");
  const links = [
    { href: "/tableau-de-bord", label: t.app.dashboard },
    ...(canManageTeam ? [{ href: "/equipe", label: t.app.team }] : []),
  ];

  return (
    <OrganizationContext.Provider
      value={{
        organization: current.data,
        role: current.meta.roles[0],
        permissions: current.meta.permissions,
      }}
    >
      <div className={styles.subbar}>
        <nav aria-label={t.app.nav} className={styles.tabs}>
          {links.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className={styles.tab}
              aria-current={pathname === link.href ? "page" : undefined}
            >
              {link.label}
            </Link>
          ))}
        </nav>
        {organizations.length > 1 && (
          <label className={styles.switcher}>
            <span className={styles.switcherLabel}>{t.app.switchOrganization}</span>
            <select className={styles.select} value={current.data.id} onChange={(e) => select(e.target.value)}>
              {organizations.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.name}
                </option>
              ))}
            </select>
          </label>
        )}
      </div>
      {children}
    </OrganizationContext.Provider>
  );
}
