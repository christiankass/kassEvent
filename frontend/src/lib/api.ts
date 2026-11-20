/**
 * Client de l'API kassEvent. La connexion passe par un cookie de session (Sanctum SPA) :
 * le navigateur envoie ses cookies, et chaque écriture porte le jeton CSRF lu dans XSRF-TOKEN.
 */
export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api/v1";
const API_ORIGIN = new URL(API_URL).origin;

export type User = { id: string; name: string; email: string; locale: "fr" | "en"; two_factor_enabled: boolean };

export type Organization = {
  id: string;
  name: string;
  slug: string;
  type: OrganizationType;
  country: string;
  currency: Currency;
  locale: "fr" | "en";
};

export const CURRENCIES = ["USD", "CDF"] as const;
export type Currency = (typeof CURRENCIES)[number];

export type OrganizationType = "organizer" | "agency" | "company" | "institution" | "individual";

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly errors: Record<string, string[]> = {},
    /** Raison précise d'un refus, quand l'API la donne (ex. « two_factor_required »). */
    readonly code?: string,
  ) {
    super(message);
  }

  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

type Options = { method?: "GET" | "POST" | "PATCH" | "DELETE"; body?: unknown; organizationId?: string };

function readXsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : undefined;
}

async function ensureCsrfCookie(): Promise<void> {
  if (!readXsrfToken()) {
    await fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, { credentials: "include" });
  }
}

/** Session expirée pendant l'utilisation : retour à la connexion. */
export function isUnauthenticated(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}

export async function api<T>(path: string, options: Options = {}, retried = false): Promise<T> {
  const { method = "GET", body, organizationId } = options;
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Accept-Language": document.documentElement.lang || "fr",
  };
  // Un fichier part en multipart : le navigateur pose lui-même l'en-tête et sa frontière.
  const isUpload = body instanceof FormData;
  if (body !== undefined && !isUpload) headers["Content-Type"] = "application/json";
  if (organizationId) headers["X-Organization-Id"] = organizationId;

  let response: Response;
  try {
    if (method !== "GET") {
      await ensureCsrfCookie();
      const xsrf = readXsrfToken();
      if (xsrf) headers["X-XSRF-TOKEN"] = xsrf;
    }
    response = await fetch(`${API_URL}${path}`, {
      method,
      headers,
      credentials: "include",
      body: body === undefined || isUpload ? (body as FormData | undefined) : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, "network");
  }

  // Jeton CSRF expiré : on en demande un nouveau et on rejoue la requête une fois.
  if (response.status === 419 && !retried) {
    document.cookie = "XSRF-TOKEN=; path=/; max-age=0";
    return api<T>(path, options, true);
  }

  if (response.status === 204) return undefined as T;

  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new ApiError(response.status, data.message ?? "", data.errors ?? {}, data.code);

  return data as T;
}

export type Role = "owner" | "admin" | "event_manager" | "finance" | "check_in_agent" | "viewer";

export type Member = { id: string; name: string; email: string; role: Role | null };

export type Invitation = { id: string; email: string; role: Role; expires_at: string; expired: boolean };
