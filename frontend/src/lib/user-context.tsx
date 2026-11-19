"use client";

import { createContext, useContext } from "react";
import type { User } from "./api";

export const UserContext = createContext<{ user: User; setUser: (user: User) => void } | null>(null);

function useUserContext() {
  const value = useContext(UserContext);
  if (!value) throw new Error("useUser doit être utilisé dans l'espace connecté");
  return value;
}

/** Utilisateur connecté, disponible dans toutes les pages de l'espace connecté. */
export function useUser(): User {
  return useUserContext().user;
}

/** Met à jour l'utilisateur affiché après un changement de son compte (sans recharger la session). */
export function useSetUser(): (user: User) => void {
  return useUserContext().setUser;
}
