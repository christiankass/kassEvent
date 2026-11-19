"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import { Alert, Button, Field } from "@/components/ui";
import { api, type Invitation, type Member, type Role } from "@/lib/api";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { useFormSubmit } from "@/lib/form";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { useUser } from "@/lib/user-context";
import { PageSkeleton } from "../../PageSkeleton";
import styles from "../../app.module.css";

type Team = { members: Member[]; invitations: Invitation[]; assignable: Role[] };

export default function TeamPage() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const user = useUser();
  const { organization, permissions } = useOrganization();
  const organizationId = organization.id;
  const canManage = permissions.includes("members.manage");

  const [team, setTeam] = useState<Team | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  // Résultat de la dernière action, annoncé aux lecteurs d'écran.
  const [notice, setNotice] = useState<{ tone: "success" | "error"; text: string } | null>(null);
  const [confirming, setConfirming] = useState<string | null>(null);
  const formRef = useRef<HTMLFormElement>(null);

  const load = useCallback(() => fetchTeam(organizationId).then(setTeam), [organizationId]);

  useEffect(() => {
    if (!canManage) {
      router.replace("/tableau-de-bord");
      return;
    }
    load().catch(setLoadError);
  }, [canManage, load, router]);

  const invite = useFormSubmit(async (form) => {
    const email = String(form.get("email") ?? "");
    await api("/invitations", { method: "POST", organizationId, body: { email, role: form.get("role") } });
    formRef.current?.reset();
    // L'invitation est partie : un échec du rechargement ne doit pas la faire croire ratée.
    await load().catch(setLoadError);
    setNotice({ tone: "success", text: format(t.team.invited, { email: email.trim().toLowerCase() }) });
  });

  /** Lance une action sur la liste, puis recharge l'équipe et annonce le résultat. */
  async function act(request: () => Promise<unknown>, success: string) {
    setNotice(null);
    try {
      await request();
      await load();
      setNotice({ tone: "success", text: success });
    } catch (e) {
      setNotice({ tone: "error", text: actionErrorMessage(e, t) });
      load().catch(setLoadError);
    }
    setConfirming(null);
  }

  if (loadError) {
    return (
      <main className={styles.main}>
        <Alert tone="error">{formErrorMessage(loadError, t)}</Alert>
      </main>
    );
  }
  if (!team) return <PageSkeleton />;

  const dateFormat = new Intl.DateTimeFormat(locale, { dateStyle: "long" });
  const roleName = (role: Role | null) => (role ? t.roles[role][0] : "");
  const defaultRole = team.assignable.includes("event_manager") ? "event_manager" : team.assignable[0];

  return (
    <main className={styles.main}>
      <h1 className={styles.title}>{t.team.title}</h1>
      <p className={styles.lede}>{t.team.lede}</p>

      <div className={styles.notice} aria-live="polite">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      </div>

      <div className={styles.teamGrid}>
        <section className={styles.panel} aria-labelledby="invite-title">
          <h2 id="invite-title" className={styles.sectionTitle}>
            {t.team.inviteTitle}
          </h2>
          <form ref={formRef} className={styles.form} onSubmit={invite.submit} noValidate>
            {invite.message && <Alert tone="error">{invite.message}</Alert>}
            <Field
              label={t.common.email}
              name="email"
              type="email"
              autoComplete="off"
              inputMode="email"
              required
              error={invite.fieldError("email")}
            />
            <fieldset className={styles.fieldset}>
              <legend className={styles.legend}>{t.team.role}</legend>
              <div className={styles.roleChoices}>
                {team.assignable.map((role) => (
                  <label key={role} className={styles.choice}>
                    <input type="radio" name="role" value={role} defaultChecked={role === defaultRole} />
                    <span className={styles.choiceTitle}>{t.roles[role][0]}</span>
                    <span className={styles.choiceHint}>{t.roles[role][1]}</span>
                  </label>
                ))}
              </div>
            </fieldset>
            <div>
              <Button type="submit" disabled={invite.pending}>
                {invite.pending ? t.common.loading : t.team.invite}
              </Button>
            </div>
          </form>
        </section>

        <div className={styles.stack}>
          <section aria-labelledby="members-title">
            <h2 id="members-title" className={styles.sectionTitle}>
              {t.team.membersTitle}
            </h2>
            <ul className={styles.list}>
              {team.members.map((member) => {
                // Propriétaire, soi-même, ou rôle que je n'ai pas le droit de donner : pas modifiable ici.
                const locked = member.id === user.id || !member.role || !team.assignable.includes(member.role);
                return (
                  <li key={member.id} className={styles.row}>
                    <div className={styles.who}>
                      <span className={styles.name}>
                        {member.name}
                        {member.id === user.id && <span className={styles.muted}> ({t.team.you})</span>}
                      </span>
                      <span className={styles.email}>{member.email}</span>
                    </div>
                    {locked ? (
                      <span className={styles.badge}>{roleName(member.role)}</span>
                    ) : (
                      <div className={styles.actions}>
                        <select
                          className={styles.select}
                          aria-label={format(t.team.roleOf, { name: member.name })}
                          value={member.role ?? ""}
                          onChange={(e) => {
                            const role = e.target.value as Role;
                            void act(
                              () => api(`/members/${member.id}`, { method: "PATCH", organizationId, body: { role } }),
                              format(t.team.roleChanged, { name: member.name, role: t.roles[role][0].toLowerCase() }),
                            );
                          }}
                        >
                          {team.assignable.map((role) => (
                            <option key={role} value={role}>
                              {t.roles[role][0]}
                            </option>
                          ))}
                        </select>
                        {confirming === member.id ? (
                          <>
                            <Button
                              type="button"
                              variant="danger"
                              onClick={() =>
                                act(
                                  () => api(`/members/${member.id}`, { method: "DELETE", organizationId }),
                                  format(t.team.removed, { name: member.name }),
                                )
                              }
                            >
                              {t.team.confirmRemove}
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => setConfirming(null)}>
                              {t.team.cancel}
                            </Button>
                          </>
                        ) : (
                          <Button type="button" variant="secondary" onClick={() => setConfirming(member.id)}>
                            {t.team.remove}
                          </Button>
                        )}
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          </section>

          <section aria-labelledby="pending-title">
            <h2 id="pending-title" className={styles.sectionTitle}>
              {t.team.pendingTitle}
            </h2>
            {team.invitations.length === 0 ? (
              <p className={styles.muted}>{t.team.pendingEmpty}</p>
            ) : (
              <ul className={styles.list}>
                {team.invitations.map((invitation) => (
                  <li key={invitation.id} className={styles.row}>
                    <div className={styles.who}>
                      <span className={styles.name}>{invitation.email}</span>
                      <span className={styles.email}>
                        {roleName(invitation.role)} ·{" "}
                        {invitation.expired ? (
                          <span className={styles.warn}>{t.team.expired}</span>
                        ) : (
                          format(t.team.expires, { date: dateFormat.format(new Date(invitation.expires_at)) })
                        )}
                      </span>
                    </div>
                    <div className={styles.actions}>
                      <Button
                        type="button"
                        variant="secondary"
                        onClick={() =>
                          act(
                            () => api(`/invitations/${invitation.id}/resend`, { method: "POST", organizationId }),
                            format(t.team.resent, { email: invitation.email }),
                          )
                        }
                      >
                        {t.team.resend}
                      </Button>
                      <Button
                        type="button"
                        variant="secondary"
                        onClick={() =>
                          act(
                            () => api(`/invitations/${invitation.id}`, { method: "DELETE", organizationId }),
                            format(t.team.cancelled, { email: invitation.email }),
                          )
                        }
                      >
                        {t.team.cancelInvitation}
                      </Button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      </div>
    </main>
  );
}

async function fetchTeam(organizationId: string): Promise<Team> {
  const [members, invitations] = await Promise.all([
    api<{ data: Member[]; meta: { assignable_roles: Role[] } }>("/members", { organizationId }),
    api<{ data: Invitation[] }>("/invitations", { organizationId }),
  ]);
  return { members: members.data, invitations: invitations.data, assignable: members.meta.assignable_roles };
}
