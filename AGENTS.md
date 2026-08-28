# AGENTS.md

Moduł Magento 2 `ordo/module-automation` (marketing automation dla Ordo/Sellina): kreator scenariuszy kampanii (triggery → warunki → akcje) z edytorem flow opartym o Drawflow.

## Jak testować zmiany

Ten katalog to samodzielne repo modułu — nie ma tu `vendor/`, więc testów nie da się odpalić bezpośrednio stąd. Testy jednostkowe uruchamia się w osobnym środowisku Magento:

- Środowisko testowe: `/Users/michalper/Projects/magento-ordo-test/`
  - `docker-compose.yml` — usługi `db` (MySQL 8), `opensearch`, `php` (kontener `ordo_test_php`), `selenium`.
  - `magento/` — pełna instalacja Magento Open Source 2.4.7.
  - Ten katalog (`mma`) jest zamontowany w kontenerze php jako `/var/www/mma`.

**Ważna pułapka:** moduł jest podpięty do Magento przez composer path repository z `"options": {"symlink": false}` — to znaczy, że Composer **kopiuje** pliki do `vendor/ordo/module-automation`, a nie linkuje symlinkiem. **Zmiany w plikach tego repo nie są widoczne w środowisku testowym, dopóki nie odświeżysz kopii przez `composer update`.**

**Druga pułapka:** wersja modułu w jego `composer.json` jest przypięta na sztywno (`"version": "1.0.0"`), więc samo `composer update ordo/module-automation` czasem zwraca "Nothing to modify in lock file" i **nie** przekopiowuje nowych plików, bo Composer nie widzi zmiany wersji. Jeśli po `composer update` testy dalej widzą starą wersję kodu, wymuś reinstalację:
```bash
docker compose exec php sh -c "rm -rf vendor/ordo/module-automation && composer update ordo/module-automation"
```

### Komendy

```bash
cd /Users/michalper/Projects/magento-ordo-test

# uruchomienie środowiska (jeśli kontenery nie działają)
docker compose up -d

# po KAŻDEJ zmianie w plikach mma — odśwież kopię w vendor/
docker compose exec php composer update ordo/module-automation

# uruchomienie testów jednostkowych tego modułu
docker compose exec php vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/ordo/module-automation/Test/Unit

# albo cały pakiet testów jednostkowych Magento (moduł jest w nim uwzględniony automatycznie)
docker compose exec php vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist
```

Testy modułu wchodzą w skład standardowego testsuite `Magento_Unit_Tests_Other` zdefiniowanego w `magento/dev/tests/unit/phpunit.xml.dist` (obejmuje `vendor/*/module-*/Test/Unit`) — nie trzeba żadnej dodatkowej konfiguracji, wystarczy mieć aktualną kopię w `vendor/`.

## Dispatch kampanii jest asynchroniczny (kolejka Magento)

Triggery (`order_placed`, `customer_registered`, `tag_added`) nie wołają już `CampaignDispatcher::dispatch()` bezpośrednio z observera — publikują wiadomość na temat `ordo.automation.campaign.dispatch` (`Model/Queue/CampaignDispatchPublisher.php`), którą odbiera `Model/Queue/CampaignDispatchConsumer.php`. To po to, żeby checkout/rejestracja klienta nie czekały na wykonanie warunków/akcji kampanii.

To środowisko testowe **nie ma RabbitMQ** — Magento używa domyślnej kolejki opartej o bazę danych (DB queue driver). Żeby konsument faktycznie ruszał automatycznie (a nie tylko zbierał wiadomości w tabeli kolejki), trzeba w `magento/app/etc/env.php` środowiska testowego dodać sekcję `cron_consumers_runner`, np.:

```php
'cron_consumers_runner' => [
    'cron_run' => true,
    'max_messages' => 20000,
    'consumers' => ['ordo.automation.campaign.dispatch'],
],
```

Po dodaniu tej sekcji uruchom `bin/magento setup:upgrade` (żeby zarejestrować konfigurację kolejki/consumera) i sprawdź `bin/magento queue:consumers:list` — powinien pokazać `ordo.automation.campaign.dispatch`.

`CampaignDispatcher` cachuje też lookup "które kampanie są aktywne dla danego triggera" (tag cache `CampaignDispatcher::CACHE_TAG`, czyszczony przy zapisie/usunięciu kampanii/triggera — zobacz `Controller/Adminhtml/Campaign/Save.php`, `Delete.php`, `CampaignRepository.php`, `CampaignTriggerRepository.php`). Jeśli po zmianie triggera w adminie kampania "nie widzi" nowego triggera w testach manualnych, sprawdź najpierw czy cache faktycznie się wyczyścił (`bin/magento cache:flush` jako obejście, jeśli coś nie zadziała).

## Testy integracyjne (`Test/Integration/`) — realne DI, realna baza, bez mocków

