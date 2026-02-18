## Plan BS AWO Jobs – Nächste Schritte

**Erledigt (Stand jetzt)**  
- HTML-Markup in `SchemaPage`/`SettingsPage` bereinigt.  
- Layout-Umschalter im Frontend (list/grid) repariert und URL-Parameter integriert.  
- Frontend-Filterleiste erweitert: Orte-Dropdown, abhängige Jobfamilien/Vertragsarten, Reset-Link, responsives Grid (Mobile/Tablet).  
- Performance: gecachte Filter-Optionen (Transients), keine `get_option()`-Aufrufe in der Job-Schleife, Assets in `assets/`.  
- Frontend-Shortcode-Dokumentation im Backend (Frontend-Tab), Footer-Hinweise auf Shortcodes bereinigt.  
- API- und I18n: API-URL nur HTTPS (`esc_url_raw(..., ['https'])`), `load_plugin_textdomain('bs-awo-jobs', …)` in `plugins_loaded`, Ordner `languages/` für .po/.mo.
- **Run-Datenintegrität für Fluktuationsanalyse:** `store_run()` nutzt `INSERT ... ON DUPLICATE KEY UPDATE` (run_id bleibt bei mehreren Syncs pro Tag erhalten).
- **Vordefinierte Shortcodes bei department_source = „api“:** Shortcodes nutzen immer Bezeichnungen (`fachbereich = 'Kita'` etc.); bei API-Fachbereich filtert `JobBoard` je nach Wert über `department_api` (Bezeichnung) oder `department_api_id` (ID).
- **DynamicStyles für alle Shortcodes:** Prüfung in `DynamicStyles.php` auf alle relevanten Shortcodes erweitert (`bs_awo_jobs`, `bs_awo_jobs_kita`, `bs_awo_jobs_pflege`, `bs_awo_jobs_verwaltung`).
- **TRUNCATE-Escaping vereinheitlichen:** Alle `TRUNCATE TABLE`-Aufrufe in `SettingsPage.php` mit Backticks um Tabellennamen.
- **Import-Validierung:** In `handle_import_backup()` MIME-Type (application/json, text/plain), max. Dateigröße 50 MB; Admin-Hinweise bei zu_large/invalid_type.
- **AJAX-Filter und Spinner:** Frontend-Filter per AJAX (ohne voller Reload), Lade-Spinner; Admin-Spinner bei „Jetzt synchronisieren“; Fallback mit Anker #bs-awo-jobs bei Reload.

---

## Priorisierte To-dos

### Phase 2 – Mittel (Refactoring & UX)

4. **Dokumentation**
   - `README.md` auf aktuellen Funktionsumfang (Events, Stats, Frontend-Shortcodes, Backup, Fluktuation) aktualisieren.
   - `DEVLOG.md` bei Änderungen knapp weiterpflegen.

---

### Phase 3 – Niedrig (Backlog)

6. **Große Klassen aufteilen** *(zurückgestellt)*  
   Geplant war: `SettingsPage` in Bereiche zerlegen (Einstellungen, Backup/Restore, Runs/Events), `JobBoard` in Query-/Filter-Logik und Rendering trennen.  
   **Begründung für Zurückstellung:** Bei stabiler Nutzung und wenigen geplanten Erweiterungen bringt das Aufteilen wenig Nutzen bei realem Risiko (Regressionen ohne ausreichende Tests). Refactoring lohnt sich vor allem bei häufigem Ändern oder bei Einführung von Unit-/Integrationstests. Erst dann wieder aufgreifen.

7. **Code-Qualität**
   - Unit-/Integrationstests einführen.
   - Type-Hints und DocBlocks ergänzen, Code-Style vereinheitlichen (z. B. `! empty()` vs `!empty()`).
