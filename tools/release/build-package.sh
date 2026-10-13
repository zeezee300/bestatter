#!/usr/bin/env bash
# Baut das Installationspaket der App (tar.gz für den Nextcloud App Store, zusätzlich zip) aus dem Quellcode.
#
#   bash tools/release/build-package.sh [Optionen]
#
#   --out DIR             Zielordner (Standard: releases/)
#   --vendor-from DIR     fertigen vendor/-Ordner übernehmen statt "composer install --no-dev" auszuführen
#   --key DATEI           privater App-Store-Schlüssel (bestatter.key) – nur zum Signieren
#   --cert DATEI          Zertifikat der Nextcloud-Zertifizierungsstelle (bestatter.crt) – nur zum Signieren
#   --occ "BEFEHL"        occ-Aufruf einer Nextcloud-Instanz für "integrity:sign-app",
#                         z. B. "php /var/www/nextcloud/occ" oder "docker exec -u www-data nextcloud-test php occ"
#   --container NAME      bei Docker: Name des Containers, in den Paket, Schlüssel und Zertifikat kurz kopiert werden
#   --skip-checks         Tests und PHPStan vor dem Bauen auslassen (nur im Notfall; das Paket wird als ungeprüft gemeldet)
#
# Vor dem Bauen laufen die Tests (node tests/run-all.mjs) und PHPStan. Schlägt eins fehl, entsteht kein Paket.
# PHPStan wird gesucht als $PHPSTAN (z. B. Pfad zu phpstan.phar), vendor/bin/phpstan oder phpstan im Suchpfad;
# Konfiguration: $PHPSTAN_CONFIG, sonst phpstan.neon. Fehlt der Ordner tests/ (öffentliche Arbeitskopie, CI), werden
# beide Prüfungen mit Hinweis übersprungen – die CI führt PHPStan in einem eigenen Schritt aus.
#
# Ohne --key/--cert entsteht ein unsigniertes Paket (Testinstallation). Mit --key/--cert/--occ wird zuerst
# appinfo/signature.json erzeugt (Nextcloud-Codesignatur) und danach die App-Store-Signatur des tar.gz in
# <paket>.tar.gz.sig geschrieben. Der private Schlüssel verlässt den Rechner nicht und wird nicht ins Paket gepackt.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$ROOT/releases"; VENDOR_FROM=""; KEY=""; CERT=""; OCC=""; CONTAINER=""; SKIP_CHECKS=0
while [ $# -gt 0 ]; do
	case "$1" in
		--out) OUT="$2"; shift 2 ;;
		--vendor-from) VENDOR_FROM="$2"; shift 2 ;;
		--key) KEY="$2"; shift 2 ;;
		--cert) CERT="$2"; shift 2 ;;
		--occ) OCC="$2"; shift 2 ;;
		--container) CONTAINER="$2"; shift 2 ;;
		--skip-checks) SKIP_CHECKS=1; shift ;;
		-h|--help) sed -n '2,26p' "$0"; exit 0 ;;
		*) echo "Unbekannte Option: $1" >&2; exit 2 ;;
	esac
done
fail() { echo "FEHLER: $*" >&2; exit 1; }
# Zielordner absolut machen: zip läuft später im Bauordner, ein relativer Pfad (z. B. --out dist) zeigte dort ins Leere.
mkdir -p "$OUT"; OUT="$(cd "$OUT" && pwd)"

# 1. Versionen müssen übereinstimmen
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ROOT/appinfo/info.xml")
[ -n "$VERSION" ] || fail "Keine Version in appinfo/info.xml"
grep -q "\"version\": \"$VERSION\"" "$ROOT/package.json" || fail "package.json hat nicht Version $VERSION"
grep -q "VERSION = '$VERSION'" "$ROOT/lib/AppInfo/Application.php" || fail "Application::VERSION ist nicht $VERSION"
grep -qE "^## $VERSION( |$)" "$ROOT/CHANGELOG.md" || fail "CHANGELOG.md hat keinen Abschnitt „## $VERSION“ (Format des App Stores: ^## x.y.z)"
[ -f "$ROOT/js/main.js" ] || fail "js/main.js fehlt – vorher „pnpm run build“ ausführen"
if [ -n "$KEY$CERT" ] && { [ -z "$KEY" ] || [ -z "$CERT" ] || [ -z "$OCC" ]; }; then fail "Zum Signieren sind --key, --cert und --occ nötig"; fi

