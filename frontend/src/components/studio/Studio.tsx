"use client";

import Link from "next/link";
import { memo, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import {
  ArrowArcLeft,
  ArrowArcRight,
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  Circle,
  Copy,
  DownloadSimple,
  Eye,
  FloppyDisk,
  ImageSquare,
  LineSegment,
  PaintBucket,
  QrCode,
  Square,
  Sparkle,
  SquaresFour,
  Star as StarIcon,
  TextAa,
  TextAlignCenter,
  TextAlignLeft,
  TextAlignRight,
  TextB,
  TextItalic,
  TextT,
  Trash,
  UploadSimple,
  UserCircle,
  X,
} from "@phosphor-icons/react";
import { Alert, buttonClass } from "@/components/ui";
import { api, apiBlob } from "@/lib/api";
import {
  CANVAS_FONTS,
  FIELDS,
  FORMATS,
  ICONS,
  USAGES,
  USAGE_FORMATS,
  canvasProblem,
  constrainQr,
  formatOf,
  isTextual,
  newId,
  qrMinSize,
  resizeDoc,
  type CanvasDoc,
  type CanvasElement,
  type CanvasFont,
  type FieldName,
  type FormatId,
  type IconName,
  type ShapeElement,
  type StudioData,
  type Usage,
} from "@/lib/canvas";
import { FILLED_ICONS, ICON_PATHS } from "@/lib/canvas-icons";
import {
  CATEGORIES,
  STYLES,
  TEMPLATE_IDS,
  buildTemplate,
  categoryCount,
  templateMatches,
  type Category,
  type TemplateContext,
  type TemplateId,
  type TemplateStyle,
} from "@/lib/canvas-templates";
import { textOn } from "@/lib/designs";
import { actionErrorMessage } from "@/lib/errors";
import { formatEventDate, ticketPrice, type EventData } from "@/lib/events";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { loadCanvasFonts, ticketFontVariables } from "@/lib/ticket-fonts";
import { IMAGE_ACCEPT, prepareImage } from "@/lib/images";
import { CanvasStage, fitText, type StageHandle } from "./CanvasStage";
import styles from "./studio.module.css";

type Panel = "templates" | "text" | "elements" | "photos" | "fields" | "background";
type History = { past: CanvasDoc[]; future: CanvasDoc[] };
type Notice = { tone: "success" | "error"; text: string };

const SAMPLE_CODE = "APER-CU0E-XEMP";
const SWATCHES = ["#FFFFFF", "#0E1B1C", "#0B3B3C", "#F2B705", "#E4572E", "#17BEBB", "#C3315C", "#6B1E3A", "#D4AF37", "#14213D", "#FBF6EE", "#7209B7"];
const HISTORY_LIMIT = 80;
/** Modifications rapprochées d'un même réglage (curseur, couleur) : une seule étape d'annulation. */
const MERGE_DELAY = 700;

type Props = {
  event: EventData;
  studio: StudioData;
  organizationId: string;
  organizationName: string;
  canManage: boolean;
  userName: string;
  usage: Usage;
  onUsageChange: (usage: Usage) => void;
};

/**
 * Studio : éditeur libre du billet (ou de l'invitation) et de l'affiche, en glisser-déposer, à la
 * manière d'un logiciel d'affiches. Modèles par occasion, textes, formes, photos, calques, annulation.
 */
export default function Studio({ event, studio: initial, organizationId, organizationName, canManage, userName, usage, onUsageChange }: Props) {
  const { t, locale } = useI18n();
  const s = t.studio;
  const rootRef = useRef<HTMLDivElement>(null);
  const stageRef = useRef<StageHandle>(null);
  const areaRef = useRef<HTMLDivElement>(null);
  const textAreaRef = useRef<HTMLTextAreaElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const lastChange = useRef<{ key: string; at: number } | null>(null);
  const requested = useRef(new Set<string>());

  const context: TemplateContext = useMemo(() => {
    const start = new Date(event.starts_at_utc);
    const part = (options: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(locale, { timeZone: event.timezone, ...options }).format(start);
    const cheapest = event.ticket_types.length > 0 ? Math.min(...event.ticket_types.map((type) => type.price)) : 0;
    return {
      title: event.title,
      date: formatEventDate(event, locale),
      day: part({ weekday: "long", day: "numeric", month: "long", year: "numeric" }),
      shortDate: part({ day: "numeric", month: "short", year: "numeric" }).replace(".", "").toUpperCase(),
      time: part({ hour: "2-digit", minute: "2-digit" }),
      dayNumber: part({ day: "2-digit" }),
      month: part({ month: "short" }).replace(".", "").toUpperCase(),
      year: part({ year: "numeric" }),
      venue: `${event.venue_name}, ${event.city}`,
      city: event.city,
      organizer: organizationName,
      price: ticketPrice(cheapest, event.currency, locale, t),
      guestLabel: initial.kind === "invitation" ? s.sample.guest : s.sample.holder,
      photoLabel: s.photoPlaceholder,
      labels: s.templateText,
    };
  }, [event, locale, initial.kind, s, t, organizationName]);
  const samples: Record<FieldName, string> = useMemo(
    () => ({ holder: userName, ticket_type: event.ticket_types[0]?.name ?? s.sample.ticketType, code: SAMPLE_CODE }),
    [userName, event.ticket_types, s],
  );

  const [studio, setStudio] = useState(initial);
  const [docs, setDocs] = useState<Record<Usage, CanvasDoc>>(() => ({
    ticket: initial.ticket ?? buildTemplate("fleuve", "ticket", USAGE_FORMATS.ticket[0], context),
    poster: initial.poster ?? buildTemplate("fleuve", "poster", USAGE_FORMATS.poster[0], context),
  }));
  const [history, setHistory] = useState<Record<Usage, History>>({ ticket: { past: [], future: [] }, poster: { past: [], future: [] } });
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [panel, setPanel] = useState<Panel>(initial[usage] ? "photos" : "templates");
  const [category, setCategory] = useState<Category>("all");
  const [style, setStyle] = useState<TemplateStyle | null>(null);
  const [fonts, setFonts] = useState<Record<CanvasFont, string> | null>(null);
  const [assets, setAssets] = useState<string[]>([]);
  const [images, setImages] = useState<Record<string, HTMLImageElement | undefined>>({});
  const [scale, setScale] = useState(0.4);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [preview, setPreview] = useState<string | null>(null);

  const doc = docs[usage];
  const selected = doc.elements.find((element) => element.id === selectedId) ?? null;
  const saved = studio[usage];
  const dirty = useMemo(() => JSON.stringify(saved) !== JSON.stringify(doc), [saved, doc]);
  const problem = canvasProblem(doc, usage);
  const pageFormat = formatOf(doc, usage);
  const used = useMemo(() => usedAssets(docs), [docs]);
  const library = useMemo(() => [...new Set([...assets, ...used])], [assets, used]);

  // Polices réelles du dessin, chargées avant le premier rendu du canvas.
  useEffect(() => {
    if (rootRef.current) loadCanvasFonts(rootRef.current).then(setFonts);
  }, []);

  // Images de l'événement, lues à travers l'API (connexion de l'organisateur) puis gardées en mémoire.
  useEffect(() => {
    const missing = library.filter((name) => !requested.current.has(name));
    missing.forEach((name) => requested.current.add(name));
    missing.forEach((name) =>
      apiBlob(`/events/${event.id}/studio/assets/${name}`, organizationId)
        .then(decode)
        .then((image) => setImages((current) => ({ ...current, [name]: image })))
        .catch(() => undefined),
    );
  }, [library, event.id, organizationId]);

  // Libère les images décodées en quittant le studio.
  const imagesRef = useRef(images);
  useEffect(() => {
    imagesRef.current = images;
  }, [images]);
  useEffect(() => () => Object.values(imagesRef.current).forEach((image) => image && URL.revokeObjectURL(image.src)), []);

  // Page à la taille de l'espace disponible.
  useEffect(() => {
    const area = areaRef.current;
    if (!area) return;
    const resize = () => {
      const width = area.clientWidth - 24;
      const height = Math.min(area.clientHeight || Infinity, window.innerHeight * 0.75) - 24;
      setScale(Math.max(0.1, Math.min(width / doc.width, height / doc.height, 1)));
    };
    resize();
    const observer = new ResizeObserver(resize);
    observer.observe(area);
    return () => observer.disconnect();
  }, [doc.width, doc.height, fonts]);

  useEffect(() => {
    if (!dirty) return;
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  /** Nouvel état du document ; `mergeKey` regroupe les retouches d'un même réglage en une étape. */
  const change = useCallback(
    (next: CanvasDoc, mergeKey?: string) => {
      const now = Date.now();
      const merge = !!mergeKey && lastChange.current?.key === mergeKey && now - lastChange.current.at < MERGE_DELAY;
      lastChange.current = mergeKey ? { key: mergeKey, at: now } : null;
      setHistory((all) => {
        const current = all[usage];
        return merge ? all : { ...all, [usage]: { past: [...current.past, docs[usage]].slice(-HISTORY_LIMIT), future: [] } };
      });
      setDocs((all) => ({ ...all, [usage]: next }));
    },
    [docs, usage],
  );

  /** Règles de chaque calque, quelle que soit la retouche : QR code lisible, texte jamais coupé. */
  const normalize = useCallback(
    (element: CanvasElement): CanvasElement =>
      element.type === "qr" ? constrainQr(element, doc) : element.type === "text" && fonts ? fitText(element, fonts) : element,
    [doc, fonts],
  );

  const updateElement = useCallback(
    (element: CanvasElement, mergeKey?: string) => {
      const next = normalize(element);
      change({ ...doc, elements: doc.elements.map((current) => (current.id === next.id ? next : current)) }, mergeKey);
    },
    [change, doc, normalize],
  );

  const patch = (changes: Partial<CanvasElement>, key: string) => {
    if (!selected) return;
    updateElement({ ...selected, ...changes } as CanvasElement, `${selected.id}:${key}`);
  };

  const undo = useCallback(() => {
    const { past, future } = history[usage];
    if (past.length === 0) return;
    setHistory((all) => ({ ...all, [usage]: { past: past.slice(0, -1), future: [docs[usage], ...future] } }));
    setDocs((all) => ({ ...all, [usage]: past[past.length - 1] }));
    lastChange.current = null;
  }, [history, docs, usage]);

  const redo = useCallback(() => {
    const { past, future } = history[usage];
    if (future.length === 0) return;
    setHistory((all) => ({ ...all, [usage]: { past: [...past, docs[usage]], future: future.slice(1) } }));
    setDocs((all) => ({ ...all, [usage]: future[0] }));
    lastChange.current = null;
  }, [history, docs, usage]);

  const add = (element: CanvasElement) => {
    const placed = { ...element, x: Math.round((doc.width - element.width) / 2), y: Math.round((doc.height - element.height) / 2) } as CanvasElement;
    change({ ...doc, elements: [...doc.elements, normalize(placed)] });
    setSelectedId(element.id);
  };

  const remove = useCallback(
    (id: string) => {
      change({ ...doc, elements: doc.elements.filter((element) => element.id !== id) });
      setSelectedId(null);
    },
    [change, doc],
  );

  const duplicate = useCallback(
    (id: string) => {
      const source = doc.elements.find((element) => element.id === id);
      if (!source || source.type === "qr") return;
      const copy = { ...source, id: newId(), x: source.x + 30, y: source.y + 30 };
      const index = doc.elements.indexOf(source);
      change({ ...doc, elements: [...doc.elements.slice(0, index + 1), copy, ...doc.elements.slice(index + 1)] });
      setSelectedId(copy.id);
    },
    [change, doc],
  );

  /** Déplace un calque : +1 vers le haut de la pile, -1 vers le bas, ±Infinity tout au bout. */
  const move = (id: string, by: number) => {
    const elements = [...doc.elements];
    const from = elements.findIndex((element) => element.id === id);
    const to = Math.max(0, Math.min(elements.length - 1, by === Infinity ? elements.length - 1 : by === -Infinity ? 0 : from + by));
    if (from === to) return;
    const [element] = elements.splice(from, 1);
    elements.splice(to, 0, element);
    change({ ...doc, elements });
  };

  // Raccourcis : annuler, rétablir, dupliquer, supprimer, flèches.
  useEffect(() => {
    if (!canManage) return;
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      if (target.closest("input, textarea, select, [contenteditable]") || preview) return;
      const meta = e.ctrlKey || e.metaKey;
      if (meta && e.key.toLowerCase() === "z") {
        e.preventDefault();
        if (e.shiftKey) redo();
        else undo();
      } else if (meta && e.key.toLowerCase() === "y") {
        e.preventDefault();
        redo();
      } else if (selected && meta && e.key.toLowerCase() === "d") {
        e.preventDefault();
        duplicate(selected.id);
      } else if (selected && (e.key === "Delete" || e.key === "Backspace")) {
        e.preventDefault();
        remove(selected.id);
      } else if (selected && e.key.startsWith("Arrow")) {
        e.preventDefault();
        const step = e.shiftKey ? 10 : 1;
        const dx = e.key === "ArrowLeft" ? -step : e.key === "ArrowRight" ? step : 0;
        const dy = e.key === "ArrowUp" ? -step : e.key === "ArrowDown" ? step : 0;
        updateElement({ ...selected, x: selected.x + dx, y: selected.y + dy }, `${selected.id}:nudge`);
      } else if (e.key === "Escape") {
        setSelectedId(null);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [canManage, selected, undo, redo, duplicate, remove, updateElement, doc, preview]);

  /** Change le format de la page : les calques sont replacés et mis à l'échelle. */
  function changeFormat(format: FormatId) {
    if (format === pageFormat) return;
    const [width, height] = FORMATS[format];
    const next = resizeDoc(doc, width, height);
    change({ ...next, elements: next.elements.map((element) => (element.type === "text" && fonts ? fitText(element, fonts) : element)) });
    setSelectedId(null);
  }

  function applyTemplate(id: TemplateId) {
    const next = buildTemplate(id, usage, pageFormat, context);
    if (fonts) next.elements = next.elements.map((element) => (element.type === "text" ? fitText(element, fonts) : element));
    // Les photos déjà posées remplissent les emplacements du modèle, dans l'ordre.
    const photos = doc.elements.flatMap((element) => (element.type === "image" && element.asset ? [element.asset] : []));
    next.elements = next.elements.map((element) => (element.type === "image" && !element.asset && photos.length > 0 ? { ...element, asset: photos.shift()! } : element));
    change(next);
    setSelectedId(null);
  }

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setNotice(null);
    try {
      await action();
    } catch (e) {
      setNotice({ tone: "error", text: actionErrorMessage(e, t) });
    }
    setBusy(false);
  }

  /** Envoie une photo, puis la pose : dans l'emplacement choisi, sinon au milieu de la page. */
  async function sendPhoto(original: File) {
      const file = await prepareImage(original);
      const body = new FormData();
      body.append("image", file);
      // La photo est déjà dans le navigateur : décodée pendant l'envoi, pas retéléchargée.
      const [{ data }, image] = await Promise.all([
        api<{ data: { name: string } }>(`/events/${event.id}/studio/assets`, { method: "POST", organizationId, body }),
        decode(file),
      ]);
      requested.current.add(data.name);
      setAssets((current) => [...current, data.name]);
      setImages((current) => ({ ...current, [data.name]: image }));
      place(data.name, image);
  }

  const upload = (file: File) => run(() => sendPhoto(file));

  /** L'affiche de l'événement, copiée dans les photos du studio. */
  const useCover = () =>
    run(async () => {
      const cover = await apiBlob(`/events/${event.id}/studio/assets/cover`, organizationId);
      await sendPhoto(new File([cover], "affiche.jpg", { type: cover.type || "image/jpeg" }));
    });

  function place(name: string, image?: HTMLImageElement) {
    if (selected?.type === "image") {
      updateElement({ ...selected, asset: name });
      return;
    }
    const natural = image ?? images[name];
    const ratio = natural ? natural.naturalHeight / natural.naturalWidth : 0.75;
    const width = Math.round(doc.width * 0.6);
    add({ id: newId(), type: "image", x: 0, y: 0, width, height: Math.round(width * ratio), rotation: 0, asset: name, cornerRadius: 0 });
  }

  const save = () =>
    run(async () => {
      if (problem) {
        setNotice({ tone: "error", text: s.problems[problem] });
        return;
      }
      setSelectedId(null);
      await new Promise((resolve) => requestAnimationFrame(resolve));
      // Image fixe : tout sauf ce qui change d'un invité à l'autre, ajouté par le serveur à l'envoi.
      const image = await stageRef.current!.exportBlob("image/jpeg", false);
      const body = new FormData();
      body.append("canvas", JSON.stringify(doc));
      body.append("image", new File([image], `${usage}.jpg`, { type: "image/jpeg" }));
      const { data } = await api<{ data: StudioData }>(`/events/${event.id}/studio/${usage}`, { method: "POST", organizationId, body });
      const stored = data[usage]!;
      setStudio(data);
      setDocs((all) => ({ ...all, [usage]: stored }));
      setNotice({ tone: "success", text: usage === "ticket" ? s.savedTicket : s.savedPoster });
    });

  const useClassic = () =>
    run(async () => {
      const { data } = await api<{ data: StudioData }>(`/events/${event.id}/studio/classic`, { method: "POST", organizationId });
      setStudio(data);
      setNotice({ tone: "success", text: s.classicOn });
    });

  /** Billet tel qu'un invité le reçoit, calculé par le serveur (QR code et nom compris). */
  const showPreview = () =>
    run(async () => {
      setPreview(URL.createObjectURL(await apiBlob(`/events/${event.id}/studio/preview`, organizationId)));
    });

  const download = () =>
    run(async () => {
      setSelectedId(null);
      await new Promise((resolve) => requestAnimationFrame(resolve));
      const blob = await stageRef.current!.exportBlob("image/png", true);
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.download = `${slugify(event.title)}-${usage}.png`;
      link.click();
      URL.revokeObjectURL(link.href);
    });

  function editText(id: string) {
    setSelectedId(id);
    requestAnimationFrame(() => textAreaRef.current?.focus());
  }

  const panels: { id: Panel; label: string; icon: ReactNode }[] = [
    { id: "templates", label: s.panels.templates, icon: <SquaresFour size={22} /> },
    { id: "text", label: s.panels.text, icon: <TextT size={22} /> },
    { id: "elements", label: s.panels.elements, icon: <StarIcon size={22} /> },
    { id: "photos", label: s.panels.photos, icon: <ImageSquare size={22} /> },
    ...(usage === "ticket" ? [{ id: "fields" as const, label: s.panels.fields, icon: <QrCode size={22} /> }] : []),
    { id: "background", label: s.panels.background, icon: <PaintBucket size={22} /> },
  ];
  const activePanel = usage === "poster" && panel === "fields" ? "templates" : panel;
  const history_ = history[usage];

  return (
    <div ref={rootRef} className={`${styles.studio} ${ticketFontVariables}`}>
      <header className={styles.toolbar}>
        <Link href={`/evenements/${event.id}`} className={styles.back}>
          <ArrowLeft size={18} aria-hidden />
          <span className={styles.backLabel}>{t.design.back}</span>
        </Link>
        <div className={styles.usages} role="tablist" aria-label={s.usageLabel}>
          {USAGES.map((value) => (
            <button
              key={value}
              type="button"
              role="tab"
              aria-selected={usage === value}
              className={styles.usage}
              onClick={() => {
                setSelectedId(null);
                onUsageChange(value);
              }}
            >
              {value === "ticket" ? (initial.kind === "invitation" ? s.usages.invitation : s.usages.ticket) : s.usages.poster}
            </button>
          ))}
        </div>
        {canManage && (
          <div className={styles.tools}>
            <IconButton label={s.undo} onClick={undo} disabled={history_.past.length === 0}>
              <ArrowArcLeft size={20} />
            </IconButton>
            <IconButton label={s.redo} onClick={redo} disabled={history_.future.length === 0}>
              <ArrowArcRight size={20} />
            </IconButton>
            {usage === "ticket" ? (
              <button type="button" className={buttonClass("secondary", styles.toolButton)} onClick={showPreview} disabled={busy || !studio.ticket}>
                <Eye size={18} aria-hidden /> <span>{s.preview}</span>
              </button>
            ) : (
              <button type="button" className={buttonClass("secondary", styles.toolButton)} onClick={download} disabled={busy}>
                <DownloadSimple size={18} aria-hidden /> <span>{s.download}</span>
              </button>
            )}
            <button type="button" className={buttonClass("primary", styles.toolButton)} onClick={save} disabled={busy || !dirty || !fonts}>
              <FloppyDisk size={18} aria-hidden /> <span>{busy ? s.saving : s.save}</span>
            </button>
          </div>
        )}
      </header>

      <div className={styles.status} aria-live="polite">
        {usage === "ticket" && (
          <span className={studio.mode === "canvas" ? styles.badgeOn : styles.badge}>
            {studio.mode === "canvas" ? s.modeCanvas : s.modeClassic}
          </span>
        )}
        {usage === "poster" && <span className={styles.badge}>{s.posterHint}</span>}
        {canManage && (
          <div className={styles.formats} role="group" aria-label={s.formatLabel}>
            {USAGE_FORMATS[usage].map((value) => (
              <button key={value} type="button" className={styles.formatButton} aria-pressed={pageFormat === value} onClick={() => changeFormat(value)} title={s.formatHints[value]}>
                <span className={styles.formatShape} data-format={value} aria-hidden />
                {s.formats[value]}
              </button>
            ))}
          </div>
        )}
        {dirty && canManage && <span className={styles.dirty}>{saved ? s.unsaved : s.notSaved}</span>}
        {usage === "ticket" && studio.mode === "canvas" && canManage && (
          <button type="button" className={styles.linkButton} onClick={useClassic} disabled={busy}>
            {s.useClassic}
          </button>
        )}
      </div>
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      {!canManage && <Alert tone="error">{s.readOnly}</Alert>}

      <div className={canManage ? styles.workspace : styles.workspaceReadOnly}>
        {canManage && (
          <>
            <nav className={styles.rail} aria-label={s.panelsLabel}>
              {panels.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  className={styles.railButton}
                  aria-pressed={activePanel === item.id}
                  onClick={() => setPanel(item.id)}
                >
                  {item.icon}
                  <span>{item.label}</span>
                </button>
              ))}
            </nav>

            <section className={styles.panel} aria-label={panels.find((item) => item.id === activePanel)?.label}>
              {activePanel === "templates" && (
                <>
                  <h2 className={styles.panelTitle}>{s.panels.templates}</h2>
                  <p className={styles.hint}>{s.templatesHint}</p>
                  <div className={styles.chips} role="group" aria-label={s.categoryLabel}>
                    {CATEGORIES.map((value) => (
                      <button key={value} type="button" className={styles.chip} aria-pressed={category === value} onClick={() => setCategory(value)}>
                        {s.categories[value]} <small>{categoryCount(value)}</small>
                      </button>
                    ))}
                  </div>
                  <div className={styles.chips} role="group" aria-label={s.styleLabel}>
                    {STYLES.map((value) => (
                      <button key={value} type="button" className={styles.chipSoft} aria-pressed={style === value} onClick={() => setStyle(style === value ? null : value)}>
                        {s.styles[value]}
                      </button>
                    ))}
                  </div>
                  <ul className={styles.templates} data-format={pageFormat}>
                    {TEMPLATE_IDS.filter((id) => templateMatches(id, category, style)).map((id) => (
                      <li key={id}>
                        <TemplateThumb
                          id={id}
                          usage={usage}
                          format={pageFormat}
                          context={context}
                          fonts={fonts}
                          samples={samples}
                          label={s.templates[id]}
                          onPick={() => applyTemplate(id)}
                        />
                      </li>
                    ))}
                  </ul>
                  {!TEMPLATE_IDS.some((id) => templateMatches(id, category, style)) && <p className={styles.hint}>{s.noTemplates}</p>}
                </>
              )}

              {activePanel === "text" && (
                <>
                  <h2 className={styles.panelTitle}>{s.panels.text}</h2>
                  <div className={styles.stack}>
                    {(
                      [
                        ["title", { font: "fraunces", fontSize: 96, bold: true }, 900, 120],
                        ["subtitle", { font: "instrument", fontSize: 52, bold: true }, 800, 70],
                        ["body", { font: "instrument", fontSize: 34 }, 700, 100],
                        ["script", { font: "script", fontSize: 90 }, 800, 110],
                        ["impact", { font: "anton", fontSize: 130 }, 900, 150],
                        ["rounded", { font: "fredoka", fontSize: 80, bold: true }, 800, 100],
                        ["classic", { font: "cinzel", fontSize: 72, bold: true }, 900, 90],
                      ] as const
                    ).map(([key, style, width, height]) => (
                      <button
                        key={key}
                        type="button"
                        className={`${styles.addText} ${styles[`addText_${key}`]}`}
                        onClick={() =>
                          add({
                            id: newId(),
                            type: "text",
                            x: 0,
                            y: 0,
                            width: Math.min(width, doc.width - 80),
                            height,
                            rotation: 0,
                            fill: textOn(doc.background.color),
                            align: "center",
                            lineHeight: 1.15,
                            text: s.textPresets[key],
                            ...style,
                          })
                        }
                      >
                        {s.textPresets[key]}
                      </button>
                    ))}
                  </div>
                </>
              )}

              {activePanel === "elements" && (
                <>
                  <h2 className={styles.panelTitle}>{s.panels.elements}</h2>
                  <div className={styles.grid}>
                    {(
                      [
                        ["rect", <Square key="r" size={30} weight="fill" />, {}],
                        ["rounded", <Square key="rr" size={30} weight="duotone" />, { cornerRadius: 40 }],
                        ["ellipse", <Circle key="c" size={30} weight="fill" />, {}],
                        ["star", <StarIcon key="s" size={30} weight="fill" />, {}],
                        ["line", <LineSegment key="l" size={30} weight="bold" />, {}],
                        ["gradient", <Square key="g" size={30} weight="duotone" />, { fill2: "#F2B705" }],
                      ] as const
                    ).map(([key, icon, extra]) => (
                      <button
                        key={key}
                        type="button"
                        className={styles.tile}
                        onClick={() =>
                          add({
                            id: newId(),
                            type: key === "rounded" || key === "gradient" ? "rect" : key,
                            x: 0,
                            y: 0,
                            width: key === "line" ? 400 : 300,
                            height: key === "line" ? 20 : key === "rect" || key === "rounded" || key === "gradient" ? 200 : 300,
                            rotation: 0,
                            fill: key === "line" ? null : "#17BEBB",
                            stroke: key === "line" ? "#0E1B1C" : null,
                            strokeWidth: key === "line" ? 6 : 0,
                            ...extra,
                          } as ShapeElement)
                        }
                      >
                        {icon}
                        <span>{s.shapes[key]}</span>
                      </button>
                    ))}
                  </div>
                  <p className={styles.label}>{s.iconsTitle}</p>
                  <div className={styles.icons}>
                    {ICONS.map((name) => (
                      <button
                        key={name}
                        type="button"
                        className={styles.iconTile}
                        title={s.icons[name]}
                        aria-label={s.icons[name]}
                        onClick={() => add({ id: newId(), type: "icon", icon: name, x: 0, y: 0, width: 120, height: 120, rotation: 0, fill: textOn(doc.background.color) })}
                      >
                        <IconGlyph name={name} />
                      </button>
                    ))}
                  </div>
                </>
              )}

              {activePanel === "photos" && (
                <>
                  <h2 className={styles.panelTitle}>{s.panels.photos}</h2>
                  <p className={styles.hint}>{selected?.type === "image" ? s.photosReplaceHint : s.photosHint}</p>
                  <input
                    ref={fileRef}
                    type="file"
                    accept={IMAGE_ACCEPT}
                    hidden
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      e.target.value = "";
                      if (file) upload(file);
                    }}
                  />
                  <button type="button" className={buttonClass("primary", styles.full)} onClick={() => fileRef.current?.click()} disabled={busy}>
                    <UploadSimple size={18} aria-hidden /> {s.upload}
                  </button>
                  {event.cover_url && (
                    <button type="button" className={buttonClass("secondary", styles.full)} onClick={useCover} disabled={busy}>
                      <ImageSquare size={18} aria-hidden /> {s.useCover}
                    </button>
                  )}
                  <PhotoGrid names={library} images={images} label={s.placePhoto} onPick={place} />
                </>
              )}

              {activePanel === "fields" && (
                <>
                  <h2 className={styles.panelTitle}>{s.panels.fields}</h2>
                  <p className={styles.hint}>{s.fieldsHint}</p>
                  <div className={styles.stack}>
                    {FIELDS.map((name) => (
                      <button
                        key={name}
                        type="button"
                        className={styles.fieldButton}
                        onClick={() =>
                          add({
                            id: newId(),
                            type: "field",
                            field: name,
                            x: 0,
                            y: 0,
                            width: 600,
                            height: name === "holder" ? 64 : 44,
                            rotation: 0,
                            font: name === "code" ? "mono" : "instrument",
                            fontSize: name === "holder" ? 50 : 34,
                            bold: name === "holder",
                            fill: textOn(doc.background.color),
                          })
                        }
                      >
                        <UserCircle size={20} aria-hidden />
                        <span>
                          <strong>{s.fields[name]}</strong>
                          <small>{samples[name]}</small>
                        </span>
                      </button>
                    ))}
                    <button
                      type="button"
                      className={styles.fieldButton}
                      disabled={doc.elements.some((element) => element.type === "qr")}
                      onClick={() => add({ id: newId(), type: "qr", x: 0, y: 0, width: 260, height: 260, rotation: 0 })}
                    >
                      <QrCode size={20} aria-hidden />
                      <span>
                        <strong>{s.qr}</strong>
                        <small>{format(s.qrHint, { min: String(qrMinSize(doc)) })}</small>
                      </span>
                    </button>
                  </div>
                </>
              )}

              {activePanel === "background" && (
                <>
                  <h2 className={styles.panelTitle}>{s.panels.background}</h2>
                  <ColorPicker
                    label={s.backgroundColor}
                    value={doc.background.color}
                    onChange={(color) => color && change({ ...doc, background: { ...doc.background, color } }, "background:color")}
                  />
                  <p className={styles.label}>{s.backgroundImage}</p>
                  <PhotoGrid
                    names={library}
                    images={images}
                    label={s.setBackground}
                    selected={doc.background.image}
                    onPick={(name) => change({ ...doc, background: { ...doc.background, image: name } })}
                  />
                  {doc.background.image && (
                    <button type="button" className={buttonClass("secondary", styles.full)} onClick={() => change({ ...doc, background: { ...doc.background, image: null } })}>
                      <X size={18} aria-hidden /> {s.removeBackground}
                    </button>
                  )}
                </>
              )}
            </section>
          </>
        )}

        <div ref={areaRef} className={styles.area}>
          {fonts ? (
            <div className={styles.page} style={{ width: doc.width * scale, height: doc.height * scale }}>
              <CanvasStage
                ref={stageRef}
                doc={doc}
                scale={scale}
                fonts={fonts}
                images={images}
                samples={samples}
                photoLabel={s.photoPlaceholder}
                selectedId={selectedId}
                interactive={canManage}
                onSelect={setSelectedId}
                onChange={(element) => updateElement(element)}
                onEditText={editText}
              />
            </div>
          ) : (
            <p className={styles.hint}>{t.common.loading}</p>
          )}
          <p className={styles.formatNote}>
            {format(s.formatNote, { width: String(doc.width), height: String(doc.height) })}
            {problem && <strong className={styles.problem}> {s.problems[problem]}</strong>}
          </p>
        </div>

        {canManage && (
          <aside className={styles.inspector} aria-label={s.inspectorLabel}>
            {selected ? (
              <Inspector
                element={selected}
                doc={doc}
                textAreaRef={textAreaRef}
                patch={patch}
                onDuplicate={() => duplicate(selected.id)}
                onRemove={() => remove(selected.id)}
                onMove={(by) => move(selected.id, by)}
                onReplacePhoto={() => setPanel("photos")}
              />
            ) : (
              <p className={styles.hint}>{s.selectHint}</p>
            )}
            <h2 className={styles.panelTitle}>{s.layers}</h2>
            <ol className={styles.layers}>
              {[...doc.elements].reverse().map((element, index) => (
                <li key={element.id} className={element.id === selectedId ? styles.layerOn : styles.layer}>
                  <button type="button" className={styles.layerName} onClick={() => setSelectedId(element.id)}>
                    <LayerIcon element={element} />
                    <span>{layerLabel(element, s)}</span>
                  </button>
                  <IconButton label={s.forward} onClick={() => move(element.id, 1)} disabled={index === 0}>
                    <ArrowUp size={16} />
                  </IconButton>
                  <IconButton label={s.backward} onClick={() => move(element.id, -1)} disabled={index === doc.elements.length - 1}>
                    <ArrowDown size={16} />
                  </IconButton>
                </li>
              ))}
            </ol>
          </aside>
        )}
      </div>

      {preview && (
        <div className={styles.overlay} role="dialog" aria-modal="true" aria-label={s.previewTitle}>
          <div className={styles.dialog}>
            <div className={styles.dialogHead}>
              <h2 className={styles.panelTitle}>{s.previewTitle}</h2>
              <IconButton
                label={s.close}
                onClick={() => {
                  URL.revokeObjectURL(preview);
                  setPreview(null);
                }}
              >
                <X size={20} />
              </IconButton>
            </div>
            <p className={styles.hint}>{dirty ? s.previewStale : s.previewHint}</p>
            {/* eslint-disable-next-line @next/next/no-img-element -- image calculée par le serveur */}
            <img className={styles.previewImage} src={preview} alt={s.previewTitle} />
            <a className={buttonClass("secondary", styles.full)} href={preview} download={`${slugify(event.title)}-billet.jpg`}>
              <DownloadSimple size={18} aria-hidden /> {s.download}
            </a>
          </div>
        </div>
      )}
    </div>
  );
}

type InspectorProps = {
  element: CanvasElement;
  doc: CanvasDoc;
  textAreaRef: React.RefObject<HTMLTextAreaElement | null>;
  patch: (changes: Partial<CanvasElement>, key: string) => void;
  onDuplicate: () => void;
  onRemove: () => void;
  onMove: (by: number) => void;
  onReplacePhoto: () => void;
};

/** Réglages du calque choisi. */
function Inspector({ element, doc, textAreaRef, patch, onDuplicate, onRemove, onMove, onReplacePhoto }: InspectorProps) {
  const { t } = useI18n();
  const s = t.studio;
  const number = (key: "x" | "y" | "width" | "height" | "rotation", label: string) => (
    <label className={styles.numberField}>
      <span>{label}</span>
      <input
        type="number"
        value={Math.round(element[key])}
        onChange={(e) => {
          const value = Number(e.target.value);
          if (Number.isFinite(value)) patch(key === "width" && element.type === "qr" ? { width: value, height: value } : { [key]: value }, key);
        }}
      />
    </label>
  );

  return (
    <div className={styles.inspectorBody}>
      <h2 className={styles.panelTitle}>{layerLabel(element, s)}</h2>

      {element.type === "text" && (
        <label className={styles.textField}>
          <span className={styles.label}>{s.textContent}</span>
          <textarea ref={textAreaRef} value={element.text} maxLength={500} rows={3} onChange={(e) => patch({ text: e.target.value }, "text")} />
        </label>
      )}
      {element.type === "field" && <p className={styles.hint}>{s.fieldNote}</p>}
      {element.type === "qr" && <p className={styles.hint}>{format(s.qrNote, { min: String(qrMinSize(doc)) })}</p>}
      {element.type === "image" && (
        <>
          <button type="button" className={buttonClass("secondary", styles.full)} onClick={onReplacePhoto}>
            <ImageSquare size={18} aria-hidden /> {element.asset ? s.replacePhoto : s.choosePhoto}
          </button>
          <div className={styles.toggles} role="group" aria-label={s.fit}>
            {(["cover", "contain"] as const).map((fit) => (
              <button key={fit} type="button" className={styles.chipSoft} aria-pressed={(element.fit ?? "cover") === fit} onClick={() => patch({ fit }, "fit")}>
                {s.fits[fit]}
              </button>
            ))}
          </div>
        </>
      )}
      {element.type === "icon" && <ColorPicker label={s.fill} value={element.fill} onChange={(fill) => fill && patch({ fill }, "fill")} />}

      {isTextual(element) && (
        <>
          <label className={styles.selectField}>
            <span className={styles.label}>{s.font}</span>
            <select value={element.font} onChange={(e) => patch({ font: e.target.value as CanvasFont }, "font")}>
              {CANVAS_FONTS.map((font) => (
                <option key={font} value={font}>
                  {s.fonts[font]}
                </option>
              ))}
            </select>
          </label>
          <div className={styles.row}>
            <label className={styles.numberField}>
              <span>{s.fontSize}</span>
              <input type="number" min={6} max={600} value={Math.round(element.fontSize)} onChange={(e) => patch({ fontSize: Number(e.target.value) || 6 }, "fontSize")} />
            </label>
            <div className={styles.toggles} role="group" aria-label={s.style}>
              <IconButton label={s.bold} pressed={!!element.bold} onClick={() => patch({ bold: !element.bold }, "bold")}>
                <TextB size={18} />
              </IconButton>
              {element.type === "text" && (
                <IconButton label={s.italic} pressed={!!element.italic} onClick={() => patch({ italic: !element.italic }, "italic")}>
                  <TextItalic size={18} />
                </IconButton>
              )}
            </div>
          </div>
          <div className={styles.toggles} role="group" aria-label={s.align}>
            {(
              [
                ["left", <TextAlignLeft key="l" size={18} />],
                ["center", <TextAlignCenter key="c" size={18} />],
                ["right", <TextAlignRight key="r" size={18} />],
              ] as const
            ).map(([align, icon]) => (
              <IconButton key={align} label={s.aligns[align]} pressed={(element.align ?? "left") === align} onClick={() => patch({ align }, "align")}>
                {icon}
              </IconButton>
            ))}
          </div>
          <ColorPicker label={s.textColor} value={element.fill} onChange={(fill) => patch({ fill }, "fill")} />
          {/* Champs : écrits par le serveur, sans italique ni espacement des lettres. */}
          {element.type === "text" && (
            <Slider label={s.letterSpacing} min={-5} max={30} step={0.5} value={element.letterSpacing ?? 0} onChange={(letterSpacing) => patch({ letterSpacing }, "letterSpacing")} />
          )}
          {element.type === "text" && (
            <Slider label={s.lineHeight} min={0.7} max={2.5} step={0.05} value={element.lineHeight ?? 1.2} onChange={(lineHeight) => patch({ lineHeight }, "lineHeight")} />
          )}
        </>
      )}

      {(element.type === "rect" || element.type === "ellipse" || element.type === "star") && (
        <>
          <ColorPicker label={s.fill} value={element.fill ?? null} allowNone onChange={(fill) => patch({ fill }, "fill")} />
          <ColorPicker label={s.fill2} value={element.fill2 ?? null} allowNone onChange={(fill2) => patch({ fill2 }, "fill2")} />
        </>
      )}
      {(element.type === "rect" || element.type === "ellipse" || element.type === "star" || element.type === "line") && (
        <>
          <ColorPicker label={element.type === "line" ? s.lineColor : s.stroke} value={element.stroke ?? null} allowNone={element.type !== "line"} onChange={(stroke) => patch({ stroke }, "stroke")} />
          <Slider label={s.strokeWidth} min={0} max={60} step={1} value={element.strokeWidth ?? 0} onChange={(strokeWidth) => patch({ strokeWidth }, "strokeWidth")} />
        </>
      )}
      {(element.type === "rect" || element.type === "image") && (
        <Slider
          label={s.cornerRadius}
          min={0}
          max={Math.round(Math.min(element.width, element.height) / 2)}
          step={1}
          value={element.cornerRadius ?? 0}
          onChange={(cornerRadius) => patch({ cornerRadius }, "cornerRadius")}
        />
      )}
      {element.type !== "qr" && (
        <Slider label={s.opacity} min={0.05} max={1} step={0.05} value={element.opacity ?? 1} onChange={(opacity) => patch({ opacity }, "opacity")} />
      )}

      <details className={styles.position}>
        <summary>{s.position}</summary>
        <div className={styles.row}>
          {number("x", "X")}
          {number("y", "Y")}
        </div>
        <div className={styles.row}>
          {number("width", s.width)}
          {element.type !== "qr" && number("height", s.height)}
        </div>
        {element.type !== "qr" && number("rotation", s.rotation)}
      </details>

      <div className={styles.actions}>
        {element.type !== "qr" && (
          <IconButton label={s.duplicate} onClick={onDuplicate}>
            <Copy size={18} />
          </IconButton>
        )}
        <IconButton label={s.toFront} onClick={() => onMove(Infinity)}>
          <ArrowUp size={18} weight="bold" />
        </IconButton>
        <IconButton label={s.toBack} onClick={() => onMove(-Infinity)}>
          <ArrowDown size={18} weight="bold" />
        </IconButton>
        <IconButton label={s.remove} onClick={onRemove} danger>
          <Trash size={18} />
        </IconButton>
      </div>
    </div>
  );
}

const TemplateThumb = memo(function TemplateThumb({
  id,
  usage,
  format,
  context,
  fonts,
  samples,
  label,
  onPick,
}: {
  id: TemplateId;
  usage: Usage;
  format: FormatId;
  context: TemplateContext;
  fonts: Record<CanvasFont, string> | null;
  samples: Record<FieldName, string>;
  label: string;
  onPick: () => void;
}) {
  const ref = useRef<HTMLButtonElement>(null);
  const visible = useVisible(ref);
  const doc = useMemo(() => {
    const built = buildTemplate(id, usage, format, context);
    return fonts ? { ...built, elements: built.elements.map((element) => (element.type === "text" ? fitText(element, fonts) : element)) } : built;
  }, [id, usage, format, context, fonts]);
  const scale = (format === "landscape" ? 216 : 104) / doc.width;
  return (
    <button ref={ref} type="button" className={styles.template} onClick={onPick}>
      <span className={styles.thumb} style={{ width: doc.width * scale, height: doc.height * scale, background: doc.background.color }}>
        {fonts && visible && <CanvasStage doc={doc} scale={scale} fonts={fonts} images={{}} samples={samples} photoLabel={context.photoLabel} />}
      </span>
      <span className={styles.templateName}>{label}</span>
    </button>
  );
});

function PhotoGrid({
  names,
  images,
  label,
  selected,
  onPick,
}: {
  names: string[];
  images: Record<string, HTMLImageElement | undefined>;
  label: string;
  selected?: string | null;
  onPick: (name: string) => void;
}) {
  const { t } = useI18n();
  if (names.length === 0) return <p className={styles.hint}>{t.studio.noPhotos}</p>;
  return (
    <ul className={styles.photos}>
      {names.map((name) => (
        <li key={name}>
          <button
            type="button"
            className={styles.photo}
            aria-pressed={selected === undefined ? undefined : selected === name}
            aria-label={label}
            onClick={() => onPick(name)}
          >
            {images[name] && (
              // eslint-disable-next-line @next/next/no-img-element -- image locale déjà chargée
              <img src={images[name]!.src} alt="" />
            )}
          </button>
        </li>
      ))}
    </ul>
  );
}

function ColorPicker({
  label,
  value,
  onChange,
  allowNone = false,
}: {
  label: string;
  value: string | null;
  onChange: (color: string | null) => void;
  allowNone?: boolean;
}) {
  const { t } = useI18n();
  return (
    <fieldset className={styles.colors}>
      <legend className={styles.label}>{label}</legend>
      <div className={styles.swatches}>
        {allowNone && (
          <button type="button" className={`${styles.swatch} ${styles.none}`} aria-pressed={value === null} aria-label={t.studio.noColor} onClick={() => onChange(null)} />
        )}
        {SWATCHES.map((color) => (
          <button
            key={color}
            type="button"
            className={styles.swatch}
            style={{ background: color }}
            aria-pressed={value?.toUpperCase() === color}
            aria-label={format(t.design.swatch, { color })}
            onClick={() => onChange(color)}
          />
        ))}
        <label className={styles.customColor}>
          <span className={styles.srOnly}>{t.design.customColor}</span>
          <input type="color" value={value ?? "#FFFFFF"} onChange={(e) => onChange(e.target.value.toUpperCase())} />
        </label>
      </div>
    </fieldset>
  );
}

function Slider({ label, value, onChange, min, max, step }: { label: string; value: number; onChange: (value: number) => void; min: number; max: number; step: number }) {
  return (
    <label className={styles.slider}>
      <span className={styles.label}>
        {label} <output>{Math.round(value * 100) / 100}</output>
      </span>
      <input type="range" min={min} max={max} step={step} value={value} onChange={(e) => onChange(Number(e.target.value))} />
    </label>
  );
}

function IconButton({
  label,
  onClick,
  disabled,
  pressed,
  danger,
  children,
}: {
  label: string;
  onClick: () => void;
  disabled?: boolean;
  pressed?: boolean;
  danger?: boolean;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      className={danger ? styles.iconDanger : styles.icon}
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      title={label}
      aria-pressed={pressed}
    >
      {children}
    </button>
  );
}

function LayerIcon({ element }: { element: CanvasElement }) {
  const size = 16;
  switch (element.type) {
    case "text":
      return <TextAa size={size} aria-hidden />;
    case "field":
      return <UserCircle size={size} aria-hidden />;
    case "image":
      return <ImageSquare size={size} aria-hidden />;
    case "icon":
      return <Sparkle size={size} aria-hidden />;
    case "qr":
      return <QrCode size={size} aria-hidden />;
    case "ellipse":
      return <Circle size={size} aria-hidden />;
    case "star":
      return <StarIcon size={size} aria-hidden />;
    case "line":
      return <LineSegment size={size} aria-hidden />;
    default:
      return <Square size={size} aria-hidden />;
  }
}

/** Vrai dès que l'élément approche de l'écran : les vignettes ne se dessinent qu'à ce moment. */
function useVisible(ref: React.RefObject<HTMLElement | null>): boolean {
  const [visible, setVisible] = useState(false);
  useEffect(() => {
    const node = ref.current;
    if (!node || visible) return;
    const observer = new IntersectionObserver(([entry]) => entry.isIntersecting && setVisible(true), { rootMargin: "300px" });
    observer.observe(node);
    return () => observer.disconnect();
  }, [ref, visible]);
  return visible;
}

/** Aperçu d'un pictogramme du studio, en SVG. */
function IconGlyph({ name }: { name: IconName }) {
  const filled = FILLED_ICONS.has(name);
  return (
    <svg viewBox="0 0 24 24" width={28} height={28} aria-hidden fill={filled ? "currentColor" : "none"} stroke={filled ? "none" : "currentColor"} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
      <path d={ICON_PATHS[name]} />
    </svg>
  );
}

function layerLabel(element: CanvasElement, s: ReturnType<typeof useI18n>["t"]["studio"]): string {
  if (element.type === "text") return element.text.trim().slice(0, 28) || s.types.text;
  if (element.type === "field") return s.fields[element.field];
  if (element.type === "image") return element.name || s.types.image;
  if (element.type === "icon") return s.icons[element.icon];
  return s.types[element.type];
}


function usedAssets(docs: Record<Usage, CanvasDoc>): string[] {
  return Object.values(docs).flatMap((doc) => [
    ...(doc.background.image ? [doc.background.image] : []),
    ...doc.elements.flatMap((element) => (element.type === "image" && element.asset ? [element.asset] : [])),
  ]);
}

/** Image décodée depuis un fichier (URL blob locale : le canvas reste exportable). */
async function decode(file: Blob): Promise<HTMLImageElement> {
  const image = new Image();
  image.src = URL.createObjectURL(file);
  await image.decode();
  return image;
}

function slugify(value: string): string {
  return (
    value
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-|-$/g, "") || "kassevent"
  );
}

