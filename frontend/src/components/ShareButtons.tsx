"use client";

import { useState, useSyncExternalStore } from "react";
import { Check, FacebookLogo, LinkSimple, ShareNetwork, WhatsappLogo } from "@phosphor-icons/react";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { buttonClass } from "./ui";
import styles from "./share.module.css";

const subscribe = () => () => {};

/** Partage d'une page publique : WhatsApp d'abord (canal principal en RDC), Facebook, copie du lien, partage natif. */
export function ShareButtons({ url, title }: { url: string; title: string }) {
  const { t } = useI18n();
  const [copied, setCopied] = useState(false);
  // Le partage natif n'existe que dans certains navigateurs (surtout sur téléphone).
  const canShare = useSyncExternalStore(
    subscribe,
    () => typeof navigator.share === "function",
    () => false,
  );
  const text = format(t.share.message, { title });

  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2500);
    } catch {
      window.prompt(t.share.copy, url);
    }
  }

  return (
    <div className={styles.share}>
      <a
        className={buttonClass("secondary", styles.whatsapp)}
        href={`https://wa.me/?text=${encodeURIComponent(`${text}\n${url}`)}`}
        target="_blank"
        rel="noopener noreferrer"
      >
        <WhatsappLogo size={20} weight="fill" aria-hidden /> {t.share.whatsapp}
      </a>
      <a
        className={buttonClass("secondary")}
        href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`}
        target="_blank"
        rel="noopener noreferrer"
      >
        <FacebookLogo size={20} weight="fill" aria-hidden /> {t.share.facebook}
      </a>
      <button type="button" className={buttonClass("secondary")} onClick={copy} aria-live="polite">
        {copied ? <Check size={20} aria-hidden /> : <LinkSimple size={20} aria-hidden />}
        {copied ? t.share.copied : t.share.copy}
      </button>
      {canShare && (
        <button
          type="button"
          className={buttonClass("secondary")}
          onClick={() => navigator.share({ title, text, url }).catch(() => {})}
        >
          <ShareNetwork size={20} aria-hidden /> {t.share.more}
        </button>
      )}
    </div>
  );
}
