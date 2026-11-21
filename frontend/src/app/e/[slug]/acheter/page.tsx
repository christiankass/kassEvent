import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { dictionaries } from "@/lib/i18n/dictionaries";
import { resolveLocale } from "@/lib/i18n/server";
import { fetchPublicEvent } from "@/lib/public-event";
import { Checkout } from "./Checkout";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const event = await fetchPublicEvent((await params).slug);
  const t = dictionaries[await resolveLocale()];
  return { title: event ? `${t.checkout.title} · ${event.title} | kassEvent` : "kassEvent", robots: { index: false } };
}

export default async function CheckoutPage({ params }: Props) {
  const event = await fetchPublicEvent((await params).slug);
  if (!event) notFound();

  return <Checkout event={event} />;
}
