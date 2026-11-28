import type { Metadata } from "next";
import { Suspense } from "react";
import { UnsubscribeView } from "./UnsubscribeView";

/** Lien personnel de désinscription : jamais indexé, et sa clé ne part pas vers d'autres sites. */
export const metadata: Metadata = {
  title: "kassEvent",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default function UnsubscribePage() {
  return (
    <Suspense>
      <UnsubscribeView />
    </Suspense>
  );
}
