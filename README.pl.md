# Ordo Automation dla Magento 2

*[Read in English](README.md)*

Marketing automation działający *wewnątrz* **czystego Magento Open Source** — bez licencji Adobe Commerce B2B, bez zewnętrznej subskrypcji MA (Klaviyo, iPresso, SalesManago...). Każdy trigger liczony jest z danych, które Magento już ma (zamówienia, koszyki, klienci), albo z niewielkiego własnego modelu danych dołożonego obok.

Celem tego modułu jest bycie realną alternatywą dla ogólnego platformy MA na sklepie Magento — nie garstką dodatków "w stylu B2B" — obejmującą zarówno klasyczną automatyzację cyklu życia B2C, jak i triggery B2B, których strukturalnie nie widzi większość zewnętrznych narzędzi MA.

## Dlaczego nie po prostu Klaviyo/iPresso/SalesManago?

Są mocne w kanałach komunikacji (e-mail/SMS/push) i UI kampanii, ale widzą tylko to, co sklep wyeksportuje przez generyczny konektor — sumy zamówień, eventy koszyka, oglądane produkty. Nie liczą wzorców cyklu zakupowego, nie wiedzą o wygasaniu ofert, limitach kredytowych czy hierarchii firm, a ich śledzenie zachowań B2C działa przez snippet JS + cookie całkowicie odcięty od danych zamówień po stronie serwera. Ordo Automation działa wewnątrz samego Magento, więc triggery mogą łączyć obie te rzeczy bez warstwy integracyjnej.

## Funkcje (v0.8)

**B2B**
- **Przypomnienia o ponownym zamówieniu (reorder)** — wykrywa powtarzalny wzorzec zakupowy per klient/SKU z historii zamówień i wysyła przypomnienie przed przewidywaną datą kolejnego zamówienia.
- **Przypomnienia o wygasaniu oferty/wyceny** — własna encja oferty (`ordo_offer`) z *proaktywnym* przypomnieniem "wygasa za N dni" — każda sprawdzona przez nas ugruntowana platforma B2B (Adobe Commerce B2B, OroCommerce) powiadamia dopiero reaktywnie, po zmianie statusu.
- **Alerty limitu kredytowego** — atrybut limitu kredytowego klienta + cron ostrzegający przy konfigurowalnym progu (domyślnie 80%) zanim konto zostanie zablokowane, zamiast reagować dopiero po przekroczeniu. Status jest też odczytywalny na żywo przez REST (`GET /V1/ordo/credit-limit/mine`), nie tylko wypychany e-mailem — niezależny front może pokazać "ile zostało limitu" we własnym koncie klienta. Zobacz `API.md`.
- **Workflow akceptacji zamówień** — opcjonalny limit wydatków per klient + e-mail do administratora akceptacji; zamówienia powyżej limitu są wstrzymywane (status `Pending Approval`, ten sam stan "new", więc rezerwacja zapasu nie jest naruszana), a administrator dostaje link akceptuj/odrzuć oparty na tokenie, bez logowania. Nierozstrzygnięte akceptacje są eskalowane (ponowna wysyłka, z limitem) po konfigurowalnej liczbie dni.
- **Gratis powyżej progu koszyka** — administrator definiuje pulę gratisów oraz jeden lub więcej kaskadowych progów wartości koszyka; każdy osiągnięty próg DODAJE slot na gratis kumulatywnie (nie jeden płaski próg ani stała liczba 1), a klient wybiera z puli przez REST. Zobacz `API.md`.

**B2C**
- **Odzyskiwanie porzuconych koszyków** — znajduje nieaktywne koszyki powyżej konfigurowalnego progu wartości i wysyła e-mail przypominający, z limitem liczby wysyłek na koszyk.
- **E-mail powitalny** — uruchamiany na `customer_register_success`, taguje klienta jako `new_customer`.
- **E-mail win-back / reaktywacyjny** — nocne tagowanie klientów nieaktywnych od N dni, po czym jednorazowy e-mail win-back; tagi znikają automatycznie, gdy klient znów złoży zamówienie.

