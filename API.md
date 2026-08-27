# Ordo Automation — REST API reference

Every endpoint below is real, wired into `etc/webapi.xml`, and was **actually called against a
running Magento 2.4.7 instance** while writing this document — request/response examples are
copy-pasted from those real calls (with test data), not hand-written. See `Test/Api/README.md`
for the automated test suite that exercises the same flows, and for the four real WebAPI
defects that surfaced (and were fixed) only by doing this.

This module is designed to work fully headless — every flow a native Magento admin/storefront
would drive is also reachable over REST, with one honest exception noted below (order-approval
tokens, which are deliberately delivered only by email, matching a "click to approve" link's
trust model).

## Authentication

Two token types, matching who's allowed to call what:

- **Admin token** (`POST /rest/V1/integration/admin/token` with `username`/`password`) — for
  every `Ordo_Automation::campaigns` / `Ordo_Automation::config`-scoped route below. Send as
  `Authorization: Bearer <token>`.
- **Customer token** (`POST /rest/V1/integration/customer/token`) — only for the self-extend
  offer endpoint.
- **Anonymous** — only the order-approval decision endpoints (`.../approve`, `.../reject`).
  Possession of the one-time token in the URL *is* the credential; no `Authorization` header is
  sent or checked, the same trust model as the email link it mirrors.

## Campaigns

Full CRUD. ACL resource: `Ordo_Automation::campaigns` (admin token).

| Method | Path | Service method |
|---|---|---|
| GET | `/V1/ordo/campaigns?searchCriteria[...]` | `CampaignRepositoryInterface::getList` |
| GET | `/V1/ordo/campaigns/:entityId` | `CampaignRepositoryInterface::getById` |
| POST | `/V1/ordo/campaigns` | `CampaignRepositoryInterface::save` |
| PUT | `/V1/ordo/campaigns/:entityId` | `CampaignRepositoryInterface::save` |
| DELETE | `/V1/ordo/campaigns/:entityId` | `CampaignRepositoryInterface::deleteById` |

```
POST /rest/V1/ordo/campaigns
{"campaign": {"name": "API Test Campaign", "trigger_event": "order_placed", "enabled": true}}

→ 200 {"entity_id":10,"name":"API Test Campaign","trigger_event":"order_placed","enabled":true}
```

`trigger_event` is one of `order_placed`, `customer_registered`, `tag_added`, `cart_abandoned`
(`Api\Data\CampaignInterface::TRIGGER_*` constants).

## Campaign conditions / actions

Full CRUD on the per-campaign rule rows the admin form's dynamicRows sections edit — a headless
client can now author these too, not just create/enable/disable the parent campaign. Flat
resources, not nested under `/campaigns/:id/...` — filter by `campaign_id` via `searchCriteria`
to get everything on one campaign, the same convention Magento's own APIs use (e.g. order
items). ACL resource: `Ordo_Automation::campaigns` (admin token).

| Method | Path | Service method |
|---|---|---|
| GET | `/V1/ordo/campaign-conditions?searchCriteria[...]` | `CampaignConditionRepositoryInterface::getList` |
| GET | `/V1/ordo/campaign-conditions/:entityId` | `CampaignConditionRepositoryInterface::getById` |
| POST | `/V1/ordo/campaign-conditions` | `CampaignConditionRepositoryInterface::save` |
| PUT | `/V1/ordo/campaign-conditions/:entityId` | `CampaignConditionRepositoryInterface::save` |
| DELETE | `/V1/ordo/campaign-conditions/:entityId` | `CampaignConditionRepositoryInterface::deleteById` |
| GET / POST / PUT / DELETE | `/V1/ordo/campaign-actions[/...]` | `CampaignActionRepositoryInterface` (same shape) |

```
POST /rest/V1/ordo/campaign-conditions
{"condition": {"campaign_id": 12, "type": "order_total_gte", "params_json": "{\"amount\":\"500\"}", "sort_order": 0}}

→ 200 {"entity_id":8,"campaign_id":12,"type":"order_total_gte","params_json":"{\"amount\":\"500\"}","sort_order":0}

GET /rest/V1/ordo/campaign-conditions?searchCriteria[filterGroups][0][filters][0][field]=campaign_id
                                      &searchCriteria[filterGroups][0][filters][0][value]=12
→ 200 {"items":[{"entity_id":8,"campaign_id":12, ...}], "total_count":1}
```

