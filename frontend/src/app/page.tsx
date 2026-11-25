import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import {
  ArrowRight,
  CheckCircle,
  EnvelopeSimple,
  MessengerLogo,
  Translate,
  UsersThree,
  WhatsappLogo,
} from "@phosphor-icons/react/dist/ssr";
import { LocaleSwitch, Wordmark } from "@/components/ui";
import { dictionaries } from "@/lib/i18n/dictionaries";
import { resolveLocale } from "@/lib/i18n/server";
import billetConcert from "@/assets/accueil/billet-concert.webp";
import billetRumba from "@/assets/accueil/billet-rumba.webp";
import envoiMessenger from "@/assets/accueil/envoi-messenger.webp";
import invites from "@/assets/accueil/invites.webp";
import modelesAffiches from "@/assets/accueil/modeles-affiches.webp";
import paiementMobileMoney from "@/assets/accueil/paiement-mobile-money.webp";
import salleTables from "@/assets/accueil/salle-tables.webp";
import scanValide from "@/assets/accueil/scan-valide.webp";
import versements from "@/assets/accueil/versements.webp";
import styles from "./page.module.css";

export async function generateMetadata(): Promise<Metadata> {
  const t = dictionaries[await resolveLocale()].home;
  return { title: t.metaTitle, description: t.metaDescription };
}

