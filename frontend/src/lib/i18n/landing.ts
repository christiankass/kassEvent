// Textes de la page d'accueil publique (avant la connexion).

export const homeFr = {
  metaTitle: "kassEvent : billetterie et invitations en mobile money",
  metaDescription:
    "Vendez vos billets en mobile money ou par carte, envoyez-les par WhatsApp et contrôlez chaque entrée, même sans réseau.",
  register: "Créer un compte",
  login: "Se connecter",
  nav: {
    label: "Sections de la page",
    features: "Fonctionnalités",
    studio: "Modèles",
    private: "Mariages et fêtes",
  },
  hero: {
    // Titre : [début, mot surligné en jaune, fin]
    title: ["Vendez vos billets. ", "Contrôlez", " chaque entrée."],
    lede: "Mobile money ou carte, billets envoyés par WhatsApp, scan à l'entrée même sans réseau. Pensé pour Kinshasa.",
    ticketAlt: "Billet d'entrée kassEvent avec QR code, reçu par WhatsApp",
    invitationAlt: "Billet personnalisé « Rumba Live Kinshasa » avec QR code",
    phoneAlt: "Écran de paiement M-Pesa : le client valide sur son téléphone",
  },
  pay: {
    title: "Vos clients paient comme ils en ont l'habitude.",
    methods: ["M-Pesa", "Orange Money", "Airtel Money", "Visa", "Mastercard"],
    currencies: "Prix en dollars ou en francs congolais",
  },
  features: {
    title: "Tout pour remplir la salle sans perdre un franc.",
    pay: {
      title: "Encaissez en mobile money",
      body: "Le client valide le paiement sur son téléphone. Ses billets arrivent aussitôt, chacun avec un QR code unique.",
      alt: "Écran d'attente du paiement mobile money",
    },
    send: {
      title: "Billets par e-mail, WhatsApp et Messenger",
      body: "Chaque acheteur reçoit ses billets là où il lit ses messages. Rien à installer.",
      alt: "Commande confirmée avec envoi des billets par WhatsApp",
    },
    scan: {
      title: "Scan à l'entrée, même hors ligne",
      body: "Valide, déjà utilisé ou faux : le contrôleur le sait en une seconde. Tout se synchronise au retour du réseau.",
      alt: "Écran de scan vert « Valide » avec le nom de l'invitée",
    },
    payouts: {
      title: "Vos recettes reversées automatiquement",
      body: "kassEvent encaisse pour vous, puis reverse l'argent sur votre mobile money ou votre compte bancaire, commission déduite.",
      alt: "Écran des versements avec le solde et l'historique",
    },
    team: {
      title: "Une équipe, des rôles sur mesure",
      body: "Caisse, contrôle, comptabilité : chacun ne voit que ce qui le concerne. Double authentification pour l'argent.",
    },
    languages: {
      title: "Français et anglais",
      body: "Vos pages, vos billets et vos messages dans la langue de vos invités.",
    },
  },
  studio: {
    title: "Plus de 300 modèles d'affiches, de billets et d'invitations, tous différents.",
    body: "Concert, mariage, culte, gala, anniversaire : choisissez un modèle, ajoutez votre photo et vos couleurs.",
    points: [
      "Portrait, carré, story ou paysage, au bon format pour chaque réseau",
      "De vraies photos libres de droits, ou la vôtre, et vos couleurs",
      "Le QR code ajouté tout seul sur chaque billet",
    ],
    alt: "Planche de modèles d'affiches kassEvent",
  },
  private: {
    eyebrow: "Pour les particuliers",
    title: "Un mariage, un anniversaire ? Gérez vos invités sans agence.",
    body: "Invitations nominatives, réponses des invités, plan de salle. Le jour J, le QR code de l'invitation ouvre la porte.",
    points: [
      "Chaque invité répond en un geste : présent ou non, avec combien d'accompagnants",
      "Le nombre de tables calculé pour vous, chacun placé à la sienne",
      "Les accompagnants comptés au scan, sans liste papier",
    ],
    guestsAlt: "Liste des invités : qui vient, qui ne vient pas, qui n'a pas répondu",
    tablesAlt: "Capacité de la salle et calcul des tables",
  },
  steps: {
    title: "Prêt en trois gestes.",
    items: [
      { verb: "Créez", body: "Votre événement, vos billets et vos prix en quelques minutes." },
      { verb: "Partagez", body: "Votre page de vente sur WhatsApp, Facebook ou TikTok." },
      { verb: "Scannez", body: "Les billets à l'entrée, avec n'importe quel téléphone." },
    ],
  },
  final: {
    title: "Votre prochain événement commence ici.",
    body: "Créez votre compte et publiez votre premier événement aujourd'hui.",
  },
  footer: "Billetterie et invitations, de Kinshasa pour vos événements.",
};

