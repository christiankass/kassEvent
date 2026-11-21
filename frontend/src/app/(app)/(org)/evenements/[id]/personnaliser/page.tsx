"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useState } from "react";
import { ArrowLeft, Check, EnvelopeSimple, Ticket, Trash } from "@phosphor-icons/react";
import { TicketCard } from "@/components/TicketCard";
import { Alert, Button, Field, TextArea, buttonClass } from "@/components/ui";
import { api } from "@/lib/api";
import {
  ACCENT_SWATCHES,
  FONT_STACKS,
  FONTS,
  HEX,
  PRIMARY_SWATCHES,
  TEMPLATES,
  previewDesign,
  settingsOf,
  type BannerSource,
  type DesignKind,
  type DesignResponse,
  type DesignSettings,
} from "@/lib/designs";
import { actionErrorMessage, formErrorMessage } from "@/lib/errors";
import { formatEventDate, type EventData } from "@/lib/events";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import { ticketFontVariables } from "@/lib/ticket-fonts";
import { useUser } from "@/lib/user-context";
import { PageSkeleton } from "../../../../PageSkeleton";
import app from "../../../../app.module.css";
import styles from "./atelier.module.css";

type Notice = { tone: "success" | "error"; text: string };
type Image = "logo" | "banner";

/**
 * Atelier de l'organisateur : apparence des billets ou invitations d'un événement, avec un aperçu
 * en direct du billet et l'e-mail tel qu'il partira.
 */
