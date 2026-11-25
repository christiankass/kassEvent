import type { Metadata } from "next";
import { Suspense } from "react";
import { RsvpView } from "./RsvpView";

/** Lien personnel de l'invité : jamais indexé, et sa clé ne part pas vers d'autres sites. */
export const metadata: Metadata = {
  title: "kassEvent",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default function RsvpPage() {
  return (
    <Suspense>
      <RsvpView />
    </Suspense>
  );
}
