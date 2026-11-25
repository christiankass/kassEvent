"use client";

import { useRef, useState, type FormEvent, type ReactNode } from "react";
import { EnvelopeSimple, MessengerLogo, WhatsappLogo } from "@phosphor-icons/react";
import { Alert, Field } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { actionErrorMessage } from "@/lib/errors";
import { format, type Dictionary } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import type { DeliveryChannel, Order } from "@/lib/orders";
import styles from "./order.module.css";

type Channel = DeliveryChannel | "email";

/**
 * Billets d'une commande payée envoyés sur WhatsApp ou Messenger, ou de nouveau par e-mail.
 * Messenger : une page Facebook ne peut écrire qu'à quelqu'un qui lui a écrit ; le bouton ouvre donc
 * la conversation avec kassEvent par un lien à usage unique, et les billets partent dès qu'on y touche « Démarrer ».
 */
export function Deliveries({ order, orderKey, onUpdate }: { order: Order; orderKey: string; onUpdate: (order: Order) => void }) {
  const { t } = useI18n();
  const d = t.order.delivery;
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<{ channel: Channel; error: unknown } | null>(null);
  const [emailSent, setEmailSent] = useState(false);
  // Verrou synchrone : un double clic part avant que React ait désactivé le bouton.
  const busy = useRef(false);
  const whatsapp = order.deliveries.find((delivery) => delivery.channel === "whatsapp");
  const messenger = order.deliveries.find((delivery) => delivery.channel === "messenger");

  async function send(channel: Channel, extra: Record<string, unknown> = {}) {
    if (busy.current) return;
    busy.current = true;
    setPending(true);
    setError(null);
    try {
      const response = await api<{ data: Order; messenger_url: string | null }>(`/public/orders/${order.id}/deliveries`, {
        method: "POST",
        body: { key: orderKey, channel, ...extra },
      });
      onUpdate(response.data);
      if (channel === "email") setEmailSent(true);
      // Messenger : l'application s'ouvre sur la conversation (le site reste ouvert derrière).
      if (response.messenger_url) window.location.assign(response.messenger_url);
    } catch (caught) {
      setError({ channel, error: caught });
    }
    busy.current = false;
    setPending(false);
  }

  // Numéro refusé : affiché sous le champ ; toute autre erreur, sous le bouton du canal.
  const whatsappError = error?.channel === "whatsapp" && error.error instanceof ApiError && error.error.status === 422 ? error.error.field("whatsapp") : undefined;
  const generalError = (channel: Channel) =>
    error?.channel === channel && !(channel === "whatsapp" && whatsappError) ? actionErrorMessage(error.error, t) : null;

  function submitWhatsApp(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    void send("whatsapp", { whatsapp: new FormData(e.currentTarget).get("whatsapp") });
  }

  if (!order.channels.whatsapp && !order.channels.messenger) {
    return null;
  }

  return (
    <section className={styles.card} aria-labelledby="deliveries-title">
      <h2 id="deliveries-title" className={styles.subtitle}>
        {order.design?.kind === "invitation" ? d.titleInvitation : d.title}
      </h2>
      <p className={styles.muted}>{d.lede}</p>
      {order.channels.simulated && <p className={styles.demo}>{d.simulated}</p>}

      <ul className={styles.channels}>
        {order.channels.whatsapp && (
          <Channel icon={<WhatsappLogo size={24} weight="fill" aria-hidden />} name={d.whatsapp} tone="whatsapp" status={whatsapp && <Status delivery={whatsapp} d={d} />}>
            <form className={styles.channelForm} onSubmit={submitWhatsApp} noValidate>
              <Field label={d.whatsappNumber} hint={d.whatsappHint} name="whatsapp" type="tel" inputMode="tel" autoComplete="tel" required maxLength={25} error={whatsappError} />
              <button type="submit" className={`${styles.secondary} ${styles.channelButton}`} disabled={pending}>
                {whatsapp ? d.whatsappResend : d.whatsappSend}
              </button>
            </form>
            <ChannelError message={generalError("whatsapp")} />
          </Channel>
        )}

        {order.channels.messenger && (
          <Channel icon={<MessengerLogo size={24} weight="fill" aria-hidden />} name={d.messenger} tone="messenger" status={messenger && <Status delivery={messenger} d={d} />}>
            {(!messenger || messenger.status === "awaiting_contact" || messenger.status === "failed") && <p className={styles.muted}>{d.messengerHint}</p>}
            <button type="button" className={`${styles.secondary} ${styles.channelButton}`} disabled={pending} onClick={() => void send("messenger")}>
              {!messenger ? d.messengerSend : messenger.status === "awaiting_contact" ? d.messengerReopen : d.messengerAgain}
            </button>
            <ChannelError message={generalError("messenger")} />
          </Channel>
        )}

        {order.buyer_email && (
          <Channel icon={<EnvelopeSimple size={24} aria-hidden />} name={d.email} tone="email" status={emailSent ? <span role="status">{d.emailSent}</span> : null}>
            <button type="button" className={styles.linkButton} disabled={pending} onClick={() => void send("email")}>
              {format(d.emailResend, { email: order.buyer_email })}
            </button>
            <ChannelError message={generalError("email")} />
          </Channel>
        )}
      </ul>
    </section>
  );
}

function Channel({ icon, name, tone, status, children }: { icon: ReactNode; name: string; tone: string; status: ReactNode; children: ReactNode }) {
  return (
    <li className={styles.channel}>
      <div className={styles.channelHead}>
        <span className={styles.channelIcon} data-tone={tone}>
          {icon}
        </span>
        <span className={styles.channelName}>{name}</span>
      </div>
      {status && (
        <p className={styles.channelStatus} aria-live="polite">
          {status}
        </p>
      )}
      {children}
    </li>
  );
}

function Status({ delivery, d }: { delivery: Order["deliveries"][number]; d: Dictionary["order"]["delivery"] }) {
  const status = d.status[delivery.status];
  if (delivery.status === "failed") {
    const failures = d.failures as Record<string, string>;
    return <span className={styles.channelFailed}>{failures[delivery.failure_code ?? ""] ?? d.failures.other}</span>;
  }
  return (
    <span data-status={delivery.status}>{delivery.recipient ? format(d.to, { recipient: delivery.recipient, status }) : status}</span>
  );
}

function ChannelError({ message }: { message: string | null }) {
  return message ? <Alert tone="error">{message}</Alert> : null;
}
