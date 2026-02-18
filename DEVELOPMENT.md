## Entwicklungsphasen – BS AWO Jobs

Diese Datei dokumentiert den Fortschritt der größeren Entwicklungsphasen rund um Auswertungen und Fluktuation.

### Übersicht Phasenmodul „Fluktuationen“

- [x] **Phase 1 – Vorbereitung & Library-Integration**
  - [x] PhpSpreadsheet via Composer eingebunden (`composer.json`, `vendor/`).
  - [x] Optionaler Composer-Autoloader im Plugin-Hauptfile (`bs-awo-jobs.php`).
  - [x] Custom-Tabelle `wp_bs_awo_stats` (mit Prefix) auf Aktivierung und Upgrade angelegt.
  - [x] Tests: PhpSpreadsheet-Availability + Tabellenerstellung verifiziert.

- [x] **Phase 2 – Upload-Interface & Parser-Logik**
  - [x] Upload-Feld (.xlsx) im Tab „Fluktuation“ (mit Nonce und Capability-Check).
  - [x] Parser-Funktion basierend auf PhpSpreadsheet.
  - [x] Mapping S-Nr → `s_nr` (UNIQUE, ON DUPLICATE KEY UPDATE) und restliche Felder (inkl. zusammengeführter Ort/Adress-Infos).
  - [x] Testbar: Beispiel-Export kann hochgeladen und anschließend in `wp_bs_awo_stats` geprüft werden.

- [ ] **Phase 3 – VZE-Mapping & Berechnungs-Logik**
  - [ ] Options-Interface für BA-Zeiteinteilung → Dezimalwert (z. B. „Vollzeit“ = 1.0).
  - [ ] Skript, das nach dem Import `vze_wert` gemäß Mapping berechnet.
  - [ ] Test: Korrekte Dezimalwerte für Stichproben in der Datenbank.

- [ ] **Phase 4 – Analyse-Dashboard (Chart.js)**
  - [ ] Filter-UI (Zeitraum, internes Kürzel, Einrichtung) im Tab „Fluktuation“.
  - [ ] Balkendiagramm: Summe VZE pro internem Fachbereich.
  - [ ] Tortendiagramm: Verteilung Anstellungsarten.
  - [ ] Kennzahl: „Gesamt offene VZE“.
  - [ ] Test: Diagramme reagieren korrekt auf Filter.

- [ ] **Phase 5 – Reporting & Export (PDF/Excel)**
  - [ ] PDF-Export (Dompdf) für aktuelle Filteransicht mit AWO-Header, Datum, Kennzahlen.
  - [ ] Excel-Export der gefilterten, bereinigten Daten inkl. berechneter VZE.
  - [ ] Test: Professionell formatiertes PDF + korrekte Excel-Daten.

### Notizen

- DEVLOG (`DEVLOG.md`) bleibt das chronologische Logbuch; diese Datei dient nur als kompakte Checkliste über den Status der Fluktuations-Phasen.

