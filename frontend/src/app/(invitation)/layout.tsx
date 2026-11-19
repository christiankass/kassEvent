import { AuthShell } from "../(auth)/AuthShell";

// Comme les pages de connexion, mais accessible aussi une fois connecté (pour accepter l'invitation).
export default function InvitationLayout({ children }: { children: React.ReactNode }) {
  return <AuthShell>{children}</AuthShell>;
}
