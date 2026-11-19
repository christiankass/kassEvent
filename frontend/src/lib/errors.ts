import { ApiError } from "./api";
import type { Dictionary } from "./i18n/dictionaries";

/** Message général à afficher au-dessus d'un formulaire (les erreurs de champ restent sous chaque champ). */
export function formErrorMessage(error: unknown, t: Dictionary): string | null {
  if (error == null) return null;
  if (!(error instanceof ApiError)) return t.common.genericError;
  if (error.status === 0) return t.common.networkError;
  if (error.status === 429) return error.field("email") ?? t.common.tooManyAttempts;
  if (error.status === 422) return null;
  if (error.status === 401) return t.common.sessionExpired;
  // Les autres messages du serveur ne sont pas toujours traduits : on affiche les nôtres.
  return t.common.genericError;
}