# 1b. Prüfungen vor dem Bauen: Tests und PHPStan müssen grün sein, sonst entsteht kein Paket.
CHECKS="nicht ausgeführt"
if [ $SKIP_CHECKS -eq 1 ]; then
	echo "WARNUNG: --skip-checks – Tests und PHPStan wurden NICHT ausgeführt; das Paket ist ungeprüft." >&2
	CHECKS="übersprungen (--skip-checks)"
elif [ ! -f "$ROOT/tests/run-all.mjs" ]; then
	echo "Hinweis: kein Ordner tests/ (öffentliche Arbeitskopie bzw. CI) – Tests und PHPStan werden hier nicht ausgeführt."
	CHECKS="hier nicht ausgeführt (kein tests/)"
else
	command -v node >/dev/null || fail "node nicht gefunden – für die Tests wird Node.js ≥ 22 benötigt (oder --skip-checks)"
	echo "Prüfung: Tests …"
	node "$ROOT/tests/run-all.mjs" "$ROOT" > "$OUT/.tests-$VERSION.log" 2>&1 || { tail -40 "$OUT/.tests-$VERSION.log" >&2; fail "Tests fehlgeschlagen – kein Paket gebaut (Protokoll: $OUT/.tests-$VERSION.log)"; }
	echo "  $(tail -1 "$OUT/.tests-$VERSION.log")"
	rm -f "$OUT/.tests-$VERSION.log"
	PHPSTAN_BIN="${PHPSTAN:-}"
	[ -n "$PHPSTAN_BIN" ] || { [ -x "$ROOT/vendor/bin/phpstan" ] && PHPSTAN_BIN="$ROOT/vendor/bin/phpstan"; } || true
	[ -n "$PHPSTAN_BIN" ] || PHPSTAN_BIN="$(command -v phpstan || true)"
	[ -n "$PHPSTAN_BIN" ] || fail "PHPStan nicht gefunden – PHPSTAN=/pfad/phpstan.phar setzen, composer install (Entwicklung) ausführen oder --skip-checks"
	echo "Prüfung: PHPStan …"
	(cd "$ROOT" && php -d memory_limit=4G "$PHPSTAN_BIN" analyse -c "${PHPSTAN_CONFIG:-$ROOT/phpstan.neon}" --no-progress > "$OUT/.phpstan-$VERSION.log" 2>&1) || { tail -40 "$OUT/.phpstan-$VERSION.log" >&2; fail "PHPStan meldet Fehler – kein Paket gebaut (Protokoll: $OUT/.phpstan-$VERSION.log)"; }
	rm -f "$OUT/.phpstan-$VERSION.log"
	echo "  PHPStan: keine Fehler"
	CHECKS="Tests und PHPStan bestanden"
fi

# 2. Paketordner zusammenstellen (nur Laufzeitdateien)
BUILD="$(mktemp -d)"; trap 'rm -rf "$BUILD"' EXIT
APP="$BUILD/bestatter"; mkdir -p "$APP"
for item in appinfo css img js lib resources templates CHANGELOG.md README.md LICENSE THIRD-PARTY-NOTICES.md composer.json composer.lock; do
	[ -e "$ROOT/$item" ] || fail "$item fehlt"
	cp -R "$ROOT/$item" "$APP/"
done
rm -f "$APP/appinfo/signature.json" "$APP"/js/*.map
# Update-Skript für Server-Betreuer (bin/bestatter-update.sh, siehe Handbuch „Technik → Update“)
mkdir -p "$APP/bin" && cp "$ROOT/tools/update/bestatter-update.sh" "$APP/bin/bestatter-update.sh" && chmod 755 "$APP/bin/bestatter-update.sh"
if [ -n "$VENDOR_FROM" ]; then
	[ -f "$VENDOR_FROM/autoload.php" ] || fail "$VENDOR_FROM/autoload.php fehlt"
	cp -R "$VENDOR_FROM" "$APP/vendor"
else
	command -v composer >/dev/null || fail "composer nicht gefunden – alternativ --vendor-from <ordner> angeben"
	(cd "$APP" && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --quiet)
fi
rm -f "$APP/composer.lock"
# Entwicklungsdateien der Bibliotheken entfernen (Tests, Werkzeugkonfiguration); Lizenzen und Quellen bleiben.
for pkg in "$APP"/vendor/*/*/; do
	[ -d "$pkg" ] || continue
	rm -rf "$pkg"test "$pkg"tests "$pkg".github "$pkg".gitattributes "$pkg".gitignore "$pkg"phpcs.xml* "$pkg"phpunit.xml* \
		"$pkg"package.json "$pkg"package-lock.json "$pkg"CHANGELOG.md
