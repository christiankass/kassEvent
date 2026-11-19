const fr = {
  brand: {
    // Titre du panneau : [début, mot surligné en jaune, fin]
    headline: ["Vos billets, vos invités, ", "chaque entrée", "."],
    tagline: "Vendez vos billets en mobile money, envoyez vos invitations par WhatsApp et contrôlez chaque entrée.",
  },
  home: {
    lede: "Vendez vos billets en mobile money, envoyez vos invitations par WhatsApp et contrôlez chaque entrée, même sans réseau.",
    register: "Créer un compte",
    login: "Se connecter",
  },
  common: {
    email: "Adresse e-mail",
    password: "Mot de passe",
    showPassword: "Afficher le mot de passe",
    hidePassword: "Masquer le mot de passe",
    loading: "Chargement…",
    networkError: "Impossible de joindre kassEvent. Vérifiez votre connexion Internet et réessayez.",
    genericError: "Une erreur est survenue. Réessayez dans un instant.",
    tooManyAttempts: "Trop de tentatives. Patientez une minute avant de réessayer.",
    sessionExpired: "Votre session a expiré. Reconnectez-vous.",
    retry: "Réessayer",
    switchLanguage: "English",
    switchLanguageLabel: "Passer en anglais",
  },
  login: {
    title: "Connexion",
    lede: "Retrouvez vos événements, vos ventes et vos invités.",
    remember: "Rester connecté sur cet appareil",
    submit: "Se connecter",
    forgot: "Mot de passe oublié ?",
    noAccount: "Pas encore de compte ?",
    toRegister: "Créer un compte",
  },
  register: {
    title: "Créer un compte",
    lede: "Gratuit, sans engagement. Vous créerez ensuite votre espace d'organisateur.",
    name: "Nom complet",
    passwordHint: "10 caractères minimum, avec au moins une lettre et un chiffre.",
    passwordConfirmation: "Confirmer le mot de passe",
    submit: "Créer mon compte",
    hasAccount: "Déjà un compte ?",
    toLogin: "Se connecter",
  },
  forgot: {
    title: "Mot de passe oublié",
    lede: "Indiquez votre adresse e-mail : nous vous envoyons un lien pour choisir un nouveau mot de passe.",
    submit: "Envoyer le lien",
    sent: "Si un compte existe pour cette adresse, un lien vient d'y être envoyé. Pensez à regarder dans vos courriers indésirables.",
    back: "Retour à la connexion",
  },
  reset: {
    title: "Nouveau mot de passe",
    lede: "Choisissez un nouveau mot de passe pour votre compte.",
    submit: "Enregistrer",
    done: "Votre mot de passe a été changé. Vous pouvez vous connecter.",
    invalidLink: "Ce lien est incomplet. Demandez un nouveau lien de réinitialisation.",
    newLink: "Demander un nouveau lien",
    toLogin: "Se connecter",
  },
  app: {
    logout: "Se déconnecter",
    logoutFailed: "La déconnexion n'a pas abouti. Vérifiez votre connexion et réessayez.",
    switchOrganization: "Organisation",
  },
  newOrganization: {
    title: "Votre espace",
    lede: "Chaque espace est séparé des autres : vos événements, vos ventes et vos invités restent à vous.",
    name: "Nom de l'espace",
    nameHint: "Le nom de votre agence, de votre entreprise ou de votre événement familial.",
    type: "Vous êtes",
    types: {
      organizer: ["Organisateur indépendant", "Concerts, soirées, conférences"],
      agency: ["Agence événementielle", "Vous organisez pour vos clients"],
      company: ["Entreprise", "Événements internes ou clients"],
      institution: ["Institution", "Université, ONG, administration"],
      individual: ["Particulier", "Mariage, anniversaire, fête de famille"],
    },
    currency: "Devise des prix",
    submit: "Créer mon espace",
  },
  dashboard: {
    hello: "Bonjour {name}",
    roleIntro: "Votre rôle dans {organization} : {role}.",
    roles: {
      owner: "propriétaire",
      admin: "administrateur",
      event_manager: "gestionnaire d'événements",
      finance: "finance",
      check_in_agent: "agent d'accueil",
      viewer: "lecture seule",
    },
    emptyTitle: "Votre premier événement",
    emptyBody: "Bientôt, vous créerez ici vos événements, vos billets et vos invitations.",
    emptyCta: "Bientôt disponible",
  },
};

