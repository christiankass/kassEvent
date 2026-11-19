"use client";

import { useState, type FormEvent } from "react";
import { ApiError } from "./api";
import { formErrorMessage } from "./errors";
import { useI18n } from "./i18n/I18nProvider";

/**
 * État commun des formulaires : envoi en cours, message général, erreurs de champ.
 * Pas de `useActionState` : une action de formulaire React vide les champs après l'envoi,
 * et l'utilisateur perdrait sa saisie à chaque erreur.
 * En cas de succès, `pending` reste vrai : la page change d'écran ou de route.
 */
export function useFormSubmit(onSubmit: (form: FormData) => Promise<void>) {
  const { t } = useI18n();
  const [error, setError] = useState<unknown>(null);
  const [pending, setPending] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setPending(true);
    setError(null);
    try {
      await onSubmit(form);
    } catch (e) {
      setError(e);
      setPending(false);
    }
  }

  return {
    submit,
    pending,
    message: formErrorMessage(error, t),
    // Erreurs de champ seulement pour une validation (422) ; un 429 s'affiche une fois, en haut.
    fieldError: (name: string) => (error instanceof ApiError && error.status === 422 ? error.field(name) : undefined),
  };
}
