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

To środowisko testowe **nie ma RabbitMQ** — Magento używa domyślnej kolejki opartej o bazę danych (DB queue driver). Konsument musi więc realnie działać w tle, nie tylko zbierać wiadomości w tabeli kolejki.

**To jest już zautomatyzowane w środowisku testowym, nie trzeba nic robić ręcznie.** Kontener `php` (`Dockerfile.php` + `docker/entrypoint.sh` + `supervisord.conf` w `magento-ordo-test/`) startuje przez `supervisord` zamiast `sleep infinity`:
- `entrypoint.sh` przy każdym starcie kontenera dopisuje do `app/etc/env.php` sekcję `cron_consumers_runner` z `cron_run => false` (jeśli `env.php` już istnieje, czyli Magento jest zainstalowane).
- `cron_run` jest celowo `false`, bo konsumery **nie** są uruchamiane przez cron — supervisord trzyma je jako stałe, długo żyjące procesy (`docker/run-consumer.sh ordo.automation.campaign.dispatch` / `...visitor.aggregate`, z `autorestart=true`), więc nie ma sensu, żeby cron *też* je odpalał (dublowałoby się to z realnym procesem konsumenta i marnowało cykle).
- `docker/run-magento-cron.sh` osobno pętli `bin/magento cron:run` co 60s — to obsługuje zwykłe zadania cronowe modułu (np. `Cron\PrunePendingPopups`), niezależnie od konsumentów kolejki.
- Wszystkie trzy skrypty czekają w pętli, aż `bin/magento`/`env.php` faktycznie istnieją (na wypadek świeżo zbudowanego obrazu przed instalacją Magento), więc bezpiecznie przetrwają `docker compose up -d --build` na pustym `magento/`.

Po świeżej instalacji Magento (`setup:install`/`setup:upgrade`) wystarczy `docker compose restart php`, żeby entrypoint dopisał `cron_consumers_runner` i supervisord zaczął trzymać konsumery żywe. Sprawdź `bin/magento queue:consumers:list` — powinien pokazać `ordo.automation.campaign.dispatch` i `ordo.automation.visitor.aggregate`.

Przy okazji `docker-compose.yml`'s `db` service ma teraz na stałe `--log_bin_trust_function_creators=1` w `command`, więc `SET GLOBAL log_bin_trust_function_creators = 1;` nie trzeba już ręcznie odtwarzać po każdym restarcie kontenera bazy przed `setup:upgrade`.

`CampaignDispatcher` cachuje też lookup "które kampanie są aktywne dla danego triggera" (tag cache `CampaignDispatcher::CACHE_TAG`, czyszczony przy zapisie/usunięciu kampanii/triggera — zobacz `Controller/Adminhtml/Campaign/Save.php`, `Delete.php`, `CampaignRepository.php`, `CampaignTriggerRepository.php`). Jeśli po zmianie triggera w adminie kampania "nie widzi" nowego triggera w testach manualnych, sprawdź najpierw czy cache faktycznie się wyczyścił (`bin/magento cache:flush` jako obejście, jeśli coś nie zadziała).

## Testy integracyjne (`Test/Integration/`) — realne DI, realna baza, bez mocków

`Test/Unit/` mockuje wszystkich współpracowników — dowodzi, że logika jest poprawna, ale nie że mechanizm faktycznie działa jako całość. `Test/Integration/` używa wariantu ze skilla `magento-testing:magento-integration-test-lite`: prawdziwy bootstrap całej zainstalowanej aplikacji Magento (`app/bootstrap.php` + `Bootstrap::create`), prawdziwe DI, prawdziwa baza dev — bez drugiej instalacji do `dev/tests/integration` i **bez transakcyjnego rollbacku**. To oznacza, że każdy test sam sprząta po sobie w `tearDown()` (usuwa utworzone kampanie/klientów/reguły/kupony/tagi) — jeśli piszesz nowy test integracyjny, dopisz tam czyszczenie, inaczej zaśmiecisz bazę dev na stałe.

