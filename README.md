# CompKit Game Engine — free core

The free core of a commercial WooCommerce plugin suite. It turns the instant-win result on
an order confirmation page into something the customer plays rather than reads.

Live at **[compkit.kriscreates.co.uk](https://compkit.kriscreates.co.uk)** · GPL-2.0-or-later

This repository is the free core exactly as it ships. The premium game modules are licensed
separately and are not here; what follows describes how the whole system fits together,
because the interesting parts of the engineering are at the seams.

---

## What it does

CompKit is an add-on to [Giveaway for WooCommerce by Flintop](https://woocommerce.com/products/giveaway-for-woocommerce/).
Without it, a customer who wins an instant-win prize sees a line of text on the order
confirmation page. With it, they scratch a card, spin a wheel, work a claw machine or take
a penalty, and the result is revealed by playing it.

The core in this repository provides:

- **Instant Reveal** — the free reveal, and the hook into WooCommerce order completion
- **Smart Email Delay Handler** — described below
- the settings framework, licence client, and the update path the premium modules use

Nine premium modules sit on top: scratch card, slot machine, spin the wheel, grab claw,
race car, darts, penalty shootout, balloon pop and a typing fight. Each is an HTML5 canvas
engine with its own settings page, a live device-emulating admin preview, global defaults
with per-product overrides, and importable JSON theme files.

## The problem nobody expects

WooCommerce sends the order confirmation email when the order completes. The order
completes before the customer has played anything. So the email arrives in the inbox with
the result in it, and the game is pointless — the customer already knows.

The **Smart Email Delay Handler** holds the confirmation email back until every ticket on
the order has been played, then releases it. It is the single most important piece of the
product and it is three hundred lines in the free core, because the valuable part of a
product is rarely the part that looks impressive in a screenshot.

Interfering with a core transactional email is dangerous, so it fails safe: there is a
backstop that releases the email regardless after a bounded wait. A customer never loses
their receipt because they closed the tab.

## How the commercial side works

Not in this repository, but it is the part worth describing.

**The engines are not in the plugin.** Everything in a WordPress plugin zip sits on the
customer's server in plain sight, and a subscription that stops being paid for does not make
the code stop existing. So the premium modules ship without their engines: the JavaScript
that actually runs each game is served from the licence server at runtime, behind
HMAC-signed URLs tied to the subscription.

That was a deliberate trade and it is not free. It turns a static plugin into one with a
hard runtime dependency on a server I run, which is a genuinely bad property for a WordPress
plugin to have. So it is paid for:

- **Releases stage to demo sites first** and only reach customers on an explicit publish,
  so a bad build is caught against real stores rather than in someone's shop.
- **Updates go through the standard WordPress update API** rather than anything bespoke.
  The one thing worse than depending on my server is depending on my server *and* my own
  update mechanism.
- **Failure is loud.** If a licence cannot be validated the site says so, rather than
  quietly rendering a broken reveal to a paying customer.

The licence server also builds **white-label cores on demand** — four files are forked per
white-label build, so a reseller's copy is a build artefact rather than a hand-edited fork
that drifts — and produces the WordPress.org **Lite** edition through a build that audits
its own output and refuses to produce a zip if an excluded file made it in.

## A bug worth keeping

For a while, published updates were invisible. Sites reported themselves up to date while
newer versions sat on the server.

Two things were true at once. The update cache was keyed on the installed version, so a site
that had never updated never re-checked. And the screen where the notice should have
appeared had no core UI on it at all. Either alone would have been found in an afternoon.
Together they produced a system that was confidently, silently wrong — which is the failure
mode worth being afraid of. Fixed in 3.0.15.

## Layout

```
giveaways-add-ons.php              plugin header, constants, bootstrap
giveaways-loader.php               module discovery and load order
giveaways-admin.php                settings framework shared by every module
giveaways-license-manager.php      licence client: validation, caching, failure states
giveaways-updater.php              the WordPress update API integration
giveaways-review-prompt.php        review prompt, rate-limited and dismissible for good
instant-reveal-display-addon/      the free reveal
instant-email/                     Smart Email Delay Handler
assets/, icons/                    styles, scripts, artwork
```

## Requirements

WordPress 6.0+, PHP 7.4+, WooCommerce, and the Flintop Giveaway for WooCommerce extension.
It is an add-on and does nothing on its own.

---

Built and supported by Kris Sull — [kriscreates.co.uk](https://kriscreates.co.uk) ·
hello@kriscreates.co.uk