**Wspólny fundament**
- **Tagowanie behawioralne** (`CustomerTagManager`) — prymityw segmentacji, z którego korzysta każdy trigger powyżej (`new_customer`, `inactive`, `win_back_sent`, ...), ten sam wzorzec, na którym ogólne platformy MA budują targetowanie kampanii.
- **Podpis opiekuna handlowego** (`SalesRepEmailContext`) — każdy automatyczny e-mail powyżej jest podpisany danymi przypisanego opiekuna klienta, jeśli taki jest ustawiony, w przeciwnym razie nazwą sklepu. Cotygodniowy digest grupuje też nieaktywnych klientów per opiekun.
- **Silnik kampanii** (`ordo_campaign` + `ordo_campaign_condition` + `ordo_campaign_action`) — realnie konfigurowalny silnik reguł "gdy zdarzy się X i Y jest prawdą, zrób Z", nie zaszyty na sztywno cron per pomysł. Warunki i akcje to plug-iny rejestrowane w `di.xml` (`Model\Campaign\ConditionPool` / `ActionPool`) względem `Api\Campaign\ConditionInterface` / `ActionInterface` — dodanie nowego typu warunku lub akcji to nowa klasa + jedna linijka w `di.xml`, nigdy zmiana w dispatcherze. W zestawie dwa warunki (`tag`, `order_total_gte`) i trzy akcje (`add_tag`, `send_email`, `generate_coupon`), uruchamiane na `order_placed`, `customer_registered` i `tag_added` (ten ostatni jako event Magento wystrzeliwany przez `CustomerTagManager`, nie bezpośrednie wywołanie, żeby uniknąć cyklu DI z warunkiem `tag`). Wystawiony jako pełny kontrakt usługi (`CampaignRepositoryInterface`) z endpointami REST pod `/V1/ordo/campaigns`.
- **Śledzenie zachowań na stronie** — bezzależnościowy snippet JS (`tracker.js`) ustawia własne cookie odwiedzającego i wysyła eventy `page_view`/`product_view`/`category_view`; tożsamość jest doszywana do klienta po zalogowaniu, a powtarzające się odsłony zamieniają się w tagi silnika kampanii (np. `viewed_category_view_15`) zamiast surowego zapisu per-klik.
- **Panel administracyjny** — dedykowane menu adminowe "Ordo Automation" z dashboardem, pełnym kreatorem kampanii (grid + formularz z dynamicznymi wierszami warunków/akcji, listy typów zawsze zsynchronizowane z tym, co zarejestrowane w `di.xml`) oraz diagnostycznym gridem cykli reorder. Oferty/progi/pula gratisów są na razie zarządzane tylko przez REST API/bazę danych (brak jeszcze gridu adminowego — zobacz `ROADMAP.md`).

Wszystko konfigurowalne pod **Stores → Configuration → Ordo Automation** (albo, dla kampanii i gratisów, przez ich tabele / REST API), każda funkcja z własnym przełącznikiem włącz/wyłącz i własnym zadaniem cron (zobacz `etc/crontab.xml`).

**Uwaga o skali:** silnik kampanii celowo działa na ustrukturyzowanych, rzadkich zdarzeniach (zamówienia, rejestracje, zmiany tagów) — garstka wierszy per klient, nie per klik. Surowe, wysokoczęstotliwościowe śledzenie zachowań na stronie (odsłony/kliknięcia przez snippet JS powyżej) to inny problem skali i *nie* jest przechowywane jako jeden wiersz per event w tym samym schemacie — zobacz `ROADMAP.md`, Faza 5, po pełny opis projektu i jak to zasila system tagów zamiast go omijać.

## Architektura

