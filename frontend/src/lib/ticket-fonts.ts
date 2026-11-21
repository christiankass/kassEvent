import { Great_Vibes, Playfair_Display } from "next/font/google";

/** Polices propres aux billets personnalisés (Fraunces et Instrument Sans viennent du layout). */
const playfair = Playfair_Display({ subsets: ["latin"], weight: ["700"], variable: "--f-playfair" });
const script = Great_Vibes({ subsets: ["latin"], weight: ["400"], variable: "--f-script" });

export const ticketFontVariables = `${playfair.variable} ${script.variable}`;