Trzy pliki, różny zakres:
- `CampaignDispatchScenarioTest.php` — silnik dispatchera pod każdym kątem: każdy typ warunku, każdy typ akcji (poza `send_email`, patrz niżej), AND warunków, nieznany typ warunku/akcji (fail-closed), opóźnione akcje + wznowienie przez `Cron\RunScheduledCampaignActions` (cofa `run_at` zamiast czekać realnego czasu), kampanie z wieloma triggerami, cache trigger→kampanie (dowodzi że jest stale i że inwalidacja działa). Woła `CampaignDispatcher::dispatch()` bezpośrednio — **celowo pomija observery i kolejkę**, żeby testować silnik w izolacji od transportu.
- `CampaignQueueWiringTest.php` — dowodzi tego, co tamten plik pomija: prawdziwy event Magento (`customer_register_success`) faktycznie trafia do naszego observera (`etc/events.xml`), observer faktycznie publikuje na kolejkę (`etc/communication.xml`/`etc/queue*.xml`), a `bin/magento queue:consumers:start ordo.automation.campaign.dispatch --max-messages=1` (odpalane jako realny podproces, bo to środowisko ma tylko DB-queue, nie RabbitMQ) faktycznie konsumuje wiadomość i wywołuje dispatch.
- `CampaignSendEmailActionTest.php` — jedyne miejsce gdzie podstawiamy coś sztucznie: `SendEmail` woła prawdziwy `TransportBuilder`, który realnie wysłałby maila / wymagałby zarejestrowanego szablonu e-mail. Podmieniamy tylko ogon `TransportBuilder::getTransport()` (klasa `RecordingTransportBuilder` w tym samym pliku) — reszta zależności (`CustomerRepositoryInterface`, `StoreManagerInterface`) jest prawdziwa.
- `CampaignVisitorPopupScenarioTest.php` — ścieżka anonimowego odwiedzającego (Phase 5/7): realna agregacja `ordo_visitor_event` → `ordo_visitor_tag` bez logowania, realny dispatch triggera `visitor_tag_added`, realny warunek `visitor_tag`, realna akcja `popup` → prawdziwy wiersz `ordo_pending_popup`, plus test na realnej bazie że `UPDATE ... WHERE delivered_at IS NULL` faktycznie blokuje podwójne dostarczenie (nie mockowany SQL builder).

### Uruchomienie

**Ważne od czasu automatyzacji konsumentów przez supervisord (patrz sekcja wyżej): zatrzymaj oba stałe konsumery przed testami integracyjnymi, inaczej testy zawisną w nieskończoność.** `CampaignQueueWiringTest` i `CampaignVisitorPopupScenarioTest::drainPendingMessages()` publikują wiadomość, po czym same odpalają `bin/magento queue:consumers:start <temat> --max-messages=1` jako podproces i czekają, aż on skonsumuje *tę konkretną* wiadomość. Jeśli w tle działa też stały konsument z supervisorda (`consumer-campaign-dispatch`/`consumer-visitor-aggregate`), on zjada wiadomość pierwszy — podproces testu czeka wtedy na wiadomość, która już zniknęła, i wisi bez końca (napotkane i naprawione: dwa zawieszone przebiegi `phpunit` trzeba było ręcznie ubić przez `kill -9` po PID z `/proc/[0-9]*/cmdline`, bo `ps`/`pkill` nie ma w tym obrazie).

```bash
cd /Users/michalper/Projects/magento-ordo-test
docker compose up -d
docker compose exec php sh -c "rm -rf vendor/ordo/module-automation && composer update ordo/module-automation"

# zatrzymaj stałe konsumery na czas testów integracyjnych
docker compose exec php supervisorctl stop consumer-campaign-dispatch consumer-visitor-aggregate

# z katalogu Magento (nie modułu!) — wymaga --bootstrap app/bootstrap.php, inaczej BP nie istnieje
docker compose exec php vendor/bin/phpunit --bootstrap app/bootstrap.php \
    vendor/ordo/module-automation/Test/Integration

# odpal je z powrotem po testach
docker compose exec php supervisorctl start consumer-campaign-dispatch consumer-visitor-aggregate
```

