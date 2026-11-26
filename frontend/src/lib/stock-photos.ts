import { api } from "./api";

/** Photo de la banque libre (Pexels ou Unsplash), telle que l'API kassEvent la décrit. */
export type StockPhoto = {
  source: "pexels" | "unsplash";
  id: string;
  width: number;
  height: number;
  color: string;
  alt: string;
  /** Petite image (≈ 320 px), servie par la banque : galerie et vignettes des modèles. */
  thumb: string;
  preview: string;
  author: string;
  author_url: string;
  url: string;
};

export type StockCredit = { source: StockPhoto["source"]; author: string; author_url: string; url: string };

export const STOCK_SOURCES: Record<StockPhoto["source"], string> = { pexels: "Pexels", unsplash: "Unsplash" };

type Search = { photos: StockPhoto[]; enabled: boolean };

/** Résultats gardés pour la session : la galerie redemande les mêmes thèmes en changeant de page. */
const searches = new Map<string, Promise<Search>>();

export function searchStock(query: string, organizationId: string): Promise<Search> {
  const key = `${organizationId}|${query.trim().toLowerCase()}`;
  let search = searches.get(key);
  if (!search) {
    search = api<{ data: StockPhoto[]; meta: { enabled: boolean } }>(`/stock-photos?${new URLSearchParams({ q: query.trim() })}`, { organizationId })
      .then(({ data, meta }) => ({ photos: data, enabled: meta.enabled }))
      .catch((error) => {
        searches.delete(key); // nouvel essai à la prochaine demande
        throw error;
      });
    searches.set(key, search);
  }
  return search;
}

/** Copie la photo dans les images de l'événement : elle s'enregistre et s'exporte comme une photo envoyée. */
export async function importStock(photo: Pick<StockPhoto, "source" | "id">, eventId: string, organizationId: string) {
  const { data } = await api<{ data: { name: string; credit: StockCredit } }>(`/events/${eventId}/studio/assets/stock`, {
    method: "POST",
    organizationId,
    body: { source: photo.source, id: photo.id },
  });
  return data;
}

const thumbs = new Map<string, Promise<HTMLImageElement>>();

/** Vignette décodée une seule fois, partagée entre toutes les vignettes de modèles qui l'utilisent. */
export function loadThumb(url: string): Promise<HTMLImageElement> {
  let image = thumbs.get(url);
  if (!image) {
    image = new Promise((resolve, reject) => {
      const element = new Image();
      element.crossOrigin = "anonymous";
      element.decoding = "async";
      element.onload = () => resolve(element);
      element.onerror = () => {
        thumbs.delete(url);
        reject(new Error("thumb"));
      };
      element.src = url;
    });
    thumbs.set(url, image);
  }
  return image;
}
