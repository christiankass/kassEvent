/** Le navigateur ne sait pas lire l'image (HEIC d'iPhone, AVIF, fichier abîmé…). */
export class UnsupportedImageError extends Error {}

const ACCEPTED = ["image/jpeg", "image/png", "image/webp"];
/** Pour l'attribut `accept` des champs de fichier : les formats que le serveur accepte. */
export const IMAGE_ACCEPT = ACCEPTED.join(",");
const MAX_BYTES = 4 * 1024 * 1024;

/**
 * Prépare une image avant l'envoi : une photo de téléphone (souvent 4 à 12 Mo, parfois tournée par
 * ses données EXIF) est redessinée dans le navigateur, à `maxSide` pixels au plus, dans le bon sens.
 * Une image déjà petite, dans un format accepté, part telle quelle.
 */
export async function prepareImage(file: File, maxSide = 2000): Promise<File> {
  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
  } catch {
    throw new UnsupportedImageError();
  }

  let canvas: HTMLCanvasElement;
  try {
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    if (scale === 1 && ACCEPTED.includes(file.type) && file.size <= MAX_BYTES) return file;

    canvas = document.createElement("canvas");
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const context = canvas.getContext("2d");
    // Plus de mémoire pour le dessin (très grande photo) : mieux vaut un refus qu'une image vide.
    if (!context) throw new UnsupportedImageError();
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  } finally {
    bitmap.close();
  }

  // Le JPEG reste en JPEG ; les autres formats peuvent avoir de la transparence : WebP (PNG sur les vieux Safari).
  const type = file.type === "image/jpeg" ? "image/jpeg" : "image/webp";
  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, 0.9));
  canvas.width = canvas.height = 0; // libère la mémoire du dessin sans attendre le ramasse-miettes
  if (!blob) throw new UnsupportedImageError();
  const extension = blob.type.split("/")[1];

  return new File([blob], `${file.name.replace(/\.[^.]+$/, "") || "image"}.${extension}`, { type: blob.type });
}