`type` must match a type key registered in `Model\Campaign\ConditionPool` / `ActionPool` (the
same registry the admin form's type dropdown reads from) — an unregistered type won't error on
save, but the dispatcher will log and skip it at trigger time (fails closed, see
`CampaignDispatcher`). `params_json` is the field name (not `params`, the raw DB column) so it
can't collide with the model's own `getParams(): array` decoded-helper method — see
`Api\Data\CampaignConditionInterface` for why.

## Offers

Full CRUD plus one customer-facing action. ACL resource: `Ordo_Automation::config` for CRUD
(admin token); `self` for self-extend (customer token, any authenticated customer — ownership
of the specific offer is checked inside the service, not by the route).

| Method | Path | Service method | Auth |
|---|---|---|---|
| GET | `/V1/ordo/offers?searchCriteria[...]` | `OfferRepositoryInterface::getList` | admin |
| GET | `/V1/ordo/offers/:entityId` | `OfferRepositoryInterface::getById` | admin |
| POST | `/V1/ordo/offers` | `OfferRepositoryInterface::save` | admin |
| PUT | `/V1/ordo/offers/:entityId` | `OfferRepositoryInterface::save` | admin |
| DELETE | `/V1/ordo/offers/:entityId` | `OfferRepositoryInterface::deleteById` | admin |
| POST | `/V1/ordo/offers/:offerId/self-extend` | `OfferManagementInterface::selfExtend` | customer |

```
POST /rest/V1/ordo/offers/2/self-extend      (customer token)
→ 200 {"entity_id":2,"customer_id":1,"reference":"API-OFR-1","status":"sent","total":100,
       "currency_code":"PLN","expires_at":"2027-01-07 00:00:00","extension_count":1, ...}

POST /rest/V1/ordo/offers/2/self-extend      (called again — already at the configured max)
→ 400 {"message":"This offer has already been extended the maximum of %1 time(s).","parameters":[1]}

POST /rest/V1/ordo/offers/999999/self-extend (offer doesn't exist, or belongs to someone else)
→ 404 {"message":"Offer with id \"%1\" does not exist.","parameters":[999999]}
```

The 404 response is deliberately identical whether the offer doesn't exist or belongs to a
different customer — see `Model/OfferManagement::selfExtend()` — so the API never confirms
"offer #123 exists, you just don't own it."

`status` is one of `draft`, `sent`, `accepted`, `rejected`, `expired`
(`Api\Data\OfferInterface::STATUS_*`). Self-extension policy (how many times, how many days
each time) is admin-configurable: `Stores → Configuration → Ordo Automation → Offer` /
`Helper\Config::getOfferMaxSelfExtensions()` / `getOfferSelfExtensionDays()`.

## Reorder cycles

Read-only — these rows are computed nightly by `Cron\CalculateReorderCycle`, never written
through the API. ACL resource: `Ordo_Automation::config` (admin token).

| Method | Path | Service method |
|---|---|---|
| GET | `/V1/ordo/reorder-cycles?searchCriteria[...]` | `ReorderCycleRepositoryInterface::getList` |
| GET | `/V1/ordo/reorder-cycles/:entityId` | `ReorderCycleRepositoryInterface::getById` |

```
GET /rest/V1/ordo/reorder-cycles/1
→ 200 {"entity_id":1,"customer_id":1,"sku":"ordo-test-sku","avg_interval_days":30,
       "last_order_date":"2026-08-25","next_expected_date":"2026-08-28",
       "orders_considered":3,"updated_at":"2026-08-26 12:50:37"}
```

A headless storefront can use this to show "you're due to reorder SKU X" without needing the
admin diagnostic grid at all.

## Customer tags

The segmentation primitive campaigns target ("send to everyone tagged `vip`"). ACL resource:
`Ordo_Automation::campaigns` (admin token) — tagging is currently an admin/system action, not
customer self-service.

| Method | Path | Service method |
|---|---|---|
| GET | `/V1/ordo/customers/:customerId/tags` | `CustomerTagManagementInterface::getTags` |
| PUT | `/V1/ordo/customers/:customerId/tags/:tag` | `CustomerTagManagementInterface::addTag` |
| DELETE | `/V1/ordo/customers/:customerId/tags/:tag` | `CustomerTagManagementInterface::removeTag` |
| GET | `/V1/ordo/customers/:customerId/tags/:tag` | `CustomerTagManagementInterface::hasTag` |
| GET | `/V1/ordo/tags/:tag/customers` | `CustomerTagManagementInterface::getCustomerIdsWithTag` |

```
PUT  /rest/V1/ordo/customers/1/tags/vip           → 200 []
GET  /rest/V1/ordo/customers/1/tags               → 200 ["vip", "engine_e2e_test", ...]
GET  /rest/V1/ordo/customers/1/tags/vip            → 200 true
GET  /rest/V1/ordo/tags/vip/customers              → 200 [1]
DELETE /rest/V1/ordo/customers/1/tags/vip          → 200 []
```

Adding a tag that already exists is a no-op (idempotent); it does **not** re-fire the
`ordo_customer_tag_added` event or re-trigger `tag_added` campaigns a second time — see
`Model/CustomerTagManager::addTag()`.

## Order approvals

Read (admin-scoped) plus two anonymous, token-authenticated decision endpoints — the only
routes in this module that are neither admin- nor customer-scoped, by design.

| Method | Path | Service method | Auth |
|---|---|---|---|
| GET | `/V1/ordo/order-approvals?searchCriteria[...]` | `OrderApprovalRepositoryInterface::getList` | admin |
| GET | `/V1/ordo/order-approvals/:entityId` | `OrderApprovalRepositoryInterface::getById` | admin |
| POST | `/V1/ordo/order-approvals/:token/approve` | `OrderApprovalManagementInterface::approveByToken` | anonymous |
| POST | `/V1/ordo/order-approvals/:token/reject` | `OrderApprovalManagementInterface::rejectByToken` | anonymous |

```
GET /rest/V1/ordo/order-approvals?searchCriteria[pageSize]=3   (admin token)
→ 200 {"items":[{"entity_id":4,"order_id":8,"admin_email":"admin-approver@example.com",
       "status":"approved","reminders_sent":0,"created_at":"...","decided_at":"...",
       "pending":false}], ...}

POST /rest/V1/ordo/order-approvals/<token>/approve   (no Authorization header)
→ 200 {"entity_id":5,"order_id":5,"admin_email":"...","status":"approved", ...}

POST /rest/V1/ordo/order-approvals/<same token again>/approve
→ 404 {"message":"Invalid or already-used approval token."}
```

Note what's **not** in the response: the `token` field itself is deliberately excluded from
`Api\Data\OrderApprovalInterface` — it never round-trips through the read (admin) API, so
knowing an approval's `entity_id` never lets you reconstruct or guess its token.

There is intentionally no REST endpoint that *creates* an approval or *retrieves an existing
approval's token* — the only way a token is issued is by `Observer\HoldOrderForApproval`
generating one server-side and emailing it. A headless client (e.g. a sales-rep mobile app)
cannot yet fetch "the current pending approval's decision link" over the API without that
token; it would need to come from the same email today. Flagged as a real, current limitation,
not silently glossed over.

## Full example: campaign CRUD round trip

```bash
TOKEN=$(curl -s -X POST "$BASE/rest/V1/integration/admin/token" \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"..."}' | tr -d '"')

curl -s -X POST "$BASE/rest/V1/ordo/campaigns" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"campaign":{"name":"Win-back","trigger_event":"cart_abandoned","enabled":true}}'

curl -s "$BASE/rest/V1/ordo/campaigns?searchCriteria[pageSize]=10" \
  -H "Authorization: Bearer $TOKEN"

curl -s -X DELETE "$BASE/rest/V1/ordo/campaigns/<id>" -H "Authorization: Bearer $TOKEN"
```
