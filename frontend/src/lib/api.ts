/**
 * Client de l'API kassEvent. La connexion passe par un cookie de session (Sanctum SPA) :
 * le navigateur envoie ses cookies, et chaque écriture porte le jeton CSRF lu dans XSRF-TOKEN.
 */
const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api/v1";
const API_ORIGIN = new URL(API_URL).origin;

export type User = { id: string; name: string; email: string; locale: "fr" | "en" };

export type Organization = {
  id: string;
  name: string;
  slug: string;
  type: OrganizationType;
  country: string;
  currency: "USD" | "CDF";
  locale: "fr" | "en";
};

export type OrganizationType = "organizer" | "agency" | "company" | "institution" | "individual";

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
  }

  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

type Options = { method?: "GET" | "POST"; body?: unknown; organizationId?: string };

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
  if (body !== undefined) headers["Content-Type"] = "application/json";
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
      body: body === undefined ? undefined : JSON.stringify(body),
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
  if (!response.ok) throw new ApiError(response.status, data.message ?? "", data.errors ?? {});

  return data as T;
}
