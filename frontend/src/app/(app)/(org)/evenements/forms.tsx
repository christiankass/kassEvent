"use client";

import { useRef } from "react";
import { Alert, Button, Field, Select, TextArea } from "@/components/ui";
import { CURRENCIES, type Currency } from "@/lib/api";
import { priceInput, TIMEZONES, type EventData, type TicketType } from "@/lib/events";
import { useFormSubmit } from "@/lib/form";
import { format } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import styles from "./events.module.css";

/** Champ facultatif : une saisie vide part en null pour effacer la valeur. */
const optional = (value: FormDataEntryValue | null) => (value === null || value === "" ? null : String(value));

/** Informations d'un événement : création (sans `event`) ou modification. */
export function EventForm({
  event,
  currency,
  submitLabel,
  onSubmit,
}: {
  event?: EventData;
  currency: Currency;
  submitLabel: string;
  onSubmit: (body: Record<string, unknown>) => Promise<void>;
}) {
  const { t } = useI18n();
  const form = useFormSubmit(
    (data) =>
      onSubmit({
        title: data.get("title"),
        description: optional(data.get("description")),
        starts_at: data.get("starts_at"),
        ends_at: optional(data.get("ends_at")),
        timezone: data.get("timezone"),
        venue_name: data.get("venue_name"),
        venue_address: optional(data.get("venue_address")),
        city: data.get("city"),
        capacity: optional(data.get("capacity")),
        // Devise figée une fois les billets créés : le champ désactivé n'est pas envoyé.
        currency: data.get("currency") ?? undefined,
      }),
    { navigates: !event },
  );

  return (
    <form className={styles.form} onSubmit={form.submit} noValidate>
      {form.message && <Alert tone="error">{form.message}</Alert>}
      <Field
        label={t.events.fieldTitle}
        hint={t.events.fieldTitleHint}
        name="title"
        defaultValue={event?.title}
        required
        maxLength={160}
        error={form.fieldError("title")}
      />
      <div className={styles.twoCols}>
        <Field
          label={t.events.fieldStartsAt}
          name="starts_at"
          type="datetime-local"
          defaultValue={event?.starts_at}
          required
          error={form.fieldError("starts_at")}
        />
        <Field
          label={t.events.fieldEndsAt}
          name="ends_at"
          type="datetime-local"
          defaultValue={event?.ends_at ?? undefined}
          error={form.fieldError("ends_at")}
        />
      </div>
      <div className={styles.twoCols}>
        <Field
          label={t.events.fieldVenue}
          hint={t.events.fieldVenueHint}
          name="venue_name"
          defaultValue={event?.venue_name}
          required
          maxLength={160}
          error={form.fieldError("venue_name")}
        />
        <Field
          label={t.events.fieldCity}
          name="city"
          defaultValue={event?.city ?? "Kinshasa"}
          required
          maxLength={80}
          error={form.fieldError("city")}
        />
      </div>
      <Field
        label={t.events.fieldAddress}
        name="venue_address"
        defaultValue={event?.venue_address ?? undefined}
        maxLength={255}
        error={form.fieldError("venue_address")}
      />
      <TextArea
        label={t.events.fieldDescription}
        hint={t.events.fieldDescriptionHint}
        name="description"
        defaultValue={event?.description ?? undefined}
        maxLength={5000}
        error={form.fieldError("description")}
      />
      <div className={styles.threeCols}>
        <Field
          label={t.events.fieldCapacity}
          hint={t.events.fieldCapacityHint}
          name="capacity"
          type="number"
          inputMode="numeric"
          min={1}
          defaultValue={event?.capacity ?? undefined}
          error={form.fieldError("capacity")}
        />
        <Select label={t.events.fieldTimezone} name="timezone" defaultValue={event?.timezone ?? "Africa/Kinshasa"}>
          {Object.entries(TIMEZONES).map(([zone, label]) => (
            <option key={zone} value={zone}>
              {label}
            </option>
          ))}
        </Select>
        <Select
          label={t.events.fieldCurrency}
          name="currency"
          defaultValue={event?.currency ?? currency}
          disabled={Boolean(event?.ticket_types.length)}
          hint={event?.ticket_types.length ? t.events.currencyLocked : undefined}
          error={form.fieldError("currency")}
        >
          {CURRENCIES.map((code) => (
            <option key={code} value={code}>
              {code}
            </option>
          ))}
        </Select>
      </div>
      <div>
        <Button type="submit" disabled={form.pending}>
          {form.pending ? t.common.loading : submitLabel}
        </Button>
      </div>
    </form>
  );
}