```
etc/
  module.xml, di.xml, crontab.xml, db_schema.xml, events.xml, email_templates.xml, acl.xml, webapi.xml
  adminhtml/system.xml          — konfiguracja sklepu
  frontend/routes.xml           — /ordo/approval/* (na tokenie, bez logowania)
Api/, Api/Data/                 — kontrakty usług: Offer*, Campaign*, Campaign/ConditionInterface, Campaign/ActionInterface
Cron/
  CalculateReorderCycle.php, SendReorderReminders.php
  SendAbandonedCartReminders.php
  SendOfferExpiryReminders.php, ExpireOverdueOffers.php
  SendCreditLimitAlerts.php
  TagInactiveCustomers.php, SendWinBackEmails.php
  EscalateStalePendingApprovals.php
  SendSalesRepDigest.php
Observer/
  SendWelcomeEmail.php                        — customer_register_success
  HoldOrderForApproval.php                    — sales_order_place_after
  DispatchOrderPlacedCampaigns.php            — sales_order_place_after
  DispatchCustomerRegisteredCampaigns.php     — customer_register_success
  DispatchTagAddedCampaigns.php               — ordo_customer_tag_added (zdarzenie własne)
Controller/Approval/            — Approve.php, Reject.php (akcje frontowe na tokenie)
Model/, Model/ResourceModel/     — ordo_reorder_cycle, ordo_offer, ordo_customer_tag, ordo_order_approval, ordo_campaign(_condition/_action)
Model/Campaign/                  — ConditionPool, ActionPool, Condition/*, Action/* (rejestr plug-inów)
Model/CampaignDispatcher.php     — "event triggera + kontekst na wejściu, pasujące kampanie się wykonują"
Model/Rule/Action/Discount/      — CheapestItemFree (własny kalkulator SalesRule), QualifyingSetTracker
Controller/Adminhtml/Campaign/, ReorderCycle/ — kontrolery gridu/formularza adminowego
Block/Adminhtml/Campaign/Edit/   — bloki przycisków toolbara (Back/Delete/Save & Continue)
Ui/Component/Listing/Column/     — CampaignActions (linki Edit/Delete w wierszach)
view/adminhtml/ui_component/     — ordo_campaign_listing, ordo_campaign_form, ordo_reorder_cycle_listing
Model/CreditLimitCalculator.php  — used-credit liczone z otwartych sales_order.total_due
Model/CreditLimitManagement.php  — wrapper REST-owy (mine / po id klienta) nad kalkulatorem powyżej
Model/FreeGiftOffer(Tier/Product).php, Model/FreeGiftManagement.php — oferty gratisów z kaskadowymi progami + wybór
Model/QuoteGiftItem.php          — znacznik łączący quote_item z ofertą, z której gratis został przyznany
Observer/TrimExcessFreeGifts.php — usuwa gratisy, które przestały się kwalifikować przy spadku wartości koszyka
Model/CustomerTagManager.php     — add/remove/check/list-by-tag; wystrzeliwuje ordo_customer_tag_added
Model/CouponGenerator.php        — generuje jednorazowy kod kuponu SalesRule
Model/SalesRepEmailContext.php   — współdzielony blok podpisu w e-mailu
Setup/Patch/Data/                — atrybuty klienta (limit kredytowy/wydatków, e-mail administratora akceptacji, opiekun), status zamówienia Pending Approval
Helper/Config.php                — typowany dostęp do wartości z system.xml
view/frontend/email/             — szablony e-mail
Controller/Track/Event.php       — publiczny, zwolniony z CSRF endpoint śledzenia
Model/VisitorEventLogger.php     — zapisuje ordo_visitor_event, uruchamia agregację gdy tożsamość jest znana
Model/VisitorAggregator.php      — surowe eventy → tagi ordo_customer_tag po przekroczeniu progu
view/frontend/web/js/tracker.js  — bezzależnościowy snippet cookie odwiedzającego + eventów
Test/Unit/                       — testy PHPUnit (aktualny stan pokrycia w ROADMAP.md, Faza 6)
i18n/                            — pliki CSV tłumaczeń (en_US, pl_PL)
```

## Instalacja

```bash
composer require ordo/module-automation
bin/magento module:enable Ordo_Automation
bin/magento setup:upgrade
bin/magento cache:flush
```

## Standardy jakości i testowania (reguła projektu, nie aspiracja)

To są wiążące reguły tego repozytorium na przyszłość, nie lista życzeń "kiedyś" — każda nowa klasa dodana od tego momentu powinna je spełniać, a istniejący kod jest sukcesywnie doprowadzany do tego samego poziomu (śledzone w `ROADMAP.md`, Faza 6):

