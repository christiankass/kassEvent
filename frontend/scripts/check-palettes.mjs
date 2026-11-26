// Contrastes des palettes du studio (WCAG 2.1). Usage : node --experimental-strip-types scripts/check-palettes.mjs
import { PALETTES } from "../src/lib/canvas-palettes.ts";

const lum = (hex) => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
  const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
  return (x + 0.05) / (y + 0.05);
};
// [premier plan, fond, minimum] : texte courant 4,5 ; grands titres, pastilles et pictogrammes 3.
const RULES = [
  ["ink", "bg", 4.5],
  ["soft", "bg", 3],
  ["accent", "bg", 3],
  ["onAccent", "accent", 3],
  ["onAccent", "accent2", 3],
  ["cardInk", "card", 4.5],
  ["cardSoft", "card", 3],
];
let failures = 0;
for (const [id, palette] of Object.entries(PALETTES)) {
  for (const [fg, bg, min] of RULES) {
    for (const back of bg === "bg" && palette.bg2 ? ["bg", "bg2"] : [bg]) {
      const value = ratio(palette[fg], palette[back]);
      if (value < min) {
        failures++;
        console.log(`${id}: ${fg} ${palette[fg]} sur ${back} ${palette[back]} = ${value.toFixed(2)} (< ${min})`);
      }
    }
  }
}
console.log(failures ? `${failures} contraste(s) insuffisant(s)` : `${Object.keys(PALETTES).length} palettes lisibles`);
process.exit(failures ? 1 : 0);
