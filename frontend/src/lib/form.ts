"use client";

import { useRef, useState, type FormEvent } from "react";
import { ApiError } from "./api";
import { formErrorMessage } from "./errors";
import { useI18n } from "./i18n/I18nProvider";

/**
 * État commun des formulaires : envoi en cours, message général, erreurs de champ.
 * Pas de `useActionState` : une action de formulaire React vide les champs après l'envoi,
 * et l'utilisateur perdrait sa saisie à chaque erreur.
 * `navigates` : le succès mène à une autre page ; le bouton reste alors désactivé
 * jusqu'au changement de page.
 * Un double clic part avant que React ait désactivé le bouton : le verrou `busy` (synchrone)
 * écarte le second envoi.
 */
export function useFormSubmit(onSubmit: (form: FormData) => Promise<void>, { navigates = false } = {}) {
  const { t } = useI18n();
  const [error, setError] = useState<unknown>(null);
  const [pending, setPending] = useState(false);
  const busy = useRef(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy.current) return;
    busy.current = true;
    const form = new FormData(event.currentTarget);
    setPending(true);
    setError(null);
    try {
      await onSubmit(form);
      if (!navigates) release();
    } catch (e) {
      setError(e);
      release();
    }
  }

  function release() {
    busy.current = false;
    setPending(false);
  }

  return {
    submit,
    pending,
    message: formErrorMessage(error, t),
    // Erreurs de champ seulement pour une validation (422) ; un 429 s'affiche une fois, en haut.
    fieldError: (name: string) => (error instanceof ApiError && error.status === 422 ? error.field(name) : undefined),
  };
}
