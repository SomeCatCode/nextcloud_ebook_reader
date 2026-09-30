# E-Book Reader für Nextcloud – Projektplan

Zielplattform: **Nextcloud Hub 26 Spring (34.0.x)**
Arbeitstitel / App-ID: `ebookreader` (Name „E-Book Reader“)

## 1. Ziele

| Bereich | Umfang |
|---|---|
| Formate | EPUB, MOBI, AZW3 (KF8), FB2, CBZ, CBR |
| Viewer | E-Books direkt aus der Files-App öffnen (Viewer-Integration) und über eigene Reader-Route |
| Bibliothek | Eigene App-Seite mit Cover-Raster, Metadaten, **Genres & Tags**, Suche, Filter, Sortierung, „Weiterlesen“ |
| Metadaten | Titel, Autor(en), Serie + Band, Beschreibung, **Genres, Tags**, Sprache, Verlag, Erscheinungsdatum, ISBN; dazu nur in der App: Bewertung und Lesestatus |
| Editor | Metadaten bearbeiten, Cover tauschen, Kapitel/Seiten entfernen und umsortieren, Inhaltsverzeichnis umbenennen/umsortieren, Datei umbenennen |
| Lesefortschritt | Serverseitig in der Nextcloud-DB gespeichert, geräteübergreifend synchron, offline-tauglich |
| **Mobil-Vorbereitung** | API-first: Alles, was die Web-UI kann, geht auch über eine dokumentierte, versionierte REST-API (OpenAPI). So lässt sich später eine **Android-App** ohne Server-Umbau anbinden (siehe 6.8) |
| Entwicklung | Lokale Nextcloud 34 per Docker |

**Bewusst nicht enthalten (spätere Erweiterungen):** Markierungen/Notizen, Lesezeichen-Liste, OPDS-Feed, KOReader-Sync, Regale/Sammlungen, PDF (dafür hat Nextcloud schon einen Viewer), Bearbeiten von Kapiteltexten (WYSIWYG).

## 2. Technologie-Stack

**Backend**
- PHP ≥ 8.2 (empfohlen 8.3), `declare(strict_types=1)`
- Nextcloud AppFramework: `IBootstrap`, OCS-Controller, QBMapper/Entities, Migrations, Events, BackgroundJobs, occ-Commands
- Dateibearbeitung mit `ZipArchive` und `DOMDocument`, beides Standard in PHP
- `info.xml`: `<nextcloud min-version="34" max-version="34"/>`

