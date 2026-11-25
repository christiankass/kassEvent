import { Anton, Cinzel, Fredoka, Great_Vibes, Pacifico, Playfair_Display, Poppins } from "next/font/google";
import type { CanvasFont } from "./canvas";

/**
 * Polices propres aux billets personnalisés (Fraunces, Instrument Sans et JetBrains Mono viennent du layout).
 * Pas de préchargement : le navigateur ne télécharge que celle qu'un billet utilise vraiment.
 */
const playfair = Playfair_Display({ subsets: ["latin"], weight: ["400", "700"], variable: "--f-playfair", preload: false });
const script = Great_Vibes({ subsets: ["latin"], weight: ["400"], variable: "--f-script", preload: false });
const anton = Anton({ subsets: ["latin"], weight: ["400"], variable: "--f-anton", preload: false });
const poppins = Poppins({ subsets: ["latin"], weight: ["400", "800"], variable: "--f-poppins", preload: false });
const fredoka = Fredoka({ subsets: ["latin"], weight: ["400", "700"], variable: "--f-fredoka", preload: false });
const cinzel = Cinzel({ subsets: ["latin"], weight: ["400", "700"], variable: "--f-cinzel", preload: false });
const pacifico = Pacifico({ subsets: ["latin"], weight: ["400"], variable: "--f-pacifico", preload: false });

export const ticketFontVariables = [playfair, script, anton, poppins, fredoka, cinzel, pacifico].map((font) => font.variable).join(" ");

/** Variable CSS de chaque police du studio (mêmes fichiers que ceux du serveur, resources/fonts). */
const CANVAS_FONT_VARIABLES: Record<CanvasFont, string> = {
  fraunces: "--f-display",
  playfair: "--f-playfair",
  instrument: "--f-body",
  script: "--f-script",
  mono: "--f-code",
  anton: "--f-anton",
  poppins: "--f-poppins",
  fredoka: "--f-fredoka",
  cinzel: "--f-cinzel",
  pacifico: "--f-pacifico",
};

/**
 * Familles réelles des polices du studio : le dessin sur canvas ne lit pas les variables CSS. `root` doit
 * porter `ticketFontVariables`. Attend leur chargement, pour que le premier dessin soit le bon.
 */
export async function loadCanvasFonts(root: HTMLElement): Promise<Record<CanvasFont, string>> {
  const style = getComputedStyle(root);
  const families = Object.fromEntries(
    Object.entries(CANVAS_FONT_VARIABLES).map(([font, variable]) => [font, style.getPropertyValue(variable).trim() || "sans-serif"]),
  ) as Record<CanvasFont, string>;
  await Promise.all(
    Object.values(families).flatMap((family) =>
      ["400", "700"].map((weight) => document.fonts.load(`${weight} 40px ${family}`).catch(() => [])),
    ),
  );
  return families;
}
