"use client";

import { useParams, usePathname } from "next/navigation";
import { WorkspaceNav } from "../WorkspaceNav";

/** Espace de travail de l'événement : les mêmes onglets au-dessus de chaque page, sauf le scan (plein écran). */
export default function EventWorkspaceLayout({ children }: { children: React.ReactNode }) {
  const { id } = useParams<{ id: string }>();
  const pathname = usePathname();
  if (pathname.endsWith("/scanner")) return children;
  return (
    <>
      <WorkspaceNav eventId={id} wide={pathname.endsWith("/studio")} />
      {children}
    </>
  );
}
