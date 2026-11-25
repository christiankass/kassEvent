/**
 * Caméra arrière et lecture des QR codes : le détecteur intégré au navigateur quand il existe
 * (Chrome sur Android), sinon jsQR, chargé à la demande (Safari sur iPhone).
 */

export type Camera = { stop: () => void };

type Detector = { detect: (source: CanvasImageSource) => Promise<{ rawValue: string }[]> };

const EVERY_MS = 120;
const MAX_WIDTH = 640; // image réduite : décodage rapide sur les téléphones modestes

async function makeDecoder(): Promise<(video: HTMLVideoElement, canvas: HTMLCanvasElement) => Promise<string | null>> {
  const Native = (window as unknown as { BarcodeDetector?: new (o: { formats: string[] }) => Detector }).BarcodeDetector;
  if (Native) {
    try {
      const detector = new Native({ formats: ["qr_code"] });
      return async (video) => (await detector.detect(video))[0]?.rawValue ?? null;
    } catch {
      // Format non pris en charge : repli sur jsQR.
    }
  }

  const { default: jsQR } = await import("jsqr");
  let context: CanvasRenderingContext2D | null = null;
  return async (video, canvas) => {
    const scale = Math.min(1, MAX_WIDTH / video.videoWidth);
    const width = Math.round(video.videoWidth * scale);
    const height = Math.round(video.videoHeight * scale);
    // Redimensionner vide le canevas : seulement quand la taille de l'image change.
    if (canvas.width !== width || canvas.height !== height) {
      canvas.width = width;
      canvas.height = height;
    }
    context ??= canvas.getContext("2d", { willReadFrequently: true });
    if (!context) return null;
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    const image = context.getImageData(0, 0, canvas.width, canvas.height);
    return jsQR(image.data, image.width, image.height, { inversionAttempts: "dontInvert" })?.data ?? null;
  };
}

/** Ouvre la caméra dans `video` et appelle `onCode` à chaque QR lu, sauf quand `paused()` dit d'attendre. */
export async function startCamera(video: HTMLVideoElement, onCode: (text: string) => void, paused: () => boolean): Promise<Camera> {
  if (!navigator.mediaDevices?.getUserMedia) throw new DOMException("No camera", "NotFoundError");
  const stream = await navigator.mediaDevices.getUserMedia({
    audio: false,
    video: { facingMode: { ideal: "environment" }, width: { ideal: 1280 }, height: { ideal: 720 } },
  });
  video.srcObject = stream;
  await video.play();

  const decode = await makeDecoder();
  const canvas = document.createElement("canvas");
  let stopped = false;
  let timer = 0;

  const tick = async () => {
    if (stopped) return;
    if (!paused() && video.readyState >= 2 && video.videoWidth > 0) {
      try {
        const text = await decode(video, canvas);
        if (text && !stopped) onCode(text);
      } catch {
        // Image illisible : on passe à la suivante.
      }
    }
    timer = window.setTimeout(tick, EVERY_MS);
  };
  void tick();

  return {
    stop() {
      stopped = true;
      window.clearTimeout(timer);
      stream.getTracks().forEach((track) => track.stop());
      video.srcObject = null;
    },
  };
}
