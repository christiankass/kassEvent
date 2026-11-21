"use client";

import dynamic from "next/dynamic";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui";
import { api } from "@/lib/api";
import type { StudioData, Usage } from "@/lib/canvas";
import { formErrorMessage } from "@/lib/errors";
import type { EventData } from "@/lib/events";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { useUser } from "@/lib/user-context";
import { PageSkeleton } from "../../../../PageSkeleton";
import app from "../../../../app.module.css";

// Le dessin (Konva) n'existe que dans le navigateur.
const Studio = dynamic(() => import("@/components/studio/Studio"), { ssr: false, loading: () => <PageSkeleton /> });

/** Studio de l'événement : billet (ou invitation) et affiche, à créer librement. */
export default function StudioPage() {
  const { t } = useI18n();
  const user = useUser();
  const router = useRouter();
  const { id } = useParams<{ id: string }>();
  const usage: Usage = useSearchParams().get("format") === "poster" ? "poster" : "ticket";
  const { organization, permissions } = useOrganization();
  const organizationId = organization.id;
  const [data, setData] = useState<{ event: EventData; studio: StudioData } | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);

  useEffect(() => {
    Promise.all([
      api<{ data: EventData }>(`/events/${id}`, { organizationId }),
      api<{ data: StudioData }>(`/events/${id}/studio`, { organizationId }),
    ])
      .then(([event, studio]) => setData({ event: event.data, studio: studio.data }))
      .catch(setLoadError);
  }, [id, organizationId]);

  if (loadError) {
    return (
      <main className={app.main}>
        <Alert tone="error">{formErrorMessage(loadError, t)}</Alert>
      </main>
    );
  }
  if (!data) return <PageSkeleton />;

  return (
    <Studio
      event={data.event}
      studio={data.studio}
      organizationId={organizationId}
      organizationName={organization.name}
      canManage={permissions.includes("events.manage")}
      userName={user.name}
      usage={usage}
      onUsageChange={(next) => router.replace(`/evenements/${id}/studio${next === "poster" ? "?format=poster" : ""}`, { scroll: false })}
    />
  );
}
