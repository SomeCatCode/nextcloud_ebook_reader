# 📚 E-Book Reader für Nextcloud

Bibliothek, Reader und Editor für E-Books und Comics direkt in deiner Nextcloud. Bücher bleiben normale Dateien in deinem Speicher. Die App liest sie, zeigt sie als Bibliothek an und synchronisiert den Lesefortschritt zwischen all deinen Geräten.

![Nextcloud 34](https://img.shields.io/badge/Nextcloud-34-0082c9?logo=nextcloud&logoColor=white)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)
![Lizenz AGPL-3.0](https://img.shields.io/badge/Lizenz-AGPL--3.0--or--later-blue)
![Status Alpha](https://img.shields.io/badge/Status-Alpha-orange)

> [!WARNING]
> **Alpha-Version.** Die App ist vollständig implementiert und durch automatische Tests abgedeckt, wurde aber noch nicht in einer produktiven Nextcloud-Instanz erprobt. Bitte zuerst in einer Testinstanz ausprobieren und Backups deiner Bücher behalten. Der Editor verändert Dateien, auch wenn Nextcloud dabei automatisch eine Version anlegt.

---

## Funktionen

### Lesen
- **Formate:** EPUB 2/3, MOBI, AZW3 (KF8), FB2, FB2.ZIP, CBZ, CBR
- Direkt aus der **Files-App** öffnen, per Klick oder über das Kontextmenü „Im E-Book Reader öffnen“
- Paginierte oder scrollende Darstellung, Themes **hell / sepia / dunkel**, Schriftart, Schriftgröße, Zeilenhöhe
- Inhaltsverzeichnis, Volltextsuche, Tastatur (←/→, Bild↑/↓), Tipp-Zonen und Wischgesten
- Comics: Einzel- oder Doppelseite, Leserichtung rechts-nach-links (Manga)
- **Lesefortschritt wird auf dem Server gespeichert** und ist auf jedem Gerät an derselben Stelle. Bei Konflikten fragt die App, ob du zur neueren Position springen möchtest.

### Bibliothek
- Cover-Raster oder Listenansicht mit Lesefortschritt pro Buch
- „Weiterlesen“-Leiste mit den zuletzt gelesenen Büchern
- Navigation nach **Genres, Tags, Autoren, Serien und Formaten**
- Kombinierbare Filter, Suche und Sortierung (Titel, Autor, Serie, Bewertung, zuletzt hinzugefügt/gelesen)
- Bewertung (Sterne) und Lesestatus (ungelesen / lese gerade / gelesen)
- Genres und Tags für mehrere Bücher gleichzeitig setzen
- Metadaten und Cover werden automatisch aus den Dateien gelesen, neue Bücher erkennt die App von selbst

### Bearbeiten
- **Metadaten:** Titel, Autoren, Serie und Band, Beschreibung, Genres, Tags, Sprache, Verlag, Datum, ISBN
- **Cover** tauschen (Upload oder eine Seite als Cover wählen)
- **Kapitel bzw. Seiten entfernen und per Drag & Drop umsortieren**
- **Inhaltsverzeichnis** umbenennen, umsortieren und verschachteln
- Datei umbenennen, frei oder nach einem Muster wie `{author} - {title}`
- Speichern oder „Als Kopie speichern“. Nextcloud legt beim Überschreiben automatisch eine Version an.

| | EPUB | CBZ | FB2 | CBR | MOBI / AZW3 |
|---|:-:|:-:|:-:|:-:|:-:|
| Metadaten, Genres, Tags | ✅ | ✅ | ✅ | ✅¹ | ✅² |
| Cover tauschen | ✅ | ✅ | ✅ | ✅¹ | – |
| Kapitel/Seiten umsortieren und entfernen | ✅ | ✅ | ✅ | ✅¹ | – |
| Inhaltsverzeichnis bearbeiten | ✅ | ✅ | ✅ | ✅¹ | – |

¹ nach Umwandlung in CBZ, die der Editor per Klick anbietet
² nur in der App-Datenbank, die Datei selbst bleibt unverändert

### Sicherheit und Datenschutz
- Skripte in E-Books werden **nicht** ausgeführt, externe Inhalte wie Tracking-Pixel oder Web-Fonts werden **nicht** nachgeladen. Details: [docs/SECURITY-READER.md](docs/SECURITY-READER.md)
- Keine Verbindungen zu externen Diensten, keine Telemetrie

---

## Voraussetzungen

| | |
|---|---|
| Nextcloud | **34** (Hub 26 Spring) |
| PHP | 8.2 oder neuer, mit den Erweiterungen `zip`, `gd`, `dom`, `libxml`, `mbstring`. Diese sind in üblichen Nextcloud-Installationen und im offiziellen Docker-Image bereits vorhanden. |
| Hintergrundjobs | **Cron** empfohlen (Einstellungen → Verwaltung → Grundeinstellungen). AJAX funktioniert auch, dann werden große Bibliotheken aber nur langsam eingelesen. |
| Browser | aktuelle Versionen von Firefox, Chrome/Edge oder Safari |

---

## Installation

Die App ist noch nicht im Nextcloud App Store. Für die Installation hast du zwei Möglichkeiten.

### Variante A: Aus einem Release-Paket (empfohlen)

1. Lade `ebookreader.tar.gz` von der [Releases-Seite](../../releases) herunter.
2. Entpacke das Paket in das App-Verzeichnis deiner Nextcloud, meist `custom_apps/` oder `apps/`:
   ```bash
   tar -xzf ebookreader.tar.gz -C /var/www/nextcloud/custom_apps/
   ```
   ```bash
   chown -R www-data:www-data /var/www/nextcloud/custom_apps/ebookreader
   ```
3. Aktiviere die App, entweder in der Weboberfläche unter **Apps → Deaktivierte Apps → E-Book Reader** oder per Kommandozeile:
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:enable ebookreader
   ```

### Variante B: Aus dem Quellcode bauen

Dafür brauchst du Node.js ≥ 22 und npm.

```bash
cd /var/www/nextcloud/custom_apps
```
```bash
git clone https://github.com/<dein-account>/nextcloud_ebook_reader.git ebookreader
```
```bash
cd ebookreader && npm ci && npm run build
```
```bash
sudo -u www-data php /var/www/nextcloud/occ app:enable ebookreader
```

Zur Laufzeit braucht die App keine Composer-Pakete, Nextcloud lädt die PHP-Klassen selbst.

Ein installierbares Paket wie in Variante A kannst du auch selbst bauen:
```bash
make appstore
```
Das Paket liegt danach unter `build/artifacts/ebookreader.tar.gz`.

### Docker (offizielles `nextcloud`-Image)

Das Paket in das gemountete `custom_apps`-Verzeichnis entpacken und dann:
```bash
docker exec -u www-data <container> php occ app:enable ebookreader
```

### Nach der Installation: Dateitypen

Beim Aktivieren trägt die App die Dateitypen **AZW3** und **FB2.ZIP** in `config/mimetypemapping.json` ein. Alle anderen Formate kennt Nextcloud schon. Dafür muss `config/` für den Webserver beschreibbar sein. Falls das nicht der Fall war (Hinweis im Log), trage die Typen nachträglich ein:
```bash
sudo -u www-data php occ maintenance:repair
```
```bash
sudo -u www-data php occ maintenance:mimetype:update-db --repair-filecache
```

---

## Erste Schritte

1. **Bücher ablegen:** Standardmäßig liest die App den Ordner **`/Books`** in deinen Dateien ein. In den Einstellungen der App (unten links in der Navigation) kannst du andere oder mehrere Ordner wählen.
2. **App öffnen:** In der Nextcloud-Navigation auf **E-Book Reader** klicken. Beim ersten Mal auf **„Bibliothek scannen“** klicken. Danach erkennt die App neue, geänderte und gelöschte Bücher automatisch.
3. **Lesen:** Auf ein Buch klicken und dann auf „Lesen“. Alternativ ein E-Book direkt in der Files-App öffnen.
4. **Bearbeiten:** In der Detailansicht eines Buchs „Bearbeiten“ wählen, oder in Files im Kontextmenü „E-Book bearbeiten“.

### Nützliche `occ`-Befehle

| Befehl | Zweck |
|---|---|
| `occ ebookreader:scan <benutzer>` | Bibliothek eines Benutzers einlesen |
| `occ ebookreader:scan --all` | Bibliotheken aller Benutzer einlesen, z. B. nach dem ersten Einrichten |
| `occ ebookreader:inspect <pfad-zur-datei>` | Metadaten einer Datei als JSON ausgeben, praktisch zur Fehlersuche |

### Admin-Einstellungen

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `max_edit_size_mb` | `500` | Maximale Dateigröße, die der Editor bearbeitet |

```bash
sudo -u www-data php occ config:app:set ebookreader max_edit_size_mb --value=1000
```

---

## Bekannte Einschränkungen

- **DRM-geschützte Bücher** (Kindle, Adobe) lassen sich nicht öffnen. Die App zeigt einen entsprechenden Hinweis.
- **MOBI/AZW3-Dateien** werden nicht verändert. Metadaten-Änderungen landen nur in der App.
- **Öffentliche Freigabe-Links** öffnen Bücher nicht im Reader, dort gibt es den normalen Download.
- **Safari:** Die Tipp- und Wischnavigation im Reader kann eingeschränkt sein, siehe [docs/SECURITY-READER.md](docs/SECURITY-READER.md).
- **PDF** wird bewusst nicht unterstützt, dafür hat Nextcloud einen eigenen Viewer.

---

## Entwicklung

Voraussetzungen: Docker, Node.js ≥ 22, PHP ≥ 8.2 (für lokale Tests mit `zip` und `gd`).

```bash
make build
```
```bash
make dev-up
```
```bash
make enable
```

Nextcloud 34 läuft dann mit MariaDB und Redis auf **http://localhost:8080**. Die Test-Zugangsdaten stehen in `docker/docker-compose.yml`. Das App-Verzeichnis ist direkt gemountet; `npm run watch` baut das Frontend bei jeder Änderung neu.

| Befehl | Zweck |
|---|---|
| `make build` | npm-Abhängigkeiten installieren und das Frontend nach `js/` bauen |
| `npm run watch` | Frontend bei Änderungen neu bauen |
| `make dev-up` / `make dev-down` | Testumgebung starten / stoppen |
| `make dev-reset` | Testumgebung komplett zurücksetzen |
| `make test-php` | PHPUnit im PHP-8.3-Container |
| `make test-js` | Vitest |
| `make lint` | ESLint, vue-tsc, `php -l`, Psalm |
| `make openapi` | `openapi.json` neu erzeugen |
| `make appstore` | Installationspaket bauen |

Test-Bücher (EPUB, FB2, CBZ, MOBI …) erzeugt `php tests/fixtures/generate.php` reproduzierbar nach `tests/fixtures/books/`.

### Aufbau

```
lib/                  PHP-Backend (Controller, Services, Metadaten-Extraktoren, Editor, Jobs)
src/                  Vue-3-Frontend (Bibliothek, Reader, Editor, Files/Viewer-Integration)
packages/reader-core/ Framework-unabhängiger Reader-Kern auf Basis von foliate-js
tests/                PHPUnit-Tests und Test-Bücher
docs/                 Technische Dokumentation
```

### Weitere Dokumentation

- [PLAN.md](PLAN.md): Projektplan, Architektur, Datenmodell und Roadmap
- [docs/CONTRACTS.md](docs/CONTRACTS.md): API- und Schnittstellenverträge
- [docs/SECURITY-READER.md](docs/SECURITY-READER.md): Sicherheitskonzept des Readers
- [docs/DEVIATIONS.md](docs/DEVIATIONS.md): Abweichungen vom Plan
- [openapi.json](openapi.json): REST-API-Beschreibung. Die API ist vorbereitet für externe Clients, z. B. eine spätere Android-App.

---

## Roadmap

- OPDS-Feed, damit sich die Bibliothek in KOReader, Moon+ Reader und Librera nutzen lässt
- KOReader-Sync für den Lesefortschritt
- Dashboard-Widget „Weiterlesen“ und Einbindung in die Nextcloud-Suche
- Markierungen, Notizen und Lesezeichen
- Android-App

---

## Danksagung

- [foliate-js](https://github.com/johnfactotum/foliate-js) (MIT) von John Factotum: Rendering-Engine des Readers
- [libarchive.js](https://github.com/nika-begiashvili/libarchivejs) (MIT): Entpacken von CBR-Dateien im Browser
- [fflate](https://github.com/101arrowz/fflate) (MIT): ZIP-Erzeugung im Browser

## Lizenz

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)
