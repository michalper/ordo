[Polski](#polski) | [English](#english)

# Polski

# Gratisy przy przekroczeniu progu koszyka

Klasyczna promocja "wydaj X, dostań gratis" — w pełni automatyczna, bez kuponów do wpisywania.
Przykład: wydaj 100 zł i dostań gratis A, wydaj 200 zł i dostań gratis A oraz B. Gratis pojawia
się w koszyku automatycznie, w cenie 0 zł, gdy tylko suma koszyka przekroczy próg.

![Siatka ofert gratisów](images/free-gift-offers-grid.png)

## Jak to skonfigurować

W formularzu oferty ustawiasz:
- **Poziomy** — im wyższy próg wartości koszyka, tym więcej "slotów" na gratisy klient odblokowuje.
  Poziomy się sumują: przekroczenie wyższego progu nie zabiera gratisów z niższego, dolicza kolejne.
- **Pula produktów** — lista SKU, spośród których klient może wybrać, gdy się zakwalifikuje.

![Edycja oferty gratisu — poziomy kaskadowe](images/free-gift-offer-edit.png)

Przykład z rzeczywistej oferty demo: poziom 1 przy 100 zł daje 1 gratis, poziom 2 przy 200 zł daje
kolejny (czyli w sumie 2 gratisy przy koszyku za 200 zł+).

Jeśli klient usunie coś z koszyka i spadnie poniżej progu, gratis, do którego już się nie
kwalifikuje, jest automatycznie usuwany — nie trzeba pilnować tego ręcznie.

Włączasz to w **Sklepy → Konfiguracja → Ordo Automation → Free Gift Above Threshold**.

---

# English

# Free Gift Above Cart Threshold

The classic "spend $X, get a free gift" promotion — fully automatic, no coupon codes to type in.
Example: spend $100 and get gift A, spend $200 and get gift A and B. The gift is added to the cart
automatically, priced at $0, as soon as the cart total crosses the threshold.

![Free Gift Offers grid](images/free-gift-offers-grid.png)

## How to set it up

In the offer form you set:
- **Tiers** — the higher the cart-value threshold, the more gift "slots" a customer unlocks.
  Tiers stack: crossing a higher threshold doesn't remove the lower tier's gifts, it adds more.
- **Product pool** — the list of SKUs a customer can pick from once they qualify.

![Free gift offer edit — cascading tiers](images/free-gift-offer-edit.png)

Example from a real demo offer: tier 1 at $100 grants 1 gift, tier 2 at $200 grants another (so 2
gifts total once the cart reaches $200+).

If a customer removes something and the cart drops back below a threshold, a gift they no longer
qualify for is automatically removed — nothing to police manually.

Turn this on under **Stores → Configuration → Ordo Automation → Free Gift Above Threshold**.
