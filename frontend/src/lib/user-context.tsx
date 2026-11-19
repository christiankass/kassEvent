"use client";

import { createContext, useContext } from "react";
import type { User } from "./api";

export const UserContext = createContext<User | null>(null);

/** Utilisateur connecté, disponible dans toutes les pages de l'espace connecté. */
export function useUser(): User {
  const user = useContext(UserContext);
  if (!user) throw new Error("useUser doit être utilisé dans l'espace connecté");
  return user;
}
