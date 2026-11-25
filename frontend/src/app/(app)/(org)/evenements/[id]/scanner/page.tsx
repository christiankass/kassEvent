"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import { ArrowLeft, Camera, CheckCircle, CloudArrowDown, Prohibit, Question, Warning, WarningOctagon, WifiHigh, WifiSlash } from "@phosphor-icons/react";
import { Alert, Button } from "@/components/ui";
import { ApiError } from "@/lib/api";
import {
  admits,
  decideOffline,
  downloadList,
  fingerprint,
  isOutage,
  loadDesk,
  normalizeCode,
  saveDesk,
  sendScans,
  type Conflict,
  type Desk,
  type FirstScan,
  type PendingScan,
  type Verdict,
} from "@/lib/check-in";
import { formErrorMessage } from "@/lib/errors";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { useUser } from "@/lib/user-context";
import app from "../../../../app.module.css";
import styles from "./scanner.module.css";
import { startCamera, type Camera as CameraHandle } from "./camera";

/** Au-delà, on décide avec la liste du téléphone : le résultat reste sous les 2 secondes. */
const ONLINE_TIMEOUT_MS = 1500;
const SYNC_EVERY_MS = 15_000;
const REFRESH_EVERY_MS = 120_000;
const VALID_DISPLAY_MS = 2500;
const SAME_CODE_PAUSE_MS = 3000;
const GATE_KEY = "kassevent.gate";

type CameraState = "idle" | "starting" | "on" | "denied" | "missing";

const EMPTY: Desk = { list: null, local: {}, pending: [] };

