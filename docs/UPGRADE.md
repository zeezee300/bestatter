# Installation und Upgrade

Stand: 0.65.0, Nextcloud 34; Nextcloud 35 noch nicht laufzeitgetestet

## Voraussetzungen

- PHP 8.2 bis 8.5 einschließlich GD und ZipArchive
- vollständiger Release-Ordner `vendor/`
- funktionierender Nextcloud-Cron
- für PDF-Ausgaben ein Nextcloud-Konvertierungsanbieter oder EuroOffice

## Testumgebung aktualisieren

```sh
docker exec -u www-data nextcloud-bestatter-test php occ upgrade
docker restart nextcloud-bestatter-test
docker exec -u www-data nextcloud-bestatter-test php occ status
docker exec -u www-data nextcloud-bestatter-test php occ app:list | grep -A2 bestatter
```

Auf Nextcloud 34 werden nach dem App-Upgrade `bestatter: 0.65.0`, `maintenance: false` und `needsDbUpgrade: false` erwartet. Für Nextcloud 35 ist vor dem Containerwechsel die isolierte Abnahme gemäß `docs/NEXTCLOUD-35-KOMPATIBILITAET.md` erforderlich.

0.65.0 erweitert die deklarierte App-Kompatibilität auf Nextcloud 34 und 35, ohne Datenbankmigration. Der lokale statische Codeabgleich deckt die bekannten Nextcloud-35-Bruchstellen ab; ein PHP-/Integrationstest unter Nextcloud 35 konnte in dieser Entwicklungsumgebung noch nicht laufen. **Das Paket allein ist keine Freigabe für das Upgrade des bestehenden Containers.** Die bisherige 34er-Installation nicht allein wegen der neuen `max-version` auf 35 umstellen. Die Entwicklerabhängigkeit wurde auf OCP 34/35 erweitert, der vorhandene `composer.lock` bleibt bis zu einem Composer-Matrixlauf auf OCP 34 fixiert.

0.64.2 nutzt den zentralen Nextcloud-Systemabsender und eine gültige Nextcloud-Profiladresse der sendenden Person als `Reply-To`. Die bisherigen App-Werte `business_mail_from` und `business_mail_enabled` werden ignoriert. Migration 4000 ergänzt `reply_to` in der Versand-Outbox; vor dem Upgrade Datenbank sichern. Nach Installation und Upgrade eine interne Testmail mit PDF, Absender, Antwortadresse und Outbox-Eintrag prüfen. Bei fehlender Mitarbeiteradresse antwortet der Empfänger an das Geschäfts-Postfach. Die Funktion ist bei gültiger Nextcloud-Mailkonfiguration verfügbar, aber bis zur fachlichen Freigabe nur im Testsystem zu verwenden. OP-040 zur gemeinsamen Fallablage bleibt offen.

0.64.1 erlaubt den Versand auch für die per Nextcloud-UID zugeordnete Person. Es gibt keine neue Migration. Bestehende Fallzuständigkeiten, die als Kontakt-Anzeigenamen gespeichert wurden, müssen durch einen Bestatter-Administrator auf einen Benutzer aus der Bestatter-Gruppe umgestellt werden. Vor dem produktiven Mehrbenutzerbetrieb bleibt OP-040 (gemeinsame Fallablage) zwingend offen; bis dahin kann der Versand eines von einem anderen Konto erzeugten Dokuments scheitern. Der Versand bleibt standardmäßig deaktiviert und darf erst nach interner SMTP-/PDF-Abnahme freigeschaltet werden.

0.64.0 ergänzt den standardmäßig deaktivierten, kontrollierten PDF-Versand aus einem zentralen Geschäfts-Postfach. Migration 3900 legt die Versand-Outbox mit eindeutiger Versandkennung an; vor `occ upgrade` daher Datenbank und Fallakten sichern. Nach dem Upgrade erst die Nextcloud-Mailkonfiguration mit einer internen Testadresse prüfen, dann `business_mail_from` setzen und `business_mail_enabled=yes` ausschließlich im Testsystem aktivieren. Ein vom Mailserver angenommener Versand ist kein Zustellnachweis. Details und Abnahme: `docs/TICKET-BEST-119-GESCHAEFTSPOSTFACH-VERSAND.md`.

