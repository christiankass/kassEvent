/** Le navigateur ne sait pas lire l'image (HEIC d'iPhone, AVIF, fichier abîmé…). */
export class UnsupportedImageError extends Error {}

const ACCEPTED = ["image/jpeg", "image/png", "image/webp"];
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

  const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
  if (scale === 1 && ACCEPTED.includes(file.type) && file.size <= MAX_BYTES) {
    bitmap.close();
    return file;
  }

  const canvas = document.createElement("canvas");
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext("2d")?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close();

  // Le JPEG reste en JPEG ; les autres formats peuvent avoir de la transparence : WebP (PNG sur les vieux Safari).
  const type = file.type === "image/jpeg" ? "image/jpeg" : "image/webp";
  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, 0.9));
  if (!blob) throw new UnsupportedImageError();
  const extension = blob.type.split("/")[1] ?? "jpg";

  return new File([blob], `${file.name.replace(/\.[^.]+$/, "") || "image"}.${extension}`, { type: blob.type });
}
