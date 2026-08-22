# Veranstaltungs-Export

**Version 2.2.0**

WordPress-Plugin für die Webseite der Kulturstiftung: crawlt Veranstaltungen
externer Quellen und legt sie in The Events Calendar (TEC) an.

Quellen:

| Quelle | Kürzel | Parser |
|---|---|---|
| Gemeinde Seevetal (Nolis) | `seevetal` | `crawler/SeevetalParser.php` |
| Burg Seevetal (Nolis) | `burg` | `crawler/BurgSeevetalParser.php` |
| Empore Buchholz | `empore` | `crawler/EmporeBuchholzParser.php` |
| Kulturverein Winsen | `winsen` | `crawler/KulturvereinWinsenParser.php` |
| Musik in alten Heidekirchen | `miah` | `crawler/MusikInAltenHeidekirchenParser.php` |

## Aufbau

```
veranstaltungs-export.php     Bootstrap, lädt alle Bausteine
admin/menu.php                Menü + Vorschauseiten
admin/settings.php            Einstellungen, Zeitpläne, Cron-Runner je Quelle
crawler/*Parser.php           Je Quelle: Links sammeln, Detailseite parsen
includes/event-identity.php   "Kenne ich diese Veranstaltung schon?"
includes/tec_import.php       Upsert nach TEC (kse_tec_upsert_event)
includes/run-lock.php         Überlappungsschutz für Crawler-Läufe
includes/dedupe.php           Gruppieren und Zusammenführen von Dubletten
includes/dubletten-admin.php  Admin-Seite "Dubletten" (Diagnose + Bereinigung)
includes/stats.php            Lauf-Statistik
tests/test-identity.php       Logiktests ohne WordPress
```

## Dubletten-Erkennung

Ein importiertes Event wird über `_kse_source_uid` identifiziert, nicht über
seine Detail-URL. Bei Nolis-Quellen (seevetal.de) ist das die numerische
Event-ID aus der URL, z. B. `nolis:seevetal.de:910027108`. Sie bleibt gleich,
wenn sich der Titel ändert (`VERLEGT: ...` erzeugt einen neuen Slug) und wenn
dieselbe Seite zusätzlich unter `/regional/veranstaltungen/buchen/...`
ausgeliefert wird.

Reihenfolge der Prüfung in `kse_ei_find_event()`:

1. `_kse_source_uid`
2. `_kse_source_url` bzw. `_EventURL` (Alt-Bestand)
3. Nolis-ID irgendwo in der gespeicherten Quell-URL (Alt-Bestand mit geändertem Slug)
4. normalisierter Titel + exaktes Startdatum
5. normalisierter Titel + gleicher Tag, nur wenn eindeutig

Alle Abfragen laufen direkt über `$wpdb`. WP_Query ist hier ungeeignet: TEC
hängt sich in jede Query auf `tribe_events` ein, und `post_status => 'any'`
schließt den Papierkorb aus – gelöschte Events wurden dadurch bei jedem Lauf
neu angelegt.

Wird ein Treffer im Papierkorb gefunden, passiert nichts: kein neuer Eintrag,
aber auch keine Wiederbelebung.

## Bedienung

Menü **Veranstaltungs-Export**:

* je Quelle eine Vorschauseite mit „Liste aktualisieren“ und „Jetzt starten“
* **Einstellungen** – Whitelist/Blacklist Seevetal, Zeitplan je Quelle
* **Statistik** – was der letzte Lauf gefunden/angelegt/übersprungen hat
* **Dubletten** – Kennzahlen, Dubletten-Gruppen, „Kennungen nachtragen“,
  „Alle Dubletten zusammenführen“ (überzählige Einträge wandern in den Papierkorb)

## Entwicklung

Kein lokales WordPress nötig, um die Logik zu prüfen:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli php tests/test-identity.php
```

Syntaxcheck über alle Dateien:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli sh -c 'for f in $(find . -name "*.php"); do php -l "$f"; done'
```

Deploy: Dateien per FTP auf STRATO überschreiben (PHP 8.4). Bei hartnäckigem
OPcache den Plugin-Ordner kurz umbenennen.

## Changelog

### 2.2.0
* Dubletten-Ursachen behoben: stabile Quell-Kennung (`_kse_source_uid`),
  SQL-basierte Existenzprüfung inklusive Papierkorb, Entdoppelung der
  Link-Liste vor dem Import, Überlappungsschutz für Cron-Läufe.
* Zeitzonen-Fehler behoben: Start- und Endzeiten wurden durch `strtotime()` +
  `date_i18n()` um den GMT-Offset zu spät gespeichert (19:30 → 21:30) und
  verschoben sich zusätzlich über den Sommerzeit-Wechsel.
* Events ohne verwertbares Startdatum werden übersprungen statt mit der
  Importzeit angelegt.
* `source_category` wird von allen Runnern korrekt übergeben (vorher `category`,
  der Schlüssel wurde nie ausgewertet); `source_slug` wird gesetzt, damit der
  Überschreibschutz zwischen Quellen greift.
* Neue Admin-Seite „Dubletten“ für Diagnose und Bereinigung.

### 2.1.0
* Burg Seevetal als Quelle, SeevetalParser V3.0 mit JSON-LD als primärer
  Datenquelle.
