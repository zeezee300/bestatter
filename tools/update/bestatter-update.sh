#!/usr/bin/env bash
# Spielt ein neues Installationspaket der App „Bestatter“ ein und prüft das Ergebnis.
#
#   bash bestatter-update.sh PAKET [Optionen]            # nur prüfen und den Ablauf anzeigen (Probelauf)
#   bash bestatter-update.sh PAKET [Optionen] --execute  # Update wirklich ausführen
#
# PAKET: bestatter-x.y.z.tar.gz, .zip oder ein bereits entpackter Ordner „bestatter“.
#
# Ablauf mit --execute:
#   1. Prüfsumme (SHA-256) des Pakets prüfen, Paket entpacken, Version und Nextcloud-Kompatibilität prüfen
#   2. Sicherung: occ bestatter:backup --include-files (Fachpaket mit Fallakten)
#   3. bisherigen App-Ordner als Archiv im Ablageordner aufbewahren (Rückweg)
#   4. Wartungsmodus an, App-Ordner austauschen, occ upgrade (Datenbank-Migrationen), Wartungsmodus aus
#   5. occ bestatter:update-check: Version, Systemprüfung, Audit-Kette; Ergebnis wird vermerkt
# Scheitert „occ upgrade“ für Bestatter, werden die bisherigen App-Dateien zurückgelegt und der Wartungsmodus
# bleibt an. Ist Bestatter migriert und betrifft der Fehler nur Nextcloud oder eine andere App, bleiben die neuen Dateien.
#
# Optionen:
#   --execute               Update ausführen (ohne: Probelauf, nichts wird verändert)
#   --docker CONTAINER      Nextcloud läuft in Docker (z. B. „nextcloud-aio-nextcloud“ oder der Name aus
#                           „docker compose ps“); occ und Dateizugriffe laufen dann im Container
#   --nextcloud DIR         Nextcloud-Ordner auf dem Server (Standard: /var/www/nextcloud oder /var/www/html)
#   --occ "BEFEHL"          eigener occ-Aufruf, z. B. "sudo -u www-data php /srv/nextcloud/occ"
#   --sha256 PRÜFSUMME      erwartete SHA-256-Prüfsumme (Standard: Datei PAKET.sha256 daneben)
#   --no-checksum           Prüfsumme nicht prüfen (nicht empfohlen)
#   --backup ART            full (Standard: Fachpaket mit Dateien), snapshot (nur Datenbankinhalt der App), none
#   --keep DIR              Ablageordner für das bisherige App-Archiv und das Protokoll
#                           (Standard: ./bestatter-update-<Datum>-<Uhrzeit>)
#   --check-user KONTO      Konto, unter dem die Systemprüfung liest (Standard: erstes Mitglied der
#                           Bestatter-Administration)
#   --allow-same            dieselbe Version erneut einspielen (z. B. nach einer Korrektur der Dateien)
#   --allow-downgrade       ältere Version einspielen (nur mit passender Sicherung; Migrationen laufen nicht zurück)
#   -h, --help              diese Hilfe
#
# Rückgabewerte: 0 = in Ordnung, 1 = abgebrochen vor jeder Änderung, 2 = eingespielt, aber die Prüfung oder
# „occ upgrade“ (für Nextcloud/andere Apps) meldet Fehler, 3 = „occ upgrade“ gescheitert, Wartungsmodus an.
set -uo pipefail

