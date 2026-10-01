# Changelog

Alle nennenswerten Änderungen an dieser App werden hier dokumentiert.
Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionen folgen [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt
- Bücher löschen, einzeln in der Detailansicht oder für eine Auswahl, mit Bestätigungsdialog. Die Dateien landen im Nextcloud-Papierkorb und lassen sich wiederherstellen.
- Fortschrittsdialog beim Speichern im Editor: Upload-Fortschritt, Verarbeitung auf dem Server, vergangene Zeit.

### Behoben
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
