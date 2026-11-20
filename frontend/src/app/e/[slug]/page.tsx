import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";
import { CalendarBlank, MapPin } from "@phosphor-icons/react/dist/ssr";
import { ShareButtons } from "@/components/ShareButtons";
import { Wordmark } from "@/components/ui";
import { formatEventDate, publicEventUrl, SERVER_API_URL, ticketPrice, type PublicEvent } from "@/lib/events";
import { dictionaries, format } from "@/lib/i18n/dictionaries";
import { resolveLocale } from "@/lib/i18n/server";
import styles from "./public.module.css";


type Props = { params: Promise<{ slug: string }> };

/** Une seule requête par affichage, partagée entre la page et ses métadonnées. */
const fetchEvent = cache(async (slug: string): Promise<PublicEvent | null> => {
  if (!/^[a-z0-9-]{1,100}$/.test(slug)) return null;
  const response = await fetch(`${SERVER_API_URL}/public/events/${slug}`, {
    headers: { Accept: "application/json" },
    // Pas de cache : une page retirée de la vente disparaît aussitôt.
    cache: "no-store",
  });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`API ${response.status}`);
  return (await response.json()).data;
});

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const event = await fetchEvent((await params).slug);
  if (!event) return { title: "kassEvent" };
  const locale = await resolveLocale();
  const description = `${formatEventDate(event, locale)} · ${event.venue_name}, ${event.city}`;
  return {
    title: `${event.title} | kassEvent`,
    description,
    openGraph: {
      title: event.title,
      description,
      url: publicEventUrl(event.slug),
      images: event.cover_url ? [{ url: event.cover_url, width: 1200, height: 630 }] : undefined,
    },
  };
}

export default async function PublicEventPage({ params }: Props) {
  const event = await fetchEvent((await params).slug);
  if (!event) notFound();

  const locale = await resolveLocale();
  const t = dictionaries[locale];
  const price = (minor: number) => ticketPrice(minor, event.currency, locale, t);
  const cheapest = Math.min(...event.ticket_types.map((ticket) => ticket.price));
  // La fin est déjà à l'heure du lieu : on la lit comme une heure UTC pour l'afficher telle quelle.
  const sameDay = event.ends_at?.slice(0, 10) === event.starts_at.slice(0, 10);
  const end = event.ends_at
    ? new Intl.DateTimeFormat(locale, {
        ...(sameDay ? {} : { dateStyle: "full" }),
        timeStyle: "short",
        timeZone: "UTC",
      }).format(new Date(`${event.ends_at}:00Z`))
    : null;

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <Wordmark />
      </header>

      <main className={styles.main}>
        <div className={styles.hero}>
          <div className={styles.cover}>
            {event.cover_url ? (
              // Affiche hébergée par kassEvent, déjà au bon format : pas d'optimisation d'image nécessaire.
              // eslint-disable-next-line @next/next/no-img-element
              <img src={event.cover_url} alt={format(t.events.coverAlt, { title: event.title })} />
            ) : (
              <span className={styles.coverFallback} aria-hidden>
                {event.title}
              </span>
            )}
          </div>

          <div className={styles.heading}>
            {event.organization && <p className={styles.by}>{format(t.publicEvent.by, { name: event.organization.name })}</p>}
            <h1 className={styles.title}>{event.title}</h1>
            <dl className={styles.facts}>
              <div>
                <dt>
                  <CalendarBlank size={20} aria-hidden /> {t.publicEvent.when}
                </dt>
                <dd>
                  {formatEventDate(event, locale)}
                  {end && ` – ${end}`}
                </dd>
              </div>
              <div>
                <dt>
                  <MapPin size={20} aria-hidden /> {t.publicEvent.where}
                </dt>
                <dd>
                  {event.venue_name}
                  <br />
                  <span className={styles.muted}>{[event.venue_address, event.city].filter(Boolean).join(", ")}</span>
                </dd>
              </div>
            </dl>
          </div>
        </div>

        <div className={styles.body}>
          <section aria-labelledby="tickets-title">
            <h2 id="tickets-title" className={styles.sectionTitle}>
              {t.publicEvent.tickets}
            </h2>
            <ul className={styles.ticketList}>
              {event.ticket_types.map((ticket) => (
                <li key={ticket.id} className={styles.ticket}>
                  <div className={styles.ticketMain}>
                    <span className={styles.ticketName}>{ticket.name}</span>
                    {ticket.description && <span className={styles.muted}>{ticket.description}</span>}
                    {!ticket.on_sale && <span className={styles.offSale}>{t.publicEvent.notOnSale}</span>}
                  </div>
                  <span className={styles.ticketPrice}>{price(ticket.price)}</span>
                </li>
              ))}
            </ul>
            <p className={styles.buyHint}>{t.publicEvent.buyHint}</p>
          </section>

          {event.description && (
            <section aria-labelledby="about-title">
              <h2 id="about-title" className={styles.sectionTitle}>
                {t.publicEvent.about}
              </h2>
              <p className={styles.description}>{event.description}</p>
            </section>
          )}

          <section aria-label={t.share.title}>
            <ShareButtons url={publicEventUrl(event.slug)} title={event.title} />
          </section>
        </div>
      </main>

      {/* Barre d'achat toujours visible sur téléphone (DESIGN.md) ; la vente ouvre avec les paiements. */}
      <div className={styles.buyBar}>
        <div className={styles.buyInner}>
          {event.ticket_types.length > 0 && (
            <span className={styles.from}>
              {format(t.publicEvent.from, { price: price(cheapest) })}
            </span>
          )}
          <button type="button" className={styles.buy} disabled>
            {t.publicEvent.buy}
          </button>
        </div>
      </div>

      <footer className={styles.footer}>
        {t.publicEvent.poweredBy} <Link href="/">kassEvent</Link>
      </footer>
    </div>
  );
}
