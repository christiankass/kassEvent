"use client";

import { useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { invitationPath, readInvitationLink } from "@/lib/invitation-link";
import { useSession } from "@/lib/session";
import { AuthShell } from "./AuthShell";

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  const session = useSession();
  const router = useRouter();
  const params = useSearchParams();

  // Déjà connecté : pas besoin de revoir ces pages (on revient à l'invitation si on en suivait une).
  useEffect(() => {
    if (session.status !== "signed-in") return;
    const link = readInvitationLink(params);
    router.replace(link ? invitationPath(link) : "/tableau-de-bord");
  }, [session.status, router, params]);

  return <AuthShell>{children}</AuthShell>;
}
