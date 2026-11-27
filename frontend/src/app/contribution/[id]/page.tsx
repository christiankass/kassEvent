import type { Metadata } from "next";
import { Suspense } from "react";
import { ContributionView } from "./ContributionView";

/** Lien personnel du contributeur : jamais indexé, et sa clé ne part pas vers d'autres sites. */
export const metadata: Metadata = {
  title: "kassEvent",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default function ContributionPage() {
  return (
    <Suspense>
      <ContributionView />
    </Suspense>
  );
}
