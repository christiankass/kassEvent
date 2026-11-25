import type { TicketDesign } from "./designs";

export type GuestStatus = "pending" | "confirmed" | "declined";

/** Invité tel que le voit l'organisation (rsvp_url : seulement pour qui gère l'événement). */
export type Guest = {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  group_name: string | null;
  companions_allowed: number;
  status: GuestStatus;
  companions_confirmed: number | null;
  /** Places occupées : lui et ses accompagnants confirmés, ou tous ceux permis s'il n'a pas répondu. */
  party: number;
  table_id: string | null;
  invited_at: string | null;
  reminded_at: string | null;
  responded_at: string | null;
  can_remind: boolean;
  rsvp_url: string | null;
};

export type GuestTable = { id: string; number: number; name: string | null; label: string; seats: number; occupied: number };

export type GuestSummary = {
  guests: number;
  confirmed: number;
  declined: number;
  pending: number;
  not_invited: number;
  people_confirmed: number;
  people_expected: number;
  unseated: number;
};

export type GuestPage = {
  data: Guest[];
  summary: GuestSummary;
  plan: { hall_capacity: number; seats_per_table: number } | null;
  tables: GuestTable[];
};

export type GuestInput = { name: string; phone?: string | null; email?: string | null; group_name?: string | null; companions_allowed?: number };

/** Page de réponse de l'invité (lien personnel). */
export type Rsvp = {
  guest: { name: string; companions_allowed: number; status: GuestStatus; companions_confirmed: number | null; table: string | null };
  event: {
    title: string;
    description: string | null;
    starts_at_utc: string;
    timezone: string;
    venue_name: string;
    venue_address: string | null;
    city: string;
    cover_url: string | null;
    organization: { name: string };
  };
  design: TicketDesign;
  open: boolean;
  invitation_url: string | null;
};

/** Même calcul que le serveur : 95 places à 10 par table donnent 5 tables de 10 et 5 de 9. */
export function layoutSeats(capacity: number, seatsPerTable: number | null, tableCount: number | null): number[] {
  if (!(capacity > 0)) return [];
  const tables = Math.max(1, Math.min(tableCount ?? Math.ceil(capacity / (seatsPerTable || 10)), capacity));
  const base = Math.floor(capacity / tables);
  const extra = capacity % tables;
  return Array.from({ length: tables }, (_, i) => base + (i < extra ? 1 : 0));
}

/** « 5 tables de 10 et 5 tables de 9 » : groupes de tables de même taille, de la plus grande à la plus petite. */
export function layoutGroups(seats: number[]): { count: number; seats: number }[] {
  const groups = new Map<number, number>();
  seats.forEach((s) => groups.set(s, (groups.get(s) ?? 0) + 1));
  return [...groups.entries()].sort((a, b) => b[0] - a[0]).map(([s, count]) => ({ count, seats: s }));
}

/** Lien WhatsApp vers l'invité, message prérempli (envoyé depuis le téléphone de l'organisateur). */
export function whatsAppLink(phone: string | null, message: string): string {
  const text = encodeURIComponent(message);
  return phone ? `https://wa.me/${phone}?text=${text}` : `https://wa.me/?text=${text}`;
}

/**
 * Liste collée (une personne par ligne, depuis un tableur, une note ou WhatsApp) :
 * « Nom ; téléphone ; e-mail ; accompagnants ; groupe », séparés par ; , ou tabulation.
 * Le nombre seul est lu comme les accompagnants, une adresse avec @ comme l'e-mail.
 */
export function parseGuestList(text: string): GuestInput[] {
  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const parts = line.split(/\t|;|,(?=\s*[+0-9a-zA-Z])/).map((part) => part.trim()).filter(Boolean);
      const guest: GuestInput = { name: "" };
      const rest: string[] = [];
      for (const part of parts) {
        if (!guest.email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(part)) guest.email = part;
        else if (guest.companions_allowed === undefined && /^\+?\d{1,2}$/.test(part)) guest.companions_allowed = Number(part.replace("+", ""));
        else if (!guest.phone && /^[+0(][\d\s().-]{6,}$/.test(part)) guest.phone = part;
        else rest.push(part);
      }
      guest.name = rest.shift() ?? "";
      if (rest.length) guest.group_name = rest.join(" ");
      return guest;
    })
    .filter((guest) => guest.name !== "");
}

type ContactsManager = {
  select(properties: string[], options: { multiple: boolean }): Promise<{ name?: string[]; tel?: string[]; email?: string[] }[]>;
};

/** Contacts du téléphone (Chrome sur Android) ; ailleurs, on colle une liste. */
export function canPickContacts(): boolean {
  return typeof navigator !== "undefined" && "contacts" in navigator && "ContactsManager" in window;
}

export async function pickContacts(): Promise<GuestInput[]> {
  const contacts = (navigator as unknown as { contacts: ContactsManager }).contacts;
  const picked = await contacts.select(["name", "tel", "email"], { multiple: true });
  return picked
    .map((contact) => ({
      name: contact.name?.[0]?.trim() ?? "",
      phone: contact.tel?.[0] ?? null,
      email: contact.email?.[0] ?? null,
    }))
    .filter((guest) => guest.name !== "");
}
