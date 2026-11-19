"use client";

import { useRouter } from "next/navigation";
import { Alert, Button, Field } from "@/components/ui";
import { api, type Organization, type OrganizationType } from "@/lib/api";
import { setCurrentOrganizationId } from "@/lib/current-organization";
import { useFormSubmit } from "@/lib/form";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "../../app.module.css";

const TYPES: OrganizationType[] = ["organizer", "agency", "company", "institution", "individual"];

export default function NewOrganizationPage() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    const { data } = await api<{ data: Organization }>("/organizations", {
      method: "POST",
      body: { name: form.get("name"), type: form.get("type"), currency: form.get("currency"), locale },
    });
    setCurrentOrganizationId(data.id);
    router.replace("/tableau-de-bord");
   }, { navigates: true });
  const copy = t.newOrganization;

  return (
    <main className={`${styles.main} ${styles.narrow}`}>
      <h1 className={styles.title}>{copy.title}</h1>
      <p className={styles.lede}>{copy.lede}</p>
      <form className={styles.form} onSubmit={submit} noValidate>
        {message && <Alert tone="error">{message}</Alert>}
        <Field label={copy.name} name="name" required hint={copy.nameHint} error={fieldError("name")} />

        <fieldset className={styles.fieldset}>
          <legend className={styles.legend}>{copy.type}</legend>
          <div className={styles.choices}>
            {TYPES.map((type, index) => (
              <label key={type} className={styles.choice}>
                <input type="radio" name="type" value={type} defaultChecked={index === 0} />
                <span className={styles.choiceTitle}>{copy.types[type][0]}</span>
                <span className={styles.choiceHint}>{copy.types[type][1]}</span>
              </label>
            ))}
          </div>
          {fieldError("type") && <Alert tone="error">{fieldError("type")}</Alert>}
        </fieldset>

        <fieldset className={styles.fieldset}>
          <legend className={styles.legend}>{copy.currency}</legend>
          <div className={styles.segmented}>
            {(["USD", "CDF"] as const).map((currency, index) => (
              <label key={currency} className={styles.choice}>
                <input type="radio" name="currency" value={currency} defaultChecked={index === 0} />
                <span className={styles.choiceTitle}>{currency}</span>
              </label>
            ))}
          </div>
        </fieldset>

        <div>
          <Button type="submit" disabled={pending}>
            {pending ? t.common.loading : copy.submit}
          </Button>
        </div>
      </form>
    </main>
  );
}