done
find "$APP" \( -name '.git' -o -name '.github' -o -name '.DS_Store' -o -name 'node_modules' -o -name '*.orig' \) -prune -exec rm -rf {} +
[ -f "$APP/vendor/autoload.php" ] || fail "vendor/autoload.php fehlt im Paket"
if command -v php >/dev/null; then
	find "$APP/appinfo" "$APP/lib" "$APP/templates" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null || fail "PHP-Syntaxfehler im Paket"
fi

# 3. Optional: Nextcloud-Codesignatur (appinfo/signature.json)
if [ -n "$KEY" ]; then
	if [ -n "$CONTAINER" ]; then
		TMP="/tmp/bestatter-sign-$$"
		docker exec "$CONTAINER" mkdir -p "$TMP"
		docker cp "$APP" "$CONTAINER:$TMP/bestatter"
		docker cp "$KEY" "$CONTAINER:$TMP/app.key"
		docker cp "$CERT" "$CONTAINER:$TMP/app.crt"
		docker exec "$CONTAINER" chown -R www-data "$TMP"
		set +e
		$OCC integrity:sign-app --privateKey="$TMP/app.key" --certificate="$TMP/app.crt" --path="$TMP/bestatter"
		RC=$?
		set -e
		[ $RC -eq 0 ] && docker cp "$CONTAINER:$TMP/bestatter/appinfo/signature.json" "$APP/appinfo/signature.json"
		docker exec "$CONTAINER" rm -rf "$TMP"
		[ $RC -eq 0 ] || fail "integrity:sign-app fehlgeschlagen"
	else
		$OCC integrity:sign-app --privateKey="$(realpath "$KEY")" --certificate="$(realpath "$CERT")" --path="$APP"
	fi
	[ -s "$APP/appinfo/signature.json" ] || fail "appinfo/signature.json wurde nicht erzeugt"
fi

# 4. Archive packen (ein Wurzelordner "bestatter", ohne Dateirechte/Besitzer des Bau-Rechners)
mkdir -p "$OUT"
TAR="$OUT/bestatter-$VERSION.tar.gz"; ZIP="$OUT/bestatter-$VERSION.zip"
rm -f "$TAR" "$ZIP" "$TAR.sig" "$TAR.sha256" "$ZIP.sha256"
tar --sort=name --owner=0 --group=0 --numeric-owner -C "$BUILD" -czf "$TAR" bestatter
if command -v zip >/dev/null; then (cd "$BUILD" && zip -qrX "$ZIP" bestatter); fi

# Prüfsummen-Dateien (werden von bin/bestatter-update.sh neben dem Paket erwartet)
(cd "$OUT" && sha256sum "$(basename "$TAR")" > "$(basename "$TAR").sha256")
[ -f "$ZIP" ] && (cd "$OUT" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")

# 5. Optional: App-Store-Signatur des tar.gz
if [ -n "$KEY" ]; then
	openssl dgst -sha512 -sign "$KEY" "$TAR" | openssl base64 -A > "$TAR.sig"
fi

echo "Paket:   $TAR ($(du -h "$TAR" | cut -f1), $(tar -tzf "$TAR" | grep -vc '/$') Dateien)"
[ -f "$ZIP" ] && echo "Zip:     $ZIP"
echo "SHA-256: $(sha256sum "$TAR" | cut -d' ' -f1)"
echo "Prüfungen: $CHECKS"
if [ -n "$KEY" ]; then echo "Signiert: appinfo/signature.json im Paket, App-Store-Signatur in $TAR.sig"
else echo "Hinweis: unsigniert – für den App Store mit --key/--cert/--occ erneut bauen."; fi
