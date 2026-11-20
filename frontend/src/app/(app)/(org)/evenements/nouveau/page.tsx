"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { ArrowLeft } from "@phosphor-icons/react";
import { api } from "@/lib/api";
import type { EventData } from "@/lib/events";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import app from "../../../app.module.css";
import { EventForm } from "../forms";
import styles from "../events.module.css";

export default function NewEventPage() {
  const { t } = useI18n();
  const router = useRouter();
  const { organization, permissions } = useOrganization();
  const canManage = permissions.includes("events.manage");

  useEffect(() => {
    if (!canManage) router.replace("/evenements");
  }, [canManage, router]);

  return (
    <main className={`${app.main} ${app.narrow}`}>
      <Link href="/evenements" className={styles.back}>
        <ArrowLeft size={18} aria-hidden /> {t.events.back}
      </Link>
      <h1 className={app.title}>{t.events.newTitle}</h1>
      <p className={app.lede}>{t.events.newLede}</p>
      <EventForm
        currency={organization.currency}
        submitLabel={t.events.createSubmit}
        onSubmit={async (body) => {
          const { data } = await api<{ data: EventData }>("/events", {
            method: "POST",
            organizationId: organization.id,
            body,
          });
          router.push(`/evenements/${data.id}`);
        }}
      />
    </main>
  );
}