Wymaga działającego `app/etc/env.php` (baza, cache) tego środowiska — to nie jest tryb bezstanowy, testy faktycznie łączą się z bazą dev. `CampaignQueueWiringTest` dodatkowo uruchamia `bin/magento` jako podproces (`exec()`), więc PHP w kontenerze musi mieć prawo odpalać poleceń powłoki.

## MFTF — od zapisu triggera po realny efekt

- `AdminCreateMultiTriggerCampaignTest.xml` — tylko triggery (wielotriggerowość).
- `AdminCreateCampaignWithConditionsAndActionsTest.xml` — to samo dla warunków i akcji (dotąd nieotestowane w MFTF).
- `AdminCampaignScenarioEndToEndTest.xml` — **jedyny test w całym module, który dowodzi tego z pytania "czy to w ogóle ma sens i działa" bez żadnego skrótu**: buduje scenariusz w adminie (trigger=`order_placed`, warunek=`order_total_gte`, akcja=`generate_coupon` — wybrana bo ma realne, widoczne UI w adminie; `add_tag`/`send_email` nie mają żadnego grida), realny klient robi realny checkout na storefroncie, `queue:consumers:start ordo.automation.campaign.dispatch --max-messages=1` przetwarza wiadomość (znów: brak RabbitMQ w tym środowisku), i na końcu sprawdza w Marketing → Cart Price Rules → Manage Coupon Codes, że kupon **faktycznie tam jest**.

### Uruchomienie MFTF w tym środowisku — realne pułapki, wszystkie napotkane i naprawione

To środowisko **nie ma prawdziwego webservera** — `docker-compose.yml`'s `php` service ma `command: sleep infinity`, więc ktoś (my) musi ręcznie odpalić wbudowany serwer PHP przed każdym `mftf run:test`. Napotkane i naprawione pułapki, w kolejności odkrycia:

1. **Brak serwera w ogóle** → `curl` do `localhost:8080` z kontenera php dostawał "connection refused". Trzeba go odpalić ręcznie:
   ```bash
   docker compose exec -d -e PHP_CLI_SERVER_WORKERS=8 php sh -c "cd /var/www/magento/dev/tests/acceptance/utils && exec php -S 0.0.0.0:8080 -t /var/www/magento/pub /tmp/router.php"
   ```
   **`PHP_CLI_SERVER_WORKERS=8` jest obowiązkowe** — bez tego wbudowany serwer PHP obsługuje jeden request na raz, a Selenium/Chrome robi wiele równoległych requestów (JS/CSS/AJAX) — bez workerów strona admina renderuje się z urwanymi assetami (`Uncaught SyntaxError`, RequireJS `Script error`) albo zawiesza się na 60s timeout.
   **Sprawdź, że port faktycznie się zwolnił przed restartem** — `docker compose exec` nie ubija starego procesu automatycznie; jeśli `php -S` już nasłuchuje na 8080, kolejne uruchomienie po prostu cicho nie zbinduje się i STARY proces (z inną konfiguracją) dalej obsługuje ruch, co wygląda jak "moja zmiana nie zadziałała". Zabij ręcznie przez skan `/proc/[0-9]*/cmdline` (brak `pgrep`/`ps` w tym obrazie) zanim wystartujesz nowy.
2. **Statyczne assety generowane w locie** (`app mode: default`) pogłębiają problem #1 nawet z workerami — każdy request do niewdrożonego pliku w `pub/static/` triggeruje LESS/JS kompilację. Wdróż raz na start środowiska:
   ```bash
   bin/magento setup:static-content:deploy -f en_US -a adminhtml
   bin/magento setup:static-content:deploy -f en_US -a frontend
   ```
