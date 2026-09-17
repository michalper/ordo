[Polski](#polski) | [English](#english)

# Polski

# RFM i punktacja leadów

## Raport RFM — kto jest Twoim najlepszym klientem

RFM to trzy wymiary, po których ranking klientów: **R**ecency (jak niedawno kupował), **F**requency
(jak często kupuje) i **M**onetary (ile wydaje). Każdy klient dostaje ranking 1-5 w każdym z tych
trzech wymiarów, porównując go z resztą Twoich klientów — 5 zawsze oznacza "najlepszy". Klient
5/5/5 kupił niedawno, kupuje często i wydaje najwięcej — to Twój najcenniejszy odbiorca.

![Raport RFM](images/rfm-report.png)

Kolumna "Kwalifikuje się do" pokazuje, do jakich segmentów dany klient aktualnie pasuje na
podstawie tego rankingu. Dane odświeżają się automatycznie co noc, więc ranking zawsze odzwierciedla
najświeższe zachowanie klientów.

Jeśli klient nagle przestanie kupować (np. był 5/5/5, a teraz jego Recency spadnie), to świetny
sygnał do kampanii win-back, zanim odejdzie na dobre.

## Punktacja leadów (Reguły punktacji)

Osobny mechanizm: przypisujesz punkty do konkretnych cech klienta (np. "klient z grupy Hurtownicy
= +80 punktów"). Wszystkie pasujące reguły sumują się w jeden, bieżący wynik klienta. Gdy wynik
przekroczy ustawiony próg, może automatycznie odpalić kampanię (np. powiadomienie dla handlowca
o gorącym leadzie).

![Siatka reguł punktacji](images/score-rules-grid.png)

Każda reguła to: atrybut klienta, operator (równa się / nie równa się / zawiera), wartość, i ile
punktów przyznać przy dopasowaniu. Punkty mogą być też ujemne, jeśli chcesz coś odejmować.

Konfiguracja progu, oraz progów dla poziomów lojalności (Silver/Gold), znajduje się w **Sklepy →
Konfiguracja → Ordo Automation → Lead Scoring**.

---

# English

# RFM & Lead Scoring

## RFM Report — who your best customers are

RFM ranks customers along three dimensions: **R**ecency (how recently they ordered), **F**requency
(how often they order), and **M**onetary (how much they spend). Every customer gets a 1-5 rank in
each dimension, relative to the rest of your customers — 5 always means "best." A 5/5/5 customer
ordered recently, orders often, and spends the most — your most valuable customer.

![RFM Report](images/rfm-report.png)

The "Qualifies For" column shows which segments a customer currently matches based on this
ranking. Data refreshes automatically overnight, so the ranking always reflects the freshest
customer behavior.

If a customer suddenly stops ordering (say they were 5/5/5 and their Recency rank drops), that's a
great signal for a win-back campaign before they're gone for good.

## Lead Scoring (Score Rules)

A separate mechanism: you assign points to specific customer traits (e.g. "customer in the
Wholesale group = +80 points"). Every matching rule adds up into one running score per customer.
Once a score crosses a configured threshold, it can automatically fire a campaign (e.g. notifying
a sales rep about a hot lead).

![Score Rules grid](images/score-rules-grid.png)

Each rule is: a customer attribute, an operator (equals / not equals / contains), a value, and how
many points to award on a match. Points can also be negative if you want to subtract.

The threshold, along with the thresholds for loyalty tiers (Silver/Gold), lives under **Stores →
Configuration → Ordo Automation → Lead Scoring**.
