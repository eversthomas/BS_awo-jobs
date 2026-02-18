# BS_awo-jobs Development Log

## Current Phase: 6
## Status: Completed

### Completed
- [x] Plugin skeleton created
- [x] Tables created on activation
- [x] Admin menu registered
- [x] Settings page: API URL input + save
- [x] Schema Inspector functional
- [x] Manual sync stores run + jobs
- [x] DiffEngine class built (created/modified/offlined detection)
- [x] Cron setup (daily at configurable time, default 03:00)
- [x] Events persisted to bsawo_events table
- [x] Settings page: cron toggle + manual controls + Force Resync
- [x] Events Log admin page
- [x] Anomaly detection (>50% mass disappearance)
- [x] Facility_id collision detection (warn in admin if multiple facilities share same ID)
- [x] Department source toggle (API Fachbereich vs. Mandantenfeld) in Settings
- [x] Dashboard uses selected department source for “active” Fachbereich count
- [x] Optional table bsawo_facilities created on activation, synced from jobs_current on each sync
- [x] Admin notice on Settings page showing facility collision details (no CLI/DB needed)
- [x] bsawo_events extended: facility_id, jobfamily_id, department_api_id, department_custom (Phase 4)
- [x] Cron::persist_events fills new event columns from job state (new_state/previous_state)
- [x] DB migration (maybe_upgrade) on plugins_loaded for existing installs
- [x] Dashboard “Fluktuation” tab: date range picker, window 30/60/90 days, department/jobfamily filter
- [x] Metrics: total created/offlined in range, repeated postings, avg vacancy duration by facility/department/jobfamily
- [x] Phase 5: Dashboard-Tab-Persistenz, Backup-Export/Import
- [x] **Phase 6:** Shortcode [bs_awo_jobs]: Listen- und Detail-Ansicht
- [x] **Phase 6:** Filter: Ort (Text), Fachbereich, Beruf/Jobfamilie, Vertragsart (GET-Parameter, buchmarkbar)
- [x] **Phase 6:** Kurzform: Karten mit Titel, Einrichtung, Standort, Vertragsart, „Weiterlesen“-Link
- [x] **Phase 6:** Detail-Ansicht: ?job_id=XXX, Volltext (Einleitungstext, Qualifikation, Infos, Wirbieten), „Zurück zur Übersicht“
- [x] **Phase 6:** Externer Link: DetailUrl aus raw_json, target="_blank" rel="noopener noreferrer", Hinweis „Sie verlassen unsere Website“
- [x] **Phase 6:** List/Grid-Umschalter, Pagination, namespaced CSS (bs-awo-jobs.css)

### Database State
- bsawo_runs: stores all sync attempts (success/failed)
- bsawo_jobs_current: updated on each sync (normalized job data, dual dept fields)
- bsawo_events: lifecycle events + facility_id, jobfamily_id, department_api_id, department_custom (Phase 4)
- bsawo_facilities: one row per facility_id (canonical_name, normalized_address; optional manual_override/notes)

### Key Design Decisions
- facility_id = hash(Einrichtung + Strasse + PLZ + Ort) because Mandantnr is unreliable
- Store BOTH department sources: department_api AND department_custom
- Admin setting “Fachbereich für Auswertungen”: API or Mandantenfeld; Dashboard, Fluktuation and Frontend filter use it
- Timestamps are Unix format in JSON → store as INT
- DiffEngine operates on Stellennummer as stable job_id and uses Aenderungsdatum for “modified” detection
- Anomaly threshold: >50% of jobs offlined im Vergleich zum vorherigen Snapshot → Warnhinweis im Admin
- Facility collisions: multiple distinct (name+address) per facility_id → option + notice on Settings page
- Phase 4: Vacancy duration = DATEDIFF(offlined_date, created_date) per job; repeated posting = same (facility_id, jobfamily_id) ≥2× created in window
- Phase 5: Backup = JSON with version, exported_at, tables. Import replaces all four tables; runs keep id for referential consistency.
- Phase 6: [bs_awo_jobs] one shortcode; list by default, detail when ?job_id=XXX. DetailUrl from raw_json; extern link with disclaimer.

### Next Phase: 7 (optional)
- [bs_awo_jobs_stats] shortcode (optional counter widget)
- DEVLOG updated after Phase 7
