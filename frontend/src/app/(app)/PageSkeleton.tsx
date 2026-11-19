import styles from "./app.module.css";

/** Squelette affiché pendant le chargement d'une page de l'espace connecté. */
export function PageSkeleton() {
  return (
    <main className={styles.main} aria-busy="true">
      <div className={styles.skeleton} />
      <div className={styles.skeletonLine} />
    </main>
  );
}
