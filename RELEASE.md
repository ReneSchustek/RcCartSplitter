# RELEASE — RcCartSplitter

Reihenfolge für einen Live-Release des Plugins. Onboarding-fest dokumentiert: ein neuer Maintainer kann das Plugin anhand dieser Datei ohne Zusatzkontext deployen.

Versionshistorie: [`CHANGELOG_de-DE.md`](CHANGELOG_de-DE.md) und [`CHANGELOG_en-GB.md`](CHANGELOG_en-GB.md).

## 1. Pre-Release-Checks (lokal)

```bash
composer quality
composer coverage
composer coverage:gate
composer audit --no-dev
git status
```

`composer quality` führt `cs-check`, `lint:xml`, `lint:twig`, `phpstan` und `test` aus. Coverage-Lauf benötigt einen aktiven Treiber (`pcov` empfohlen, alternativ `xdebug` mit `XDEBUG_MODE=coverage`). Aggregat-Schwellen: `src/Service/` ≥ 80 %, `src/Subscriber/` ≥ 60 %. `git status` muss sauber sein — `.phpunit.result.cache` ist Test-Cache und darf nicht mitcommittet werden.

PHPStan-Banned-Code-Liste fängt `dump`, `dd`, `var_dump`, `error_log`, `extract`, `md5`, `sha1`, `rand`, `mt_rand`, `unserialize`.

## 2. Versions-Bump

1. `composer.json` → `version` auf neue SemVer-Nummer setzen.
2. `CHANGELOG_de-DE.md` und `CHANGELOG_en-GB.md` um neuen Versions-Eintrag ergänzen (Shopware-Plugin-Manager-Konvention: `# 1.2.1`-Header, Bullet-Liste der Änderungen).
3. Commit-Konvention: `release: Versions-Bump auf <version> plus CHANGELOG`.

## 3. DevBox-Push (Bare-Repo)

```bash
git push origin master
```

`git push origin master` synchronisiert nur das Bare-Repo der DevBox. Die laufenden Plugin-Container werden **nicht** automatisch aktualisiert — dafür ist Schritt 4 nötig.

## 4. Plugin-Sync zu DevBox-Instanzen (tar)

| Ziel | Pfad |
|------|------|
| Plugin-Master (Dev-Instanzen via Symlink) | `/workspace/plugins/RcCartSplitter/` |
| live-clone (direkte Kopie) | `/workspace/shopware/instances/live-clone/custom/plugins/RcCartSplitter/` |
| live-latest (direkte Kopie) | `/workspace/shopware/instances/live-latest/custom/plugins/RcCartSplitter/` |

Sync-Befehl (für jedes Ziel einmal aufrufen, Zielpfad nach `cd` anpassen):

```bash
tar cf - --exclude='.idea' --exclude='node_modules' --exclude='vendor' --exclude='.git' --exclude='var' --exclude='.phpunit.cache' . | \
  ssh devbox "cd /workspace/plugins/RcCartSplitter && tar xf -"
```

`/workspace/plugins/RcCartSplitter/` deckt dev-6781 und dev-6782 mit ab (Bind-Mount, identische Inodes). `live-clone` und `live-latest` brauchen je einen eigenen Sync.

Permissions: `/workspace/plugins/` gehört `www-data`. Beim erstmaligen Anlegen neuer Verzeichnisse vorab per `docker exec --user root ddev-<instance>-web mkdir + chown`.

## 5. Build-Schritte (auf jeder Ziel-Instanz)

| Änderung | Befehl |
|----------|--------|
| Nur PHP/Twig | `ddev exec php bin/console cache:clear` |
| Migration | zusätzlich `ddev exec php bin/console plugin:update RcCartSplitter` |
| SCSS | `ddev exec php bin/console theme:compile` |
| JavaScript | `ddev exec bin/build-storefront.sh` |

Reine VCS-Konfigurationsänderungen (`.gitignore`, `.editorconfig`) brauchen keinen Build-Schritt.

## 6. Plattform-Lints und Smoke-Tests auf live-clone

```bash
ddev exec php bin/console snippets:validate
ddev exec php bin/console lint:twig src/Resources/views
ddev exec php bin/console lint:xml src/Resources/config
ddev exec composer coverage
ddev exec composer coverage:gate
```

Manuelle Smoke-Tests:

- **Cart-Splitting:** zwei verschiedene TMMS-Eingaben am gleichen Produkt → zwei separate Positionen im Warenkorb. Gleiche Eingabe zweimal → Mengenerhöhung der bestehenden Position.
- **Screenreader:** NVDA oder VoiceOver liest „Kundeneingaben" als Gruppen-Label vor den Begriff-Wert-Paaren vor (über `aria-label` an `<dl>`).
- **Bestellabschluss:** Custom-Fields jeder Bestellposition zeigen die zur Position gehörende Eingabe. Ohne die Korrektur würde TMMS die letzte Eingabe auf alle Positionen schreiben.

## 7. BFSG-Live-Theme-Spotcheck

Das Plugin trifft eine Token-Wahl (`text-body-secondary`); die effektive Farbe hängt vom aktiven Theme ab. Bei Inbetriebnahme und nach jedem Theme-Update prüfen.

- Drei Screenshots: Mini-Cart, Cart-Page, Confirm-Page.
- Auswertung mit `axe DevTools`, Lighthouse oder `llama3.2-vision`.
- Schwelle: Kontrast ≥ 4.5:1 (WCAG 2.2 AA, kleiner Text).
- Befund unter `.ai/reviews/<datum>-bfsg-kontrast/` archivieren.

## 8. GitHub-Push (nur nach expliziter Freigabe)

GitHub-Pushes laufen niemals direkt auf `main`, sondern immer über den persistenten lokalen Branch `github-export`. **Force-Push ist verboten** — die Historie auf GitHub muss erhalten bleiben. **Erst nach expliziter Freigabe** durch den Verantwortlichen.

```bash
git checkout github-export
git merge master --no-edit
git rm -r --cached .ai/ scripts/ CLAUDE.md brief-template.md 2>/dev/null
git rm --cached -r vendor/ var/ node_modules/ .idea/ .vscode/ 2>/dev/null
git commit -m "Stand: <Beschreibung>"
git push github github-export:main
git checkout master
```

Whitelist (was nach GitHub darf): `src/`, `tests/`, `.github/workflows/`, `composer.json`, `composer.lock`, `phpunit.xml.dist`, `README.md`, `RELEASE.md`, `CHANGELOG_de-DE.md`, `CHANGELOG_en-GB.md`, `LICENSE`, `.editorconfig`, `.gitignore`, `.php-cs-fixer.php`, `phpstan.neon`.

Blacklist (was nicht nach GitHub darf): `scripts/`, `.ai/`, `CLAUDE.md`, `brief-template.md`, `vendor/`, `var/`, `node_modules/`, `.idea/`, `.vscode/`.

## 9. Release-Readiness-Gate (CI)

GitHub Actions führt den Job `release-readiness` bei Tag-Push (`v*`) aus und kombiniert `composer quality`, `composer coverage:gate` und `composer audit --no-dev` als harten Gate vor jedem Release-Tag. Ein roter Job blockiert den Release.
