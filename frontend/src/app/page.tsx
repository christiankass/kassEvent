import styles from "./page.module.css";

export default function Home() {
  return (
    <main className={styles.main}>
      <h1 className={styles.title}>
        kass<mark>Event</mark>
      </h1>
      <p className={styles.lede}>
        Vendez vos billets en mobile money, envoyez vos invitations par WhatsApp et contrôlez chaque entrée, même sans réseau.
      </p>
      <p className={styles.soon}>Ouverture prochaine</p>
    </main>
  );
}
