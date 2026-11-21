import { Great_Vibes, Playfair_Display } from "next/font/google";

/**
 * Polices propres aux billets personnalisés (Fraunces et Instrument Sans viennent du layout).
 * Pas de préchargement : le navigateur ne télécharge que celle qu'un billet utilise vraiment.
 */
const playfair = Playfair_Display({ subsets: ["latin"], weight: ["700"], variable: "--f-playfair", preload: false });
const script = Great_Vibes({ subsets: ["latin"], weight: ["400"], variable: "--f-script", preload: false });

export const ticketFontVariables = `${playfair.variable} ${script.variable}`;