3. **`<magentoCLI>` w testach MFTF zwracał HTTP 404** — `.env` nie miał `MAGENTO_CLI_COMMAND_PATH`/`MAGENTO_CLI_COMMAND_PARAMETER` w ogóle (MFTF bez nich POSTuje na pusty base URL). Dodane do `dev/tests/acceptance/.env`:
   ```
   MAGENTO_CLI_COMMAND_PATH=cli-bridge/a/b/command.php
   MAGENTO_CLI_COMMAND_PARAMETER=command
   ```
   **Ta ścieżka MUSI być dokładnie 3 katalogi głębokości pod `pub/`** — `dev/tests/acceptance/utils/command.php` liczy `bin/magento` jako `../../../../bin/magento` (4×`../`) **względem CWD, które wbudowany serwer PHP ustawia na `docroot + katalog żądania URL`**, nie względem rzeczywistej lokalizacji pliku (nawet jeśli plik jest symlinkiem, nawet jeśli serwer wystartował z innym CWD). Z docroot=`pub/`, 3 poziomy zagnieżdżenia + 4×`../` trafia dokładnie w katalog główny Magento. Symlink trzeba założyć ręcznie (nie jest w gicie, bo żyje w `pub/` środowiska testowego, nie w repo modułu):
   ```bash
   mkdir -p /var/www/magento/pub/cli-bridge/a/b
   ln -sf /var/www/magento/dev/tests/acceptance/utils/command.php /var/www/magento/pub/cli-bridge/a/b/command.php
   ```
4. **Indeksy "Update by Schedule" ukrywają świeżo utworzone dane testowe** — produkt stworzony przez `<createData entity="SimpleProduct2">` nie jest "salable"/dodawalny do koszyka, dopóki indeksy stocku/ceny się nie przeliczą. Zamiast wymuszać `indexer:reindex` w każdym teście (co dodatkowo failuje cały krok, jeśli akurat OpenSearch nie żyje, bo `catalogsearch_fulltext` kaskaduje), ustaw raz globalnie:
   ```bash
   bin/magento indexer:set-mode realtime cataloginventory_stock catalog_product_price catalog_product_attribute catalog_category_product catalog_product_category
   ```
   (celowo BEZ `catalogsearch_fulltext` — ten test go nie potrzebuje, a wymaga żywego OpenSearcha).

**Znany, nierozwiązany problem: losowe `tab crashed` / `Operation timed out` w Chrome/Selenium.** Ten host ma zainstalowanych mnóstwo innych, niezwiązanych projektów Docker (widoczne w `docker stats` — jeden kontener sam zajmował 66% limitu pamięci Dockera), przez co `db`/`opensearch` regularnie padają z OOM (exit 137), a sesje Chrome w Selenium crashują w losowym, nieprzewidywalnym momencie testu (raz na pierwszym kroku, raz tuż przed metą). To **nie jest błąd w kodzie modułu ani w samym teście** — `AdminCreateMultiTriggerCampaignTest` i `AdminCreateCampaignWithConditionsAndActionsTest` (czysto adminowe, krótsze) przechodzą stabilnie po tych poprawkach; `AdminCampaignScenarioEndToEndTest` (długi, prawdziwy checkout) dochodził w kolejnych próbach coraz dalej (aż do wyboru akcji `generate_coupon`) bez ani jednego prawdziwego błędu logicznego, ale nie ukończył się w pełni z powodu wyczerpania pamięci hosta. Żeby faktycznie dokończyć weryfikację tego testu: albo zwolnij pamięć hosta (zatrzymaj inne, niepotrzebne w danej chwili projekty Docker), albo uruchom go w mniej obciążonym środowisku/CI.

Uruchomienie:
```bash
docker compose exec php vendor/bin/mftf generate:tests AdminCampaignScenarioEndToEndTest AdminCreateCampaignWithConditionsAndActionsTest AdminCreateMultiTriggerCampaignTest
docker compose exec php vendor/bin/mftf run:test AdminCampaignScenarioEndToEndTest AdminCreateCampaignWithConditionsAndActionsTest AdminCreateMultiTriggerCampaignTest
```
