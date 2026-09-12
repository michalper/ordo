# GitHub Wiki — staging area

This directory holds staged content for the project's GitHub Wiki (the roadmap item "GitHub
Wiki covering every feature, bilingual PL/EN, with screenshots"). GitHub Wikis are their own git
repository (`https://github.com/michalper/ordo.wiki.git`), separate from this one — nothing here
is pushed there automatically. Once a page below is reviewed, copy its content into the real wiki
(`git clone` the `.wiki.git` repo, or paste via the wiki's web editor).

## Structure decision: bilingual-per-page

**Decision:** one Markdown file per capability, PL and EN content stacked in the same page,
Polish first then English, separated by a `---` and jumped between with anchor links at the top
(`[Polski](#polski) | [English](#english)`). Not a language-split page tree
(`Campaigns.md` + `Campaigns-pl.md`, or `en/Campaigns.md` + `pl/Campaigns.md`).

**Why, weighed against GitHub Wiki's actual constraints:**

- **One shared `_Sidebar.md`, one shared `_Footer.md`.** GitHub Wiki has no per-language nav —
  there's exactly one sidebar for the whole wiki, not one per language tree. A split tree
  (`en/`, `pl/`) would force a choice: either one sidebar links into both trees (double-length,
  visually mixing languages in the nav itself), or maintain two near-duplicate sidebars manually
  synced by hand on every page add/rename — a second thing to keep in sync besides the content
  itself. Bilingual-per-page needs exactly one sidebar entry per topic, no duplication.
- **No per-page metadata.** GitHub Wiki pages have no frontmatter/language tag the sidebar or
  search could key off, so a language-split tree gets no structural help from the platform for
  keeping `Campaigns.md`/`Campaigns-pl.md` (or `en/Campaigns.md`/`pl/Campaigns.md`) in sync —
  drift is entirely on the author's discipline, and silent (nothing fails if the PL page falls a
  version behind).
- **Sync-by-construction.** With both languages in one file, editing the page to reflect a code
  change puts both languages in the same diff, in the same PR review, in the same reading pass.
  A translator or reviewer sees immediately if the EN half changed but the PL half didn't. With
  two separate files, updating only one language during a quick edit is the easy path, not the
  hard one — the exact failure mode this module's own `i18n/` CSVs went through before native
  review (see ROADMAP.md's Localization section) as machine-translated, unreviewed content.
- **Small wiki, few pages.** At 8 capability pages plus a home page, a split tree's main
  benefit — being able to browse "the whole wiki in one language" without interleaved content —
  buys little: a reader wanting only-PL skips past one clearly marked EN half per page, not
  across dozens of pages. This benefit would matter more at wiki sizes this module doesn't have.
- **Trade-off accepted:** bilingual-per-page pages are roughly 2x as long to scroll through as a
  single-language page, and a reader who only reads PL still downloads/scrolls past the EN half
  (and vice versa). Judged an acceptable cost against the drift risk above, for a wiki this size.

**Page list** (each file below stands in for the GitHub Wiki page of the same name once copied
over):

- `Home.md` — landing page, links to every page below (stand-in for the wiki's actual `Home.md`).
- `Campaigns.md`
- `Segments.md`
- `RFM-and-Lead-Scoring.md`
- `Free-Gifts.md`
- `Order-Approval.md`
- `Tracking-and-Popups.md`
- `Reorder-Cycles.md`
- `Dashboard.md`

`_Sidebar.md` is included here too, staged the same way, listing all pages above in the order a
reader would want (start at Dashboard, then the capability pages roughly in the order a merchant
would set them up).

## What's real vs. staged in this pass

- Every page's walkthrough content was written from reading this module's actual `Controller/`,
  `Block/`, `Model/`, `Ui/`, and `view/adminhtml/ui_component/*.xml` classes, plus
  `docs/ARCHITECTURE.md` and `docs/CHANGELOG.md` — not invented. Exact class names, config paths,
  and admin URLs are called out per page so a reviewer can spot-check any claim against the code.
- Screenshots are **real**, taken against a running Magento 2.4.9 admin instance
  (`magento-ordo-test/`, this module installed from the current branch) logged in as a real admin
  user, for: the module dashboard, the Campaigns grid, the Segments grid, the RFM report, the
  Score Rules grid, the Free Gift Offers grid and its tier-editing form, the Order Approvals grid,
  the Reorder Cycles grid, and the On-Site Behavior Tracking configuration section. See
  `docs/wiki/images/`.
- **Not captured:** the campaign builder's Drawflow/Flow canvas itself (trigger → conditions →
  actions graph). Opening `ordo/campaign/edit` in that test environment currently throws
  `LogicException: Circular dependency: Ordo\Automation\Model\Campaign\ActionPool depends on
  Ordo\Automation\Model\CampaignDispatcher and vice versa` — a pre-existing environment/DI issue
  unrelated to this documentation change, out of scope to fix here (docs-only task, no PHP
  changes). The Campaigns page has a placeholder marking exactly what a human needs to capture
  once that's resolved (or reproduced in a different environment).
- Native-language review of the PL text in every page is **not done** — it was written by the
  same (non-native-workflow) pass as the EN text, same caveat this repo already carries for the
  10 machine-translated locale CSVs (see ROADMAP.md).
