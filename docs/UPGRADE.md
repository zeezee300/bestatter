# Installation und Upgrade

Stand: 0.59.0, Nextcloud 34

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

Erwartet werden `bestatter: 0.59.0`, `maintenance: false` und `needsDbUpgrade: false`.

Die Anwendung befindet sich weiterhin in der internen Entwicklung. Hinweise zu Tests und Datenbankänderungen stehen in `ENTWICKLUNG.md`; ein öffentlicher Upgradepfad wird erst vor der ersten externen Installation festgelegt.
