"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Info, Plus } from "@phosphor-icons/react";
import { buttonClass } from "@/components/ui";
import { api } from "@/lib/api";
import { rate, type CampaignsIndex, type CampaignSummary } from "@/lib/campaigns";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { PageSkeleton } from "../../PageSkeleton";
import app from "../../app.module.css";
import { AccessGate } from "../AccessGate";
import book from "../contacts/contacts.module.css";
import styles from "./campaigns.module.css";

/** Campagnes e-mail : la liste, avec l'état et les chiffres de chacune. */
export default function CampaignsPage() {
  const { t, locale } = useI18n();
  const c = t.campaigns;
  const { organization } = useOrganization();
  const [index, setIndex] = useState<CampaignsIndex | null>(null);
  const [error, setError] = useState<unknown>(null);

  useEffect(() => {
    let cancelled = false;
    api<CampaignsIndex>("/campaigns", { organizationId: organization.id })
      .then((result) => !cancelled && setIndex(result))
      .catch((e) => !cancelled && setError(e));
    return () => {
      cancelled = true;
    };
  }, [organization.id]);

  if (error) return <AccessGate error={error} twoFactor={c.twoFactor} forbidden={c.forbidden} />;
  if (!index) return <PageSkeleton />;

  const date = new Intl.DateTimeFormat(locale, { dateStyle: "medium", timeStyle: "short" });
  const when = (campaign: CampaignSummary) =>
    campaign.status === "scheduled" && campaign.scheduled_at
      ? format(c.scheduledFor, { date: date.format(new Date(campaign.scheduled_at)) })
      : campaign.sent_at
        ? format(c.sentOn, { date: date.format(new Date(campaign.sent_at)) })
        : campaign.created_at
          ? format(c.createdOn, { date: date.format(new Date(campaign.created_at)) })
          : "";

  return (
    <main className={`${app.main} ${book.page}`}>
      <header className={styles.head}>
        <div>
          <h1 className={app.sectionTitle}>{c.title}</h1>
          <p className={book.lede}>{c.lede}</p>
        </div>
        <Link href="/campagnes/nouvelle" className={buttonClass("primary", styles.create)}>
          <Plus size={18} weight="bold" aria-hidden />
          {c.create}
        </Link>
      </header>

      {index.demo && (
        <p className={styles.demo}>
          <Info size={20} aria-hidden />
          {c.demo}
        </p>
      )}
      {index.audiences.all === 0 && <p className={book.empty}>{c.noReachable}</p>}

      {index.data.length === 0 ? (
        index.audiences.all > 0 && <p className={book.empty}>{c.empty}</p>
      ) : (
        <ul className={book.list}>
          {index.data.map((campaign) => (
            <li key={campaign.id}>
              <Link href={`/campagnes/${campaign.id}`} className={`${book.row} ${styles.link}`}>
                <div className={book.main}>
                  <span className={book.name}>{campaign.subject}</span>
                  <span className={book.meta}>{[c.audienceNames[campaign.audience], campaign.event?.title, when(campaign)].filter(Boolean).join(" · ")}</span>
                  {campaign.status === "sent" || campaign.status === "sending" ? (
                    <span className={book.meta}>
                      {format(c.line, {
                        sent: String(campaign.sent_count),
                        opened: rate(campaign.opened_count, campaign.sent_count, locale),
                        clicked: String(campaign.clicked_count),
                      })}
                    </span>
                  ) : null}
                </div>
                <span className={`${book.status} ${styles.pill}`} data-status={campaign.status === "sent" ? "reachable" : campaign.status}>
                  {c.status[campaign.status]}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </main>
  );
}
