import type { Metadata } from "next";
import { WishBookView } from "./WishBookView";

/** Lien partagé entre proches : pas indexé par les moteurs de recherche. */
export const metadata: Metadata = {
  title: "kassEvent",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default function WishBookPage() {
  return <WishBookView />;
}
