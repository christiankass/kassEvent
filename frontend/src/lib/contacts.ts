/** Carnet de contacts de l'organisation (GET /contacts). */
export type ContactFilter = "all" | "reachable" | "no_consent" | "unsubscribed";

export type Contact = {
  id: string;
  name: string;
  email: string | null;
  phone: string | null;
  reachable: boolean;
  consented_at: string | null;
  consent_source: "checkout" | "rsvp" | null;
  unsubscribed_at: string | null;
  orders_count: number;
  invitations_count: number;
  last_activity_at: string | null;
};

export type ContactsPage = {
  data: Contact[];
  meta: { current_page: number; last_page: number };
  summary: { total: number; reachable: number; unsubscribed: number; with_phone: number };
};

/** Numéro international enregistré (243812345678) affiché « +243 812 345 678 ». */
export function formatPhone(phone: string): string {
  const match = phone.match(/^243(\d{3})(\d{3})(\d{3})$/);
  return match ? `+243 ${match[1]} ${match[2]} ${match[3]}` : `+${phone}`;
}
