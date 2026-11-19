"use client";

import { useCallback, useEffect, useState } from "react";
import { api, isUnauthenticated, type User } from "./api";

export type Session =
  | { status: "loading" }
  | { status: "guest" }
  | { status: "error"; error: unknown }
  | { status: "signed-in"; user: User };

/**
 * Utilisateur connecté, d'après l'API (seule source de vérité). Seul un 401 signifie « non connecté » :
 * une coupure de réseau ou une erreur serveur ne déconnecte pas l'utilisateur.
 */
export function useSession(): Session & { retry: () => void; setUser: (user: User) => void } {
  const [session, setSession] = useState<Session>({ status: "loading" });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;
    api<{ data: User }>("/me")
      .then(({ data }) => !cancelled && setSession({ status: "signed-in", user: data }))
      .catch((error) => !cancelled && setSession(isUnauthenticated(error) ? { status: "guest" } : { status: "error", error }));
    return () => {
      cancelled = true;
    };
  }, [attempt]);

  const retry = useCallback(() => {
    setSession({ status: "loading" });
    setAttempt((n) => n + 1);
  }, []);

  const setUser = useCallback((user: User) => setSession({ status: "signed-in", user }), []);

  return { ...session, retry, setUser };
}
