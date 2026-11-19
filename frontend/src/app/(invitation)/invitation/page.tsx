"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert, Button } from "@/components/ui";
import { api, ApiError, type Role } from "@/lib/api";
import { setCurrentOrganizationId } from "@/lib/current-organization";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { readInvitationLink, withInvitation, type InvitationLink } from "@/lib/invitation-link";
import { useSession } from "@/lib/session";
import styles from "../../(auth)/auth.module.css";

type Details = {
  organization: { id: string; name: string };
  email: string;
  role: Role;
  inviter: string | null;
  expired: boolean;
  has_account: boolean;
};

type State = { status: "loading" } | { status: "invalid" } | { status: "error"; error: unknown } | { status: "ready"; details: Details };

export default function InvitationPage() {
  const { t } = useI18n();
  const link = readInvitationLink(useSearchParams());
  const [state, setState] = useState<State>(link ? { status: "loading" } : { status: "invalid" });
  const organisation = link?.organisation;
  const jeton = link?.jeton;

  useEffect(() => {
    if (!organisation || !jeton) return;
    api<{ data: Details }>(`/invitation?${new URLSearchParams({ organization: organisation, token: jeton })}`)
      .then(({ data }) => setState({ status: "ready", details: data }))
      .catch((error) =>
        setState(error instanceof ApiError && error.status === 404 ? { status: "invalid" } : { status: "error", error }),
      );
  }, [organisation, jeton]);

  return (
    <div className={styles.card}>
      <h1 className={styles.title}>{t.invitation.title}</h1>
      {state.status === "loading" && <p className={styles.lede}>{t.common.loading}</p>}
      {state.status === "invalid" && <Alert tone="error">{t.invitation.invalid}</Alert>}
      {state.status === "error" && <Alert tone="error">{formErrorMessage(state.error, t)}</Alert>}
      {state.status === "ready" && link && <InvitationDetails details={state.details} link={link} />}
    </div>
  );
}

function InvitationDetails({ details, link }: { details: Details; link: InvitationLink }) {
  const { t } = useI18n();
  const session = useSession();
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [renewed, setRenewed] = useState(false);
  const body = { organization: link.organisation, token: link.jeton };
  const organization = details.organization.name;

  async function run(request: () => Promise<void>) {
    setPending(true);
    setError(null);
    try {
      await request();
    } catch (e) {
      setError(actionErrorMessage(e, t));
      setPending(false);
    }
  }

  const accept = () =>
    run(async () => {
      await api("/invitation/accept", { method: "POST", body });
      setCurrentOrganizationId(details.organization.id);
      router.replace("/tableau-de-bord");
    });

  const renew = () =>
    run(async () => {
      await api("/invitation/renew", { method: "POST", body });
      setRenewed(true);
      setPending(false);
    });

  const switchAccount = () =>
    run(async () => {
      await api("/auth/logout", { method: "POST" });
      setPending(false);
      session.retry();
    });

  return (
    <>
      <div>
        <p className={styles.intro}>
          {format(t.invitation.intro, {
            inviter: details.inviter ?? organization,
            organization,
            role: t.roles[details.role][0].toLowerCase(),
          })}
        </p>
        <p className={styles.lede}>{format(t.invitation.sentTo, { email: details.email })}</p>
      </div>
      {error && <Alert tone="error">{error}</Alert>}

      {details.expired ? (
        renewed ? (
          <Alert tone="success">{format(t.invitation.renewed, { email: details.email })}</Alert>
        ) : (
          <>
            <Alert tone="error">{t.invitation.expired}</Alert>
            <Button type="button" onClick={renew} disabled={pending}>
              {pending ? t.common.loading : t.invitation.renew}
            </Button>
          </>
        )
      ) : session.status === "loading" ? (
        <p className={styles.lede}>{t.common.loading}</p>
      ) : session.status === "signed-in" ? (
        session.user.email === details.email ? (
          <Button type="button" onClick={accept} disabled={pending}>
            {pending ? t.common.loading : format(t.invitation.accept, { organization })}
          </Button>
        ) : (
          <>
            <Alert tone="error">
              {format(t.invitation.wrongAccount, { current: session.user.email, email: details.email })}
            </Alert>
            <Button type="button" variant="secondary" onClick={switchAccount} disabled={pending}>
              {t.invitation.switchAccount}
            </Button>
          </>
        )
      ) : session.status === "error" ? (
        <Alert tone="error">{formErrorMessage(session.error, t)}</Alert>
      ) : (
        <GuestActions details={details} link={link} />
      )}
    </>
  );
}

/** Pas connecté : se connecter ou créer son compte, puis revenir ici. */
function GuestActions({ details, link }: { details: Details; link: InvitationLink }) {
  const { t } = useI18n();
  const login = withInvitation("/connexion", link, details.email);
  const register = withInvitation("/inscription", link, details.email);
  const [primary, secondary] = details.has_account
    ? [
        { href: login, label: t.invitation.haveAccount },
        { href: register, label: t.invitation.createAccount },
      ]
    : [
        { href: register, label: t.invitation.createAccount },
        { href: login, label: t.invitation.haveAccount },
      ];

  return (
    <>
      <p className={styles.lede}>{details.has_account ? t.invitation.needLogin : t.invitation.needAccount}</p>
      <div className={styles.actions}>
        <Link href={primary.href} className={styles.primaryLink}>
          {primary.label}
        </Link>
        <Link href={secondary.href}>{secondary.label}</Link>
      </div>
    </>
  );
}
