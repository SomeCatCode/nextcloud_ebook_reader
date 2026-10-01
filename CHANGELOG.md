# Changelog

Alle nennenswerten Änderungen an dieser App werden hier dokumentiert.
Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionen folgen [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## 0.5.1 – 2026-10-01

### Behoben
- Nach einer Konvertierung im Browser (z. B. CBR ohne `7z` auf dem Server) blieb das Original liegen, und das neue Buch erschien erst Minuten später. Grund: Große Dateien werden im Hintergrund indexiert, der Dialog wartete aber nur wenige Sekunden. Jetzt übernimmt der Server den Abschluss sofort, unabhängig von der Dateigröße: Er indexiert das neue Buch, kopiert die Begleitdatei, übernimmt Bewertung, Lesestatus, App-Tags und Leseposition und löscht danach das Original, wenn gewünscht.
- Der Konvertieren-Dialog merkt sich, ob das Original gelöscht werden soll.

## 0.5.0 – 2026-10-01

### Hinzugefügt
- **Begleitdateien** (wie `.nfo` bei Kodi): Metadaten-Änderungen landen standardmäßig in einer kleinen versteckten Datei `.<Buchdatei>.opf` neben dem Buch (Calibre-kompatibles OPF 2.0 mit Titel, Autoren, Beschreibung, Sprache, Verlag, Datum, ISBN, Genres, Tags, Serie und Band). Das geht schnell, funktioniert für alle Formate inklusive MOBI, AZW3, CBR, CB7 und CBT und lässt die Buchdatei unangetastet.
- Neue Einstellung „Wo Metadaten-Änderungen gespeichert werden“ (Begleitdatei, in der Datei, beides, nur Bibliothek). „Im Hintergrund/sofort“ gilt nur noch für das Schreiben in die Buchdatei. Die frühere Einstellung „nie“ wird zu „Nur Bibliothek“.
- Rangfolge beim Einlesen: in der App geänderte Felder, dann Begleitdatei, dann eingebettete Metadaten, dann Dateiname. Eine von außen geänderte, neu angelegte oder gelöschte Begleitdatei löst das erneute Einlesen des Buchs aus.
- Umbenennen, Verschieben, Sortieren nach Muster, Konvertieren (Kopie, beim Löschen des Originals auch Löschen) und Löschen eines Buchs nehmen die Begleitdatei mit. Begleitdateien werden nie als Bücher indexiert.
- Aktion „Metadaten in die Buchdatei schreiben“ (Buchdetails, EPUB/CBZ/FB2/FBZ) und Endpunkt `POST /api/v1/books/{fileId}/metadata/embed`, bei großen Dateien als Hintergrundaufgabe. Damit sehen auch Reader wie Kobo oder KOReader die Metadaten, die nur eingebettete Werte lesen.
- Die Buchdetails zeigen „Metadaten in Begleitdatei gespeichert“, das Buch-JSON enthält `hasSidecar`.
- **Regale** (Backend): manuelle Regale und intelligente Regale (gespeicherter Filter) mit den Endpunkten `GET/POST /shelves`, `PATCH/DELETE /shelves/{id}`, `POST/DELETE /shelves/{id}/books` und `PUT /shelves/{id}/books/order`. Neuer Filterbegriff `shelf:<id>` und Sortierung `sort=shelf`. Zuordnungen verschwinden beim endgültigen Entfernen eines Buchs (Aufräumjob) und beim Löschen des Benutzers.
- **Serien** (Backend): Endpunkt `GET /series` (Name, Anzahl, gelesen, Cover, erstes Buch) und der Parameter `inSeries=0|1` für `GET /books`. `sort=series` sortiert innerhalb einer Serie nach Band.
- **Hierarchische Genres und Tags** (Backend): `tag:Fantasy/*` und `genre:Fantasy/*` treffen den Eintrag selbst und alles darunter (Groß-/Kleinschreibung egal, auch als Ausschluss). Leerzeichen um `/` werden beim Speichern entfernt, maximal 5 Ebenen.
- Neue Datenbanktabellen `ebookreader_shelves` und `ebookreader_shelf_books` (Migration `Version1004Date20261002090000`), Version 0.5.0.
- **Regale** (Oberfläche): Navigationsbereich „Regale“ mit manuellen und intelligenten Regalen (Anzahl, Filter-Symbol), Anlegen, Umbenennen, Löschen mit Bestätigung und Umsortieren per Auf/Ab. „Als intelligentes Regal speichern…“ in der Filterleiste, „Regal aktualisieren“, wenn der Filter eines intelligenten Regals geändert wurde. „Zu Regal hinzufügen…“ in den Buchdetails (Mehrfachauswahl, „Neues Regal“) und in der Auswahlleiste, dort auch „Aus Regal entfernen“. Ein manuelles Regal wird in Regalreihenfolge angezeigt.
- **Serien gruppieren**: Schalter in der Werkzeugleiste (wird im Browser gemerkt). Serienkarten mit gestapelten Covern, Bandanzahl und Lesefortschritt, darunter die Bücher ohne Serie. Ein Klick zeigt die Bände der Serie mit „Zurück zu den Serien“. Serienansicht, Regal und Filter stehen in der URL.
- **Hierarchie bei Genres und Tags**: Einträge mit `/` (z. B. `Fantasy/High Fantasy`) erscheinen als aufklappbarer Baum, auch wenn der Oberbegriff selbst nicht vergeben ist. Ein Oberbegriff filtert mit `tag:Fantasy/*` den ganzen Zweig und zeigt in der Filterleiste „Fantasy (+ sub)“. Zahlen an Oberbegriffen sind Summen und mit „≈“ markiert.
- **Hochladen**: Schaltfläche „Hochladen“ in der Navigation und im leeren Zustand sowie Drag & Drop auf die Bibliothek („Zum Hinzufügen in die Bibliothek ablegen“). Der Upload läuft über `@nextcloud/upload` mit Chunking (auch 300 bis 400 MB), nur Bucherweiterungen (epub, mobi, azw3, fb2, fbz, cbz, cbr, cb7, cbt), in den ersten Bibliotheksordner. Vorhandene Dateien werden nie überschrieben („Name (2).epub“). Fortschritt pro Datei und gesamt, Abbrechen möglich; danach werden Bibliothek und Filter neu geladen. Bei Dateien über 20 MB erscheint der Hinweis „Large files are being indexed in the background“.
- **Mehrfachauswahl bearbeiten**: „Edit selected…“ (früher „Genres und Tags bearbeiten“) öffnet den Dialog „Edit selected books“ mit den Abschnitten Autoren (ersetzen, hinzufügen, entfernen), Serie (setzen oder entfernen, Bandnummern behalten, in Listenreihenfolge oder nach Titel nummerieren mit Start und Schrittweite, Vorschau der ersten 10 Bücher), Verlag, Sprache sowie Genres und Tags. Nur abgehakte Abschnitte werden gesendet, alles andere bleibt pro Buch erhalten. Die Auswahl bleibt nach dem Speichern erhalten, Bibliothek und Filter werden neu geladen.
- Neuer Endpunkt `POST /api/v1/books/bulk-metadata` (bis zu 500 Bücher, 5 Aufrufe pro Minute). Jedes Buch läuft über den normalen Metadaten-Speicherweg (Speicherziel, Begleitdatei, Overrides, keine Änderung bei gleichem Wert); die Antwort enthält `updated`, `unchanged`, `failed` je Datei und `writeQueued`. Bei `async=1` oder bei mehr als 50 Büchern mit dem Speicherziel „In der Datei“ oder „Beides“ läuft die Änderung als Hintergrundaufgabe (Typ `bulk`, Fortschritt „Book 12 of 80“, Antwort 202 mit `taskId`).

### Geändert
- Standard für Metadaten-Änderungen ist jetzt die Begleitdatei statt der Buchdatei. Wer die Metadaten weiterhin in die Datei schreiben möchte, wählt „In der Datei“ in den Einstellungen.
- Ein vollständiges Speichern im Editor aktualisiert bei den Zielen „Begleitdatei“ und „Beides“ auch die Begleitdatei, damit sie die neuen Werte nicht überschreibt.
- Ist der Ordner oder die Freigabe schreibgeschützt, werden Änderungen nur in der Bibliothek gespeichert und mit einem Hinweis gemeldet.
- Neue Datenbankspalte `sidecar_etag` (Migration `Version1003Date20261001180000`).

### Behoben
- Tags ließen sich nicht speichern (MariaDB/MySQL: „Field 'type' doesn't have a default value“). Neue Datensätze schreiben jetzt immer alle Felder. Das betraf auch Hintergrundaufgaben beim Bearbeiten von Seiten.
- Der Hintergrundjob zum Schreiben von Metadaten brach mit „fclose(): Argument #1 must be an open stream resource“ ab. Auch das Speichern im Editor, „Als Kopie speichern“ und Konvertierungen waren betroffen.
- Meldungen („Änderungen gespeichert“ usw.) erschienen ohne Styling als Text oben links: Das Stylesheet von `@nextcloud/dialogs` wurde nicht geladen.
- Nach dem Speichern im Editor geht es zurück zur vorherigen Liste, mit Filtern, Regal und Position. Bei Warnungen erst, nachdem der Dialog geschlossen wurde. „Zurück“ verhält sich genauso.
- Genres, Tags, Autoren, Serien und Formate in der Navigation klappen auch beim Klick auf den Namen auf, nicht nur beim Klick auf den Pfeil.
- Werkzeugleiste der Bibliothek: Der Navigations-Button verdeckt das Suchfeld nicht mehr, Suche und Sortierung sind gleich hoch. Die Sortierung ist jetzt ein kompaktes Menü.
- Filter-Chips sind kompakt (einheitliche Höhe, Icons mittig), „Alle/Mindestens einer“ und „Filter zurücksetzen“ sind kleine Buttons.

## 0.3.0 – 2026-10-01

### Hinzugefügt
- Einstellung „Metadaten in Dateien schreiben“: im Hintergrund (Standard), sofort oder nie. Im Hintergrund werden Änderungen sofort in der Bibliothek gespeichert und kurz danach gesammelt in die Datei geschrieben; mehrere schnelle Änderungen ergeben nur einen Schreibvorgang.
- In der App geänderte Metadaten (Titel, Autoren, Serie, Beschreibung usw.) bleiben erhalten, wenn die Datei sich ändert oder neu eingelesen wird. Das gilt für MOBI, AZW3, CBR, CB7, CBT und für den Modus „nie“. In der Detailansicht steht „in der App geändert“ mit der Aktion „Wert aus Datei übernehmen“.
- Bücher löschen, einzeln in der Detailansicht oder für eine Auswahl, mit Bestätigungsdialog. Die Dateien landen im Nextcloud-Papierkorb und lassen sich wiederherstellen.
- Fortschrittsdialog beim Speichern im Editor: Upload-Fortschritt, Verarbeitung auf dem Server, vergangene Zeit.
- Speichern im Editor und Konvertierungen auf dem Server laufen als Hintergrundaufgabe mit echtem Fortschritt (Schritt und Fortschrittsbalken). Die Seite darf währenddessen verlassen werden, die Aufgabe läuft auf dem Server weiter.
- Die Bibliothek zeigt einen Hinweis mit Fortschritt, solange Aufgaben auf dem Server laufen, auch nach einem Neuladen der Seite.
- Vor Browser-Fallbacks, die eine große Datei (über 50 MB) vollständig herunterladen (Reader für CBR/CB7/CBT ohne Server-Seiten, Konvertierung im Browser, CBR in CBZ im Editor), erscheint eine Bestätigung.
- Lokaler Archiv-Cache (`archive_cache_mb`, Standard 2048 MB) für Bücher auf WebDAV-, SMB-, S3- oder verschlüsseltem Speicher: jede Dateiversion wird höchstens einmal kopiert und von Reader, Editor, Konvertierung und Einlesen gemeinsam genutzt. Auf lokalem, unverschlüsseltem Speicher wird gar nichts kopiert.
- Neuer Endpunkt `/apps/ebookreader/archive/{fileId}/entries` mit der Eintragsliste von EPUB, CBZ und FBZ; `/item` liefert jetzt auch FBZ und erlaubt mindestens 1200 Anfragen pro Minute.
- README-Abschnitt „Große Bibliotheken / große Dateien“: Cron, `occ background-job:worker` mit systemd-Beispiel, `archive_cache_mb`, `async_inline`, `max_edit_size_mb` und die Installation von 7z.

### Geändert
- Der Reader lädt EPUB- und FBZ-Dateien nicht mehr vollständig herunter, sondern liest nur die benötigten Einträge vom Server (mit Cache von ca. 50 MB im Browser). Schlägt das fehl, wird wie bisher die ganze Datei geladen. Der DRM-Check und die Absicherung der Kapitel bleiben unverändert.
- Das Ändern von Metadaten (z. B. ein einzelnes Tag) lädt große CBZ/EPUB/FB2-Dateien nicht mehr vollständig herunter, schreibt sie neu und lädt sie wieder hoch. Ohne tatsächliche Änderung wird gar nichts geschrieben, sonst im Hintergrund oder nach Einstellung.
- Der Editor öffnet den Metadaten-Tab sofort aus der Bibliothek, ohne die Datei zu kopieren. Seiten, Inhalt und Inhaltsverzeichnis werden erst beim Öffnen des jeweiligen Tabs geladen.
- Reine Metadaten-Änderungen im Editor behalten die Seitennamen von Comics bei und kopieren alle anderen Einträge unverändert; nur ComicInfo.xml, die OPF-Datei bzw. die FB2-Beschreibung werden ersetzt.
- Auf lokalem Speicher liest der Server Bücher direkt von der Datei, statt sie vorher in eine temporäre Datei zu kopieren.
- Das erneute Einlesen einer Datei überschreibt keine Metadaten, die noch auf das Schreiben in die Datei warten.
- Aufgaben (Speichern, Konvertieren) laufen nach der Antwort im selben PHP-Prozess (bei FPM) und zusätzlich als Hintergrundjob als Ersatz; fertige Aufgaben werden nach 24 Stunden gelöscht. Mit `async_inline=false` läuft nur der Hintergrundjob.
- Reine Metadaten-Änderungen bei CBZ und EPUB kopieren die Datei und ersetzen nur `ComicInfo.xml` bzw. die OPF-Datei; alle anderen Einträge werden roh übernommen (keine Neukomprimierung, gleiche Größe und Prüfsumme).
- Der manuelle Scan indiziert nur Dateien bis 50 MB direkt, größere Dateien laufen immer als Hintergrundjob.

### Behoben
- Beim Wechsel zwischen Büchern fuhr die Detailansicht jedes Mal neu herein und flackerte. Jetzt wird nur der Inhalt aktualisiert.
- Tag-Chips und der „Hinzufügen“-Button in der Detailansicht sind neu gestaltet: einheitliche Höhe, das × steht im Chip. Die Metadaten-Zeilen sind sauber ausgerichtet.
- Nach dem Löschen oder Umsortieren von Comic-Seiten zeigte der Editor noch die alten Seitenbilder aus dem Browser-Cache. Die Vorschau-URLs sind jetzt an die Dateiversion gebunden.
- Nach dem Speichern mit Warnungen lud der Editor den neuen Stand erst beim Bestätigen des Dialogs. Wurde der Dialog anders geschlossen, meldete das nächste Speichern fälschlich „Datei wurde geändert“. Der Editor lädt jetzt immer sofort neu.

## 0.2.0 – 2026-10-01

### Hinzugefügt
- Genres und Tags lassen sich direkt in der Detailansicht eines Buchs hinzufügen und entfernen. Ein Klick auf einen Tag filtert danach.
- Komplexe Filter: mehrere Genres, Tags, Autoren, Serien und Formate kombinierbar („alle“ oder „mindestens einer“), dazu Ausschluss-Filter. Der Filter steht in der URL und lässt sich als Lesezeichen speichern.
- Bücher organisieren: Umbenennen und Einsortieren in Ordner nach einem Muster wie `{author}/{series}/{series_index:2} - {title}`, mit Vorschau vor dem Ausführen, für ein Buch oder eine Auswahl.
- Neue Comic-Formate CB7 (7z) und CBT (tar).
- Formatkonvertierung zwischen CBZ, CB7, CBT und EPUB (Fixed Layout), mit Erklärung der Vor- und Nachteile jedes Formats. CBR kann als Ziel nicht angeboten werden, weil RAR sich nur mit proprietärer Software schreiben lässt.
  - Die Konvertierung läuft auf dem Server, wenn er Quelle und Ziel verarbeiten kann. CBZ, CBT und EPUB gehen immer, CBR und CB7 mit installiertem `7z`, `unrar` oder `bsdtar`.
  - Andernfalls konvertiert der Browser und lädt das Ergebnis hoch.
- CBR- und CB7-Comics werden ebenfalls seitenweise vom Server ausgeliefert, wenn ein Archiv-Programm installiert ist. CBT braucht dafür kein Zusatzprogramm.

### Behoben
- CBR-Comics bekommen ein Vorschaubild: serverseitig, wenn `7z`, `unrar` oder `bsdtar` installiert ist, sonst erzeugt es der Browser beim ersten Öffnen.

### Sicherheit
Die Änderungen stammen aus einem Sicherheits-Audit mit vier Schwerpunkten: Zugriffsrechte, Dateiverarbeitung, Browser, Betrieb. Einen Zugriff auf Bücher anderer Nutzer hat das Audit nicht gefunden. Details stehen in `docs/SECURITY-FIXES.md`.

**Zugriffsrechte und Freigaben**
- Freigaben mit „Download verbieten“ werden respektiert: Comic-Seiten, Buchinhalte, Editor und Konvertierung liefern keine Inhalte mehr aus. Der Reader zeigt einen Hinweis.
- Cover hochladen setzt Schreibrecht auf die Datei voraus.
- Verschieben und Umbenennen prüfen vorher Schreib- und Löschrecht.

**Auslieferung von Dateiinhalten**
- Der Endpunkt für Buchinhalte (`/item`) liefert nur noch Bilder, Fonts und Medien mit eigenem Typ. Alles andere geht als Text raus, mit strenger CSP inklusive Sandbox.
- Links in Büchern öffnen erst nach Rückfrage, nur für http, https und mailto, und ohne Zugriff des neuen Tabs auf die Nextcloud-Seite.
- Strengere CSP pro Kapitel (`script-src`, `base-uri`, `form-action`, `frame-src` und `object-src` auf `'none'`). XHTML-Kapitel mit fremdem Wurzelelement werden bereinigt.
- Buchbeschreibungen werden in der Detailansicht streng bereinigt.
- Die CSP-Erweiterung gilt nur noch für die eigenen Seiten der App sowie Files und Viewer, mit exaktem Pfadvergleich.

**Schutz vor präparierten Dateien**
- XML-Bomben (Billion Laughs, auch UTF-16-kodiert) werden abgelehnt.
- Obergrenzen für CBR/CB7: 2 GB Gesamtgröße und 5000 Seiten. Der Archivtyp wird anhand der Datei-Signatur erzwungen.
- Größenlimits für FB2, robusteres MOBI-Parsing, Begrenzung der Einträge in ZIP-Archiven.
- Riesige Bilder (über 40 Megapixel) werden nicht mehr dekodiert.
- Sehr große Kapitel lassen das Speichern im Editor nicht mehr scheitern: Ungenutzte Ressourcen werden dann behalten statt gelöscht, mit Warnung.

**Rate-Limits und Hintergrundjobs**
- Rate-Limits für Editor, Massen-Tagging (höchstens 100 Bücher), Comic-Seiten und Cover-Upload.
- Hintergrund-Scans reihen nur noch Jobs ein, höchstens 50 Nutzer pro Lauf.
- Beim Löschen eines Nutzers werden auch zwischengespeicherte Comic-Seiten entfernt.
- Die Reader-Einstellungen akzeptieren nur noch bekannte Schlüssel.

**Release-Pipeline und Abhängigkeiten**
- GitHub Actions sind auf feste Commit-Hashes gepinnt, der Release-Job läuft im Environment `appstore`, und der Signierschlüssel wird sicher aufgeräumt.
- Das App-Paket baut aus einer Liste erlaubter Dateien.
- `vitest` ist auf Version 5 aktualisiert.

## 0.1.3 – 2026-10-01

### Hinzugefügt
- CBZ-Comics werden seitenweise vom Server geladen, auf Bildschirmgröße verkleinert und serverseitig zwischengespeichert. Der Cache wird nach 30 Tagen aufgeräumt.

### Geändert
- Die Kapitel-iframes des Readers erlauben wieder Skripte, weil Chrome sonst die Bedien-Handler von foliate-js blockiert. Skripte aus Büchern bleiben blockiert: CSP pro Kapitel, geerbte Nextcloud-CSP, keine EPUB-Skriptdateien, bereinigte SVG-Kapitel.
- `GET /progress/{fileId}` antwortet bei noch nie geöffneten Büchern mit 200 und `null` statt 404.

### Behoben
- Der Reader liegt nicht mehr unter der Nextcloud-Kopfleiste. Werkzeugleiste und Fortschrittsleiste verdecken die Seite nicht mehr.
- Navigation und Seitenaufbau bei Comics funktionieren in Chrome.

## 0.1.2 – 2026-09-30

### Behoben
- Unformatierte Oberfläche: Die CSS-Dateien der App wurden nicht geladen.
- Verschachteltes Layout der Bibliothek (Navigation im Inhaltsbereich).
- „Bibliothek scannen“ indexiert sofort (bis zu 20 Sekunden pro Klick) statt nur Hintergrundjobs anzulegen. Danach lädt die Liste neu. Speichern der Einstellungen startet einen Scan.
- Bücher, die aus der Bibliothek verschwunden und wieder aufgetaucht sind, werden wieder aufgenommen.

## 0.1.1 – 2026-09-30

### Hinzugefügt
- Erste veröffentlichte Version: Bibliothek mit Genres, Tags, Suche und Filtern, Reader für EPUB, MOBI, AZW3, FB2, CBZ und CBR auf Basis von foliate-js, serverseitig synchronisierter Lesefortschritt, Editor für Metadaten, Cover, Kapitel/Seiten und Inhaltsverzeichnis (EPUB, CBZ, FB2), REST-API mit OpenAPI-Beschreibung, Integration in Files und Viewer.
- Release-Pipeline über GitHub Actions.