export default function DesignStudioPage() {
  const { t, locale } = useI18n();
  const user = useUser();
  const { id } = useParams<{ id: string }>();
  const { organization, permissions } = useOrganization();
  const organizationId = organization.id;
  const canManage = permissions.includes("events.manage");

  const [event, setEvent] = useState<EventData | null>(null);
  const [saved, setSaved] = useState<DesignResponse | null>(null);
  const [draft, setDraft] = useState<DesignSettings | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [view, setView] = useState<"ticket" | "email">("ticket");
  const [mobileTab, setMobileTab] = useState<"edit" | "preview">("edit");
  const [email, setEmail] = useState<string | null>(null);
  const [emailFailed, setEmailFailed] = useState(false);

  useEffect(() => {
    Promise.all([
      api<{ data: EventData }>(`/events/${id}`, { organizationId }),
      api<DesignResponse>(`/events/${id}/design`, { organizationId }),
    ])
      .then(([eventResponse, designResponse]) => {
        setEvent(eventResponse.data);
        setSaved(designResponse);
        setDraft(settingsOf(designResponse.data));
      })
      .catch(setLoadError);
  }, [id, organizationId]);

  // E-mail d'aperçu : chargé à l'ouverture de l'onglet, puis de nouveau après chaque enregistrement (email remis à null).
  const loadEmail = useCallback(() => {
    api<{ data: { html: string } }>(`/events/${id}/design/preview`, { organizationId })
      .then(({ data }) => setEmail(data.html))
      .catch(() => setEmailFailed(true));
  }, [id, organizationId]);

  useEffect(() => {
    if (view === "email" && email === null && !emailFailed) loadEmail();
  }, [view, email, emailFailed, loadEmail]);

  const dirty = useMemo(
    () => !!saved && !!draft && JSON.stringify(normalize(settingsOf(saved.data))) !== JSON.stringify(normalize(draft)),
    [saved, draft],
  );

  if (loadError) {
    return (
      <main className={app.main}>
        <Alert tone="error">{formErrorMessage(loadError, t)}</Alert>
      </main>
    );
  }
  if (!event || !saved || !draft) return <PageSkeleton />;

  const design = previewDesign(saved.data, draft, t.design.headlines, event.cover_url);
  const set = <K extends keyof DesignSettings>(key: K, value: DesignSettings[K]) =>
    setDraft((current) => (current ? { ...current, [key]: value } : current));

  /** Nouvelle apparence enregistrée côté serveur ; l'e-mail d'aperçu est à recharger. */
  function applySaved(response: DesignResponse, keepDraft: Partial<DesignSettings> = {}) {
    setSaved(response);
    setDraft((current) => ({ ...(current ?? settingsOf(response.data)), ...keepDraft }));
    setEmail(null);
  }

  async function run(action: () => Promise<void>) {
    setNotice(null);
    setBusy(true);
    try {
      await action();
    } catch (e) {
      setNotice({ tone: "error", text: actionErrorMessage(e, t) });
    }
    setBusy(false);
  }

  const save = () =>
    run(async () => {
      setErrors({});
      const invalid = (["primary_color", "accent_color"] as const).filter((key) => !HEX.test(draft[key]));
      if (invalid.length > 0) {
        setErrors(Object.fromEntries(invalid.map((key) => [key, t.design.hexInvalid])));
        return;
      }
      try {
        const response = await api<DesignResponse>(`/events/${id}/design`, { method: "PUT", organizationId, body: normalize(draft) });
        setSaved(response);
        setDraft(settingsOf(response.data));
        setEmail(null);
        setNotice({ tone: "success", text: t.design.saved });
      } catch (e) {
        const fields = (e as { errors?: Record<string, string[]> }).errors ?? {};
        setErrors(Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])));
        throw e;
      }
    });

  const upload = (image: Image, file: File) =>
    run(async () => {
      const body = new FormData();
      body.append("image", file);
      const response = await api<DesignResponse>(`/events/${id}/design/${image}`, { method: "POST", organizationId, body });
      // Le serveur passe la bannière sur « image dédiée » ; les autres réglages en cours restent à enregistrer.
      applySaved(response, image === "banner" ? { banner: "custom" } : {});
      setNotice({ tone: "success", text: image === "logo" ? t.design.logoSaved : t.design.bannerSaved });
    });

  const remove = (image: Image) =>
    run(async () => {
      const response = await api<DesignResponse>(`/events/${id}/design/${image}`, { method: "DELETE", organizationId });
      applySaved(response, image === "banner" && draft.banner === "custom" ? { banner: "none" } : {});
    });

  const sendTest = () =>
    run(async () => {
      const { message } = await api<{ message: string }>(`/events/${id}/design/test`, { method: "POST", organizationId });
      setNotice({ tone: "success", text: message });
    });

  const chooseTemplate = (name: (typeof TEMPLATES)[number]) =>
    setDraft((current) => (current ? { ...current, template: name, ...saved.meta.templates[name] } : current));

  const sampleType = event.ticket_types[0]?.name ?? t.design.sampleTicket;

  return (
    <main className={`${app.main} ${styles.page}`}>
      <Link href={`/evenements/${event.id}`} className={styles.back}>
        <ArrowLeft size={18} aria-hidden /> {t.design.back}
      </Link>
      <h1 className={app.title}>{t.design.title}</h1>
      <p className={app.lede}>{format(t.design.lede, { title: event.title })}</p>
      {!canManage && <p className={app.lede}>{t.design.readOnly}</p>}

      <div className={app.notice} aria-live="polite">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      </div>

      <div className={styles.mobileTabs} role="tablist" aria-label={t.design.title}>
        {(["edit", "preview"] as const).map((tab) => (
          <button
            key={tab}
            type="button"
            role="tab"
            aria-selected={mobileTab === tab}
            className={styles.mobileTab}
            onClick={() => setMobileTab(tab)}
          >
            {tab === "edit" ? t.design.tabEdit : t.design.tabPreview}
          </button>
        ))}
      </div>

      <div className={styles.studio}>
        <form
          className={`${styles.controls} ${mobileTab === "edit" ? "" : styles.hiddenMobile}`}
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <fieldset className={styles.section} disabled={!canManage}>
            <legend className={styles.legend}>{t.design.templateTitle}</legend>
            <p className={styles.hint}>{t.design.templateHint}</p>
            <div className={styles.templates}>
              {TEMPLATES.map((name) => {
                const preset = saved.meta.templates[name];
                return (
                  <label key={name} className={styles.template}>
                    <input type="radio" name="template" checked={draft.template === name} onChange={() => chooseTemplate(name)} />
                    <span className={styles.templateSwatch} aria-hidden>
                      <span style={{ background: preset.primary_color }} />
                      <span style={{ background: preset.accent_color }} />
                    </span>
                    <span className={styles.templateName}>{t.design.templates[name]}</span>
                    <span className={styles.templateKind}>{t.design.kinds[preset.kind]}</span>
                  </label>
                );
              })}
            </div>
          </fieldset>

          <fieldset className={styles.section} disabled={!canManage}>
            <legend className={styles.legend}>{t.design.kindTitle}</legend>
            <div className={styles.kinds}>
              {(["ticket", "invitation"] as DesignKind[]).map((kind) => (
                <label key={kind} className={app.choice}>
                  <input type="radio" name="kind" checked={draft.kind === kind} onChange={() => set("kind", kind)} />
                  <span className={app.choiceTitle}>{t.design.kinds[kind]}</span>
                  <span className={app.choiceHint}>{t.design.kindHints[kind]}</span>
                </label>
              ))}
            </div>
          </fieldset>

          <fieldset className={styles.section} disabled={!canManage}>
            <legend className={styles.legend}>{t.design.colorsTitle}</legend>
            <p className={styles.hint}>{t.design.colorsHint}</p>
            <ColorPicker
              label={t.design.primaryColor}
              value={draft.primary_color}
              swatches={PRIMARY_SWATCHES}
              error={errors.primary_color}
              onChange={(value) => set("primary_color", value)}
            />
            <ColorPicker
              label={t.design.accentColor}
              value={draft.accent_color}
              swatches={ACCENT_SWATCHES}
              error={errors.accent_color}
              onChange={(value) => set("accent_color", value)}
            />
          </fieldset>

          <fieldset className={`${styles.section} ${ticketFontVariables}`} disabled={!canManage}>
            <legend className={styles.legend}>{t.design.fontTitle}</legend>
            <div className={styles.fonts}>
              {FONTS.map((font) => (
                <label key={font} className={styles.font}>
                  <input type="radio" name="font" checked={draft.font === font} onChange={() => set("font", font)} />
                  <span className={styles.fontSample} style={{ fontFamily: FONT_STACKS[font] }} aria-hidden>
                    Aa
                  </span>
                  <span>{t.design.fonts[font]}</span>
                </label>
              ))}
            </div>
          </fieldset>

          <fieldset className={styles.section} disabled={!canManage}>
            <legend className={styles.legend}>{t.design.textsTitle}</legend>
            <Field
              label={t.design.headline}
              hint={format(t.design.headlineHint, { default: t.design.headlines[draft.kind] })}
              value={draft.headline ?? ""}
              maxLength={60}
              error={errors.headline}
              onChange={(e) => set("headline", e.target.value)}
            />
            <TextArea
              label={t.design.message}
              hint={format(t.design.messageHint, { count: String((draft.message ?? "").length) })}
              value={draft.message ?? ""}
              maxLength={280}
              rows={3}
              error={errors.message}
              onChange={(e) => set("message", e.target.value)}
            />
            <TextArea
              label={t.design.footer}
              hint={format(t.design.footerHint, { count: String((draft.footer ?? "").length) })}
              value={draft.footer ?? ""}
              maxLength={200}
              rows={2}
              error={errors.footer}
              onChange={(e) => set("footer", e.target.value)}
            />
          </fieldset>

          <fieldset className={styles.section} disabled={!canManage}>
            <legend className={styles.legend}>{t.design.imagesTitle}</legend>
            <ImageControl
              label={t.design.logo}
              hint={t.design.logoHint}
              url={saved.data.logo_url}
              choose={saved.data.logo_url ? t.design.logoReplace : t.design.logoChoose}
              removeLabel={t.design.logoRemove}
              busy={busy || !canManage}
              onFile={(file) => upload("logo", file)}
              onRemove={() => remove("logo")}
            />

            <div className={styles.bannerChoice} role="radiogroup" aria-label={t.design.banner}>
              <span className={styles.subLabel}>{t.design.banner}</span>
              <div className={app.segmented}>
                {(["none", "cover", "custom"] as BannerSource[]).map((source) => {
                  const unavailable = (source === "cover" && !event.cover_url) || (source === "custom" && !saved.data.custom_banner_url);
                  return (
                    <label key={source} className={app.choice}>
                      <input
                        type="radio"
                        name="banner"
                        checked={draft.banner === source}
                        disabled={unavailable}
                        onChange={() => set("banner", source)}
                      />
                      <span className={app.choiceTitle}>{t.design.banners[source]}</span>
                    </label>
                  );
                })}
              </div>
              {!event.cover_url && <p className={styles.hint}>{t.design.noCover}</p>}
              {errors.banner && <p className={styles.error}>{errors.banner}</p>}
            </div>
            <ImageControl
              label={t.design.banners.custom}
              hint={t.design.bannerHint}
              url={saved.data.custom_banner_url}
              wide
              choose={saved.data.custom_banner_url ? t.design.bannerReplace : t.design.bannerChoose}
              removeLabel={t.design.bannerRemove}
              busy={busy || !canManage}
              onFile={(file) => upload("banner", file)}
              onRemove={() => remove("banner")}
            />
          </fieldset>

          {canManage && (
            <div className={`${styles.saveBar} ${dirty ? styles.sticky : ""}`}>
              <span className={styles.dirty} aria-live="polite">
                {dirty && t.design.unsaved}
              </span>
              {dirty && (
                <Button type="button" variant="secondary" disabled={busy} onClick={() => setDraft(settingsOf(saved.data))}>
                  {t.design.reset}
                </Button>
              )}
              <Button type="submit" disabled={busy || !dirty}>
                <Check size={18} aria-hidden /> {busy ? t.design.saving : t.design.save}
              </Button>
            </div>
          )}
        </form>

        <section className={`${styles.preview} ${mobileTab === "preview" ? "" : styles.hiddenMobile}`} aria-label={t.design.tabPreview}>
          <div className={styles.previewTabs} role="tablist">
            <button type="button" role="tab" aria-selected={view === "ticket"} className={styles.previewTab} onClick={() => setView("ticket")}>
              <Ticket size={18} aria-hidden /> {t.design.previewTicket}
            </button>
            <button type="button" role="tab" aria-selected={view === "email"} className={styles.previewTab} onClick={() => setView("email")}>
              <EnvelopeSimple size={18} aria-hidden /> {t.design.previewEmail}
            </button>
          </div>

          {view === "ticket" ? (
            <div className={styles.stage}>
              <TicketCard
                design={design}
                organization={organization.name}
                title={event.title}
                when={formatEventDate(event, locale)}
                where={`${event.venue_name}, ${event.city}`}
                type={sampleType}
                holder={user.name}
                qr={saved.meta.sample_qr}
                code={saved.meta.sample_code}
              />
            </div>
          ) : (
            <div className={styles.stage}>
              <p className={styles.hint}>{dirty ? t.design.previewEmailStale : t.design.previewEmailHint}</p>
              {emailFailed ? (
                <div className={styles.frameLoading}>
                  <Button type="button" variant="secondary" onClick={() => setEmailFailed(false)}>
                    {t.common.retry}
                  </Button>
                </div>
              ) : email === null ? (
                <div className={styles.frameLoading}>{t.common.loading}</div>
              ) : (
                // Cadre isolé sans script : le HTML de l'e-mail ne peut rien exécuter dans la page.
                <iframe className={styles.frame} title={t.design.previewFrame} sandbox="" srcDoc={email} />
              )}
              {canManage && (
                <Button type="button" variant="secondary" disabled={busy} onClick={sendTest}>
                  <EnvelopeSimple size={18} aria-hidden /> {t.design.sendTest}
                </Button>
              )}
            </div>
          )}
        </section>
      </div>
    </main>
  );
}

