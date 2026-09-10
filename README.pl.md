# Ordo Automation dla Magento 2

![Ordo Automation](.github/assets/hero.svg)

[![CI](https://github.com/michalper/ordo/actions/workflows/ci.yml/badge.svg)](https://github.com/michalper/ordo/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/michalper/ordo/graph/badge.svg?token=JYXG9P7692)](https://codecov.io/gh/michalper/ordo)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=michalper_ordo&metric=alert_status)](https://sonarcloud.io/project/overview?id=michalper_ordo)
[![PHP](https://img.shields.io/badge/php-8.4%2B-777bb4)](composer.json)
[![Magento](https://img.shields.io/badge/magento-2.4.8%20%7C%202.4.9-orange)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)
[![Dependabot](https://img.shields.io/badge/dependabot-enabled-025E8C?logo=dependabot&logoColor=white)](.github/dependabot.yml)
[![Code Style](https://img.shields.io/badge/code%20style-Magento2%20Coding%20Standard-orange)](phpcs.xml.dist)
[![Open Issues](https://img.shields.io/github/issues/michalper/ordo)](https://github.com/michalper/ordo/issues)

*[Read in English](README.md)*

Marketing automation działający wewnątrz standardowego Magento Open Source — bez licencji Adobe Commerce B2B, bez
zewnętrznej subskrypcji MA. Triggery liczone są z danych, które Magento już ma (zamówienia, koszyki, klienci), albo z
niewielkiego własnego modelu danych dodanego obok nich.

Pokrywa zarówno klasyczną automatyzację lifecycle B2C, jak i triggery B2B, których zewnętrzne narzędzia MA
strukturalnie nie widzą.

## Funkcje

**B2B**

- Przypomnienia o ponownym zamówieniu na bazie historii zakupów klienta.
- Przypomnienia o wygasającej ofercie/wycenie (`ordo_offer`).
- Alerty limitu kredytowego (cron + `GET /V1/ordo/credit-limit/mine`), z opcjonalną twardą blokadą checkoutu przy
  100% wykorzystania.
- Workflow akceptacji zamówień — zamówienia powyżej limitu wydatków klienta są wstrzymywane do akceptacji/odrzucenia
  przez token w e-mailu, z eskalacją dla nierozpatrzonych.
- Gratis powyżej progu koszyka — pula produktów zdefiniowana w adminie, z kaskadowymi progami wartości koszyka,
  wybór przez REST.

**B2C**

- Odzyskiwanie porzuconych koszyków, limitowane na koszyk.
- E-mail powitalny przy rejestracji.
- E-mail win-back po N dniach nieaktywności, czyszczony automatycznie po kolejnym zamówieniu.
- SMS (Twilio) — akcja kampanii `send_sms`, ze śledzeniem statusu dostarczenia i obsługą opt-out.
- WhatsApp (Meta Cloud API) — akcja kampanii `send_whatsapp` wysyłająca zatwierdzone wcześniej szablony, z własnym
  cyklem zatwierdzania szablonów w adminie i webhookiem statusu dostarczenia.
- Powiadomienia Web Push — akcja kampanii `send_push` dostarczająca prawdziwe powiadomienia przeglądarki/systemu
  (RFC 8291/8292, bez SDK dostawcy), na każde urządzenie, z którego klient się zasubskrybował.

**Wspólny fundament**

- Tagowanie behawioralne — prymityw segmentacji, z którego korzysta każdy trigger powyżej.
- Podpis opiekuna handlowego w automatycznych e-mailach; tygodniowy digest grupujący nieaktywnych klientów per
  opiekun.
- Silnik kampanii — reguła "gdy X i Y, zrób Z", z warunkami/akcjami jako wtyczkami rejestrowanymi w `di.xml` i
  pełnym kontraktem REST.
- Śledzenie zachowań na stronie — bezzależnościowy snippet JS zamieniający odsłony stron/produktów/kategorii w
  tagi silnika kampanii.
- Panel administracyjny — dashboard, kreator kampanii (edytowalny diagram [Drawflow](https://github.com/jerosoler/Drawflow)
  trigger(y) → warunki → akcje, wiele triggerów na kampanię), kalendarz kampanii (trigger/harmonogram akcji wszystkich
  kampanii w jednym miejscu), kreator ofert gratisów, diagnostyczny grid cykli reorder.

Każda funkcja ma własny przełącznik w **Stores → Configuration → Ordo Automation** i własne zadanie cron.

## Instalacja

```bash
composer require ordo/module-automation
bin/magento module:enable Ordo_Automation
bin/magento setup:upgrade
bin/magento cache:flush
```

## Dokumentacja

- [ROADMAP.md](ROADMAP.md) — co jest jeszcze otwarte.
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — historia implementacji.
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — mapa katalogów/klas.
- [VERIFICATION.md](VERIFICATION.md) — checklista instalacji/testów na żywej instancji Magento.
- [API.md](API.md) — referencja kontraktu usług REST.
- [CONTRIBUTING.md](CONTRIBUTING.md) — wymagania jakości/testów dla zmian.
- Lokalizacja: pliki CSV w `i18n/`, kluczowane względem `en_US.csv`. Dostępne: `en_US`, `pl_PL` (zweryfikowane przez
  native speakera), `de_DE`, `fr_FR`, `es_ES`, `it_IT`, `pt_BR`, `zh_Hans_CN`, `ja_JP`, `ru_RU`, `uk_UA`, `nl_NL`
  (przetłumaczone maszynowo, czekają na przegląd native speakera).

## Licencja

MIT — zobacz [LICENSE](LICENSE).

Copyright (c) 2026 Michał Per.
