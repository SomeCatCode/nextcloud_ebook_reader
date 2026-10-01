# Changelog

Alle nennenswerten Änderungen an dieser App werden hier dokumentiert.
Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionen folgen [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt
- Genres und Tags lassen sich direkt in der Detailansicht eines Buchs hinzufügen und entfernen. Ein Klick auf einen Tag filtert danach.
- Komplexe Filter: mehrere Genres, Tags, Autoren, Serien und Formate kombinierbar („alle“ oder „mindestens einer“), dazu Ausschluss-Filter. Der Filter steht in der URL und lässt sich als Lesezeichen speichern.
- Bücher organisieren: Umbenennen und Einsortieren in Ordner nach einem Muster wie `{author}/{series}/{series_index:2} - {title}`, mit Vorschau vor dem Ausführen, für ein Buch oder eine Auswahl.
- Neue Comic-Formate CB7 (7z) und CBT (tar).
- Formatkonvertierung zwischen CBZ, CB7, CBT und EPUB (Fixed Layout), mit Erklärung der Vor- und Nachteile jedes Formats. CBR kann als Ziel nicht angeboten werden, weil RAR sich nur mit proprietärer Software schreiben lässt.

### Behoben
- CBR-Comics bekommen ein Vorschaubild: serverseitig, wenn `7z`, `unrar` oder `bsdtar` installiert ist, sonst erzeugt es der Browser beim ersten Öffnen.

## [0.1.3] - 2026-10-01

### Hinzugefügt
- CBZ-Comics werden seitenweise vom Server geladen, auf Bildschirmgröße verkleinert und serverseitig zwischengespeichert. Der Cache wird nach 30 Tagen aufgeräumt.

### Geändert
- Die Kapitel-iframes des Readers erlauben wieder Skripte, weil Chrome sonst die Bedien-Handler von foliate-js blockiert. Skripte aus Büchern bleiben blockiert: CSP pro Kapitel, geerbte Nextcloud-CSP, keine EPUB-Skriptdateien, bereinigte SVG-Kapitel.
- `GET /progress/{fileId}` antwortet bei noch nie geöffneten Büchern mit 200 und `null` statt 404.

### Behoben
- Der Reader liegt nicht mehr unter der Nextcloud-Kopfleiste. Werkzeugleiste und Fortschrittsleiste verdecken die Seite nicht mehr.
- Navigation und Seitenaufbau bei Comics funktionieren in Chrome.

## [0.1.2] - 2026-09-30

### Behoben
- Unformatierte Oberfläche: Die CSS-Dateien der App wurden nicht geladen.
- Verschachteltes Layout der Bibliothek (Navigation im Inhaltsbereich).
- „Bibliothek scannen“ indexiert sofort (bis zu 20 Sekunden pro Klick) statt nur Hintergrundjobs anzulegen. Danach lädt die Liste neu. Speichern der Einstellungen startet einen Scan.
- Bücher, die aus der Bibliothek verschwunden und wieder aufgetaucht sind, werden wieder aufgenommen.

## [0.1.1] - 2026-09-30

### Hinzugefügt
- Erste veröffentlichte Version: Bibliothek mit Genres, Tags, Suche und Filtern, Reader für EPUB, MOBI, AZW3, FB2, CBZ und CBR auf Basis von foliate-js, serverseitig synchronisierter Lesefortschritt, Editor für Metadaten, Cover, Kapitel/Seiten und Inhaltsverzeichnis (EPUB, CBZ, FB2), REST-API mit OpenAPI-Beschreibung, Integration in Files und Viewer.
- Release-Pipeline über GitHub Actions.

[Unreleased]: https://github.com/SomeCatCode/nextcloud_ebook_reader/compare/v0.1.3...HEAD
[0.1.3]: https://github.com/SomeCatCode/nextcloud_ebook_reader/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/SomeCatCode/nextcloud_ebook_reader/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/SomeCatCode/nextcloud_ebook_reader/releases/tag/v0.1.1