/** Textes vides ou faits d'espaces : rien (le titre par défaut s'applique). */
function normalize(settings: DesignSettings): DesignSettings {
  const text = (value: string | null) => value?.trim() || null;
  return { ...settings, headline: text(settings.headline), message: text(settings.message), footer: text(settings.footer) };
}

function ColorPicker({
  label,
  value,
  swatches,
  error,
  onChange,
}: {
  label: string;
  value: string;
  swatches: string[];
  error?: string;
  onChange: (value: string) => void;
}) {
  const { t } = useI18n();
  return (
    <div className={styles.color} role="group" aria-label={label}>
      <span className={styles.subLabel}>{label}</span>
      <div className={styles.swatches}>
        {swatches.map((swatch) => (
          <button
            key={swatch}
            type="button"
            className={styles.swatch}
            style={{ background: swatch }}
            aria-pressed={value.toUpperCase() === swatch}
            aria-label={format(t.design.swatch, { color: swatch })}
            onClick={() => onChange(swatch)}
          />
        ))}
        <label className={styles.custom} title={t.design.customColor}>
          <input
            type="color"
            value={HEX.test(value) ? value : "#000000"}
            aria-label={t.design.customColor}
            onChange={(e) => onChange(e.target.value.toUpperCase())}
          />
        </label>
      </div>
      <Field
        label={format(t.design.hexLabel, { name: label })}
        value={value}
        maxLength={7}
        spellCheck={false}
        autoComplete="off"
        className={styles.hex}
        error={error}
        onChange={(e) => onChange(e.target.value.trim().toUpperCase())}
      />
    </div>
  );
}

function ImageControl({
  label,
  hint,
  url,
  wide = false,
  choose,
  removeLabel,
  busy,
  onFile,
  onRemove,
}: {
  label: string;
  hint: string;
  url: string | null;
  wide?: boolean;
  choose: string;
  removeLabel: string;
  busy: boolean;
  onFile: (file: File) => void;
  onRemove: () => void;
}) {
  return (
    <div className={styles.image}>
      <span className={styles.subLabel}>{label}</span>
      {url && (
        // eslint-disable-next-line @next/next/no-img-element -- image de l'organisateur servie par kassEvent
        <img src={url} alt="" className={wide ? styles.bannerThumb : styles.logoThumb} />
      )}
      <div className={styles.imageButtons}>
        <label className={`${buttonClass("secondary")} ${styles.fileButton}`}>
          {choose}
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            disabled={busy}
            onChange={(e) => {
              const file = e.target.files?.[0];
              e.target.value = "";
              if (file) onFile(file);
            }}
          />
        </label>
        {url && (
          <Button type="button" variant="secondary" disabled={busy} onClick={onRemove}>
            <Trash size={18} aria-hidden /> {removeLabel}
          </Button>
        )}
      </div>
      <p className={styles.hint}>{hint}</p>
    </div>
  );
}
