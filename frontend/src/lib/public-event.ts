import { cache } from "react";
import { SERVER_API_URL, type PublicEvent } from "./events";

/** Événement publié, lu côté serveur ; une seule requête par affichage (page et métadonnées). */
export const fetchPublicEvent = cache(async (slug: string): Promise<PublicEvent | null> => {
  if (!/^[a-z0-9-]{1,100}$/.test(slug)) return null;
  const response = await fetch(`${SERVER_API_URL}/public/events/${slug}`, {
    headers: { Accept: "application/json" },
    // Pas de cache : une page retirée de la vente disparaît aussitôt, les places restantes sont à jour.
    cache: "no-store",
  });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`API ${response.status}`);
  return (await response.json()).data;
});