/** Page d'accueil publique : présente kassEvent avant la connexion ou l'inscription. */
export default async function Home() {
  const t = dictionaries[await resolveLocale()].home;
  const f = t.features;

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div className={styles.headerInner}>
          <Wordmark />
          <nav aria-label={t.nav.label} className={styles.nav}>
            <a href="#fonctionnalites">{t.nav.features}</a>
            <a href="#modeles">{t.nav.studio}</a>
            <a href="#particuliers">{t.nav.private}</a>
          </nav>
          <div className={styles.headerActions}>
            <LocaleSwitch />
            <Link href="/connexion" className={styles.login}>
              {t.login}
            </Link>
            <Link href="/inscription" className={`${styles.cta} ${styles.headerCta}`}>
              {t.register}
            </Link>
          </div>
        </div>
      </header>

      <main>
        <section className={styles.hero}>
          <div className={styles.heroText}>
            <h1 className={styles.heroTitle}>
              {t.hero.title[0]}
              <mark>{t.hero.title[1]}</mark>
              {t.hero.title[2]}
            </h1>
            <p className={styles.heroLede}>{t.hero.lede}</p>
            <p className={styles.heroActions}>
              <Link href="/inscription" className={styles.cta}>
                {t.register}
                <ArrowRight size={18} weight="bold" aria-hidden />
              </Link>
              <Link href="/connexion" className={styles.ghost}>
                {t.login}
              </Link>
            </p>
          </div>
          <div className={styles.heroArt}>
            <Image
              src={billetConcert}
              alt={t.hero.ticketAlt}
              priority
              sizes="(min-width: 960px) 300px, 46vw"
              className={`${styles.ticket} ${styles.ticketBack}`}
            />
            <Image
              src={billetRumba}
              alt={t.hero.invitationAlt}
              priority
              sizes="(min-width: 960px) 300px, 46vw"
              className={`${styles.ticket} ${styles.ticketFront}`}
            />
            <div className={`${styles.phone} ${styles.heroPhone}`}>
              <Image src={paiementMobileMoney} alt={t.hero.phoneAlt} sizes="220px" />
            </div>
          </div>
        </section>

        <section className={styles.pay} aria-labelledby="paiement-titre">
          <h2 id="paiement-titre" className={styles.payTitle}>
            {t.pay.title}
          </h2>
          <ul className={styles.payList}>
            {t.pay.methods.map((m) => (
              <li key={m}>{m}</li>
            ))}
          </ul>
          <p className={styles.payNote}>{t.pay.currencies}</p>
        </section>

        <section id="fonctionnalites" className={styles.section} aria-labelledby="fonctionnalites-titre">
          <h2 id="fonctionnalites-titre" className={styles.sectionTitle}>
            {f.title}
          </h2>
          <div className={styles.bento}>
            <article className={`${styles.cell} ${styles.cellPay}`}>
              <div className={styles.cellText}>
                <h3>{f.pay.title}</h3>
                <p>{f.pay.body}</p>
              </div>
              <div className={styles.cellShot}>
                <Image src={paiementMobileMoney} alt={f.pay.alt} sizes="(min-width: 960px) 260px, 70vw" />
              </div>
            </article>

            <article className={`${styles.cell} ${styles.cellScan}`}>
              <div className={styles.cellText}>
                <h3>{f.scan.title}</h3>
                <p>{f.scan.body}</p>
              </div>
              <div className={styles.cellShot}>
                <Image src={scanValide} alt={f.scan.alt} sizes="(min-width: 960px) 280px, 70vw" />
              </div>
            </article>

            <article className={`${styles.cell} ${styles.cellSend}`}>
              <div className={styles.cellText}>
                <p className={styles.channels} aria-hidden>
                  <EnvelopeSimple size={22} weight="bold" />
                  <WhatsappLogo size={22} weight="bold" />
                  <MessengerLogo size={22} weight="bold" />
                </p>
                <h3>{f.send.title}</h3>
                <p>{f.send.body}</p>
              </div>
              <div className={styles.cellShot}>
                <Image src={envoiMessenger} alt={f.send.alt} sizes="(min-width: 960px) 260px, 70vw" />
              </div>
            </article>

            <article className={`${styles.cell} ${styles.cellPayouts}`}>
              <div className={styles.cellText}>
                <h3>{f.payouts.title}</h3>
                <p>{f.payouts.body}</p>
              </div>
              <div className={styles.cellShot}>
                <Image src={versements} alt={f.payouts.alt} sizes="(min-width: 960px) 260px, 70vw" />
              </div>
            </article>

            <article className={`${styles.cell} ${styles.cellTeam}`}>
              <UsersThree size={40} weight="duotone" aria-hidden className={styles.cellIcon} />
              <h3>{f.team.title}</h3>
              <p>{f.team.body}</p>
            </article>

            <article className={`${styles.cell} ${styles.cellLang}`}>
              <Translate size={40} weight="duotone" aria-hidden className={styles.cellIcon} />
              <h3>{f.languages.title}</h3>
              <p>{f.languages.body}</p>
            </article>
          </div>
        </section>

        <section id="modeles" className={`${styles.section} ${styles.studio}`} aria-labelledby="modeles-titre">
          <div className={styles.studioText}>
            <h2 id="modeles-titre" className={styles.sectionTitle}>
              {t.studio.title}
            </h2>
            <p className={styles.sectionLede}>{t.studio.body}</p>
            <ul className={styles.points}>
              {t.studio.points.map((p) => (
                <li key={p}>
                  <CheckCircle size={22} weight="fill" aria-hidden />
                  {p}
                </li>
              ))}
            </ul>
          </div>
          <div className={styles.studioShot}>
            <Image src={modelesAffiches} alt={t.studio.alt} sizes="(min-width: 1120px) 640px, 92vw" />
          </div>
        </section>

        <section id="particuliers" className={`${styles.section} ${styles.private}`} aria-labelledby="particuliers-titre">
          <div className={styles.privateText}>
            <p className={styles.eyebrow}>{t.private.eyebrow}</p>
            <h2 id="particuliers-titre" className={styles.sectionTitle}>
              {t.private.title}
            </h2>
            <p className={styles.sectionLede}>{t.private.body}</p>
            <ul className={styles.points}>
              {t.private.points.map((p) => (
                <li key={p}>
                  <CheckCircle size={22} weight="fill" aria-hidden />
                  {p}
                </li>
              ))}
            </ul>
          </div>
          <div className={styles.privateArt}>
            <div className={`${styles.phone} ${styles.phoneBack}`}>
              <Image src={salleTables} alt={t.private.tablesAlt} sizes="(min-width: 960px) 260px, 44vw" />
            </div>
            <div className={`${styles.phone} ${styles.phoneFront}`}>
              <Image src={invites} alt={t.private.guestsAlt} sizes="(min-width: 960px) 260px, 44vw" />
            </div>
          </div>
        </section>

        <section className={styles.section} aria-labelledby="etapes-titre">
          <h2 id="etapes-titre" className={styles.sectionTitle}>
            {t.steps.title}
          </h2>
          <ol className={styles.steps}>
            {t.steps.items.map((s) => (
              <li key={s.verb}>
                <strong>{s.verb}</strong>
                <span>{s.body}</span>
              </li>
            ))}
          </ol>
        </section>

        <section className={styles.final} aria-labelledby="final-titre">
          <h2 id="final-titre">{t.final.title}</h2>
          <p>{t.final.body}</p>
          <Link href="/inscription" className={styles.finalCta}>
            {t.register}
            <ArrowRight size={18} weight="bold" aria-hidden />
          </Link>
        </section>
      </main>

      <footer className={styles.footer}>
        <Wordmark />
        <p>{t.footer}</p>
        <p>© 2026 kassEvent</p>
      </footer>
    </div>
  );
}
