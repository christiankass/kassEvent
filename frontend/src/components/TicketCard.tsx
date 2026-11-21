"use client";

import type { CSSProperties } from "react";
import { FONT_STACKS, type TicketDesign } from "@/lib/designs";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { ticketFontVariables } from "@/lib/ticket-fonts";
import styles from "./ticket-card.module.css";

type Props = {
  design: TicketDesign;
  organization: string;
  title: string;
  when: string;
  where: string;
  type: string;
  holder: string;
  qr: string;
  code: string;
};

/**
 * Billet signature aux couleurs de l'organisateur (même rendu que l'e-mail) : bannière, en-tête
 * coloré avec logo, titre dans la police choisie, ligne perforée, QR code toujours noir sur blanc.
 */
export function TicketCard({ design, organization, title, when, where, type, holder, qr, code }: Props) {
  const { t } = useI18n();
  const style = {
    "--t-primary": design.primary_color,
    "--t-on-primary": design.colors.on_primary,
    "--t-accent": design.accent_color,
    "--t-on-accent": design.colors.on_accent,
    "--t-stub": design.colors.stub,
    "--t-display": FONT_STACKS[design.font],
  } as CSSProperties;

  return (
    <article className={`${styles.ticket} ${ticketFontVariables}`} style={style}>
      {design.banner_url && (
        // eslint-disable-next-line @next/next/no-img-element -- image de l'organisateur servie par kassEvent
        <img src={design.banner_url} alt="" className={styles.banner} />
      )}
      <header className={styles.head}>
        {design.logo_url && (
          // eslint-disable-next-line @next/next/no-img-element -- logo de l'organisateur servi par kassEvent
          <img src={design.logo_url} alt={organization} className={styles.logo} />
        )}
        <p className={styles.kicker}>
          <span className={styles.headline}>{design.headline_text}</span>
          <span>{organization}</span>
        </p>
        <h3 className={`${styles.title} ${design.font === "script" ? styles.script : ""}`}>{title}</h3>
      </header>
      {design.message && <p className={styles.message}>{design.message}</p>}
      <dl className={styles.grid}>
        <div>
          <dt>{t.order.ticketWhen}</dt>
          <dd className={styles.capitalize}>{when}</dd>
        </div>
        <div>
          <dt>{t.order.ticketWhere}</dt>
          <dd>{where}</dd>
        </div>
        <div>
          <dt>{t.order.ticketType}</dt>
          <dd>{type}</dd>
        </div>
        <div>
          <dt>{design.kind === "invitation" ? t.order.ticketGuest : t.order.ticketHolder}</dt>
          <dd>{holder}</dd>
        </div>
      </dl>
      <div className={styles.stub}>
        {/* QR code généré par kassEvent (SVG en data URL) : pas d'optimisation d'image à faire. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={qr} alt={format(t.order.qrAlt, { code })} width={200} height={200} className={styles.qr} />
        <span className={styles.code}>{code}</span>
      </div>
      {design.footer && <p className={styles.footer}>{design.footer}</p>}
    </article>
  );
}