main() {
	local PACKAGE="" EXECUTE=0 CONTAINER="" NCDIR="" OCC_CMD="" SHA="" NO_CHECKSUM=0 BACKUP="full" KEEP="" CHECK_USER=""
	local ALLOW_SAME=0 ALLOW_DOWNGRADE=0
	while [ $# -gt 0 ]; do
		case "$1" in
			--execute) EXECUTE=1; shift ;;
			--docker) CONTAINER="${2:-}"; shift 2 ;;
			--nextcloud) NCDIR="${2:-}"; shift 2 ;;
			--occ) OCC_CMD="${2:-}"; shift 2 ;;
			--sha256) SHA="${2:-}"; shift 2 ;;
			--no-checksum) NO_CHECKSUM=1; shift ;;
			--backup) BACKUP="${2:-}"; shift 2 ;;
			--keep) KEEP="${2:-}"; shift 2 ;;
			--check-user) CHECK_USER="${2:-}"; shift 2 ;;
			--allow-same) ALLOW_SAME=1; shift ;;
			--allow-downgrade) ALLOW_DOWNGRADE=1; shift ;;
			-h|--help) sed -n '2,36p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; return 0 ;;
			-*) echo "Unbekannte Option: $1 (Hilfe: --help)" >&2; return 1 ;;
			*) [ -z "$PACKAGE" ] || { echo "Nur ein Paket angeben." >&2; return 1; }; PACKAGE="$1"; shift ;;
		esac
	done
	case "$BACKUP" in full|snapshot|none) ;; *) echo "--backup erwartet full, snapshot oder none" >&2; return 1 ;; esac
	[ -n "$PACKAGE" ] || { echo "Paket fehlt. Aufruf: bash bestatter-update.sh bestatter-x.y.z.tar.gz [--execute] (Hilfe: --help)" >&2; return 1; }
	[ -e "$PACKAGE" ] || { echo "Paket nicht gefunden: $PACKAGE" >&2; return 1; }

	local STAMP; STAMP="$(date +%Y%m%d-%H%M%S)"
	[ -n "$KEEP" ] || KEEP="$PWD/bestatter-update-$STAMP"
	case "$KEEP" in /*) ;; *) KEEP="$PWD/$KEEP" ;; esac
	# Der Ablageordner darf nicht in einem App-Ordner liegen: Beim Austausch würde er mit dem alten App-Ordner
	# verschoben und am Ende gelöscht (Archiv und Protokoll wären weg).
	local INSIDE
	if INSIDE="$(app_dir_above "$KEEP")"; then
		echo "ABBRUCH: Der Ablageordner $KEEP liegt im App-Ordner $INSIDE." >&2
		echo "  Bitte aus einem eigenen Ordner außerhalb von custom_apps starten (z. B. /root/updates) oder --keep ORDNER angeben." >&2
		return 1
	fi
	local WORK; WORK="$(mktemp -d "${TMPDIR:-/tmp}/bestatter-update.XXXXXX")" || { echo "Kein temporärer Ordner möglich" >&2; return 1; }
	# shellcheck disable=SC2064
	trap "rm -rf '$WORK'" EXIT
	LOG="$WORK/update.log"; : > "$LOG"

	say "Bestatter-Update – $( [ $EXECUTE -eq 1 ] && echo 'AUSFÜHRUNG' || echo 'Probelauf (nichts wird verändert; zum Ausführen --execute anhängen)')"

	# --- occ und Dateizugriff festlegen -------------------------------------------------------------------------
	if [ -n "$CONTAINER" ]; then
		command -v docker >/dev/null || fail "docker nicht gefunden"
		docker inspect "$CONTAINER" >/dev/null 2>&1 || fail "Container „$CONTAINER“ nicht gefunden (docker ps)"
		[ -n "$OCC_CMD" ] || OCC_CMD="docker exec -u www-data $CONTAINER php occ"
	else
		if [ -z "$OCC_CMD" ]; then
			if [ -z "$NCDIR" ]; then
				for candidate in /var/www/nextcloud /var/www/html; do [ -f "$candidate/occ" ] && { NCDIR="$candidate"; break; }; done
			fi
			[ -n "$NCDIR" ] && [ -f "$NCDIR/occ" ] || fail "Nextcloud-Ordner nicht gefunden – mit --nextcloud DIR, --occ \"BEFEHL\" oder --docker CONTAINER angeben"
			local OWNER; OWNER="$(stat -c %U "$NCDIR/config/config.php" 2>/dev/null)" || fail "$NCDIR/config/config.php nicht lesbar (als root oder als Webserver-Benutzer starten)"
			if [ "$(id -un)" = "$OWNER" ]; then OCC_CMD="php $NCDIR/occ"
			elif [ "$(id -u)" = "0" ]; then OCC_CMD="sudo -u $OWNER php $NCDIR/occ"
			else fail "Bitte als root oder als „$OWNER“ starten (Besitzer von config.php)"; fi
		fi
	fi
	FS_CONTAINER="$CONTAINER"
	say "occ:            $OCC_CMD"

	# --- 1. Paket prüfen ---------------------------------------------------------------------------------------
	local NEWDIR=""
	if [ -d "$PACKAGE" ]; then
		NEWDIR="$(cd "$PACKAGE" && pwd)"
		[ "$(basename "$NEWDIR")" = "bestatter" ] || [ -f "$NEWDIR/appinfo/info.xml" ] || fail "Ordner $PACKAGE enthält keine App (appinfo/info.xml fehlt)"
		say "Prüfsumme:      entfällt (entpackter Ordner)"
	else
		if [ $NO_CHECKSUM -eq 1 ]; then
			say "Prüfsumme:      NICHT geprüft (--no-checksum)"
		else
			if [ -z "$SHA" ] && [ -f "$PACKAGE.sha256" ]; then SHA="$(cut -d' ' -f1 < "$PACKAGE.sha256" | tr -d '\r\n ')"; fi
			[ -n "$SHA" ] || fail "Keine Prüfsumme: --sha256 PRÜFSUMME angeben oder $(basename "$PACKAGE").sha256 daneben legen (oder bewusst --no-checksum)"
			local ACTUAL; ACTUAL="$(sha256sum "$PACKAGE" | cut -d' ' -f1)"
			[ "${ACTUAL,,}" = "${SHA,,}" ] || fail "Prüfsumme stimmt nicht: erwartet $SHA, Paket hat $ACTUAL – Paket nicht verwenden"
			say "Prüfsumme:      in Ordnung ($ACTUAL)"
		fi
		mkdir -p "$WORK/pkg"
		case "$PACKAGE" in
			*.tar.gz|*.tgz) tar -xzf "$PACKAGE" -C "$WORK/pkg" || fail "Paket lässt sich nicht entpacken" ;;
			*.zip) command -v unzip >/dev/null || fail "unzip nicht installiert (oder das tar.gz verwenden)"; unzip -q "$PACKAGE" -d "$WORK/pkg" || fail "Paket lässt sich nicht entpacken" ;;
			*) fail "Unbekanntes Paketformat (erwartet .tar.gz, .zip oder Ordner)" ;;
		esac
		NEWDIR="$WORK/pkg/bestatter"
	fi
	[ -f "$NEWDIR/appinfo/info.xml" ] || fail "Im Paket fehlt bestatter/appinfo/info.xml"
	grep -q '<id>bestatter</id>' "$NEWDIR/appinfo/info.xml" || fail "Das Paket ist nicht die App „bestatter“"
	[ -f "$NEWDIR/vendor/autoload.php" ] || fail "Im Paket fehlt vendor/autoload.php (unvollständiges Paket)"
	[ -f "$NEWDIR/js/main.js" ] || fail "Im Paket fehlt js/main.js (unvollständiges Paket)"
	local NEWVER NCMIN NCMAX
	NEWVER="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$NEWDIR/appinfo/info.xml" | head -1)"
	NCMIN="$(sed -n 's:.*<nextcloud[^>]*min-version="\([0-9]*\)".*:\1:p' "$NEWDIR/appinfo/info.xml" | head -1)"
	NCMAX="$(sed -n 's:.*<nextcloud[^>]*max-version="\([0-9]*\)".*:\1:p' "$NEWDIR/appinfo/info.xml" | head -1)"
	[ -n "$NEWVER" ] || fail "Keine Version im Paket"
	say "Neue Version:   $NEWVER (Nextcloud ${NCMIN:-?}–${NCMAX:-?})"

	# --- Zustand der Installation (nur lesend) --------------------------------------------------------------------
	local STATUS NCVER NCMAJOR OLDVER APPDIR
	STATUS="$(occ status --output=json 2>>"$LOG")" || fail "occ status fehlgeschlagen – occ-Aufruf prüfen (siehe --occ, --docker)"
	NCVER="$(printf '%s' "$STATUS" | sed -n 's/.*"versionstring":"\([^"]*\)".*/\1/p')"
	NCMAJOR="$(printf '%s' "$STATUS" | sed -n 's/.*"version":"\([0-9]*\)\..*/\1/p')"
	if printf '%s' "$STATUS" | grep -q '"maintenance":true'; then fail "Nextcloud ist bereits im Wartungsmodus – erst klären, warum (occ maintenance:mode --off)"; fi
	if printf '%s' "$STATUS" | grep -q '"needsDbUpgrade":true'; then fail "Nextcloud wartet bereits auf „occ upgrade“ – das zuerst erledigen und prüfen"; fi
	say "Nextcloud:      ${NCVER:-?}"
	if [ -n "$NCMAJOR" ] && [ -n "$NCMIN" ] && [ -n "$NCMAX" ] && { [ "$NCMAJOR" -lt "$NCMIN" ] || [ "$NCMAJOR" -gt "$NCMAX" ]; }; then
		fail "Version $NEWVER unterstützt Nextcloud $NCMIN–$NCMAX, installiert ist $NCVER"
	fi
	OLDVER="$(occ config:app:get bestatter installed_version 2>>"$LOG" | tr -d '\r\n')"
	APPDIR="$(occ app:getpath bestatter 2>>"$LOG" | tr -d '\r\n')"
	[ -n "$OLDVER" ] || fail "Die App ist nicht installiert – Erstinstallation siehe Handbuch, Kapitel Installation"
	[ -n "$APPDIR" ] || fail "App-Ordner nicht ermittelbar (occ app:getpath bestatter)"
	fs "test -f '$APPDIR/appinfo/info.xml'" || fail "App-Ordner $APPDIR nicht erreichbar$( [ -z "$CONTAINER" ] && echo ' – läuft Nextcloud in Docker? Dann --docker CONTAINER angeben')"
	say "Installiert:    $OLDVER in $APPDIR$( [ -n "$CONTAINER" ] && echo " (Container $CONTAINER)")"

	if [ "$NEWVER" = "$OLDVER" ] && [ $ALLOW_SAME -eq 0 ]; then fail "Version $NEWVER ist bereits installiert (erneut einspielen nur mit --allow-same)"; fi
	if [ "$NEWVER" != "$OLDVER" ] && [ "$(printf '%s\n%s\n' "$NEWVER" "$OLDVER" | sort -V | tail -1)" = "$OLDVER" ]; then
		[ $ALLOW_DOWNGRADE -eq 1 ] || fail "$NEWVER ist älter als die installierte Version $OLDVER – kein Downgrade (Datenbank-Migrationen laufen nicht zurück; Rückweg: Sicherung)"
		say "WARNUNG:        Downgrade $OLDVER → $NEWVER ausdrücklich erlaubt"
	fi
	local HASCHECK=0
	if occ list bestatter 2>>"$LOG" | grep -q 'bestatter:update-check'; then HASCHECK=1; fi
	if [ $HASCHECK -eq 1 ]; then
		say "Prüfung vorher (installierte Version):"
		occ bestatter:update-check $( [ -n "$CHECK_USER" ] && printf -- '--as-user=%s' "$CHECK_USER" ) 2>&1 | sed 's/^/                /' | tee -a "$LOG"
	fi

	local PARENT; PARENT="$(dirname "$APPDIR")"
	say ""
	say "Ablauf:"
	say "  1. Sicherung: $(case $BACKUP in full) echo 'occ bestatter:backup --include-files';; snapshot) echo 'occ bestatter:backup (nur Datenbankinhalt der App)';; none) echo 'keine (--backup none)';; esac)"
	say "  2. bisherigen App-Ordner sichern nach $KEEP/bestatter-$OLDVER-app.tar.gz"
	say "  3. Wartungsmodus an, $APPDIR austauschen ($OLDVER → $NEWVER), occ upgrade, Wartungsmodus aus"
	say "  4. occ bestatter:update-check --expect-version=$NEWVER --record"
	if [ $EXECUTE -eq 0 ]; then
		say ""
		say "Probelauf beendet – alle Vorprüfungen in Ordnung. Zum Ausführen denselben Befehl mit --execute aufrufen."
		return 0
	fi

	# === Ab hier wird verändert ====================================================================================
	mkdir -p "$KEEP" || fail "Ablageordner $KEEP nicht anlegbar"
	KEEP="$(cd "$KEEP" && pwd)"; KEEP_DIR="$KEEP"
	local BACKUPPATH=""
	if [ "$BACKUP" != "none" ]; then
		say ""; say "Schritt 1: Sicherung …"
		local OUT
		if [ "$BACKUP" = "full" ]; then OUT="$(occ bestatter:backup --include-files 2>&1)"; else OUT="$(occ bestatter:backup 2>&1)"; fi
		local RC=$?
		printf '%s\n' "$OUT" >> "$LOG"
		[ $RC -eq 0 ] || fail "Sicherung fehlgeschlagen – nichts verändert. Ausgabe im Protokoll."
		BACKUPPATH="$(printf '%s' "$OUT" | sed -n 's/^ *"path": *"\(.*\)",\{0,1\}$/\1/p' | head -1 | sed 's:\\/:/:g')"
		say "  Sicherung: ${BACKUPPATH:-angelegt (Pfad siehe Protokoll)}"
	fi

	say "Schritt 2: bisherigen App-Ordner sichern …"
	if [ -n "$CONTAINER" ]; then
		docker exec "$CONTAINER" tar -czf - -C "$PARENT" "$(basename "$APPDIR")" > "$KEEP/bestatter-$OLDVER-app.tar.gz" 2>>"$LOG" || fail "App-Ordner nicht sicherbar – nichts verändert"
	else
		tar -czf "$KEEP/bestatter-$OLDVER-app.tar.gz" -C "$PARENT" "$(basename "$APPDIR")" 2>>"$LOG" || fail "App-Ordner nicht sicherbar – nichts verändert"
	fi
	say "  $KEEP/bestatter-$OLDVER-app.tar.gz"

	say "Schritt 3: neue Dateien bereitstellen …"
	local STAGE="$PARENT/.bestatter-update-new" OLDSTAGE="$PARENT/.bestatter-update-old"
	local APPOWNER; APPOWNER="$(fsout "stat -c %u:%g '$APPDIR'")" || APPOWNER=""
	fs "rm -rf '$STAGE' '$OLDSTAGE'" || fail "Arbeitsordner in $PARENT nicht anlegbar"
	if [ -n "$CONTAINER" ]; then
		docker cp "$NEWDIR" "$CONTAINER:$STAGE" >>"$LOG" 2>&1 || fail "Kopieren in den Container gescheitert – nichts verändert"
	else
		cp -a "$NEWDIR" "$STAGE" 2>>"$LOG" || fail "Kopieren nach $PARENT gescheitert – nichts verändert"
	fi
	[ -n "$APPOWNER" ] && fs "chown -R '$APPOWNER' '$STAGE'"
	fs "test -f '$STAGE/appinfo/info.xml'" || { fs "rm -rf '$STAGE'"; fail "Neue Dateien unvollständig – nichts verändert"; }

	say "  Wartungsmodus an"
	occ maintenance:mode --on >>"$LOG" 2>&1 || { fs "rm -rf '$STAGE'"; fail "Wartungsmodus ließ sich nicht einschalten – nichts verändert"; }
	if ! fs "mv '$APPDIR' '$OLDSTAGE' && mv '$STAGE' '$APPDIR'"; then
		fs "test -d '$APPDIR' || mv '$OLDSTAGE' '$APPDIR'"; fs "rm -rf '$STAGE'"
		occ maintenance:mode --off >>"$LOG" 2>&1
		fail "Austausch des App-Ordners gescheitert – alter Stand wiederhergestellt"
	fi
	say "  App-Ordner ausgetauscht, occ upgrade …"
	local UPOUT UPRC UPGRADE_WARN=0
	UPOUT="$(occ upgrade --no-interaction 2>&1)"; UPRC=$?
	printf '%s\n' "$UPOUT" >> "$LOG"
	if [ $UPRC -ne 0 ]; then
		local NOWVER; NOWVER="$(occ config:app:get bestatter installed_version 2>/dev/null | tail -1 | tr -d '\r\n ')"
		say "FEHLER: occ upgrade meldet:"
		printf '%s\n' "$UPOUT" | grep -v '^[[:space:]]*$' | tail -3 | sed 's/^ */    /' | tee -a "$LOG"
		if [ "$NOWVER" = "$NEWVER" ]; then
			# Bestatter selbst ist migriert (installed_version wird erst danach gesetzt); der Fehler betrifft Nextcloud
			# oder eine andere App. Die alten Dateien passen nicht mehr zur Datenbank – deshalb nicht zurücklegen.
			UPGRADE_WARN=1
			say "  Die Datenbank von Bestatter ist aber auf $NEWVER aktualisiert; die neuen Dateien bleiben."
			fs "rm -rf '$OLDSTAGE'"
			if occ status --output=json 2>/dev/null | grep -q '"needsDbUpgrade":true'; then
				occ maintenance:mode --on >>"$LOG" 2>&1
				say "  Nextcloud wartet weiter auf „occ upgrade“ – der Wartungsmodus bleibt AN."
				say "  Ursache beheben (Meldung oben, Protokoll $KEEP/update.log), dann occ upgrade, occ maintenance:mode --off"
				say "  und occ bestatter:update-check --expect-version=$NEWVER."
				finish_log "$KEEP"; return 3
			fi
		else
			say "  Lege die bisherigen App-Dateien ($OLDVER) zurück."
			fs "rm -rf '$PARENT/.bestatter-update-failed' && mv '$APPDIR' '$PARENT/.bestatter-update-failed' && mv '$OLDSTAGE' '$APPDIR' && rm -rf '$PARENT/.bestatter-update-failed'" \
				&& say "  Bisherige Dateien liegen wieder in $APPDIR." \
				|| say "  Zurücklegen gescheitert! Archiv der bisherigen Dateien: $KEEP/bestatter-$OLDVER-app.tar.gz (nach $PARENT entpacken)"
			occ maintenance:mode --on >>"$LOG" 2>&1
			say "  Der Wartungsmodus bleibt AN. Bereits gelaufene Migrationen bleiben in der Datenbank."
			say "  Nächste Schritte: Protokoll lesen ($KEEP/update.log), Ursache beheben und das Update wiederholen;"
			say "  oder mit dem bisherigen Stand weiterarbeiten (occ upgrade, occ maintenance:mode --off, Systemprüfung)"
			say "  bzw. die Sicherung zurückspielen (occ bestatter:restore, siehe Handbuch „Datensicherung“)."
			finish_log "$KEEP"; return 3
		fi
	fi
	occ maintenance:mode --off >>"$LOG" 2>&1
	fs "rm -rf '$OLDSTAGE'"
	say "  Datenbank aktualisiert, Wartungsmodus aus"

	say "Schritt 4: Prüfung nach dem Update …"
	local CHECKARGS=(--expect-version="$NEWVER" --record --from-version="$OLDVER")
	[ -n "$BACKUPPATH" ] && CHECKARGS+=(--backup="$BACKUPPATH")
	[ -n "$CHECK_USER" ] && CHECKARGS+=(--as-user="$CHECK_USER")
	local CHECKOUT CHECKRC
	CHECKOUT="$(occ bestatter:update-check "${CHECKARGS[@]}" 2>&1)"; CHECKRC=$?
	printf '%s\n' "$CHECKOUT" | sed 's/^/  /' | tee -a "$LOG"
	say ""
	if [ $CHECKRC -ne 0 ]; then
		say "Update auf $NEWVER eingespielt, aber die Prüfung meldet FEHLER (siehe oben)."
		[ $HASCHECK -eq 1 ] && say "Fehler, die schon vor dem Update bestanden, stehen oben unter „Prüfung vorher“."
		say "Bitte in Bestatter unter Administration → Systemprüfung nachsehen. Muss das Update zurückgenommen werden:"
		say "Server-Sicherung von vor dem Update (Datenbank und Datenverzeichnis) zusammen mit dem Archiv"
		say "$KEEP/bestatter-$OLDVER-app.tar.gz (Handbuch, Kapitel Technik → Update einspielen). Sicherung der App: ${BACKUPPATH:-keine}."
		finish_log "$KEEP"; return 2
	fi
	if [ $UPGRADE_WARN -eq 1 ]; then
		say "Update $OLDVER → $NEWVER eingespielt und geprüft; „occ upgrade“ meldete aber einen Fehler außerhalb von Bestatter"
		say "(siehe oben und Protokoll) – bitte von der Nextcloud-Betreuung klären lassen."
		finish_log "$KEEP"; return 2
	fi
	say "Update $OLDVER → $NEWVER abgeschlossen und geprüft."
	finish_log "$KEEP"
	return 0
}