/** Ajout (sans `ticket`) ou modification d'un type de billet. */
export function TicketForm({
  ticket,
  currency,
  onSubmit,
  onCancel,
}: {
  ticket?: TicketType;
  currency: string;
  onSubmit: (body: Record<string, unknown>) => Promise<void>;
  onCancel?: () => void;
}) {
  const { t } = useI18n();
  const formRef = useRef<HTMLFormElement>(null);
  const form = useFormSubmit(async (data) => {
    await onSubmit({
      name: data.get("name"),
      description: optional(data.get("description")),
      price: String(data.get("price") ?? "").trim(),
      quantity: data.get("quantity"),
      max_per_order: data.get("max_per_order"),
      sales_start_at: optional(data.get("sales_start_at")),
      sales_end_at: optional(data.get("sales_end_at")),
    });
    if (!ticket) formRef.current?.reset();
  });

  return (
    <form ref={formRef} className={styles.form} onSubmit={form.submit} noValidate>
      {form.message && <Alert tone="error">{form.message}</Alert>}
      <div className={styles.twoCols}>
        <Field
          label={t.events.ticketName}
          name="name"
          defaultValue={ticket?.name}
          placeholder="Standard"
          required
          maxLength={80}
          error={form.fieldError("name")}
        />
        <Field
          label={format(t.events.ticketPrice, { currency })}
          hint={t.events.ticketPriceHint}
          name="price"
          inputMode="decimal"
          defaultValue={ticket ? priceInput(ticket.price) : undefined}
          required
          error={form.fieldError("price")}
        />
      </div>
      <div className={styles.twoCols}>
        <Field
          label={t.events.ticketQuantity}
          name="quantity"
          type="number"
          inputMode="numeric"
          min={1}
          defaultValue={ticket?.quantity}
          required
          error={form.fieldError("quantity")}
        />
        <Field
          label={t.events.ticketDescription}
          name="description"
          defaultValue={ticket?.description ?? undefined}
          maxLength={255}
          error={form.fieldError("description")}
        />
      </div>
      <details className={styles.more}>
        <summary>{t.events.moreOptions}</summary>
        <div className={styles.threeCols}>
          <Field
            label={t.events.ticketMax}
            name="max_per_order"
            type="number"
            inputMode="numeric"
            min={1}
            max={50}
            defaultValue={ticket?.max_per_order ?? 10}
            error={form.fieldError("max_per_order")}
          />
          <Field
            label={t.events.ticketSalesStart}
            name="sales_start_at"
            type="datetime-local"
            defaultValue={ticket?.sales_start_at ?? undefined}
            error={form.fieldError("sales_start_at")}
          />
          <Field
            label={t.events.ticketSalesEnd}
            name="sales_end_at"
            type="datetime-local"
            defaultValue={ticket?.sales_end_at ?? undefined}
            error={form.fieldError("sales_end_at")}
          />
        </div>
      </details>
      <div className={styles.buttons}>
        <Button type="submit" disabled={form.pending}>
          {form.pending ? t.common.loading : ticket ? t.events.save : t.events.addTicket}
        </Button>
        {onCancel && (
          <Button type="button" variant="secondary" onClick={onCancel}>
            {t.events.cancel}
          </Button>
        )}
      </div>
    </form>
  );
}

export function StatusBadge({ status }: { status: "draft" | "published" | "past" }) {
  const { t } = useI18n();
  return <span className={`${styles.status} ${styles[status]}`}>{t.events[status]}</span>;
}