**Frontend**
- Vue 3 (Composition API) + Vite (`@nextcloud/vite-config`)
- `@nextcloud/vue` (v9, Vue-3-Komponenten), `@nextcloud/axios`, `@nextcloud/router`, `@nextcloud/initial-state`, `@nextcloud/l10n`, `@nextcloud/files`, `@nextcloud/dialogs`
- **Rendering: [foliate-js](https://github.com/johnfactotum/foliate-js)** (MIT, reines ES-Modul, keine Abhängigkeiten). Deckt EPUB, MOBI, AZW3, FB2 und CBZ ab, inklusive Paginierung, Fixed Layout, Suche und Fortschrittsberechnung. Wird als Git-Submodule bzw. als vendored Kopie mit fester Commit-Version eingebunden, weil es kein offizielles npm-Paket gibt.
- **CBR (RAR):** foliate-js kann kein RAR. Dafür kommt `libarchive.js` (WASM) dazu. Die entpackten Bilder werden über einen eigenen Loader an `comic-book.js` von foliate-js übergeben.
- Drag & Drop im Editor mit `vue-draggable-plus` (SortableJS), ZIP-Erzeugung im Browser (nur für CBR→CBZ) mit `fflate`

## 3. Architektur

```
┌──────────────────────── Browser ─────────────────────────┐
│  Files-App ──(Viewer-Handler)──┐                         │
│                                ▼                         │
│  Bibliothek (Vue) ──► Reader (foliate-js) / Editor (Vue)  │
│        │                       │   ▲                     │
│        │ OCS-API               │   │ Buchdatei via WebDAV │
└────────┼───────────────────────┼───┼─────────────────────┘
         ▼                       ▼   │
┌──────────────────────── Server ──────────────────────────┐
│  Books-/Progress-/Settings-/EditorController             │
│        │                                                 │
│  LibraryService ◄── Scanner (Events, BackgroundJob, occ)  │
│        │                                                 │
│  Metadata-Extraktoren   Book-Editoren (EPUB|CBZ|FB2)      │
│        │                                                 │
│  DB: books, book_tags, progress                          │
│  AppData: Cover-Thumbnails                               │
└──────────────────────────────────────────────────────────┘
```

Die Buchdateien werden **direkt über WebDAV** geladen (`/remote.php/dav/files/{user}/{pfad}`). Die App braucht also keinen eigenen Download-Endpunkt, und Berechtigungen, Freigaben und Verschlüsselung funktionieren automatisch.

## 4. Datenmodell

**`ebookreader_books`**: Bibliotheks-Index pro Benutzer
| Spalte | Typ | Hinweis |
|---|---|---|
| id | bigint PK | |
| user_id | string(64) | Index |
| file_id | bigint | unique (user_id, file_id) |
| format | string(8) | epub, mobi, azw3, fb2, cbz, cbr |
| title, author, series | string, nullable | Mehrere Autoren durch `; ` getrennt |
| series_index | float, nullable | |
| description | text, nullable | Bereinigtes HTML (Sanitizer, siehe 6.7) |
| language, publisher, isbn | string, nullable | |
| published_at | string(10), nullable | ISO-Datum, auch nur Jahr möglich |
| rating | smallint, nullable | 0–5, **nur App** |
| read_status | string(12) | `unread` / `reading` / `finished`, **nur App**, wird aus dem Fortschritt abgeleitet und kann manuell überschrieben werden |
| has_cover | bool | Cover liegt in AppData |
| file_mtime, file_etag | int / string | Zum Erkennen von Änderungen und für Bearbeitungskonflikte |
| added_at, updated_at | int | `updated_at` treibt die Delta-Synchronisation (`?since=`) |
| deleted_at | int, nullable | **Soft Delete (Tombstone).** Gelöschte Bücher bleiben 30 Tage als Löschmarkierung stehen, damit Offline-Clients die Löschung beim nächsten Sync mitbekommen. Danach räumt der Hintergrund-Job sie auf. |

**`ebookreader_book_tags`**: Genres und Tags (n:m, einfach gehalten)
| Spalte | Typ | Hinweis |
|---|---|---|
| id | bigint PK | |
| book_id | bigint | FK → books.id, Index |
| type | string(8) | `genre` oder `tag` |
| name | string(128) | Index (type, name) für Filter und Tag-Wolke |
| source | string(8) | `file` (steht in der Datei) oder `app` (nur in der DB, z. B. bei MOBI) |

**`ebookreader_progress`**: Lesefortschritt
| Spalte | Typ | Hinweis |
|---|---|---|
| id | bigint PK | |
| user_id | string(64) | |
| file_id | bigint | unique (user_id, file_id) |
| locator | text (JSON) | **Plattformneutraler Locator**, angelehnt an das [Readium-Locator-Modell](https://readium.org/architecture/models/locators/): `{ "href": "chapter3.xhtml", "progression": 0.42, "totalProgression": 0.37, "cfi": "epubcfi(...)", "position": 57 }`. `cfi` ist optional (Web/foliate-js), `href` + `progression` versteht jeder Reader. Bei Comics: `position` = Seitennummer. |
| percentage | float | 0–1, redundant zu `totalProgression`, für schnelle Sortierung/Anzeige |
| device | string(64), nullable | Gerätename, für „zuletzt gelesen auf Pixel 8“ |
| client_updated_at | int | Zeitpunkt der Änderung **auf dem Gerät**. Wichtig für Offline-Lesen: Konflikte werden über diesen Zeitstempel entschieden, nicht über den Eingang beim Server (siehe 6.8) |
| updated_at | int | Serverzeit, für die Delta-Synchronisation |

**User-Settings** (per `IConfig` user values): Bibliotheksordner (Standard `/Books`, mehrere möglich), Reader-Einstellungen (Schriftgröße, Schriftart, Zeilenhöhe, Theme hell/sepia/dunkel, paginiert/scrollend, Comic Einzel-/Doppelseite, Leserichtung RTL), Muster für Dateinamen (Standard `{author} - {title}`).

## 5. API (OCS, `/ocs/v2.php/apps/ebookreader/api/v1/...`)

**Grundsätze, damit auch eine Android-App die API nutzen kann:**
- Die Web-UI nutzt **ausschließlich** diese API. Es gibt keine versteckten, nur für die Web-UI gedachten Endpunkte.
- Alle Endpunkte sind OCS-Controller mit `#[NoAdminRequired]`, funktionieren per **App-Passwort / Basic Auth** (Header `OCS-APIRequest: true`) und verlangen kein Session-Cookie und kein CSRF-Token.
- Die **OpenAPI-Spezifikation** wird mit `nextcloud/openapi-extractor` automatisch aus den PHP-Attributen und Psalm-Typen erzeugt (`openapi.json` im Repo, CI prüft, ob sie aktuell ist). Daraus lassen sich TypeScript- und später Kotlin-Clients generieren.
- **Versionierung:** Das Pfad-Präfix `v1` bleibt stabil. Breaking Changes kommen nur mit `v2`, beide Versionen laufen dann eine Zeit lang parallel.
- **Capabilities:** Über `ICapability` meldet die App unter `/ocs/v2.php/cloud/capabilities` ihre Version und Features (z. B. `editor`, `formats`, `apiVersion`). Ein Mobil-Client weiß so, was der Server kann.
- Listen haben **Cursor-/Offset-Paginierung**, Detailantworten liefern einen **ETag** (`If-None-Match` → 304), damit mobile Clients Daten sparen.
- Zeitstempel sind UNIX-Sekunden (UTC), IDs sind `fileId`s. Die bleiben beim Umbenennen oder Verschieben stabil.

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/books?search=&format=&genre=&tag=&author=&series=&status=&sort=&limit=&offset=` | Bibliothek auflisten und filtern |
| GET | `/sync?since={timestamp}` | **Delta-Sync:** alle seit `since` geänderten Bücher, Tags, Fortschritte und Löschmarkierungen in einer Antwort, plus neuer `since`-Wert. Für Offline-Clients (Android) |
| GET | `/books/{fileId}` | Metadaten, Genres/Tags, WebDAV-Pfad, Fortschritt |
| PATCH | `/books/{fileId}/app-data` | Nur-App-Felder ändern (Bewertung, Lesestatus), ohne die Datei anzufassen |
| GET | `/books/{fileId}/cover?size=small\|large` | Cover-Bild in zwei Größen (Raster/Detail), mit ETag und Cache-Headern |
| POST | `/books/{fileId}/cover` | Vom Client erzeugtes Cover hochladen (Fallback für CBR) |
| GET | `/tags?type=genre\|tag` | Alle Genres/Tags mit Anzahl (Navigation, Autovervollständigung) |
| GET | `/books/{fileId}/structure` | Editor: Metadaten, Kapitel/Seiten-Liste, Inhaltsverzeichnis-Baum, etag, Schreibrecht |
| PUT | `/books/{fileId}/structure` | Editor: Änderungen speichern (siehe 6.7) |
| POST | `/books/{fileId}/rename` | Datei umbenennen (frei oder nach Muster) |
| GET / PUT | `/progress/{fileId}` | Fortschritt lesen/schreiben. Der PUT enthält `client_updated_at`. Ist der Serverstand neuer, antwortet der Server mit **409 + aktuellem Stand**, der Client kann dann „Zur neueren Position springen?“ anbieten |
| POST | `/progress/batch` | Mehrere Fortschritte auf einmal hochladen (Offline-Warteschlange einer Mobil-App) |
| GET | `/progress/recent` | Zuletzt gelesene Bücher („Weiterlesen“) |
| GET / PUT | `/settings` | User-Einstellungen |
| POST | `/scan` | Bibliothek neu einlesen |

Jeder Zugriff wird über `IRootFolder->getUserFolder($uid)->getById($fileId)` geprüft. Wenn der Benutzer die Datei nicht sehen darf, gibt es 404. Schreibende Editor-Aufrufe setzen zusätzlich `Node::isUpdateable()` voraus.

## 6. Kernkomponenten im Detail

### 6.1 Viewer-Integration
- `OCA.Viewer.registerHandler` für die MIME-Typen der unterstützten Formate. Ein Klick in Files öffnet den Reader im Viewer-Modal.
- Zusätzlich gibt es die eigene Route `/apps/ebookreader/read/{fileId}` für Vollbild und Deep-Links sowie `/apps/ebookreader/edit/{fileId}` für den Editor.
- Im Files-Kontextmenü gibt es die Aktion **„E-Book bearbeiten“** (File Action über `@nextcloud/files`).
- **CSP:** foliate-js rendert in `blob:`-iframes. Über einen Listener auf `AddContentSecurityPolicyEvent` wird `frame-src blob:` (plus `worker-src` für libarchive.js-WASM) freigegeben. Skripte in EPUBs bleiben per iframe-Sandbox deaktiviert.

### 6.2 MIME-Typen
Nextcloud kennt `application/epub+zip`. Die anderen Formate werden über einen **Repair-Step** registriert: Eintrag in `config/mimetypemapping.json`, danach wird `maintenance:mimetype:update-db` ausgeführt.
- `mobi` → `application/x-mobipocket-ebook`
- `azw3` → `application/vnd.amazon.mobi8-ebook`
- `fb2` → `application/x-fictionbook+xml`
- `cbz` → `application/vnd.comicbook+zip`
- `cbr` → `application/vnd.comicbook-rar`

### 6.3 Metadaten-, Genre- und Cover-Extraktion (PHP)
| Format | Metadaten | Genres / Tags | Cover |
|---|---|---|---|
| EPUB | OPF: `dc:title`, `dc:creator`, `dc:description`, `dc:language`, `dc:publisher`, `dc:date`, `dc:identifier` (ISBN), Serie über `calibre:series` bzw. EPUB3 `belongs-to-collection` | `dc:subject` (siehe Zuordnung unten) | Manifest `properties="cover-image"` bzw. `<meta name="cover">` |
| MOBI/AZW3 | PDB-Header + EXTH-Records (100 Autor, 101 Verlag, 103 Beschreibung, 104 ISBN, 106 Datum, 503 Titel, 524 Sprache) | EXTH 105 (Subject) → Tags | EXTH 201 Cover-Offset |
| FB2 | `<title-info>`: `book-title`, `author`, `annotation`, `sequence`, `lang`; `<publish-info>` | `<genre>` → Genres, `<keywords>` → Tags | Base64-`<binary>` aus `<coverpage>` |
| CBZ | `ComicInfo.xml`: Title, Series, Number, Summary, Writer, Publisher, Year/Month/Day, LanguageISO | `Genre` → Genres, `Tags` → Tags (kommagetrennt) | Erstes Bild bzw. `FrontCover` aus ComicInfo |
| CBR | Nur Dateiname (PHP hat meist keine RAR-Extension) | – | Der Client erzeugt es beim ersten Öffnen und schickt es per `POST /cover` |

**Genre vs. Tag in EPUB:** EPUB kennt nur `dc:subject`. Genres und Tags werden deshalb beide als `dc:subject` geschrieben, damit andere Reader (Calibre, KOReader, Apple Books) sie ebenfalls sehen. Die Unterscheidung merkt sich die DB. Beim erneuten Einlesen gilt:
1. Ein Wert, der in der DB schon als Genre bekannt ist, bleibt Genre.
2. Ein Wert, der in einer mitgelieferten Genre-Liste steht (z. B. Fantasy, Krimi, Sci-Fi, Sachbuch …, anpassbar), wird Genre.
3. Alles andere wird Tag.

Cover werden auf ca. 400 px Breite skaliert und in `IAppData` gespeichert. Optional kommt ein `IProviderV2`-Preview-Provider dazu, damit Files ebenfalls Cover-Thumbnails zeigt.

### 6.4 Scanner
- **Event-Listener** auf `NodeCreatedEvent`, `NodeWrittenEvent`, `NodeDeletedEvent`, `NodeRenamedEvent`: Der Index wird inkrementell gepflegt, wenn eine Datei im Bibliotheksordner liegt.
- **`TimedJob`** (z. B. alle 6 h) als Absicherung für Änderungen, die an den Events vorbeigehen (externe Speicher, `files:scan`).
- **occ-Command** `ebookreader:scan [--all | <user>]`.
- Scan-Button in der Bibliothek.
- Beim erneuten Einlesen werden App-Felder (Bewertung, Lesestatus) und Tags mit `source=app` **nicht** überschrieben.

### 6.5 Reader (Frontend)
- Layout: Vollbild-Reader mit Kopfleiste (Titel, Inhaltsverzeichnis, Einstellungen, Bearbeiten, Schließen) und Fortschrittsleiste mit Prozent bzw. Seite x/y.
- Navigation per Tastatur (←/→, Bild↑/↓), Klick-/Tippzonen und Swipe.
- Inhaltsverzeichnis in der Seitenleiste (`NcAppSidebar`/Drawer) und Volltextsuche über `search.js`.
- Comics: Einzel- oder Doppelseite, Leserichtung RTL für Manga, Zoom.
- **Sync:** Beim Öffnen wird der Serverstand geladen. Die Position wird als plattformneutraler Locator gespeichert (`href` + `progression` + optional `cfi`, siehe Datenmodell), damit eine spätere Android-App sie versteht. Positionswechsel werden nach 2 s Pause per PUT gespeichert. Bei `visibilitychange`/`pagehide` wird zusätzlich per `navigator.sendBeacon` bzw. `fetch keepalive` gespeichert.

### 6.6 Bibliothek (Frontend)
- `NcContent` + `NcAppNavigation` + `NcAppContent`.
- **Navigation:** Alle, Weiterlesen, Ungelesen, Gelesen, dann **Genres** (Liste mit Anzahl), **Tags** (Liste oder Tag-Wolke), Autoren, Serien, Formate.
- Cover-Raster mit Lazy Loading, Fortschrittsbalken und Genre-Chips pro Buch, alternativ eine Listenansicht mit Spalten.
- Suche über Titel, Autor, Serie, Beschreibung und Tags. Filter lassen sich kombinieren (z. B. Genre „Fantasy“ + Tag „Lieblingsbuch“ + ungelesen). Sortierung nach Titel, Autor, Serie/Band, Bewertung, zuletzt hinzugefügt, zuletzt gelesen.
- **Detailansicht** (Seitenleiste beim Klick auf ein Buch): Cover, alle Metadaten, Beschreibung, Genres/Tags, Bewertung (Sterne, direkt änderbar), Lesestatus, Buttons „Lesen“ und „Bearbeiten“.
- **Mehrfachauswahl:** Genres/Tags für mehrere Bücher auf einmal setzen oder entfernen.
- Einstellungsdialog: Bibliotheksordner über den Nextcloud-FilePicker (`@nextcloud/dialogs`), eigene Genre-Liste und Dateinamen-Muster.

### 6.7 Editor

Die Bearbeitung passiert **serverseitig in PHP**. Große Dateien müssen dadurch nicht neu hochgeladen werden, und die Logik ist mit PHPUnit testbar. Die einzige Ausnahme ist CBR (siehe unten).

**Was bei welchem Format geht:**
| Funktion | EPUB | CBZ | CBR | FB2 | MOBI/AZW3 |
|---|---|---|---|---|---|
| Metadaten inkl. Beschreibung, Genres, Tags | ✅ OPF | ✅ ComicInfo.xml (wird angelegt, falls sie fehlt) | ✅ nach Umwandlung in CBZ | ✅ `<description>` | ⚠️ nur in der DB (`source=app`), die Datei bleibt unverändert |
| Cover tauschen | ✅ | ✅ (neues Bild wird Seite 1 oder `FrontCover`) | ✅ nach Umwandlung | ✅ | ❌ |
| Seiten/Kapitel entfernen | ✅ Kapitel (Spine-Einträge) | ✅ Seiten (Bilder) | ✅ nach Umwandlung | ✅ `<section>`s | ❌ |
| Seiten/Kapitel umsortieren | ✅ | ✅ | ✅ nach Umwandlung | ✅ | ❌ |
| Inhaltsverzeichnis umbenennen/umsortieren/verschachteln | ✅ `nav.xhtml` **und** `toc.ncx` | ✅ als „Kapitelmarken“ in ComicInfo `<Pages>` (Bookmark) | ✅ nach Umwandlung | ✅ `<section><title>` | ❌ |
| Datei umbenennen | ✅ | ✅ | ✅ | ✅ | ✅ |

MOBI/AZW3 in eine Datei zurückzuschreiben ist fehleranfällig (Binärformat mit Offsets). In v1 bleibt die Datei deshalb unverändert, Metadaten lassen sich aber in der App pflegen. Ein Konverter „MOBI → EPUB“ ist als spätere Erweiterung vorgesehen.

**Bei EPUB „Seiten“ = Kapitel/Abschnitte:** Reflowable EPUBs haben keine festen Seiten. Entfernt und umsortiert werden die Spine-Einträge, also meist Kapitel oder Kapiteldateien. Bei Fixed-Layout-EPUBs entspricht ein Spine-Eintrag einer Seite.

**UI (eigene Route `/edit/{fileId}`), drei Tabs:**
1. **Metadaten:** Formular mit Titel, Autoren (Mehrfacheingabe), Serie + Band, Sprache, Verlag, Datum, ISBN, Beschreibung (einfacher Rich-Text-Editor mit Fett/Kursiv/Absatz/Liste), **Genres und Tags** (`NcSelect` mit Autovervollständigung aus `/tags`, neue Werte können frei angelegt werden), Cover-Upload bzw. Auswahl einer Seite als Cover.
2. **Inhalt:** Liste (EPUB/FB2) bzw. Thumbnail-Raster (Comics) aller Kapitel/Seiten. Einträge lassen sich per Drag & Drop umsortieren, mit Mehrfachauswahl löschen und in einer Vorschau ansehen. Die Vorschau rendert im Browser über das bereits geladene foliate-js-Buch.
3. **Inhaltsverzeichnis:** Baum mit Drag & Drop (auch Ein- und Ausrücken), Titel direkt umbenennen, Einträge hinzufügen (Sprungziel = Kapitel/Seite) und entfernen.

Kopfleiste: „Speichern“, „Als Kopie speichern“ und „Verwerfen“. Vor dem Speichern zeigt eine Zusammenfassung die Änderungen an (z. B. „3 Kapitel entfernt, Titel geändert“).

**Speicherablauf (Server):**
1. Der Client schickt das gesamte Ziel-Dokument: Metadaten, neue Reihenfolge der Kapitel-/Seiten-IDs, entfernte IDs, TOC-Baum, `etag` und `saveAsCopy`.
2. Der Server vergleicht den `etag`. Hat sich die Datei inzwischen geändert, gibt es **409 Conflict** und der Client lädt neu.
3. Die Datei wird über `ILockingProvider` gesperrt.
4. Der Editor für das Format baut eine **neue Datei in einer temporären Datei** auf. Die Originaldatei wird nicht an Ort und Stelle verändert.
   - EPUB: `mimetype` wird als erster Eintrag unkomprimiert geschrieben. OPF (Metadaten, Manifest, Spine), `nav.xhtml` und `toc.ncx` werden aktualisiert. Dateien entfernter Kapitel fliegen raus, ebenso TOC-Einträge, die darauf zeigen. Interne Links auf entfernte Kapitel werden erkannt, und der Nutzer bekommt eine Warnung.
   - CBZ: Die Bilder werden in neuer Reihenfolge mit fortlaufenden Namen (`0001.jpg` …) geschrieben, `ComicInfo.xml` wird aktualisiert (PageCount, Pages).
   - FB2: DOM-Manipulation, anschließend Validierung, ob das Ergebnis wohlgeformt ist.
5. Das Ergebnis wird geprüft: Das neue Archiv muss sich wieder öffnen und parsen lassen. Schlägt das fehl, wird abgebrochen und das Original bleibt unverändert.
6. Die temporäre Datei wird per `File::putContent()` (Stream) geschrieben. **Nextcloud legt dabei automatisch eine Version an** (files_versions), das Original lässt sich also jederzeit wiederherstellen. Bei „Als Kopie“ wird eine neue Datei `Titel (bearbeitet).epub` angelegt.
7. Metadaten, Tags und Cover werden neu indexiert. Der **Lesefortschritt** wird angepasst:
   - Comics: Der Seitenindex wird auf die neue Reihenfolge umgerechnet.
   - EPUB: Die CFI wird auf den neuen Spine-Index umgeschrieben, wenn das Kapitel noch existiert. Andernfalls springt die Position auf den Anfang des nächsten Kapitels.

**Sicherheit:**
- Die Beschreibung wird mit einem HTML-Sanitizer (Allowlist: p, br, b, i, em, strong, ul, ol, li) bereinigt, bevor sie in Datei und DB landet.
- Alle XML-Eingaben werden mit `LIBXML_NONET` und ohne externe Entities geparst (XXE-Schutz).
- Beim Lesen von ZIP-Einträgen wird gegen Zip-Slip geprüft (`../`) und es gibt Größenlimits gegen Zip-Bomben.
- Schreiben ist nur mit Update-Recht erlaubt. Bei schreibgeschützten Freigaben geht nur „Als Kopie in meinen Ordner speichern“.

**CBR:** Der Server kann kein RAR schreiben. Beim Öffnen im Editor fragt die App: *„CBR kann nicht direkt bearbeitet werden. In CBZ umwandeln?“*. Die Umwandlung passiert im Browser: `libarchive.js` entpackt, `fflate` packt neu, und das Ergebnis wird per WebDAV als `.cbz` hochgeladen. Optional wird die CBR-Datei danach gelöscht. Anschließend läuft der normale CBZ-Editor.

**Umbenennen:** Das Umbenennen geht frei oder über „Aus Metadaten erzeugen“ mit dem Muster aus den Einstellungen, z. B. `{author} - {series} {series_index} - {title}`. Umgesetzt wird es mit `Node::move()` im selben Ordner. Ungültige Zeichen werden ersetzt, bei Namenskollisionen hängt die App ` (2)` an.

### 6.8 Vorbereitung für eine spätere Android-App

Die Android-App selbst ist **nicht** Teil dieses Projekts. Die Architektur wird aber von Anfang an so gebaut, dass sie später ohne Umbau am Server möglich ist.

**Was schon jetzt im Server und in der Web-App umgesetzt wird:**
| Maßnahme | Warum |
|---|---|
| API-first + OpenAPI-Spezifikation (Abschnitt 5) | Die Android-App bekommt dieselbe API wie die Web-UI und einen generierten Kotlin-Client (`openapi-generator`) |
| Auth nur über Standard-Nextcloud-Mechanismen | Die Android-App kann sich per **Login Flow v2** (App-Passwort) anmelden oder über **Nextcloud SSO** das Konto der installierten Nextcloud-Files-App mitnutzen, ohne eigenes Login-System |
| Plattformneutraler Locator (Readium-Modell) | Die Leseposition ist zwischen Web (foliate-js) und Android austauschbar, egal ob dort foliate-js oder Readium rendert |
| Delta-Sync (`/sync?since=`), Tombstones, `client_updated_at`, `/progress/batch` | Offline lesen im Zug oder Flugzeug, danach sauber synchronisieren, ohne dass neuere Positionen überschrieben werden |
| Bücher über WebDAV mit HTTP-Range-Requests | Die App kann Bücher für offline herunterladen, unterbrochene Downloads fortsetzen und per ETag erkennen, ob sich ein Buch geändert hat (z. B. nach Bearbeitung im Editor) |
| Capabilities-Eintrag | Die App erkennt Serverversion und Features und kann Funktionen passend ein- oder ausblenden |
| Reader-Kern als eigenes, framework-unabhängiges Paket (`packages/reader-core`) | foliate-js-Anbindung, Locator-Umrechnung, Fortschrittslogik und Theme-Einstellungen sind reines TypeScript ohne Vue und ohne Nextcloud-Abhängigkeit. So lassen sie sich 1:1 in einer Android-WebView wiederverwenden |
| Cover in zwei Größen + ETag | Schnelle, datensparende Bibliotheksansicht auf dem Handy |

**Empfohlener Weg für die spätere Android-App:**
- **Native Kotlin-App (Jetpack Compose)** für Bibliothek, Einstellungen, Downloads, Offline-DB (Room) und Sync-Worker (WorkManager).
- **Rendering im WebView mit `reader-core` + foliate-js.** Damit sind alle 6 Formate sofort da (inkl. MOBI, FB2, CBR), und das Leseverhalten und die Locators sind identisch zur Web-Version.
- Alternative: **Readium Kotlin Toolkit** für ein „nativeres“ Lesegefühl. Das unterstützt allerdings nur EPUB und Comics, kein MOBI/AZW3/FB2. Deshalb gilt: WebView als Standard, Readium nur als Option.
- Der Editor bleibt zunächst in der Web-App. Die API dafür existiert aber, eine mobile Bearbeitung (Metadaten, Tags) lässt sich also später nachrüsten.

## 7. Projektstruktur

Das Repo wird ein **Monorepo**. Die Nextcloud-App liegt im Root, damit Nextcloud-Tooling (occ, App Store, Docker-Mount) unverändert funktioniert. Wiederverwendbare Pakete liegen unter `packages/`, ein späteres `android/` kommt daneben.

```
ebookreader/
├── packages/
│   ├── reader-core/             (TS, ohne Vue/Nextcloud: foliate-Adapter, Locator, Progress, CBR-Loader, Themes)
│   └── api-client/              (aus openapi.json generierter TS-Client)
├── (android/)                   (später: Kotlin-App)
├── openapi.json                 (generiert, in CI geprüft)
├── appinfo/
│   ├── info.xml
│   └── routes.php
├── lib/
│   ├── AppInfo/Application.php
│   ├── Controller/ (PageController, BooksController, TagsController, ProgressController, SyncController, SettingsController, EditorController)
│   ├── Db/ (Book, BookMapper, BookTag, BookTagMapper, Progress, ProgressMapper)
│   ├── Service/ (LibraryService, ScannerService, CoverService, TagService, SettingsService, EditorService, RenameService, ProgressRemapper)
│   ├── Metadata/ (ExtractorInterface, EpubExtractor, MobiExtractor, Fb2Extractor, CbzExtractor, FilenameExtractor, GenreClassifier)
│   ├── Editor/ (BookEditorInterface, EditRequest, EpubEditor, CbzEditor, Fb2Editor, HtmlSanitizer, Zip/SafeZipReader)
│   ├── Capabilities.php
│   ├── Listener/ (FileEventListener, CspListener, LoadViewerListener)
│   ├── BackgroundJob/RescanJob.php
│   ├── Command/Scan.php
│   ├── Migration/ (Version1000Date…, RegisterMimeTypes)
│   └── Preview/EbookCoverProvider.php
├── src/
│   ├── main-library.ts, main-viewer.ts, main-files.ts (File Action „Bearbeiten“)
│   ├── views/ (LibraryView.vue, ReaderView.vue, EditorView.vue)
│   ├── components/
│   │   ├── library/ (BookGrid, BookCard, BookDetails, TagCloud, BulkTagDialog, …)
│   │   ├── reader/ (ReaderToolbar, TocPanel, ReaderSettings, …)
│   │   └── editor/ (MetadataForm, TagInput, CoverPicker, ContentList, PageGrid, TocTreeEditor, ChangeSummary)
│   ├── services/ (progressSync.ts, nutzt packages/api-client)
│   └── editor/ (cbrToCbz.ts)
├── vendor-js/foliate-js/        (Submodule, gepinnter Commit)
├── resources/genres.json        (Standard-Genre-Liste de/en)
├── templates/main.php
├── l10n/ (de, en)
├── tests/ (Unit/, Integration/, fixtures/)
├── docker/docker-compose.yml
├── composer.json, package.json, vite.config.ts, Makefile
└── README.md
```

## 8. Entwicklungsumgebung (Docker)

- `docker-compose.yml`: `nextcloud:34-apache` + MariaDB + Redis. Das App-Verzeichnis wird nach `/var/www/html/custom_apps/ebookreader` gemountet.
- Nach dem ersten Start: `occ app:enable ebookreader`, Debug-Modus an, Testbücher aus `tests/fixtures` in den `/Books`-Ordner des Admins kopieren.
- Testbücher: gemeinfreie Titel (Project Gutenberg, Standard Ebooks) in allen Formaten, dazu ein selbst erzeugtes CBZ/CBR.
- Lokal nötig: Docker Desktop, Node ≥ 22, Composer (oder Composer im Container).
- `npm run watch` baut das Frontend ins gemountete `js/`-Verzeichnis und aktualisiert es live.
- Optional: `epubcheck` als Docker-Image, um vom Editor erzeugte EPUBs zu validieren.

## 9. Umsetzungsphasen

| # | Phase | Ergebnis / Abnahme |
|---|---|---|
| 0 | **Grundgerüst & Docker** | Monorepo mit npm-Workspaces, App-Skeleton, Build-Pipeline, Docker-Setup, OpenAPI-Extractor eingerichtet. Die App erscheint aktiviert in der Navigation. |
| 1 | **EPUB-Reader** | `packages/reader-core` mit foliate-js, eigene Reader-Route, Viewer-Handler, CSP-Listener. Ein EPUB lässt sich aus Files öffnen und lesen. |
| 2 | **Weitere Formate** | MIME-Repair-Step, MOBI/AZW3/FB2/CBZ, danach CBR über libarchive.js. Alle 6 Formate öffnen. |
| 3 | **Fortschritts-Sync** | Tabelle mit Locator-JSON und `client_updated_at`, Migration, API inkl. 409-Konfliktantwort und `/progress/batch`, Debounce und Beacon im Frontend. Buch auf Gerät A lesen und auf Gerät B an derselben Stelle weiterlesen. Die API funktioniert per `curl` mit App-Passwort. |
| 4 | **Bibliotheks-Backend** | Books- und Tags-Tabelle (inkl. Tombstones), Extraktoren inkl. Genres/Tags, GenreClassifier, Cover in zwei Größen, Scanner (Events, Job, occ), API, `/sync?since=`, Capabilities. `occ ebookreader:scan` füllt den Index inkl. Genres/Tags korrekt, `openapi.json` ist vollständig. |
| 5 | **Bibliotheks-UI** | Raster, Navigation mit Genres/Tags, kombinierbare Filter, Suche/Sortierung, Detailansicht mit Bewertung und Lesestatus, Mehrfach-Tagging, Ordnerauswahl. |
| 6 | **Editor I: Metadaten** | Editor-Grundgerüst, `structure`-API, Metadaten, Genres, Tags und Cover für EPUB/CBZ/FB2, App-Metadaten für MOBI, Umbenennen, etag-Konflikt, Versionierung, Als Kopie speichern. |
| 7 | **Editor II: Struktur** | Kapitel/Seiten entfernen und umsortieren, TOC-Editor, Link-Warnungen, Anpassung des Lesefortschritts, CBR→CBZ-Umwandlung. Bearbeitete EPUBs bestehen `epubcheck` ohne neue Fehler. |
| 8 | **Feinschliff** | Reader-Einstellungen (Theme, Schrift …), Preview-Provider, l10n de/en, Barrierefreiheit, Dark Mode. |
| 9 | **Tests & Paketierung** | PHPUnit (Extraktoren, Editoren mit Roundtrip-Tests, Mapper, Sanitizer), Vitest (Sync-Logik, Editor-State), Lint (php-cs-fixer, psalm, eslint). `make appstore` erzeugt einen installierbaren Tarball. |

| *10* | *Android-App (eigenes Folgeprojekt)* | *Kotlin/Compose-App mit Login Flow v2/SSO, Offline-Bibliothek, `reader-core` im WebView, Sync-Worker. Setzt nur auf der bestehenden API auf.* |

Jede Phase ist für sich lauffähig und kann einzeln getestet und abgenommen werden. Die Tests für Extraktoren und Editoren entstehen jeweils mit der Phase selbst, nicht erst in Phase 9.

## 10. Risiken & offene Punkte

- **CSP im Viewer-Modal:** Wenn der CSP-Listener auf der Files-Seite nicht greift, öffnet der Viewer-Handler stattdessen die eigene Reader-Route. Das wird in Phase 1 früh geprüft.
- **MIME-Registrierung** ändert `config/mimetypemapping.json`. Das braucht Schreibrechte auf `config/`, bei Docker ist das gegeben.
- **CBR-Metadaten** gibt es serverseitig nur eingeschränkt, siehe 6.3. Bearbeiten geht nur nach Umwandlung in CBZ.
- **EPUB-Vielfalt:** Kaputte oder ungewöhnliche EPUBs (fehlende NCX, falsche Pfade, EPUB 2 und 3 gemischt) kommen häufig vor. Der Editor muss tolerant lesen und sauber schreiben. Dagegen helfen eine breite Fixture-Sammlung und der Validierungsschritt vor dem Überschreiben.
- **Entfernte Kapitel mit Querverweisen** hinterlassen tote Links. Der Editor warnt davor, repariert sie aber nicht automatisch.
- **MOBI/AZW3** sind in v1 nicht als Datei bearbeitbar. Nur die App-Metadaten lassen sich ändern.
- **Große Dateien:** Große Comics (> 200 MB) werden im Reader per WebDAV komplett geladen, und das Neuschreiben im Editor braucht temporären Speicherplatz auf dem Server. Deshalb gibt es ein konfigurierbares Größenlimit.
- **DRM-geschützte Dateien** (Kindle, Adobe) werden nicht unterstützt. Reader und Editor zeigen dann eine klare Fehlermeldung.
- **API-Stabilität:** Sobald eine Android-App existiert, sind Änderungen an `v1` teuer. Deshalb wird die API bis zur Android-Phase als „beta“ markiert (Capability `apiStable: false`) und danach eingefroren.
- **Locator-Kompatibilität:** foliate-js arbeitet primär mit CFI, Readium mit `href` + `progression`. `reader-core` muss beides zuverlässig ineinander umrechnen. Dafür braucht es eigene Tests mit echten Büchern.
- **Namenskonflikt:** Im App Store gibt es schon „epubreader“/„files_reader“. Vor einer Veröffentlichung sollte die App-ID geprüft werden.

## 11. Anmerkungen zur Umsetzbarkeit (Kurz-Review)

**Gesamturteil:** Umsetzbar. Die harten Stellen sind (1) foliate-js sicher im Viewer-Modal, (2) das Neuschreiben von EPUBs mit CFI-Umrechnung, (3) der Offline-Sync. Alles andere ist Standard-Nextcloud-Handwerk. Grobe Schätzung für einen Entwickler mit KI-Unterstützung: Phasen 0–5 ca. 20–25 Tage, Editor (6–7) ca. 12–15 Tage, Feinschliff/Tests (8–9) ca. 8–10 Tage.

**Korrekturen am Plan (beim Umsetzen beachten):**
- **Kein `sendBeacon` für den Fortschritt.** `sendBeacon` kann keine Header setzen, OCS braucht aber `OCS-APIRequest: true` und das CSRF-Token. Stattdessen `fetch(..., { keepalive: true })` mit Headern verwenden (6.5).
- **Cover nicht über OCS ausliefern.** OCS verpackt Antworten in JSON. Der Cover-Endpunkt wird ein normaler Controller mit `#[NoAdminRequired]`, `#[NoCSRFRequired]` und `FileDisplayResponse` (Abschnitt 5).
- **FB2-Genres sind ein festes Vokabular** (Codes wie `sf_fantasy`, `detective`), kein Freitext. Beim Schreiben: App-Genres auf FB2-Codes abbilden, alles Übrige in `<keywords>`. Außerdem `.fb2.zip` als Format aufnehmen (6.3/6.7).
- **EPUB-Beschreibung:** `dc:description` ist laut Spec Klartext. HTML nur bereinigt und escaped schreiben, in der DB HTML halten. Beim Neuschreiben `META-INF/encryption.xml` (obfuskierte Fonts) und `META-INF/*` unverändert übernehmen, verwaiste Manifest-Einträge (Bilder/CSS entfernter Kapitel) aufräumen, sonst meckert epubcheck.
- **Locator exakt nach Readium** aufbauen: `{ href, type, title, locations: { progression, totalProgression, position, partialCfi } }` statt flacher Struktur, damit ein Kotlin-Readium-Client ihn direkt versteht (Abschnitt 4).
- **Delta-Sync nicht nur über `updated_at` in Sekunden.** Race bei gleicher Sekunde. Cursor als `(updated_at, id)` oder eine fortlaufende `change_seq`-Spalte, die jede Änderung (auch Tags, App-Felder) hochzählt.
- **Geteilte Dateien:** Eine `fileId` hat viele Nutzer. Nach einer Bearbeitung müssen Index und Fortschritts-Umrechnung für **alle** Nutzer mit dieser `fileId` laufen, nicht nur für den Bearbeiter (6.7 Schritt 7).
- **Scanner skalieren:** Statt einem großen `TimedJob` pro Datei einen `QueuedJob` einreihen, sonst reißen große Bibliotheken `max_execution_time`/`memory_limit`.
- **Aufräumen:** Listener auf `UserDeletedEvent` (Zeilen + Cover in AppData löschen) und Repair-Step beim Deinstallieren ergänzen.
- **Public Links:** `getUserFolder()` gibt es für anonyme Nutzer nicht. v1: Reader nur für angemeldete Nutzer, öffentliche Freigaben zeigen den Standard-Download.

**Spikes zuerst (je 0,5–1 Tag, vor Phase 1 bzw. 6):**
1. foliate-js im Viewer-Modal: Prüfen, welche `sandbox`-Attribute foliate-js auf seinen iframes setzt. `allow-scripts` zusammen mit `allow-same-origin` auf `blob:`-iframes wäre eine XSS-Lücke über manipulierte EPUBs. Falls nötig foliate-js patchen (vendored Kopie) und zusätzlich eine CSP per `<meta>` in den Kapitel-Dokumenten setzen (blockiert auch externe Ressourcen = Datenschutz).
2. MIME-Registrierung: In NC 34 prüfen, ob es neben `mimetypemapping.json` + Repair-Step inzwischen eine App-API gibt (Vorbild: aktuelle Apps wie `files_pdfviewer`/`drawio`).
3. EPUB-Roundtrip: 5 verschiedene EPUBs (EPUB2, EPUB3, Fixed Layout, Calibre-Export, Standard Ebooks) einlesen → unverändert neu schreiben → epubcheck-Diff gegen Original muss leer sein. Erst dann Kapitel-Umsortierung bauen.

**Tooling ab Phase 0 (spart später viel Zeit):** GitHub Actions mit `php-cs-fixer` (nextcloud/coding-standard), `psalm` mit `nextcloud/ocp:dev-stable34`, PHPUnit, ESLint (`@nextcloud/eslint-config`), Vitest, openapi-extractor-Diff; occ-Befehl `ebookreader:inspect <datei>`, der extrahierte Metadaten als JSON ausgibt (schnelle Verifikation ohne UI); `make reset-docker`; Fixture-Korpus aus Standard Ebooks (EPUB3), Project Gutenberg (EPUB2, MOBI), einem FB2 und selbst erzeugten CBZ/CBR.

**Günstige Ergänzungen mit großem Nutzen (Empfehlung für v1.1):** OPDS-Feed (ca. 1–2 Tage) macht die Bibliothek sofort in KOReader, Moon+ und Librera nutzbar, lange bevor eine eigene Android-App existiert. KOReader-Sync (kosync, winzige API) passt direkt auf die Fortschritts-Tabelle. Dashboard-Widget „Weiterlesen“ (`IWidget`) und Unified-Search-Provider sind je ein halber Tag.

## 12. Offene Entscheidungen (mit empfohlenem Standard)

| Entscheidung | Empfehlung |
|---|---|
| App-ID | `ebookreader` verwenden, vor Veröffentlichung im App Store gegenprüfen |
| Bibliotheksordner | Mehrere Ordner erlauben, Standard `/Books` |
| `packages/reader-core` jetzt oder später | Jetzt als eigener Ordner mit klarer Import-Grenze, npm-Workspace erst mit dem Android-Start |
| foliate-js Einbindung | Vendored Kopie mit Update-Skript statt Submodule (einfacher für App-Store-Paket und Patches) |
| Lizenz | AGPL-3.0-or-later (Pflicht für den App Store) |