LOG=""
FS_CONTAINER=""
KEEP_DIR=""
say() { printf '%s\n' "$*"; [ -n "$LOG" ] && printf '%s\n' "$*" >> "$LOG"; return 0; }
fail() { say "ABBRUCH: $*"; [ -n "$KEEP_DIR" ] && [ -d "$KEEP_DIR" ] && finish_log "$KEEP_DIR"; exit 1; }
# occ-Aufruf; OCC_CMD wird bewusst in Wörter zerlegt (z. B. "sudo -u www-data php /var/www/nextcloud/occ").
occ() { # shellcheck disable=SC2086
	$OCC_CMD "$@"; }
# Dateibefehl auf dem Server bzw. im Container (als root, damit Besitzrechte erhalten bleiben).
fs() { if [ -n "$FS_CONTAINER" ]; then docker exec -u 0 "$FS_CONTAINER" sh -c "$1" >>"$LOG" 2>&1; else sh -c "$1" >>"$LOG" 2>&1; fi; }
fsout() { if [ -n "$FS_CONTAINER" ]; then docker exec -u 0 "$FS_CONTAINER" sh -c "$1" 2>>"$LOG"; else sh -c "$1" 2>>"$LOG"; fi; }
# Gibt den nächsten übergeordneten Ordner aus, der eine Nextcloud-App ist (appinfo/info.xml), sonst Rückgabe 1.
app_dir_above() {
	local dir="$1"
	while [ -n "$dir" ] && [ "$dir" != "/" ] && [ "$dir" != "." ]; do
		[ -f "$dir/appinfo/info.xml" ] && { printf '%s' "$dir"; return 0; }
		dir="$(dirname "$dir")"
	done
	return 1
}
finish_log() { cp "$LOG" "$1/update.log" 2>/dev/null && printf 'Protokoll: %s\n' "$1/update.log"; return 0; }

main "$@"; exit $?
