"use client";

import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { Confetti, Eye, EyeSlash, Trash } from "@phosphor-icons/react";
import { ShareButtons } from "@/components/ShareButtons";
import { Alert, Button, Checkbox } from "@/components/ui";
import { api } from "@/lib/api";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import type { EventData } from "@/lib/events";
import { formatWishDate, type Wish, type WishPage } from "@/lib/wishes";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { PageSkeleton } from "../../../../PageSkeleton";
import app from "../../../../app.module.css";
import styles from "./wishes.module.css";

/** Livre de vœux de l'événement : ouverture, lien à partager, lecture et modération des messages. */
export default function WishesPage() {
  const { t, locale } = useI18n();
  const w = t.wishes;
  const { id } = useParams<{ id: string }>();
  const { organization, permissions } = useOrganization();
  const organizationId = organization.id;
  const canManage = permissions.includes("events.manage");

  const [event, setEvent] = useState<EventData | null>(null);
  const [page, setPage] = useState<WishPage | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [error, setError] = useState<unknown>(null);
  const [pending, setPending] = useState(false);
  const [deleting, setDeleting] = useState<string | null>(null);

  const reload = useCallback(() => api<WishPage>(`/events/${id}/wishes`, { organizationId }).then(setPage), [id, organizationId]);

  useEffect(() => {
    Promise.all([api<{ data: EventData }>(`/events/${id}`, { organizationId }).then(({ data }) => setEvent(data)), reload()]).catch(setLoadError);
  }, [id, organizationId, reload]);

  async function run(action: () => Promise<unknown>) {
    if (pending) return;
    setPending(true);
    setError(null);
    try {
      await action();
      await reload();
    } catch (e) {
      setError(e);
    }
    setPending(false);
  }

  const settings = (body: { open?: boolean; public?: boolean }) =>
    run(() => api(`/events/${id}/wishes/settings`, { method: "PUT", body, organizationId }));
  const toggle = (wish: Wish) => run(() => api(`/wishes/${wish.id}`, { method: "PATCH", body: { hidden: !wish.hidden }, organizationId }));
  const remove = (wish: Wish) =>
    run(async () => {
      await api(`/wishes/${wish.id}`, { method: "DELETE", organizationId });
      setDeleting(null);
    });

  if (loadError) {
    return (
      <main className={app.main}>
        <Alert tone="error">{formErrorMessage(loadError, t)}</Alert>
      </main>
    );
  }
  if (!event || !page) return <PageSkeleton />;

  const { book, data: wishes, summary } = page;

  return (
    <main className={app.main}>
      <h1 className={app.title}>{w.title}</h1>
      <p className={app.lede}>{w.lede}</p>
      {error ? <Alert tone="error">{actionErrorMessage(error, t)}</Alert> : null}

      {!book ? (
        <section className={styles.start}>
          <Confetti size={40} aria-hidden className={styles.startIcon} />
          <h2 className={styles.sectionTitle}>{w.start.title}</h2>
          <p>{w.start.body}</p>
          {canManage ? (
            <Button type="button" disabled={pending} onClick={() => void settings({ open: true, public: true })}>
              {w.start.button}
            </Button>
          ) : (
            <p className={styles.muted}>{w.readOnly}</p>
          )}
        </section>
      ) : (
        <>
          <section className={styles.panel} aria-labelledby="wishes-link">
            <h2 id="wishes-link" className={styles.sectionTitle}>
              {w.link}
            </h2>
            <p className={styles.url}>
              <a href={book.url} target="_blank" rel="noopener noreferrer">
                {book.url}
              </a>
            </p>
            <ShareButtons url={book.url} title={event.title} message={format(w.shareText, { title: event.title })} />
            {canManage && (
              <div className={styles.settings}>
                <Checkbox label={w.settings.open} checked={book.open} disabled={pending} onChange={(e) => void settings({ open: e.target.checked })} />
                <Checkbox
                  label={w.settings.public}
                  checked={book.public}
                  disabled={pending}
                  onChange={(e) => void settings({ public: e.target.checked })}
                />
              </div>
            )}
          </section>

          <section aria-labelledby="wishes-list">
            <h2 id="wishes-list" className={styles.sectionTitle}>
              {format(w.count, { count: String(summary.total) })}
              {summary.hidden > 0 && <span className={styles.muted}> · {format(w.hiddenCount, { count: String(summary.hidden) })}</span>}
            </h2>
            {!canManage && <p className={styles.muted}>{w.readOnly}</p>}
            {wishes.length === 0 ? (
              <p className={styles.muted}>{w.empty}</p>
            ) : (
              <ul className={styles.list}>
                {wishes.map((wish) => (
                  <li key={wish.id} className={styles.wish} data-hidden={wish.hidden || undefined}>
                    <p className={styles.message}>{wish.message}</p>
                    <p className={styles.meta}>
                      <strong>{wish.name}</strong> · {formatWishDate(wish.created_at, locale)}
                      {wish.hidden && <span className={styles.badge}>{w.hidden}</span>}
                    </p>
                    {canManage &&
                      (deleting === wish.id ? (
                        <div className={styles.confirm}>
                          <p>{format(w.confirmDelete, { name: wish.name })}</p>
                          <div className={styles.actions}>
                            <Button type="button" variant="danger" disabled={pending} onClick={() => void remove(wish)}>
                              {w.delete}
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => setDeleting(null)}>
                              {w.cancel}
                            </Button>
                          </div>
                        </div>
                      ) : (
                        <div className={styles.actions}>
                          <Button type="button" variant="secondary" disabled={pending} onClick={() => void toggle(wish)}>
                            {wish.hidden ? <Eye size={18} aria-hidden /> : <EyeSlash size={18} aria-hidden />} {wish.hidden ? w.show : w.hide}
                          </Button>
                          <Button type="button" variant="secondary" onClick={() => setDeleting(wish.id)}>
                            <Trash size={18} aria-hidden /> {w.delete}
                          </Button>
                        </div>
                      ))}
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </main>
  );
}
