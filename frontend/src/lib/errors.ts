import { ApiError } from "./api";
import type { Dictionary } from "./i18n/dictionaries";

/** Message général à afficher au-dessus d'un formulaire (les erreurs de champ restent sous chaque champ). */
export function formErrorMessage(error: unknown, t: Dictionary): string | null {
  if (error == null) return null;
  if (!(error instanceof ApiError)) return t.common.genericError;
  if (error.status === 0) return t.common.networkError;
  // Trop d'essais : le serveur précise l'attente (connexion : « email », code de vérification : « code »).
  if (error.status === 429) return error.field("email") ?? error.field("code") ?? t.common.tooManyAttempts;
  if (error.status === 422) return null;
  if (error.status === 401) return t.common.sessionExpired;
  // Les autres messages du serveur ne sont pas toujours traduits : on affiche les nôtres.
  return t.common.genericError;
}

/** Message d'une action hors formulaire : pour un refus (422), le premier message du serveur, déjà traduit. */
export function actionErrorMessage(error: unknown, t: Dictionary): string {
  const firstFieldError = error instanceof ApiError ? Object.values(error.errors)[0]?.[0] : undefined;
  return formErrorMessage(error, t) ?? firstFieldError ?? t.common.genericError;
}