export const homeEn: typeof homeFr = {
  metaTitle: "kassEvent: ticketing and invitations with mobile money",
  metaDescription: "Sell tickets with mobile money or cards, send them on WhatsApp and check every guest in, even offline.",
  register: "Create an account",
  login: "Sign in",
  nav: {
    label: "Page sections",
    features: "Features",
    studio: "Templates",
    private: "Weddings and parties",
  },
  hero: {
    title: ["Sell your tickets. ", "Check", " every entry."],
    lede: "Mobile money or card, tickets sent on WhatsApp, door scanning even offline. Built for Kinshasa.",
    ticketAlt: "kassEvent admission ticket with QR code, received on WhatsApp",
    invitationAlt: "Custom “Rumba Live Kinshasa” ticket with QR code",
    phoneAlt: "M-Pesa payment screen: the buyer approves on their phone",
  },
  pay: {
    title: "Your buyers pay the way they already do.",
    methods: ["M-Pesa", "Orange Money", "Airtel Money", "Visa", "Mastercard"],
    currencies: "Prices in US dollars or Congolese francs",
  },
  features: {
    title: "Everything to fill the room without losing a franc.",
    pay: {
      title: "Get paid with mobile money",
      body: "The buyer approves the payment on their phone. Tickets arrive right away, each with a unique QR code.",
      alt: "Mobile money payment waiting screen",
    },
    send: {
      title: "Tickets by email, WhatsApp and Messenger",
      body: "Every buyer gets their tickets where they read their messages. Nothing to install.",
      alt: "Confirmed order with tickets sent on WhatsApp",
    },
    scan: {
      title: "Door scanning, even offline",
      body: "Valid, already used or fake: staff know in a second. Everything syncs when the network is back.",
      alt: "Green “Valid” scan screen with the guest's name",
    },
    payouts: {
      title: "Your revenue paid out automatically",
      body: "kassEvent collects for you, then pays the money out to your mobile money or bank account, fee deducted.",
      alt: "Payouts screen with balance and history",
    },
    team: {
      title: "One team, custom roles",
      body: "Box office, door, accounting: everyone only sees what they need. Two-factor sign-in for money.",
    },
    languages: {
      title: "French and English",
      body: "Your pages, tickets and messages in your guests' language.",
    },
  },
  studio: {
    title: "Over 300 poster, ticket and invitation templates, each one different.",
    body: "Concert, wedding, church service, gala, birthday: pick a template, add your photo and your colours.",
    points: [
      "Portrait, square, story or landscape, the right size for every network",
      "Real royalty-free photos, or your own, and your colours",
      "The QR code added automatically to every ticket",
    ],
    alt: "Sheet of kassEvent poster templates",
  },
  private: {
    eyebrow: "For private events",
    title: "A wedding, a birthday? Manage your guests without an agency.",
    body: "Named invitations, guest replies, seating plan. On the day, the invitation's QR code opens the door.",
    points: [
      "Each guest replies in one tap: coming or not, and with how many companions",
      "The number of tables worked out for you, everyone seated at theirs",
      "Companions counted at the door, no paper list",
    ],
    guestsAlt: "Guest list: who is coming, who is not, who has not replied",
    tablesAlt: "Room capacity and table calculation",
  },
  steps: {
    title: "Ready in three moves.",
    items: [
      { verb: "Create", body: "Your event, tickets and prices in a few minutes." },
      { verb: "Share", body: "Your sales page on WhatsApp, Facebook or TikTok." },
      { verb: "Scan", body: "Tickets at the door, with any phone." },
    ],
  },
  final: {
    title: "Your next event starts here.",
    body: "Create your account and publish your first event today.",
  },
  footer: "Ticketing and invitations, from Kinshasa for your events.",
};