export default function ScannerPage() {
  const { t, locale } = useI18n();
  const { id: eventId } = useParams<{ id: string }>();
  const { organization } = useOrganization();
  const user = useUser();
  const organizationId = organization.id;

  const deskRef = useRef<Desk>(EMPTY);
  const [desk, setDesk] = useState<Desk>(EMPTY);
  const [ready, setReady] = useState(false);
  const [online, setOnline] = useState(true);
  const [gate, setGate] = useState("");
  const [verdict, setVerdict] = useState<Verdict | null>(null);
  const [conflicts, setConflicts] = useState<Conflict[]>([]);
  const [camera, setCamera] = useState<CameraState>("idle");
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [manual, setManual] = useState("");

  const videoRef = useRef<HTMLVideoElement>(null);
  const cameraRef = useRef<CameraHandle | null>(null);
  const busyRef = useRef(false); // un scan en cours ou un résultat affiché : la caméra ne lit rien
  const lastRef = useRef<{ code: string; at: number }>({ code: "", at: 0 });
  const syncingRef = useRef(false);
  const gateRef = useRef("");

  /** Toute modification passe par ici : affichée, puis gardée sur le téléphone. */
  const commit = useCallback(
    (change: (desk: Desk) => Desk) => {
      deskRef.current = change(deskRef.current);
      setDesk(deskRef.current);
      void saveDesk(organizationId, eventId, deskRef.current);
    },
    [organizationId, eventId],
  );

  const refreshList = useCallback(async () => {
    setDownloading(true);
    try {
      const list = await downloadList(organizationId, eventId);
      commit((d) => {
        // Les entrées déjà connues du serveur n'ont plus besoin de la mémoire locale.
        const known = new Set(list.tickets.filter((ticket) => ticket.first_scan).map((ticket) => ticket.hash));
        const local = Object.fromEntries(Object.entries(d.local).filter(([hash]) => !known.has(hash)));
        return { ...d, list, local };
      });
      setError(null);
    } catch (e) {
      if (!isOutage(e)) setError(e);
    }
    setDownloading(false);
  }, [organizationId, eventId, commit]);

  const sync = useCallback(async () => {
    if (syncingRef.current || !navigator.onLine) return;
    const batch = deskRef.current.pending.slice(0, 200);
    if (batch.length === 0) return;
    syncingRef.current = true;
    try {
      const { data } = await sendScans(organizationId, eventId, batch);
      const found: Conflict[] = [];
      for (const result of data) {
        const kind = result.admitted ? (result.result === "already_used" ? "double" : null) : result.result === "valid" ? "refused" : null;
        if (kind) found.push({ id: result.id, kind, ticket: result.ticket, first_scan: result.first_scan });
      }
      const done = new Set(data.map((result) => result.id));
      commit((d) => ({ ...d, pending: d.pending.filter((scan) => !done.has(scan.id)) }));
      if (found.length > 0) {
        setConflicts((current) => [...current, ...found]);
        navigator.vibrate?.([300, 100, 300, 100, 300]);
      }
      syncingRef.current = false;
      await refreshList();
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        // Lot refusé tel quel : il ne passera jamais, on ne bloque pas les suivants.
        const sent = new Set(batch.map((scan) => scan.id));
        commit((d) => ({ ...d, pending: d.pending.filter((scan) => !sent.has(scan.id)) }));
      }
      // Panne de réseau : on réessaiera. Session expirée, accès retiré : l'agent doit le savoir.
      if (!isOutage(e)) setError(e);
    }
    syncingRef.current = false;
  }, [organizationId, eventId, commit, refreshList]);

  // Chargement : ce que le téléphone a gardé, puis la liste à jour si le réseau le permet.
  useEffect(() => {
    let cancelled = false;
    loadDesk(organizationId, eventId).then((saved) => {
      if (cancelled) return;
      try {
        gateRef.current = localStorage.getItem(GATE_KEY) ?? "";
        setGate(gateRef.current);
      } catch {
        // Stockage indisponible : porte à ressaisir.
      }
      setOnline(navigator.onLine);
      deskRef.current = saved;
      setDesk(saved);
      setReady(true);
      if (navigator.onLine) void sync().then(() => (saved.pending.length === 0 ? refreshList() : undefined));
    });
    return () => {
      cancelled = true;
    };
  }, [organizationId, eventId, sync, refreshList]);

  // Retour du réseau : synchronisation immédiate, puis régulière.
  useEffect(() => {
    const up = () => {
      setOnline(true);
      void sync();
    };
    const down = () => setOnline(false);
    window.addEventListener("online", up);
    window.addEventListener("offline", down);
    const syncTimer = window.setInterval(() => void sync(), SYNC_EVERY_MS);
    const refreshTimer = window.setInterval(() => {
      if (navigator.onLine && deskRef.current.list && deskRef.current.pending.length === 0) void refreshList();
    }, REFRESH_EVERY_MS);
    return () => {
      window.removeEventListener("online", up);
      window.removeEventListener("offline", down);
      window.clearInterval(syncTimer);
      window.clearInterval(refreshTimer);
    };
  }, [sync, refreshList]);

  useEffect(() => () => cameraRef.current?.stop(), []);

  const dismiss = useCallback(() => {
    setVerdict(null);
    busyRef.current = false;
  }, []);

  // Un billet valide laisse la place au suivant tout seul ; les refus attendent un geste de l'agent.
  useEffect(() => {
    if (verdict?.result !== "valid") return;
    const timer = window.setTimeout(dismiss, VALID_DISPLAY_MS);
    return () => window.clearTimeout(timer);
  }, [verdict, dismiss]);

  const check = useCallback(
    async (raw: string) => {
      const code = normalizeCode(raw);
      const now = Date.now();
      if (busyRef.current) return;
      if (code === lastRef.current.code && now - lastRef.current.at < SAME_CODE_PAUSE_MS) return;
      busyRef.current = true;
      lastRef.current = { code, at: now };

      const id = crypto.randomUUID();
      const scan = { id, code: raw.slice(0, 512), scanned_at: new Date(now).toISOString(), gate: gateRef.current.trim() || null };
      const list = deskRef.current.list;
      const hash = code && list ? await fingerprint(code, list.fingerprint) : "";
      const mine: FirstScan = { scanned_at: scan.scanned_at, gate: scan.gate, agent: user.name };

      let shown: Verdict | null = null;
      if (code && navigator.onLine) {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), ONLINE_TIMEOUT_MS);
        try {
          const { data } = await sendScans(organizationId, eventId, [{ ...scan, offline: false, admitted: true }], controller.signal);
          shown = { ...data[0], offline: false };
          if (shown.result === "valid" && hash) commit((d) => ({ ...d, local: { ...d.local, [hash]: mine } }));
        } catch (e) {
          // Réseau trop lent ou coupé : la liste du téléphone décide, le scan partira plus tard.
          if (!isOutage(e)) {
            window.clearTimeout(timer);
            setError(e);
            busyRef.current = false;
            return;
          }
        }
        window.clearTimeout(timer);
      }

      if (!shown) {
        shown = code ? decideOffline(deskRef.current, hash, id) : { id, result: "invalid", ticket: null, first_scan: null, other_event: null, offline: true };
        const verdict = shown;
        if (code) {
          const pending: PendingScan = { ...scan, offline: true, admitted: verdict.result === "valid" };
          commit((d) => ({
            ...d,
            pending: [...d.pending, pending],
            local: verdict.result === "valid" ? { ...d.local, [hash]: mine } : d.local,
          }));
        }
      }

      navigator.vibrate?.(shown.result === "valid" ? 80 : [200, 80, 200]);
      setVerdict(shown);
    },
    [organizationId, eventId, commit, user.name],
  );

  async function openCamera() {
    if (!videoRef.current) return;
    setCamera("starting");
    try {
      cameraRef.current = await startCamera(videoRef.current, (text) => void check(text), () => busyRef.current);
      setCamera("on");
    } catch (e) {
      setCamera(e instanceof DOMException && e.name === "NotAllowedError" ? "denied" : "missing");
    }
  }

  const zone = desk.list?.event.timezone;
  const clock = new Intl.DateTimeFormat(locale, { hour: "2-digit", minute: "2-digit", timeZone: zone });
  const describeFirst = (first: FirstScan) =>
    [
      format(t.scan.firstScan, { time: clock.format(new Date(first.scanned_at)) }),
      first.gate ? format(t.scan.atGate, { gate: first.gate }) : null,
      first.agent ? format(t.scan.byAgent, { agent: first.agent }) : null,
    ]
      .filter(Boolean)
      .join(" · ");

  const known = new Set(desk.list?.tickets.filter((ticket) => ticket.first_scan).map((ticket) => ticket.hash));
  const entered = (desk.list?.checked_in ?? 0) + Object.keys(desk.local).filter((hash) => !known.has(hash)).length;

  return (
    <main className={`${app.main} ${styles.main}`}>
      <Link href={`/evenements/${eventId}`} className={styles.back}>
        <ArrowLeft size={18} aria-hidden /> {t.scan.back}
      </Link>
      <div className={styles.titleRow}>
        <div>
          <p className={styles.eyebrow}>{t.scan.title}</p>
          <h1 className={styles.title}>{desk.list?.event.title ?? t.scan.title}</h1>
        </div>
        <span className={styles.network} data-online={online}>
          {online ? <WifiHigh size={16} aria-hidden /> : <WifiSlash size={16} aria-hidden />}
          {online ? t.scan.online : t.scan.offline}
        </span>
      </div>

      {error ? <Alert tone="error">{formErrorMessage(error, t)}</Alert> : null}

      {conflicts.map((conflict) => (
        <div key={conflict.id} className={styles.conflict} data-kind={conflict.kind} role="alert">
          <WarningOctagon size={28} weight="fill" aria-hidden />
          <div>
            <strong>{conflict.kind === "double" ? t.scan.conflictTitle : t.scan.refusedTitle}</strong>
            <p>
              {format(conflict.kind === "double" ? t.scan.conflictBody : t.scan.refusedBody, {
                name: conflict.ticket?.holder_name ?? "—",
                first: conflict.first_scan ? describeFirst(conflict.first_scan) : "",
              })}
            </p>
          </div>
          <Button type="button" variant="secondary" onClick={() => setConflicts((all) => all.filter((c) => c.id !== conflict.id))}>
            {t.scan.dismiss}
          </Button>
        </div>
      ))}

      <section className={styles.viewer} aria-label={t.scan.cameraHint}>
        <video ref={videoRef} className={styles.video} muted playsInline data-on={camera === "on"} />
        {camera === "on" ? (
          <>
            <div className={styles.frame} aria-hidden />
            <p className={styles.viewerHint}>{t.scan.cameraHint}</p>
          </>
        ) : (
          <div className={styles.cameraOff}>
            <Camera size={40} aria-hidden />
            {camera === "denied" || camera === "missing" ? (
              <p>{camera === "denied" ? t.scan.cameraDenied : t.scan.cameraMissing}</p>
            ) : (
              <Button type="button" onClick={openCamera} disabled={camera === "starting" || !ready}>
                {t.scan.startCamera}
              </Button>
            )}
          </div>
        )}
      </section>

      <form
        className={styles.manual}
        onSubmit={(e) => {
          e.preventDefault();
          if (!manual.trim()) return;
          lastRef.current = { code: "", at: 0 }; // une saisie volontaire n'est jamais ignorée
          void check(manual).then(() => setManual(""));
        }}
      >
        <label className={styles.manualField}>
          <span>{t.scan.manual}</span>
          <input
            className={styles.input}
            value={manual}
            onChange={(e) => setManual(e.target.value)}
            placeholder={t.scan.manualPlaceholder}
            autoCapitalize="characters"
            autoComplete="off"
            spellCheck={false}
          />
        </label>
        <Button type="submit" variant="secondary">
          {t.scan.check}
        </Button>
      </form>

      <dl className={styles.stats}>
        <div>
          <dt>{t.scan.entered}</dt>
          <dd>
            {entered}
            {desk.list ? <small> / {desk.list.tickets.length}</small> : null}
          </dd>
        </div>
        <div>
          <dt>{t.scan.gate}</dt>
          <dd>
            <input
              className={styles.gateInput}
              value={gate}
              maxLength={40}
              placeholder={t.scan.gatePlaceholder}
              aria-label={t.scan.gate}
              onChange={(e) => {
                setGate(e.target.value);
                gateRef.current = e.target.value;
                try {
                  localStorage.setItem(GATE_KEY, e.target.value);
                } catch {
                  // Stockage indisponible : la porte vaut pour cette page seulement.
                }
              }}
            />
          </dd>
        </div>
      </dl>

      <section className={styles.listPanel}>
        <p className={styles.listStatus} data-pending={desk.pending.length > 0}>
          {desk.pending.length > 0 ? format(t.scan.pending, { count: String(desk.pending.length) }) : t.scan.synced}
        </p>
        <p className={styles.listInfo}>
          {desk.list
            ? format(t.scan.listReady, {
                count: String(desk.list.tickets.length),
                time: clock.format(new Date(desk.list.generated_at)),
              })
            : t.scan.listMissing}
        </p>
        <Button type="button" variant="secondary" onClick={() => void sync().then(refreshList)} disabled={downloading || !online}>
          <CloudArrowDown size={18} aria-hidden />
          {downloading ? t.scan.downloading : desk.list ? t.scan.refresh : t.scan.download}
        </Button>
      </section>

      {verdict && <ResultScreen verdict={verdict} describeFirst={describeFirst} onDismiss={dismiss} />}
    </main>
  );
}

