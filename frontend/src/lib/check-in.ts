import { api } from "./api";

/**
 * Contrôle d'entrée. Le téléphone garde la liste des billets de l'événement (empreintes SHA-256,
 * jamais les codes) et les scans pas encore envoyés : l'entrée continue sans réseau, et tout se
 * synchronise au retour de la connexion. Le serveur tranche ensuite : le premier scan fait foi.
 */

export type ScanResult = "valid" | "already_used" | "invalid" | "other_event";

export type TicketInfo = { holder_name: string; ticket_type: string | null; companions: number };
export type FirstScan = { scanned_at: string; gate: string | null; agent: string | null };

export type ServerScan = {
  id: string;
  result: ScanResult;
  ticket: TicketInfo | null;
  first_scan: FirstScan | null;
  other_event: string | null;
};

export type CheckInList = {
  event: { id: string; title: string; starts_at_utc: string; timezone: string; venue_name: string };
  tickets: (TicketInfo & { hash: string; first_scan: FirstScan | null })[];
  checked_in: number;
  generated_at: string;
};

/** Scan fait sur ce téléphone, en attente d'envoi. `local` : le résultat affiché à l'agent. */
export type PendingScan = { id: string; code: string; scanned_at: string; gate: string | null; offline: boolean; local: ScanResult };

/** Résultat affiché. « unverified » : hors ligne et liste jamais téléchargée sur ce téléphone. */
export type Verdict = Omit<ServerScan, "result"> & { result: ScanResult | "unverified"; offline: boolean };

/** Billet entré dans un autre scan que celui affiché comme valide : risque de double entrée. */
export type Conflict = { id: string; ticket: TicketInfo | null; first_scan: FirstScan | null };

/** Contenu d'un QR code ou code saisi à la main, ramené au code du billet (comme le serveur). */
export function normalizeCode(raw: string): string {
  const clean = raw.trim().toUpperCase().replace(/[^0-9A-Z]/g, "");
  return clean.length <= 20 ? clean : "";
}

export async function fingerprint(code: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(code));
  return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, "0")).join("");
}

// --- Stockage sur le téléphone (IndexedDB) ---------------------------------------------------

const DB_NAME = "kassevent-check-in";
const STORE = "kv";

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function withStore<T>(mode: IDBTransactionMode, run: (store: IDBObjectStore) => IDBRequest<T> | void): Promise<T | undefined> {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, mode);
    const request = run(tx.objectStore(STORE));
    tx.oncomplete = () => {
      db.close();
      resolve(request ? request.result : undefined);
    };
    tx.onerror = () => {
      db.close();
      reject(tx.error);
    };
  });
}

/** Données de contrôle d'un événement sur ce téléphone. */
export type Desk = {
  list: CheckInList | null;
  /** Empreinte → premier scan fait sur ce téléphone (pas encore dans la liste téléchargée). */
  local: Record<string, FirstScan>;
  pending: PendingScan[];
};

const deskKey = (organizationId: string, eventId: string) => `${organizationId}:${eventId}`;

export async function loadDesk(organizationId: string, eventId: string): Promise<Desk> {
  try {
    const saved = await withStore<Desk>("readonly", (store) => store.get(deskKey(organizationId, eventId)));
    return saved ?? { list: null, local: {}, pending: [] };
  } catch {
    // Stockage refusé (navigation privée) : le scan marche en ligne, sans mémoire.
    return { list: null, local: {}, pending: [] };
  }
}

export async function saveDesk(organizationId: string, eventId: string, desk: Desk): Promise<void> {
  try {
    await withStore("readwrite", (store) => store.put(desk, deskKey(organizationId, eventId)));
  } catch {
    // Voir loadDesk.
  }
}

/** À la déconnexion : la liste contient les noms des participants, elle ne reste pas sur le téléphone. */
export async function clearDesks(): Promise<void> {
  try {
    await withStore("readwrite", (store) => store.clear());
  } catch {
    // Rien à effacer.
  }
}

// --- Décision -------------------------------------------------------------------------------

/** Résultat hors ligne, d'après la liste téléchargée et les scans déjà faits sur ce téléphone. */
export function decideOffline(desk: Desk, hash: string, id: string): Verdict {
  const base = { id, ticket: null, first_scan: null, other_event: null, offline: true };
  if (!desk.list) return { ...base, result: "unverified" };
  const entry = desk.list.tickets.find((ticket) => ticket.hash === hash);
  if (!entry) return { ...base, result: "invalid" };
  const ticket = { holder_name: entry.holder_name, ticket_type: entry.ticket_type, companions: entry.companions };
  const first = entry.first_scan ?? desk.local[hash] ?? null;
  return first ? { ...base, result: "already_used", ticket, first_scan: first } : { ...base, result: "valid", ticket };
}

/** Envoie des scans et renvoie le résultat qui fait foi pour chacun. */
export function sendScans(organizationId: string, eventId: string, scans: Omit<PendingScan, "local">[], signal?: AbortSignal) {
  return api<{ data: ServerScan[] }>(`/events/${eventId}/check-in/scans`, {
    method: "POST",
    organizationId,
    body: { scans },
    signal,
  });
}

export function downloadList(organizationId: string, eventId: string) {
  return api<CheckInList>(`/events/${eventId}/check-in`, { organizationId });
}

/** Personnes à laisser entrer avec ce billet : le titulaire et ses accompagnants. */
export function admits(ticket: TicketInfo | null): number {
  return 1 + (ticket?.companions ?? 0);
}
