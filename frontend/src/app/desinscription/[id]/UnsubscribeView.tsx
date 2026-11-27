"use client";

import { useParams, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { BellSlash, CheckCircle } from "@phosphor-icons/react";
import { Alert, Button, LocaleSwitch, Wordmark } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { actionErrorMessage } from "@/lib/errors";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "./unsubscribe.module.css";

type State = { organization: string; unsubscribed: boolean };

/** Désinscription d'un contact, en un geste, sans compte. */
export function UnsubscribeView() {
  const { t } = useI18n();
  const u = t.audience.unsubscribe;
  const { id } = useParams<{ id: string }>();
  const key = useSearchParams().get("cle") ?? "";
  const [state, setState] = useState<State | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    api<{ data: State }>(`/public/contacts/${id}/unsubscribe?key=${encodeURIComponent(key)}`)
      .then(({ data }) => setState(data))
      .catch(setError);
  }, [id, key]);

  async function confirm() {
    setPending(true);
    setError(null);
    try {
      const { data } = await api<{ data: State }>(`/public/contacts/${id}/unsubscribe`, { method: "POST", body: { key } });
      setState(data);
    } catch (e) {
      setError(e);
    }
    setPending(false);
  }

  function body() {
    if (error instanceof ApiError && error.status === 404) return <p>{u.notFound}</p>;
    if (!state) return error ? <Alert tone="error">{actionErrorMessage(error, t)}</Alert> : <p>{t.common.loading}</p>;
    if (state.unsubscribed) {
      return (
        <>
          <CheckCircle size={40} weight="fill" className={styles.done} aria-hidden />
          <p role="status">{format(u.done, { organization: state.organization })}</p>
        </>
      );
    }
    return (
      <>
        <BellSlash size={40} className={styles.icon} aria-hidden />
        <h1 className={styles.title}>{u.title}</h1>
        <p>{format(u.body, { organization: state.organization })}</p>
        {error ? <Alert tone="error">{actionErrorMessage(error, t)}</Alert> : null}
        <Button type="button" onClick={confirm} disabled={pending}>
          {u.confirm}
        </Button>
      </>
    );
  }

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <Wordmark />
        <LocaleSwitch />
      </header>
      <main className={styles.card}>{body()}</main>
    </div>
  );
}
