import { cookies, headers } from "next/headers";
import { isLocale, type Locale } from "./dictionaries";

/** Langue : choix enregistré (cookie), sinon celle du navigateur, sinon le français. */
export async function resolveLocale(): Promise<Locale> {
  const saved = (await cookies()).get("locale")?.value;
  if (isLocale(saved)) return saved;
  const accepted = (await headers()).get("accept-language") ?? "";
  return /^en\b/i.test(accepted) ? "en" : "fr";
}
