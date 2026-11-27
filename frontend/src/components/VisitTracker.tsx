"use client";

import { useEffect } from "react";
import { recordVisit } from "@/lib/referral";

/** Compte la visite de la page publique et retient le canal d'où vient le visiteur (statistiques). */
export function VisitTracker({ slug }: { slug: string }) {
  useEffect(() => recordVisit(slug), [slug]);
  return null;
}
