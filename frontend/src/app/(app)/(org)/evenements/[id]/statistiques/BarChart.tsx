"use client";

import { useState } from "react";
import styles from "./stats.module.css";

export type BarPoint = { key: string; axis: string; label: string; value: number };

/**
 * Histogramme d'une seule série (une couleur, pas de légende : le titre la nomme). Survol ou focus
 * clavier d'une barre : bulle avec la date et la valeur. Seules quelques étiquettes d'axe sont écrites.
 */
export function BarChart({ points, formatValue, label }: { points: BarPoint[]; formatValue: (value: number) => string; label: string }) {
  const [active, setActive] = useState<number | null>(null);
  const max = Math.max(...points.map((point) => point.value), 0);
  // Trois repères seulement (début, milieu, fin), répartis sur la largeur : jamais coupés au bord.
  const ticks = [...new Set([0, Math.floor((points.length - 1) / 2), points.length - 1])].map((index) => points[index]);
  const current = active === null ? null : points[active];

  return (
    <figure className={styles.chart} aria-label={label} onMouseLeave={() => setActive(null)}>
      <div className={styles.chartTop}>
        <span className={styles.chartMax}>{max > 0 ? formatValue(max) : ""}</span>
        <span className={styles.chartTip} aria-live="polite">
          {current ? `${current.label} · ${formatValue(current.value)}` : ""}
        </span>
      </div>
      <div className={styles.plot} data-active={active !== null || undefined}>
        {points.map((point, index) => (
          <div
            key={point.key}
            className={styles.slot}
            tabIndex={0}
            role="img"
            aria-label={`${point.label} : ${formatValue(point.value)}`}
            data-active={active === index || undefined}
            onMouseEnter={() => setActive(index)}
            onFocus={() => setActive(index)}
            onBlur={() => setActive(null)}
          >
            <span className={styles.bar} style={{ height: max > 0 && point.value > 0 ? `max(3px, ${(point.value / max) * 100}%)` : 0 }} />
          </div>
        ))}
      </div>
      <div className={styles.axis} aria-hidden>
        {ticks.map((point) => (
          <span key={point.key}>{point.axis}</span>
        ))}
      </div>
    </figure>
  );
}