const ICONS = { valid: CheckCircle, already_used: Warning, invalid: Prohibit, other_event: Prohibit, unverified: Question };

/** Plein écran coloré : l'agent lit le résultat d'un coup d'œil, à un mètre. */
function ResultScreen({ verdict, describeFirst, onDismiss }: { verdict: Verdict; describeFirst: (first: FirstScan) => string; onDismiss: () => void }) {
  const { t } = useI18n();
  const Icon = ICONS[verdict.result];
  const people = admits(verdict.ticket);
  const closeRef = useRef<HTMLButtonElement>(null);

  useEffect(() => closeRef.current?.focus(), []);

  return (
    <div className={styles.result} data-result={verdict.result} role="alertdialog" aria-modal="true" aria-labelledby="scan-result" onClick={onDismiss}>
      <div className={styles.resultBody}>
        <Icon size={96} weight="fill" aria-hidden />
        <h2 id="scan-result" className={styles.resultTitle}>
          {t.scan.result[verdict.result]}
        </h2>
        {verdict.ticket && <p className={styles.holder}>{verdict.ticket.holder_name}</p>}
        {verdict.ticket?.ticket_type && <p className={styles.resultLine}>{verdict.ticket.ticket_type}</p>}
        {verdict.ticket && verdict.ticket.companions > 0 && verdict.result === "valid" && (
          <p className={styles.admits}>
            <strong>{format(t.scan.admits, { count: String(people) })}</strong>
            <span>{format(t.scan.companions, { count: String(verdict.ticket.companions) })}</span>
          </p>
        )}
        <p className={styles.resultLine}>{format(t.scan.resultHint[verdict.result], { title: verdict.other_event ?? "" })}</p>
        {verdict.first_scan && <p className={styles.firstScan}>{describeFirst(verdict.first_scan)}</p>}
        {verdict.offline && verdict.result !== "unverified" && <p className={styles.offlineTag}>{t.scan.checkedOffline}</p>}
      </div>
      <button ref={closeRef} type="button" className={styles.next} onClick={onDismiss}>
        {verdict.result === "valid" ? t.scan.tapToContinue : t.scan.next}
      </button>
    </div>
  );
}
