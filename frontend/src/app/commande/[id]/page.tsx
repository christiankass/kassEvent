import type { Metadata } from "next";
import { Suspense } from "react";
import { OrderView } from "./OrderView";

/** Lien personnel de l'acheteur : jamais indexé, et sa clé ne part pas vers d'autres sites. */
export const metadata: Metadata = {
  title: "kassEvent",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default function OrderPage() {
  return (
    <Suspense>
      <OrderView />
    </Suspense>
  );
}