export type Dictionary = typeof fr;

const en: Dictionary = {
  brand: {
    headline: ["Your tickets, your guests, ", "every entry", "."],
    tagline: "Sell tickets with mobile money, send invitations on WhatsApp and check every guest in.",
  },
  home: {
    lede: "Sell tickets with mobile money, send invitations on WhatsApp and check every guest in, even offline.",
    register: "Create an account",
    login: "Sign in",
  },
  common: {
    email: "Email address",
    password: "Password",
    showPassword: "Show password",
    hidePassword: "Hide password",
    loading: "Loading…",
    networkError: "kassEvent can't be reached. Check your internet connection and try again.",
    genericError: "Something went wrong. Please try again in a moment.",
    tooManyAttempts: "Too many attempts. Wait a minute before trying again.",
    sessionExpired: "Your session has expired. Please sign in again.",
    retry: "Try again",
    switchLanguage: "Français",
    switchLanguageLabel: "Switch to French",
  },
  login: {
    title: "Sign in",
    lede: "Get back to your events, sales and guests.",
    remember: "Keep me signed in on this device",
    submit: "Sign in",
    forgot: "Forgot your password?",
    noAccount: "No account yet?",
    toRegister: "Create an account",
  },
  register: {
    title: "Create an account",
    lede: "Free, no commitment. You will set up your organizer space next.",
    name: "Full name",
    passwordHint: "At least 10 characters, with at least one letter and one number.",
    passwordConfirmation: "Confirm password",
    submit: "Create my account",
    hasAccount: "Already have an account?",
    toLogin: "Sign in",
  },
  forgot: {
    title: "Forgot password",
    lede: "Enter your email address and we will send you a link to choose a new password.",
    submit: "Send the link",
    sent: "If an account exists for this address, a link has just been sent to it. Check your spam folder too.",
    back: "Back to sign in",
  },
  reset: {
    title: "New password",
    lede: "Choose a new password for your account.",
    submit: "Save",
    done: "Your password has been changed. You can sign in now.",
    invalidLink: "This link is incomplete. Ask for a new reset link.",
    newLink: "Ask for a new link",
    toLogin: "Sign in",
  },
  app: {
    logout: "Sign out",
    logoutFailed: "Signing out failed. Check your connection and try again.",
    switchOrganization: "Organization",
  },
  newOrganization: {
    title: "Your space",
    lede: "Each space is kept apart from the others: your events, sales and guests stay yours.",
    name: "Space name",
    nameHint: "The name of your agency, your company or your family event.",
    type: "You are",
    types: {
      organizer: ["Independent organizer", "Concerts, parties, conferences"],
      agency: ["Event agency", "You organize for your clients"],
      company: ["Company", "Internal or client events"],
      institution: ["Institution", "University, NGO, public body"],
      individual: ["Private individual", "Wedding, birthday, family party"],
    },
    currency: "Price currency",
    submit: "Create my space",
  },
  dashboard: {
    hello: "Hello {name}",
    roleIntro: "Your role in {organization}: {role}.",
    roles: {
      owner: "owner",
      admin: "administrator",
      event_manager: "event manager",
      finance: "finance",
      check_in_agent: "check-in agent",
      viewer: "read only",
    },
    emptyTitle: "Your first event",
    emptyBody: "Soon you will create your events, tickets and invitations here.",
    emptyCta: "Coming soon",
  },
};

export const LOCALES = ["fr", "en"] as const;
export type Locale = (typeof LOCALES)[number];
export const dictionaries: Record<Locale, Dictionary> = { fr, en };

export function isLocale(value: unknown): value is Locale {
  return (LOCALES as readonly unknown[]).includes(value);
}

/** Remplace {cle} dans un texte. */
export function format(text: string, values: Record<string, string>): string {
  return text.replace(/\{(\w+)\}/g, (_, key: string) => values[key] ?? "");
}
