"use client";

import { createContext, useContext } from "react";
import type { Organization, Role } from "./api";

export type CurrentOrganization = {
  organization: Organization;
  role: Role | undefined;
  permissions: string[];
};

export const OrganizationContext = createContext<CurrentOrganization | null>(null);

/** L'organisation courante, fournie par le layout des pages d'une organisation. */
export function useOrganization(): CurrentOrganization {
  const value = useContext(OrganizationContext);
  if (!value) throw new Error("useOrganization() doit être appelé sous le layout (org).");
  return value;
}
