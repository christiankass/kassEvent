/**
 * Paramètres du lien d'invitation (organisation + jeton) transmis de page en page
 * (invitation → connexion ou inscription → invitation). Les chemins sont reconstruits
 * à partir de valeurs vérifiées : jamais de redirection vers une adresse reçue telle quelle.
 */
import { isUuid } from "./uuid";

const TOKEN = /^[A-Za-z0-9]{48}$/;

export type InvitationLink = { organisation: string; jeton: string };

type Params = { get(name: string): string | null };

export function readInvitationLink(params: Params): InvitationLink | null {
  const organisation = params.get("organisation");
  const jeton = params.get("jeton");
  return organisation && jeton && isUuid(organisation) && TOKEN.test(jeton) ? { organisation, jeton } : null;
}

/** La page de l'invitation. */
export function invitationPath(link: InvitationLink): string {
  return `/invitation?${new URLSearchParams(link)}`;
}

/** Une page de connexion ou d'inscription qui ramène ensuite à l'invitation. */
export function withInvitation(path: "/connexion" | "/inscription", link: InvitationLink | null, email?: string): string {
  if (!link) return path;
  return `${path}?${new URLSearchParams({ ...link, ...(email ? { email } : {}) })}`;
}
