# Releases erstellen

Releases baut GitHub Actions automatisch ([release.yml](../.github/workflows/release.yml)), sobald ein Versions-Tag gepusht wird.

## Branches und Testbuilds

- Neue Arbeit kommt per Pull Request in den Branch **`dev`**, nicht direkt nach `main`.
- Jeder Push auf `dev` läuft durch die CI und baut zusätzlich ein installierbares Paket: Im Actions-Lauf unter *Artifacts* liegt `ebookreader-dev-<commit>` mit `ebookreader.tar.gz` und `.sha256` (30 Tage). Zum Testen auf dem eigenen Server entpacken und den Ordner `ebookreader` in `apps/` (bzw. `custom_apps/`) ersetzen, danach `occ upgrade`, falls sich die Version erhöht hat.
- Ist `dev` getestet, wird die Version auf `dev` vorbereitet (Schritte 1 und 2 unten), dann `dev` per Pull Request nach `main` gemergt und auf `main` getaggt.

## Release

1. **Changelog:** In `CHANGELOG.md` den Abschnitt `## [Unreleased]` in `## X.Y.Z – JJJJ-MM-TT` umbenennen und darüber einen leeren `## [Unreleased]` stehen lassen. Die Überschrift muss **ohne** eckige Klammern sein, weil der Nextcloud App Store nach `^## X.Y.Z` sucht.
2. **Version:** in `appinfo/info.xml` und `package.json` setzen, z. B.:
   ```bash
   make bump VERSION=0.6.0
   ```
3. **Committen, taggen und pushen:**
   ```bash
   git tag v0.6.0 && git push origin v0.6.0
   ```

Die Pipeline führt alle Tests aus, übernimmt die Version aus dem Tag, baut das Paket und veröffentlicht ein GitHub-Release mit `ebookreader.tar.gz`, `.sha256` und `.sig`. Der Release-Text kommt aus dem passenden Changelog-Abschnitt. Tags mit Suffix (z. B. `v0.6.0-beta.1`) werden zum Pre-Release und nutzen den Abschnitt `[Unreleased]`.

## Nextcloud App Store

Signieren und Hochladen laufen im GitHub-Environment **`appstore`** (Tag-Regel `v*`). Dafür braucht es zwei Secrets:

- `APP_PRIVATE_KEY`: Inhalt von `ebookreader.key` (Zertifikat über [app-certificate-requests](https://github.com/nextcloud/app-certificate-requests))
- `APPSTORE_TOKEN`: API-Token aus dem App-Store-Konto

Die App muss vorher unter [apps.nextcloud.com/developer/apps/new](https://apps.nextcloud.com/developer/apps/new) registriert sein. Ohne die Secrets werden diese Schritte übersprungen. Ein fehlgeschlagener Upload lässt sich mit `gh run rerun <run-id> --failed` wiederholen.