- **Analiza statyczna: PHPStan na poziomie `level: max`**, skonfigurowany w `phpstan.neon` z rozszerzeniem [bitexpert/phpstan-magento](https://github.com/bitexpert/phpstan-magento), żeby magia Magento (fabryki, proxy, tłumaczenie `__()`, magiczne gettery EAV) nie generowała fałszywych alarmów. Działa tylko jako `require-dev` — nigdy nie trafia na produkcyjną instalację.
- **Testy jednostkowe (PHPUnit)** dla każdej klasy z nietrywialną logiką — `Model/`, `Cron/`, `Helper/`, `Controller/`. `Test/Unit/Model/SalesRepEmailContextTest.php` to test-zalążek ustalający wzorzec mockowania (`createMock` na interfejsach, bez prawdziwego bootstrapu Magento).
- **MFTF (Magento Functional Testing Framework)** pokrycie end-to-end dla każdego flow widocznego dla klienta i administratora: złożenie zamówienia powyżej limitu wydatków → e-mail → link akceptuj/odrzuć → zmiana statusu zamówienia; samodzielne przedłużenie wygasającej oferty przez klienta; itd. Zgodnie z [przewodnikiem MFTF Adobe](https://developer.adobe.com/commerce/testing/functional-testing-framework/getting-started).
- **Testy API** dla każdego kontraktu usługi w `Api/` — zobacz `API.md` i `Test/Api/README.md`.
- **Cel: ~100% pokrycia kodu.** Aktualny (lub nieaktualny) zmierzony stan w `ROADMAP.md`, Faza 6, oraz `VERIFICATION.md` — co jest pokryte dziś, w porównaniu z garstką linii genuinie nieosiągalnych.

## Lokalizacja

Etykiety widoczne w adminie (`system.xml`, etykiety atrybutów klienta) są tłumaczalne przez standardowe pliki CSV i18n Magento w `i18n/`, kluczowane względem `en_US.csv` jako źródła. Aktualnie dostępne: `en_US`, `pl_PL`. Zgłoszenia/prośby o kolejne lokalizacje śledzone są w `ROADMAP.md`, Faza 6 — celem jest pokrycie każdego języka realnie istotnego dla bazy klientów sklepu, nie tylko symboliczny drugi język.

## Roadmapa

Historia wdrożonych funkcji i wszystko, co wciąż otwarte (fazy w toku, znane luki, elementy "jeszcze niezbudowane") żyje w **[ROADMAP.md](ROADMAP.md)** (po angielsku), nie tutaj — to README opisuje tylko stabilny, aktualny stan modułu.

## Wypróbowanie na żywo

**Zweryfikowane end-to-end na Magento Open Source 2.4.7, 2026-08-26**
(Docker, Magento sklonowane z GitHub — bez kluczy Adobe Marketplace).
Każdy punkt checklisty w `VERIFICATION.md`, sekcje 1–7, przeszedł pozytywnie
na prawdziwej, żywej instancji: instalacja, analiza statyczna, pełny panel
administracyjny (dashboard, kreator kampanii z dedykowanymi polami per typ,
gridy), każdy cron triggera B2B, silnik kampanii (łącznie z łańcuchem
`generate_coupon` → `send_email` i triggerem `tag_added`), kalkulator promocji
`CheapestItemFree`, śledzenie na stronie w prawdziwej przeglądarce, i —
najtrudniejsze — prawdziwe zamówienie złożone przez pełny checkout sklepowy,
wstrzymane do akceptacji, zaakceptowane przez prawdziwy link i odblokowane.

**Po drodze znaleziono i naprawiono 20 prawdziwych błędów** — złe punkty
rozszerzeń DI, ciche gubienie wartości atrybutów EAV, pułapkę domyślnej
konfiguracji obecną w 12 miejscach i więcej. Pełna lista, z dokładnym opisem
jak każdy został znaleziony i zweryfikowany, w `VERIFICATION.md`.

**Co wciąż jest genuinie otwarte:** zobacz [ROADMAP.md](ROADMAP.md) — nie porażki, nie próbowane, albo świadomie odłożone.

**Pełna checklista krok po kroku:** [VERIFICATION.md](VERIFICATION.md) — obejmuje instalację, analizę statyczną i ręczny przegląd każdej funkcji z tego README, zorganizowana tak, żeby porażka na dowolnym kroku wskazywała dokładnie, co naprawić dalej.

## Changelog

Zobacz [CHANGELOG.md](CHANGELOG.md).

## Licencja

OSL-3.0 (tak jak core Magento).
