"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { ReactNode } from "react";
import { ArrowLeft, Armchair, BookOpenText, Info, Lock, MagicWand, PaintBrush } from "@phosphor-icons/react";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "./events.module.css";

/**
 * Onglets de l'espace de travail d'un événement : informations et billets, studio, billet simple,
 * invités, livre d'or (vœux ou condoléances). Sans `eventId` (création), seuls les informations sont ouvertes : le reste s'ouvre
 * dès que le brouillon est créé.
 */
export function WorkspaceNav({ eventId, wide = false }: { eventId?: string; wide?: boolean }) {
  const { t } = useI18n();
  const pathname = usePathname();
  const w = t.events.workspace;
  const base = eventId ? `/evenements/${eventId}` : null;
  const tabs: { path: string; label: string; icon: ReactNode }[] = [
    { path: "", label: w.info, icon: <Info size={20} aria-hidden /> },
    { path: "/studio", label: w.studio, icon: <MagicWand size={20} aria-hidden /> },
    { path: "/personnaliser", label: w.look, icon: <PaintBrush size={20} aria-hidden /> },
    { path: "/invites", label: w.guests, icon: <Armchair size={20} aria-hidden /> },
    { path: "/voeux", label: w.wishes, icon: <BookOpenText size={20} aria-hidden /> },
  ];

  return (
    <div className={`${styles.workspace} ${wide ? styles.workspaceWide : ""}`}>
      <Link href="/evenements" className={styles.back}>
        <ArrowLeft size={18} aria-hidden /> {t.events.back}
      </Link>
      <nav aria-label={w.label}>
        <ul className={styles.workspaceTabs}>
          {tabs.map(({ path, label, icon }, index) => {
            // Création : l'onglet des informations est la page en cours, les autres attendent le brouillon.
            if (!base) {
              return (
                <li key={path}>
                  {index === 0 ? (
                    <span className={styles.workspaceTab} aria-current="page">
                      {icon} {label}
                    </span>
                  ) : (
                    <span className={styles.workspaceTab} aria-disabled="true">
                      <Lock size={16} aria-hidden /> {label}
                    </span>
                  )}
                </li>
              );
            }
            const href = base + path;
            return (
              <li key={path}>
                <Link href={href} className={styles.workspaceTab} aria-current={pathname === href ? "page" : undefined}>
                  {icon} {label}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>
      {!base && <p className={styles.workspaceHint}>{w.locked}</p>}
    </div>
  );
}
