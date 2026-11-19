/** Organisation choisie dans ce navigateur (le serveur vérifie toujours l'appartenance). */
const KEY = "kassevent.organization";

export function getCurrentOrganizationId(): string | null {
  try {
    return localStorage.getItem(KEY);
  } catch {
    return null;
  }
}

export function setCurrentOrganizationId(id: string | null): void {
  try {
    if (id) localStorage.setItem(KEY, id);
    else localStorage.removeItem(KEY);
  } catch {
    // Stockage indisponible (navigation privée) : on retombe sur la première organisation.
  }
}