`Test/Unit/` mockuje wszystkich współpracowników — dowodzi, że logika jest poprawna, ale nie że mechanizm faktycznie działa jako całość. `Test/Integration/` używa wariantu ze skilla `magento-testing:magento-integration-test-lite`: prawdziwy bootstrap całej zainstalowanej aplikacji Magento (`app/bootstrap.php` + `Bootstrap::create`), prawdziwe DI, prawdziwa baza dev — bez drugiej instalacji do `dev/tests/integration` i **bez transakcyjnego rollbacku**. To oznacza, że każdy test sam sprząta po sobie w `tearDown()` (usuwa utworzone kampanie/klientów/reguły/kupony/tagi) — jeśli piszesz nowy test integracyjny, dopisz tam czyszczenie, inaczej zaśmiecisz bazę dev na stałe.

Trzy pliki, różny zakres:
- `CampaignDispatchScenarioTest.php` — silnik dispatchera pod każdym kątem: każdy typ warunku, każdy typ akcji (poza `send_email`, patrz niżej), AND warunków, nieznany typ warunku/akcji (fail-closed), opóźnione akcje + wznowienie przez `Cron\RunScheduledCampaignActions` (cofa `run_at` zamiast czekać realnego czasu), kampanie z wieloma triggerami, cache trigger→kampanie (dowodzi że jest stale i że inwalidacja działa). Woła `CampaignDispatcher::dispatch()` bezpośrednio — **celowo pomija observery i kolejkę**, żeby testować silnik w izolacji od transportu.
- `CampaignQueueWiringTest.php` — dowodzi tego, co tamten plik pomija: prawdziwy event Magento (`customer_register_success`) faktycznie trafia do naszego observera (`etc/events.xml`), observer faktycznie publikuje na kolejkę (`etc/communication.xml`/`etc/queue*.xml`), a `bin/magento queue:consumers:start ordo.automation.campaign.dispatch --max-messages=1` (odpalane jako realny podproces, bo to środowisko ma tylko DB-queue, nie RabbitMQ) faktycznie konsumuje wiadomość i wywołuje dispatch.
- `CampaignSendEmailActionTest.php` — jedyne miejsce gdzie podstawiamy coś sztucznie: `SendEmail` woła prawdziwy `TransportBuilder`, który realnie wysłałby maila / wymagałby zarejestrowanego szablonu e-mail. Podmieniamy tylko ogon `TransportBuilder::getTransport()` (klasa `RecordingTransportBuilder` w tym samym pliku) — reszta zależności (`CustomerRepositoryInterface`, `StoreManagerInterface`) jest prawdziwa.

### Uruchomienie

```bash
cd /Users/michalper/Projects/magento-ordo-test
docker compose up -d
docker compose exec php sh -c "rm -rf vendor/ordo/module-automation && composer update ordo/module-automation"

# z katalogu Magento (nie modułu!) — wymaga --bootstrap app/bootstrap.php, inaczej BP nie istnieje
docker compose exec php vendor/bin/phpunit --bootstrap app/bootstrap.php \
    vendor/ordo/module-automation/Test/Integration
```

Wymaga działającego `app/etc/env.php` (baza, cache) tego środowiska — to nie jest tryb bezstanowy, testy faktycznie łączą się z bazą dev. `CampaignQueueWiringTest` dodatkowo uruchamia `bin/magento` jako podproces (`exec()`), więc PHP w kontenerze musi mieć prawo odpalać poleceń powłoki.

## MFTF — od zapisu triggera po realny efekt

- `AdminCreateMultiTriggerCampaignTest.xml` — tylko triggery (wielotriggerowość).
- `AdminCreateCampaignWithConditionsAndActionsTest.xml` — to samo dla warunków i akcji (dotąd nieotestowane w MFTF).
- `AdminCampaignScenarioEndToEndTest.xml` — **jedyny test w całym module, który dowodzi tego z pytania "czy to w ogóle ma sens i działa" bez żadnego skrótu**: buduje scenariusz w adminie (trigger=`order_placed`, warunek=`order_total_gte`, akcja=`generate_coupon` — wybrana bo ma realne, widoczne UI w adminie; `add_tag`/`send_email` nie mają żadnego grida), realny klient robi realny checkout na storefroncie, `queue:consumers:start ordo.automation.campaign.dispatch --max-messages=1` przetwarza wiadomość (znów: brak RabbitMQ w tym środowisku), i na końcu sprawdza w Marketing → Cart Price Rules → Manage Coupon Codes, że kupon **faktycznie tam jest**.

Uruchomienie (wymaga działającego Selenium — `docker compose up -d` w `magento-ordo-test` uruchamia też `ordo_test_selenium`, ale bywa zatrzymany po restarcie hosta, sprawdź `docker compose ps` najpierw):
```bash
docker compose exec php vendor/bin/mftf run:test AdminCampaignScenarioEndToEndTest AdminCreateCampaignWithConditionsAndActionsTest AdminCreateMultiTriggerCampaignTest
```
