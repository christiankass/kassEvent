"use client";

import { useState, type DragEvent } from "react";
import { MagicWand, PencilSimple, Plus, X } from "@phosphor-icons/react";
import { Alert, Button, Field } from "@/components/ui";
import { api } from "@/lib/api";
import { actionErrorMessage } from "@/lib/errors";
import type { EventData } from "@/lib/events";
import { useFormSubmit } from "@/lib/form";
import { layoutGroups, layoutSeats, type Guest, type GuestPage, type GuestTable } from "@/lib/guests";
import { format, type Dictionary } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nProvider";
import { useOrganization } from "@/lib/organization-context";
import type { Notice } from "./page";
import styles from "./guests.module.css";

type Props = { event: EventData; page: GuestPage; canManage: boolean; reload: () => Promise<void>; onNotice: (notice: Notice | null) => void };

/**
 * Plan de salle : capacité (tables calculées), puis placement. Sur téléphone, on touche un invité
 * puis sa table ; sur ordinateur, on peut aussi le glisser sur la table.
 */
export function SeatingBoard({ event, page, canManage, reload, onNotice }: Props) {
  const { t } = useI18n();
  const { organization } = useOrganization();
  const [selected, setSelected] = useState<Guest | null>(null);
  const [busy, setBusy] = useState(false);

  const coming = page.data.filter((guest) => guest.status !== "declined");
  const unseated = coming.filter((guest) => guest.table_id === null);
  const byTable = new Map<string, Guest[]>();
  coming.forEach((guest) => guest.table_id && byTable.set(guest.table_id, [...(byTable.get(guest.table_id) ?? []), guest]));

  async function act(request: () => Promise<unknown>, success?: string) {
    onNotice(null);
    setBusy(true);
    try {
      await request();
      await reload();
      if (success) onNotice({ tone: "success", text: success });
    } catch (e) {
      onNotice({ tone: "error", text: actionErrorMessage(e, t) });
    }
    setBusy(false);
  }

  function seat(guest: Guest, table: GuestTable | null) {
    setSelected(null);
    if (guest.table_id === (table?.id ?? null)) return;
    void act(
      () => api(`/guests/${guest.id}/table`, { method: "PUT", body: { table_id: table?.id ?? null }, organizationId: organization.id }),
      table ? format(t.seating.seatedAt, { name: guest.name, table: table.label }) : undefined,
    );
  }

  function autoPlace() {
    void act(async () => {
      const result = await api<{ placed: number; unplaced: number }>(`/events/${event.id}/seating/auto`, { method: "POST", organizationId: organization.id });
      const text = [format(t.seating.autoDone, { placed: String(result.placed) }), result.unplaced > 0 ? format(t.seating.autoLeft, { unplaced: String(result.unplaced) }) : ""].join(" ").trim();
      onNotice({ tone: result.unplaced > 0 ? "error" : "success", text });
    });
  }

  const onDrop = (table: GuestTable | null) => (e: DragEvent) => {
    e.preventDefault();
    const guest = page.data.find((g) => g.id === e.dataTransfer.getData("text/plain"));
    if (guest) seat(guest, table);
  };

  return (
    <div className={styles.stack}>
      {canManage && <PlanForm event={event} page={page} onSaved={async (count) => {
        await reload();
        onNotice({ tone: "success", text: format(t.seating.applied, { count: String(count) }) });
      }} />}

      {page.tables.length > 0 && (
        <section aria-labelledby="tables-title">
          <div className={styles.boardHead}>
            <h2 id="tables-title" className={styles.panelTitle}>
              {t.seating.tablesTitle}
            </h2>
            {canManage && unseated.length > 0 && (
              <Button type="button" variant="secondary" disabled={busy} onClick={autoPlace}>
                <MagicWand size={20} aria-hidden /> {t.seating.auto}
              </Button>
            )}
          </div>
          {canManage && <p className={styles.muted}>{t.seating.hint}</p>}

          {/* Invités à placer : en haut, collés à l'écran pendant qu'on fait défiler les tables. */}
          <div className={styles.tray} onDragOver={(e) => canManage && e.preventDefault()} onDrop={onDrop(null)}>
            <h3 className={styles.trayTitle}>{format(t.seating.unseatedTitle, { count: String(unseated.length) })}</h3>
            {selected && (
              <p className={styles.selectedHint} role="status">
                {format(t.seating.selected, { name: selected.name, party: String(selected.party) })}{" "}
                <button type="button" className={styles.linkButton} onClick={() => setSelected(null)}>
                  {t.seating.deselect}
                </button>
              </p>
            )}
            {unseated.length === 0 ? (
              <p className={styles.muted}>{t.seating.unseatedEmpty}</p>
            ) : (
              <ul className={styles.chips}>
                {unseated.map((guest) => (
                  <li key={guest.id}>
                    <GuestChip guest={guest} selected={selected?.id === guest.id} disabled={!canManage || busy} onSelect={setSelected} />
                  </li>
                ))}
              </ul>
            )}
          </div>

          <ul className={styles.tables}>
            {page.tables.map((table) => {
              const guests = byTable.get(table.id) ?? [];
              const left = table.seats - table.occupied;
              const fits = selected ? selected.party <= left + (selected.table_id === table.id ? selected.party : 0) : false;
              return (
                <li
                  key={table.id}
                  className={styles.table}
                  data-target={selected ? (fits ? "yes" : "no") : undefined}
                  onDragOver={(e) => canManage && e.preventDefault()}
                  onDrop={onDrop(table)}
                >
                  <button
                    type="button"
                    className={styles.tableHead}
                    disabled={!selected || busy}
                    onClick={() => selected && seat(selected, table)}
                    aria-label={selected ? `${t.seating.dropHere} : ${table.label}` : table.label}
                  >
                    <span className={styles.tableNumber}>{table.number}</span>
                    <span className={styles.tableLabel}>{table.name ?? table.label}</span>
                    <span className={styles.occupancy} data-full={left <= 0 || undefined}>
                      {format(t.seating.occupancy, { occupied: String(table.occupied), seats: String(table.seats) })}
                      {" · "}
                      {left <= 0 ? t.seating.full : format(t.seating.seatsLeft, { left: String(left) })}
                    </span>
                  </button>
                  <Meter occupied={table.occupied} seats={table.seats} />
                  {guests.length === 0 ? (
                    <p className={styles.mutedSmall}>{t.seating.emptyTable}</p>
                  ) : (
                    <ul className={styles.seated}>
                      {guests.map((guest) => (
                        <li key={guest.id} className={styles.seatedRow}>
                          <GuestChip guest={guest} selected={selected?.id === guest.id} disabled={!canManage || busy} onSelect={setSelected} />
                          {canManage && (
                            <button
                              type="button"
                              className={styles.iconButtonSmall}
                              aria-label={format(t.seating.unseat, { name: guest.name })}
                              title={format(t.seating.unseat, { name: guest.name })}
                              disabled={busy}
                              onClick={() => seat(guest, null)}
                            >
                              <X size={16} aria-hidden />
                            </button>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                  {canManage && <TableEditor table={table} busy={busy} act={act} />}
                </li>
              );
            })}
          </ul>
          {canManage && <AddTable eventId={event.id} onAdded={reload} />}
        </section>
      )}
    </div>
  );
}

function GuestChip({ guest, selected, disabled, onSelect }: { guest: Guest; selected: boolean; disabled: boolean; onSelect: (guest: Guest | null) => void }) {
  return (
    <button
      type="button"
      className={styles.guestChip}
      aria-pressed={selected}
      disabled={disabled}
      draggable={!disabled}
      onDragStart={(e) => {
        e.dataTransfer.setData("text/plain", guest.id);
        e.dataTransfer.effectAllowed = "move";
      }}
      onClick={() => onSelect(selected ? null : guest)}
    >
      <span>{guest.name}</span>
      <span className={styles.chipParty} data-pending={guest.status === "pending" || undefined}>
        {guest.party}
      </span>
    </button>
  );
}

/** Places occupées d'une table, en un coup d'œil (vert, puis jaune quand elle se remplit, rouge si dépassée). */
function Meter({ occupied, seats }: { occupied: number; seats: number }) {
  const ratio = Math.min(1, occupied / Math.max(1, seats));
  return (
    <div className={styles.meter} aria-hidden>
      <span style={{ width: `${ratio * 100}%` }} data-level={occupied > seats ? "over" : ratio >= 0.8 ? "high" : "ok"} />
    </div>
  );
}

function PlanForm({ event, page, onSaved }: { event: EventData; page: GuestPage; onSaved: (count: number) => Promise<void> }) {
  const { t } = useI18n();
  const { organization } = useOrganization();
  const [capacity, setCapacity] = useState(String(page.plan?.hall_capacity ?? event.capacity ?? ""));
  const [by, setBy] = useState<"seats" | "tables">("seats");
  const [seatsPerTable, setSeatsPerTable] = useState(String(page.plan?.seats_per_table ?? 10));
  const [tableCount, setTableCount] = useState(String(page.tables.length || ""));

  const hall = Number(capacity);
  const preview = layoutSeats(hall, by === "seats" ? Number(seatsPerTable) || null : null, by === "tables" ? Number(tableCount) || null : null);
  const expected = page.summary.people_expected;

  const { submit, pending, message, fieldError } = useFormSubmit(async () => {
    const { tables } = await api<{ tables: GuestTable[] }>(`/events/${event.id}/seating`, {
      method: "PUT",
      organizationId: organization.id,
      body: { hall_capacity: hall, ...(by === "seats" ? { seats_per_table: Number(seatsPerTable) } : { table_count: Number(tableCount) }) },
    });
    await onSaved(tables.length);
  });

  return (
    <section className={styles.panel} aria-labelledby="plan-title">
      <h2 id="plan-title" className={styles.panelTitle}>
        {t.seating.planTitle}
      </h2>
      {!page.plan && <p className={styles.muted}>{t.seating.noPlan}</p>}
      <form className={styles.form} onSubmit={submit} noValidate>
        {message && <Alert tone="error">{message}</Alert>}
        <Field
          label={t.seating.capacity}
          type="number"
          inputMode="numeric"
          min={1}
          max={5000}
          required
          value={capacity}
          onChange={(e) => setCapacity(e.target.value)}
          error={fieldError("hall_capacity")}
        />
        <fieldset className={styles.segmentedSet}>
          <legend className={styles.label}>{t.seating.by}</legend>
          <div className={styles.segmented}>
            {(["seats", "tables"] as const).map((mode) => (
              <label key={mode} data-checked={by === mode || undefined}>
                <input type="radio" name="by" value={mode} checked={by === mode} onChange={() => setBy(mode)} />
                {mode === "seats" ? t.seating.bySeats : t.seating.byTables}
              </label>
            ))}
          </div>
        </fieldset>
        {by === "seats" ? (
          <Field
            label={t.seating.seatsPerTable}
            type="number"
            inputMode="numeric"
            min={2}
            max={30}
            value={seatsPerTable}
            onChange={(e) => setSeatsPerTable(e.target.value)}
            error={fieldError("seats_per_table")}
          />
        ) : (
          <Field
            label={t.seating.tableCount}
            type="number"
            inputMode="numeric"
            min={1}
            max={300}
            value={tableCount}
            onChange={(e) => setTableCount(e.target.value)}
            error={fieldError("table_count")}
          />
        )}

        {preview.length > 0 && (
          <div className={styles.preview} aria-live="polite">
            <p className={styles.previewMain}>{describeLayout(preview, t)}</p>
            {expected > 0 && <p>{format(t.seating.expected, { people: String(expected), capacity: String(hall) })}</p>}
            {expected > hall && <p className={styles.warn}>{format(t.seating.short, { count: String(expected - hall) })}</p>}
            {page.tables.length > preview.length && <p className={styles.warn}>{t.seating.shrink}</p>}
          </div>
        )}
        <Button type="submit" disabled={pending || preview.length === 0}>
          {t.seating.apply}
        </Button>
      </form>
    </section>
  );
}

/** « 10 tables : 5 de 10 places et 5 de 9 places. » */
function describeLayout(seats: number[], t: Dictionary): string {
  const groups = layoutGroups(seats).map((g) => format(t.seating.group, { count: String(g.count), seats: String(g.seats) }));
  return format(t.seating.result, { tables: String(seats.length), groups: groups.join(t.seating.and) });
}

function TableEditor({ table, busy, act }: { table: GuestTable; busy: boolean; act: (request: () => Promise<unknown>, success?: string) => Promise<void> }) {
  const { t } = useI18n();
  const { organization } = useOrganization();
  const [open, setOpen] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [name, setName] = useState(table.name ?? "");
  const [seats, setSeats] = useState(String(table.seats));

  if (!open) {
    return (
      <button type="button" className={styles.linkButton} onClick={() => setOpen(true)}>
        <PencilSimple size={16} aria-hidden /> {t.seating.editTable}
      </button>
    );
  }

  return (
    <div className={styles.tableEditor}>
      <Field label={t.seating.tableName} value={name} maxLength={60} onChange={(e) => setName(e.target.value)} hint={t.seating.tableNameHint} />
      <Field label={t.seating.tableSeats} type="number" inputMode="numeric" min={1} max={30} value={seats} onChange={(e) => setSeats(e.target.value)} />
      <div className={styles.buttons}>
        <Button
          type="button"
          disabled={busy}
          onClick={() => {
            setOpen(false);
            void act(() => api(`/tables/${table.id}`, { method: "PATCH", body: { name, seats: Number(seats) }, organizationId: organization.id }));
          }}
        >
          {t.guests.save}
        </Button>
        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
          {t.guests.cancel}
        </Button>
        <Button type="button" variant="danger" disabled={busy} onClick={() => setConfirming(true)}>
          {t.seating.removeTable}
        </Button>
      </div>
      {confirming && (
        <div className={styles.confirm} role="alert">
          <p>{format(t.seating.removeTableConfirm, { label: table.label })}</p>
          <div className={styles.buttons}>
            <Button type="button" variant="danger" disabled={busy} onClick={() => void act(() => api(`/tables/${table.id}`, { method: "DELETE", organizationId: organization.id }))}>
              {t.seating.removeTable}
            </Button>
            <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
              {t.guests.cancel}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

function AddTable({ eventId, onAdded }: { eventId: string; onAdded: () => Promise<void> }) {
  const { t } = useI18n();
  const { organization } = useOrganization();
  const { submit, pending, message, fieldError } = useFormSubmit(async (form) => {
    await api(`/events/${eventId}/tables`, {
      method: "POST",
      organizationId: organization.id,
      body: { name: String(form.get("name") ?? ""), seats: Number(form.get("seats") || 0) },
    });
    (document.getElementById("add-table") as HTMLFormElement | null)?.reset();
    await onAdded();
  });

  return (
    <details className={styles.panel}>
      <summary className={styles.summary}>
        <Plus size={18} aria-hidden /> {t.seating.addTable}
      </summary>
      <form id="add-table" className={styles.form} onSubmit={submit} noValidate>
        {message && <Alert tone="error">{message}</Alert>}
        <div className={styles.fieldPair}>
          <Field label={t.seating.tableName} name="name" maxLength={60} hint={t.seating.tableNameHint} error={fieldError("name")} />
          <Field label={t.seating.tableSeats} name="seats" type="number" inputMode="numeric" min={1} max={30} defaultValue={10} required error={fieldError("seats")} />
        </div>
        <Button type="submit" disabled={pending}>
          {t.seating.addTableSubmit}
        </Button>
      </form>
    </details>
  );
}
