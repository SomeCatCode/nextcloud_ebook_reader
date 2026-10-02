# 📚 E-Book Reader für Nextcloud

Bibliothek, Reader und Editor für E-Books und Comics direkt in deiner Nextcloud. Deine Bücher bleiben normale Dateien in deinem Speicher. Die App liest sie ein, zeigt sie als Bibliothek an und synchronisiert den Lesefortschritt zwischen all deinen Geräten.

![Nextcloud 34](https://img.shields.io/badge/Nextcloud-34-0082c9?logo=nextcloud&logoColor=white)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)
![Lizenz AGPL-3.0](https://img.shields.io/badge/Lizenz-AGPL--3.0--or--later-blue)
![Status Beta](https://img.shields.io/badge/Status-Beta-yellow)
[![CI](https://github.com/SomeCatCode/nextcloud_ebook_reader/actions/workflows/ci.yml/badge.svg)](https://github.com/SomeCatCode/nextcloud_ebook_reader/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/SomeCatCode/nextcloud_ebook_reader?include_prereleases)](https://github.com/SomeCatCode/nextcloud_ebook_reader/releases)

> [!NOTE]
> **Beta.** Die App wird produktiv mit einer großen Bibliothek (E-Books und Comics bis 400 MB) eingesetzt und ist durch automatische Tests abgedeckt. Bis zur Version 1.0 können sich Details noch ändern. Der Editor verändert Dateien. Nextcloud legt dabei zwar automatisch eine Version an, ein Backup deiner Bücher schadet trotzdem nicht.

---

## Funktionen

### Lesen
- **Formate:** EPUB 2/3, MOBI, AZW3 (KF8), FB2, FB2.ZIP sowie die Comic-Formate CBZ, CBR, CB7 und CBT.
- Direkt aus der **Files-App** öffnen: per Klick oder über „Im E-Book Reader öffnen“.
- Paginierte oder scrollende Darstellung, Themes hell, sepia und dunkel, wählbare Schriftart, Schriftgröße und Zeilenhöhe.
- Inhaltsverzeichnis, Volltextsuche, Tastatur, Tipp-Zonen und Wischgesten.
- Comics als Einzel- oder Doppelseite, Leserichtung rechts nach links (Manga).
- **Markierungen, Notizen und Lesezeichen:** Text markieren (fünf Farben), Notiz dazu schreiben, Seiten mit einem Lesezeichen versehen (auch Comics). Alles steht im Panel „Markierungen & Lesezeichen“, wird auf dem Server gespeichert und lässt sich als Markdown exportieren.
- **Große Dateien laden schnell.** Comics kommen seitenweise vom Server, auf Bildschirmgröße verkleinert, und EPUBs werden kapitelweise geladen. Die erste Seite erscheint sofort.
- **Der Lesefortschritt wird auf dem Server gespeichert** und ist auf jedem Gerät an derselben Stelle. Gibt es eine neuere Position von einem anderen Gerät, fragt die App, ob sie dorthin springen soll.

### Bibliothek
- Cover-Raster oder Liste mit Lesefortschritt, dazu eine „Weiterlesen“-Leiste.
- **Dashboard-Widget „Weiterlesen“:** zeigt auf dem Nextcloud-Dashboard die angefangenen Bücher mit Cover, Autor und Fortschritt. Ein Klick öffnet das Buch im Reader.
- **OPDS-Katalog** für E-Reader-Apps wie KOReader, Moon+ Reader oder Thorium: Bibliothek durchstöbern und Bücher herunterladen (siehe [OPDS-Katalog](#opds-katalog)).
- Navigation nach **Genres, Tags, Autoren, Serien und Formaten**. Genres und Tags können hierarchisch sein (z. B. `Fantasy/High Fantasy`).
- **Kombinierbare Filter:** jeden Eintrag ein- oder ausschließen, „alle“ oder „mindestens einer“ müssen passen, dazu Suche und Sortierung. Der Filter steht in der URL und lässt sich als Lesezeichen speichern.
- **Nachpflegen:** Bücher ohne Genre, Tags, Autor, Serie, Beschreibung, Cover oder Sprache finden und gezielt ergänzen.
- **Regale:** manuelle Regale mit eigener Reihenfolge, dazu intelligente Regale, die einen gespeicherten Filter immer aktuell anzeigen.
- **Serien zusammenfassen:** eine Karte pro Serie mit Bandanzahl und Lesestand.
- **Mehrfachauswahl:** Autoren, Serie mit automatischer Bandnummerierung, Verlag, Sprache, Genres und Tags für viele Bücher auf einmal ändern. Außerdem Bücher in Regale legen, umbenennen und einsortieren oder löschen (in den Papierkorb).
- **Hochladen** per Drag & Drop, auch sehr große Dateien.
- Bewertung (Sterne) und Lesestatus (ungelesen, lese gerade, gelesen).
- Metadaten und Cover liest die App automatisch aus den Dateien. Neue, geänderte und gelöschte Bücher erkennt sie selbst.

### Bearbeiten
- **Metadaten:** Titel, Autoren, Serie und Band, Beschreibung, Genres, Tags, Sprache, Verlag, Datum, ISBN.
- **Cover** tauschen (Upload oder eine Seite als Cover wählen).
- **Kapitel bzw. Seiten** entfernen und per Drag & Drop umsortieren, **Inhaltsverzeichnis** bearbeiten.
- **Umbenennen und Einsortieren** nach Muster, z. B. `{author}/{series}/{series_index:2} - {title}`, mit Vorschau vor dem Ausführen.
- **Konvertieren** zwischen CBZ, CB7, CBT und EPUB (Fixed Layout). Die Vor- und Nachteile jedes Formats werden erklärt. CBR lässt sich nicht als Ziel anlegen, weil RAR nur mit proprietärer Software geschrieben werden kann.
- Speichern oder „Als Kopie speichern“. Lange Vorgänge laufen auf dem Server mit Fortschrittsanzeige weiter.

| | EPUB | CBZ | FB2 | CBR / CB7 / CBT | MOBI / AZW3 |
|---|:-:|:-:|:-:|:-:|:-:|
| Metadaten, Genres, Tags | ✅ | ✅ | ✅ | ✅¹ | ✅¹ |
| Cover tauschen | ✅ | ✅ | ✅ | ✅² | – |
| Kapitel/Seiten umsortieren und entfernen | ✅ | ✅ | ✅ | ✅² | – |
| Inhaltsverzeichnis bearbeiten | ✅ | ✅ | ✅ | ✅² | – |

¹ in der Begleitdatei (siehe unten), die Buchdatei bleibt unverändert<br>
² nach Umwandlung in CBZ, die der Editor per Klick anbietet

### Wo Metadaten gespeichert werden

Geänderte Metadaten speichert die App je nach Einstellung „Wo Metadaten-Änderungen gespeichert werden“:

| Ziel | Wirkung |
|---|---|
| **Begleitdatei** (Standard) | Eine kleine, versteckte Datei `.<Buchdatei>.opf` neben dem Buch, z. B. `.Golden Boy 01.cbz.opf`. Schnell, für alle Formate, die Buchdatei bleibt unangetastet, und die Metadaten wandern bei Kopie, Sync und Backup mit. Format: Calibre-kompatibles OPF 2.0. |
| In der Datei | Die Metadaten werden ins Buch geschrieben (EPUB, CBZ, FB2, FBZ), sodass auch andere Reader sie sehen. Das passiert im Hintergrund oder sofort. |
| Beides | Die Begleitdatei sofort, die Buchdatei nach der Einstellung oben. |
| Nur Bibliothek | Nur in der Datenbank der App, z. B. bei schreibgeschützten Ordnern. |

Rangfolge beim Einlesen: in der App geänderte Felder, dann die Begleitdatei, dann die Metadaten im Buch, zuletzt der Dateiname. Beim Umbenennen, Verschieben, Konvertieren und Löschen wandert die Begleitdatei mit. „Metadaten in die Buchdatei schreiben“ in den Buchdetails überträgt die Werte nachträglich ins Buch, etwa für Kobo oder KOReader.

### Sicherheit und Datenschutz
- Skripte in E-Books werden **nicht** ausgeführt, externe Inhalte wie Tracking-Pixel oder Web-Fonts werden **nicht** nachgeladen, und Links öffnen erst nach Rückfrage. Details: [docs/SECURITY-READER.md](docs/SECURITY-READER.md).
- Freigaben mit „Download verbieten“ werden respektiert.
- Keine Verbindungen zu externen Diensten, keine Telemetrie.

---

## Voraussetzungen

| | |
|---|---|
| Nextcloud | **34** |
| PHP | 8.2 oder neuer mit `zip`, `gd`, `dom`, `libxml`, `mbstring`. Das ist in üblichen Installationen und im offiziellen Docker-Image enthalten. |
| Hintergrundjobs | **Cron** empfohlen. Mit AJAX werden große Bibliotheken nur langsam eingelesen. |
| Optional | **7-Zip** bzw. `bsdtar` für CBR und CB7 auf dem Server, siehe [Archiv-Programme installieren](#archiv-programme-installieren-7-zip) |
| Browser | aktuelles Firefox, Chrome/Edge oder Safari |

---

## Installation

Die App ist noch nicht im Nextcloud App Store.

### Aus einem Release-Paket (empfohlen)

1. `ebookreader.tar.gz` von der [Releases-Seite](https://github.com/SomeCatCode/nextcloud_ebook_reader/releases/latest) herunterladen. Optional mit der `.sha256`-Datei prüfen:
   ```bash
   sha256sum -c ebookreader.tar.gz.sha256
   ```
2. Ins App-Verzeichnis entpacken (meist `custom_apps/`) und die Rechte setzen:
   ```bash
   tar -xzf ebookreader.tar.gz -C /var/www/nextcloud/custom_apps/
   ```
   ```bash
   chown -R www-data:www-data /var/www/nextcloud/custom_apps/ebookreader
   ```
3. Aktivieren, unter **Apps → Deaktivierte Apps** oder per Kommandozeile:
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:enable ebookreader
   ```

### Aus dem Quellcode

Dafür brauchst du Node.js ≥ 22. Zur Laufzeit sind keine Composer-Pakete nötig.

```bash
cd /var/www/nextcloud/custom_apps && git clone https://github.com/SomeCatCode/nextcloud_ebook_reader.git ebookreader
```
```bash
cd ebookreader && npm ci && npm run build
```
```bash
sudo -u www-data php /var/www/nextcloud/occ app:enable ebookreader
```

### Docker

Die App gehört nach `/var/www/html/custom_apps/ebookreader` im Nextcloud-Container und muss `www-data` gehören. `occ` läuft als `www-data`. Zuerst das Paket herunterladen und entpacken:
```bash
curl -LO https://github.com/SomeCatCode/nextcloud_ebook_reader/releases/latest/download/ebookreader.tar.gz && tar -xzf ebookreader.tar.gz
```

**Offizielles Image** (`nextcloud` steht für den Container-Namen):
```bash
docker cp ebookreader nextcloud:/var/www/html/custom_apps/
```
```bash
docker exec -u root nextcloud chown -R www-data:www-data /var/www/html/custom_apps/ebookreader
```
```bash
docker exec -u www-data nextcloud php occ app:enable ebookreader
```
Alternativ als Volume in der Compose-Datei: `- ./ebookreader:/var/www/html/custom_apps/ebookreader`.

**Nextcloud All-in-One:** Hier heißt der Container `nextcloud-aio-nextcloud`, die Befehle sind sonst dieselben wie oben. Cron und Schreibrechte sind bei AIO schon eingerichtet.

**Cron im offiziellen Image:** Das Image führt Cron nicht selbst aus. Dafür braucht es einen zusätzlichen Service mit denselben Volumes:
```yaml
  cron:
    image: nextcloud:34-apache
    entrypoint: /cron.sh
    volumes:
      - nextcloud:/var/www/html
```

### Update

Den neuen Release über den alten Ordner kopieren (vorher löschen) und die Migrationen ausführen. Unter Docker stellst du jeweils `docker exec -u www-data <container>` voran.
```bash
sudo -u www-data php occ upgrade
```
```bash
sudo -u www-data php occ ebookreader:scan --all
```

---

## Archiv-Programme installieren (7-Zip)

CBZ und CBT verarbeitet die App ohne Zusatzprogramme. Für **CBR** (RAR) und **CB7** (7z) braucht der Server ein Archiv-Programm, damit Cover, Metadaten, die seitenweise Anzeige und die Konvertierung auf dem Server laufen. Ohne Programm entpackt der Browser diese Dateien selbst und fragt bei Dateien über 50 MB vorher nach.

Die App sucht nacheinander nach `unrar`, `7zz`/`7z`/`7za` und `bsdtar`. Empfohlen sind **7-Zip** (für CB7 und CBR) plus **`bsdtar`** aus libarchive (liest RAR4 und RAR5 zuverlässig, auch wenn das 7-Zip-Paket ohne RAR-Unterstützung gebaut ist).

### Ohne Docker

| System | Befehl |
|---|---|
| Debian / Ubuntu | `sudo apt install 7zip libarchive-tools`, optional zusätzlich `unrar` (Debian: Bereich `non-free`) |
| Alpine | `apk add 7zip libarchive-tools` |

### Offizielles Docker-Image (eigenes Image bauen)

Das offizielle `nextcloud`-Image basiert auf Debian. Nachträglich per `docker exec` installierte Pakete gehen beim Neuerstellen des Containers verloren. Dauerhaft geht es mit einem eigenen Image:

`Dockerfile` neben der `docker-compose.yml`:
```dockerfile
FROM nextcloud:34-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends 7zip libarchive-tools \
 && rm -rf /var/lib/apt/lists/*
```

In der `docker-compose.yml` statt `image:` bei **beiden** Services (`app` und `cron`) bauen lassen:
```yaml
services:
  app:
    build: .
    pull_policy: build
    # ... restliche Einstellungen unverändert
  cron:
    build: .
    pull_policy: build
    entrypoint: /cron.sh
    # ...
```

Bauen und neu starten, das ist auch nach jedem Nextcloud-Update nötig:
```bash
docker compose build --pull && docker compose up -d
```

Für die FPM-Variante entsprechend `FROM nextcloud:34-fpm`.

### Nextcloud All-in-One (AIO)

AIO erlaubt kein eigenes Image. Zusätzliche Alpine-Pakete installiert man über die Umgebungsvariable **`NEXTCLOUD_ADDITIONAL_APKS`** des Mastercontainers. Der Standardwert ist `imagemagick`, er muss also mit angegeben werden:

- **`docker run`:** beim Start des Mastercontainers ergänzen:
  ```bash
  --env NEXTCLOUD_ADDITIONAL_APKS="imagemagick 7zip libarchive-tools"
  ```
- **Compose:** im Service `nextcloud-aio-mastercontainer`:
  ```yaml
  environment:
    - NEXTCLOUD_ADDITIONAL_APKS=imagemagick 7zip libarchive-tools
  ```

Danach den Mastercontainer mit der geänderten Variable neu erstellen und in der AIO-Oberfläche die Container stoppen und wieder starten. Die Pakete werden dann bei jedem Start des Nextcloud-Containers installiert.

### Prüfen

```bash
docker exec nextcloud-aio-nextcloud sh -c 'command -v 7zz 7z bsdtar unrar'
```
Ohne Docker genügt `command -v 7z bsdtar`. Im Konvertieren-Dialog der App siehst du außerdem, welche Umwandlungen auf dem Server laufen und welche im Browser.

---

## Erste Schritte

1. **Bücher ablegen:** Standardmäßig liest die App den Ordner **`/Books`**. In den Einstellungen der App (unten links) kannst du andere oder mehrere Ordner wählen.
2. **Bibliothek scannen:** Beim ersten Mal auf „Bibliothek scannen“ klicken. Danach erkennt die App Änderungen automatisch.
3. **Lesen:** ein Buch anklicken und „Lesen“ wählen, oder das Buch direkt in Files öffnen.
4. **Nachpflegen:** In der Navigation unter „Nachpflegen“ findest du Bücher ohne Genre, Tags und Ähnliches. Mit der Mehrfachauswahl ergänzt du sie gesammelt.

### `occ`-Befehle

| Befehl | Zweck |
|---|---|
| `occ ebookreader:scan <benutzer>` | Bibliothek eines Benutzers einlesen |
| `occ ebookreader:scan --all` | alle Bibliotheken einlesen |
| `occ ebookreader:inspect <pfad>` | Metadaten einer Datei als JSON ausgeben (Fehlersuche) |

### Admin-Einstellungen

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `max_edit_size_mb` | `500` | maximale Dateigröße für den Editor |
| `archive_cache_mb` | `2048` | lokaler Cache für Bücher auf WebDAV-, SMB- oder S3-Speicher und bei serverseitiger Verschlüsselung |
| `opds_enabled` | `true` | OPDS-Katalog auf diesem Server erlaubt (jeder Benutzer schaltet ihn zusätzlich selbst ein) |
| `async_inline` | `true` | Speichern und Konvertieren direkt nach der Antwort im selben PHP-Prozess ausführen (bei PHP-FPM); `false` überlässt das dem Hintergrundjob |

```bash
sudo -u www-data php occ config:app:set ebookreader archive_cache_mb --value=4096 --type=integer
```

---

## OPDS-Katalog

Mit dem OPDS-Katalog (OPDS 1.2, Atom) durchsuchen E-Reader-Apps deine Bibliothek und laden Bücher direkt herunter, ohne den Browser.

**Aktivieren:**
1. In der App unten links die **Einstellungen** öffnen und im Abschnitt „OPDS-Katalog“ **„Enable the OPDS catalog for my account“** einschalten. Der Katalog ist standardmäßig aus.
2. Die angezeigte **Katalog-URL** kopieren, z. B. `https://cloud.example.com/index.php/apps/ebookreader/opds`.
3. Unter **Einstellungen → Sicherheit** in Nextcloud ein **App-Passwort** erstellen. In der Reader-App den Benutzernamen und dieses App-Passwort eintragen (HTTP Basic), nicht das normale Passwort. Mit einem App-Passwort lässt sich der Zugang jederzeit einzeln widerrufen.

**Inhalt:** Neu hinzugefügt, Weiterlesen, Alle Bücher, Autoren, Serien, Genres, Tags und Regale (auch intelligente Regale), dazu eine Suche (OpenSearch). Listen sind seitenweise mit 50 Büchern. Zu jedem Buch gibt es Cover, Beschreibung, Autoren, Serie, Genres und Tags sowie den Download in der Originalform. Es erscheinen nur Bücher aus deiner Bibliothek. Dateien aus Freigaben ohne Download-Recht werden nicht ausgeliefert.

**Admin-Schalter:** Administratoren können den Katalog für den ganzen Server sperren, entweder im selben Abschnitt der App-Einstellungen oder per `occ`. Gesperrt ist er für alle Benutzer nicht erreichbar, egal was sie selbst eingestellt haben.

```bash
sudo -u www-data php occ config:app:set ebookreader opds_enabled --value=false --type=boolean
```

**Getestete Apps:** Bisher wurden nur die Antworten gegen die OPDS-Spezifikation geprüft, nicht jede App einzeln. Der Katalog sollte mit KOReader, Moon+ Reader, Librera, Thorium Reader sowie Panels und Chunky (Comics) funktionieren. Fehler bitte als Issue melden.

Hinweis: Fortschritt synchronisieren diese Apps nicht über OPDS. Der Lesefortschritt der Nextcloud-App bleibt davon unberührt.

---

## Große Bibliotheken und große Dateien

- **Cron:** Empfohlen ist System-Cron alle 5 Minuten (`*/5 * * * * php -f /var/www/nextcloud/cron.php`). Dateien über 20 MB werden im Hintergrund eingelesen.
- **Sofortige Hintergrundaufgaben:** Speichern im Editor und Konvertieren laufen als Aufgabe mit Fortschritt. Unter PHP-FPM startet die Aufgabe direkt nach der Antwort. Unter `mod_php` übernimmt der nächste Cron-Lauf, oder ein dauerhafter Worker:
  ```bash
  sudo -u www-data php occ background-job:worker -t 3600 'OCA\EbookReader\BackgroundJob\RunTaskJob'
  ```
  Den Worker richtest du am besten als systemd-Dienst ein, der automatisch neu startet. Bei PHP-FPM darf `request_terminate_timeout` lange Aufgaben nicht abbrechen.
- **Fremdspeicher:** Liegen Bücher auf lokalem, unverschlüsseltem Speicher, liest die App sie direkt. Sonst wird jede Dateiversion höchstens einmal in den Archiv-Cache kopiert (`archive_cache_mb`, im temporären Verzeichnis).

---

## Bekannte Einschränkungen

- **DRM-geschützte Bücher** (Kindle, Adobe) lassen sich nicht öffnen.
- **MOBI/AZW3** werden nicht verändert. Metadaten landen in der Begleitdatei.
- **Öffentliche Freigabe-Links** öffnen Bücher nicht im Reader, dort gibt es den normalen Download.
- **PDF** ist bewusst nicht dabei, dafür hat Nextcloud einen eigenen Viewer.

---

## Entwicklung

Voraussetzungen: Docker, Node.js ≥ 22, PHP ≥ 8.2 mit `zip` und `gd`.

```bash
make build && make dev-up && make enable
```

Nextcloud 34 läuft dann auf http://localhost:8080 (Zugangsdaten in `docker/docker-compose.yml`). `npm run watch` baut das Frontend bei Änderungen neu.

| Befehl | Zweck |
|---|---|
| `make test-php` / `make test-js` | PHPUnit / Vitest |
| `make lint` | ESLint, vue-tsc, `php -l`, Psalm |
| `make openapi` | `openapi.json` neu erzeugen |
| `make appstore` | Installationspaket nach `build/artifacts/` |

```
lib/                  PHP-Backend (Controller, Services, Metadaten, Editor, Jobs)
src/                  Vue-3-Frontend
packages/reader-core/ framework-unabhängiger Reader-Kern auf Basis von foliate-js
tests/                PHPUnit-Tests und Test-Bücher (tests/fixtures/generate.php)
```

- REST-API: [openapi.json](openapi.json)
- Sicherheitskonzept des Readers: [docs/SECURITY-READER.md](docs/SECURITY-READER.md)
- Releases erstellen: [docs/RELEASING.md](docs/RELEASING.md)
- Änderungen: [CHANGELOG.md](CHANGELOG.md)

Fehler und Wünsche bitte als [Issue](https://github.com/SomeCatCode/nextcloud_ebook_reader/issues) melden.

---

## Danksagung

- [foliate-js](https://github.com/johnfactotum/foliate-js) (MIT) von John Factotum: Rendering-Engine des Readers
- [libarchive.js](https://github.com/nika-begiashvili/libarchivejs) (MIT): Entpacken von CBR/CB7 im Browser
- [fflate](https://github.com/101arrowz/fflate) (MIT): ZIP-Erzeugung im Browser

Inoffizielle App, nicht mit der Nextcloud GmbH verbunden. Nextcloud ist eine Marke der Nextcloud GmbH.

## Lizenz

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)