0.63.1 ergänzt den Papiervertrag: finale Bestattungsauftrag-PDFs werden als fortlaufende Revisionen abgelegt; unterschriebene Scans aus externem Vertrag oder eigener PDF-Revision bleiben zunächst in der Sichtprüfung. Der Status „handschriftlich unterschrieben“ erfordert drei Bestätigungen und wird mit Prüfer/Zeitpunkt protokolliert. Keine neue Datenbankmigration. Im Testsystem beide Wege, doppelte Finalisierung sowie einen unvollständigen Scan prüfen. Direkte Nextcloud-Dateiänderungen bleiben außerhalb der App-Sperren; dies ist keine revisionssichere Archivierung.

0.63.0 vereinigt Bestattungsart und Untervarianten in der Liste `BURIAL_VARIANT`. Migration 3800 erweitert das Feld für Bestattungsart auf 180 Zeichen, ergänzt „Baumbestattung“ als eigene Hauptart und ordnet bestehende Baum-Untervarianten um; „Waldbestattung“ bleibt eigenständig. Vor dem Upgrade Datenbank sichern, vollständige App-Dateien ausliefern, dann `occ upgrade` ausführen. Die historische Liste `FUNERAL_TYPE` bleibt für Altdaten in der Datenbank, ist aber nicht mehr pflegbar. Alte Fälle ohne Variantencode werden nicht automatisch geändert. Danach die Fallauswahl und den Aufnahmeassistenten im Testsystem prüfen.

0.62.2 sortiert die Wertelisten-Navigation alphabetisch und erläutert die getrennten Listen „Bestattungsart“ und „Bestattungsvarianten“. Keine Datenbankmigration und keine Änderung gespeicherter Listen- oder Fallwerte.

0.62.1 ergänzt die Pflege der Bestattungs-Untervarianten in Customizing → Wertelisten. Es gibt keine neue Datenbankmigration. Nach Bereitstellung der vollständigen App-Dateien und `occ upgrade` im Testsystem die Neuanlage einer Untervariante, den Elternwechsel, die Fallauswahl und die Regelpflege im Browser prüfen. Der Entwicklungsreset ist dafür nicht nötig und wurde nicht ausgeführt.

0.62.0 enthält keine neue Datenbankmigration. Die Bedienhilfe wird nur angezeigt, wenn der Administrator einen für `core:text2text:chat` verfügbaren Nextcloud-Task-Processing-Anbieter eingerichtet und den Aufgabentyp nicht deaktiviert hat. Nur `resources/help/BEDIENUNG.md` wird als benötigte Hilfe-Laufzeitressource mitgeliefert; interne `docs/`, Tickets und Tests sind nicht im Paket. Nach Bereitstellung des Pakets sind PHP-Lint, Provider-Erkennung und Browserabnahme auf der Testinstanz zu prüfen.

Die additive Migration 3700 ergänzt die Variantenhierarchie, Fallmerkmale und Regel-/Staffeltabellen. Vor dem Upgrade die Datenbank sichern, die vollständigen App-Dateien bereitstellen und danach `occ upgrade` ausführen. Zuschlagsregeln sind zunächst deaktiviert und erzeugen keine Positionen. Die Migration und ein Browserdurchlauf auf der Testinstanz sind nach Bereitstellung des Pakets noch abzunehmen.

Bei einem zuvor gescheiterten Upgrade von 0.60.2 auf 0.61.0 (MariaDB 1005/150 beim selbstreferenzierenden Fremdschlüssel) die korrigierten App-Dateien aus 0.61.1 vollständig bereitstellen und `occ upgrade` erneut ausführen. Keine Tabellen oder Spalten manuell löschen; die Migration prüft bestehende Schemaobjekte. Wartungsmodus erst nach erfolgreichem Upgrade und geprüftem `occ status` verlassen. Falls der Fehler erneut auftritt, die vollständige SQL-/Nextcloud-Logmeldung und `SHOW CREATE TABLE oc_bestatter_choice_items` sichern; nicht auf Verdacht das Schema verändern.

Die Anwendung befindet sich weiterhin in der internen Entwicklung. Hinweise zu Tests und Datenbankänderungen stehen in `ENTWICKLUNG.md`; ein öffentlicher Upgradepfad wird erst vor der ersten externen Installation festgelegt.
