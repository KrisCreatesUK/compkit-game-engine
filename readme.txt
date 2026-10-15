=== CompKit Game Engine ===
Contributors: kriscreates
Tags: woocommerce, giveaways, instant win, scratchcard, slots
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.0.81
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Instant-win reveal games for Giveaway for WooCommerce by Flintop — scratchcards, slots, spin, racing, football, claw grab, darts and more.

== Description ==

**This is an add-on and does nothing on its own.** It requires:

* **WooCommerce**
* **Giveaway for WooCommerce by Flintop** — formerly known as **Lottery for WooCommerce** — the WooCommerce.com extension at https://woocommerce.com/products/giveaway-for-woocommerce/

Please note: this must be the **Flintop** plugin (the one previously called *Lottery for WooCommerce*). It is **not** the similarly-named "Giveaway for WooCommerce" plugin by WebCartisan — that one will not work with CompKit.

With WooCommerce and the Flintop Giveaway plugin active, CompKit adds interactive instant-win reveal games to your giveaways.

CompKit Game Engine adds interactive instant win reveal games to Giveaway for WooCommerce by Flintop (formerly Lottery for WooCommerce).

Instead of displaying a simple static winning ticket, CompKit transforms instant win prize reveals into engaging playable experiences designed to increase excitement, engagement, and repeat participation.

Customers can reveal prizes through immersive game mechanics including scratchcards, slot machines, spin wheels, racing games, football shootouts, dart challenges, claw grabs, and more.

**Instant Reveal and the Smart Email Delay Handler are included free** with no licence key required.

Premium game modules are available separately through monthly or yearly subscription plans, with bundle options available.

= Free features =

* **Instant Reveal**: A clickable animated gift box that reveals whether the customer has won a prize
* **Smart Email Delay Handler**: Delays WooCommerce order confirmation emails until all prizes and tickets have been revealed, preventing spoilers and protecting the reveal experience
* Mobile-friendly reveal experience
* Seamless integration with Giveaway for WooCommerce by Flintop
* Instant Win prize group support
* Automatic order confirmation page integration

= Premium game modules =

* Spin the Wheel
* Slot Machine
* Scratch Cards
* Football Penalties
* Race Car
* Balloon Pop
* Darts Challenge
* Prize Grab Claw

Premium modules are licensed individually or through bundle subscriptions.

= Requirements =

* WooCommerce
* Giveaway for WooCommerce by Flintop (formerly Lottery for WooCommerce)
* Instant Win enabled on giveaway products
* Prize Groups enabled on giveaway products

= How Instant Reveal works =

1. Assign your giveaway product to the **Instant Reveal** product category
2. Enable Instant Win prize groups on the product
3. When a customer completes an order, an interactive reveal game appears automatically on the order confirmation page
4. The customer reveals their result through the selected game experience

= Why use CompKit? =

Traditional instant win reveals are often static and predictable.

CompKit turns the prize reveal itself into part of the entertainment experience, helping store owners create more engaging giveaway campaigns that encourage repeat participation and improve customer excitement.

= External services =

This plugin connects to the KrisCreates licence server at `licencing.kriscreates.co.uk` for the optional premium games. It does NOT download or run code from the server inside this plugin — premium games are a separate plugin you install yourself.

The server is contacted to:

* Validate a premium licence key for the current site (on the settings page and on order confirmation pages where a licensed game is shown).
* Process a purchase via Stripe Checkout (creating the checkout session and retrieving the licence after payment).
* Provide your premium games download (a link to a zip of the games your licences cover, which you then install via Plugins → Add New → Upload).

The following data may be transmitted:

* Licence key(s)
* Site domain
* Stripe checkout session ID (during a purchase only)

No personally identifiable customer information is transmitted. Payment details are handled entirely by Stripe and are never sent to or stored by this plugin.

This service is provided by KrisCreates.

Privacy Policy:
https://kriscreates.co.uk/privacy-policy

Stripe Privacy Policy:
https://stripe.com/privacy

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Ensure **Giveaway for WooCommerce by Flintop (formerly Lottery for WooCommerce)** is installed and active
4. Enable Instant Win and Prize Groups on your giveaway products
5. Assign giveaway products to the **Instant Reveal** product category
6. The Instant Reveal game will appear automatically on order confirmation pages

For premium modules, enter your licence key under **Giveaways Add-ons** in the WordPress admin menu.

== Frequently Asked Questions ==

= Does this work without Giveaway for WooCommerce by Flintop? =

No. CompKit Game Engine is an add-on for Giveaway for WooCommerce by Flintop (formerly Lottery for WooCommerce) and requires it to be installed and active.

= Is Instant Reveal really free? =

Yes. Instant Reveal and the Smart Email Delay Handler are bundled with the plugin and require no licence key.

= What does the Smart Email Delay Handler do? =

The Smart Email Delay Handler temporarily delays WooCommerce order confirmation emails until all prizes and tickets have been revealed. This prevents customers from accidentally seeing prize information in emails before completing the reveal experience.

= Where do I get licence keys for premium modules? =

Visit https://compkit.kriscreates.co.uk for pricing, bundles, and premium module access.

= Can I use this on a staging or local site? =

Premium licence keys are domain-locked. Contact support if you require a staging domain added to your licence.

= Where do I report bugs? =

Use the WordPress.org support forum for this plugin.

== Changelog ==

= 3.0.81 =

* A new dashboard: the newest game in the spotlight, what's new at a glance with each game's icon, your games as cards you can filter, and each game's details in a side panel.
* New game: Boxing - a typing fight where every key is a punch, with seven fighters, a built-in phone keyboard, a ticket in the top bar and a judges' scorecard at the end.
* Race Car, Darts and Balloon Pop: the order page no longer shows a won prize twice. The plain prize line under the game was meant to be hidden once the game reveals it, but it was matched by the ticket's internal ID instead of its number, so it never was.
* Order emails held until the reveal: once released, a completed order gets just the Completed Order email instead of Processing and Completed together, and an order email you have switched off in WooCommerce > Settings > Emails stays off. It used to be sent anyway.
* Order emails: games whose product name contains an & or a quote now get the styled ticket bubbles and the won prize in the email. They fell back to the plain order layout, because the name was looked for as written but the email writes it encoded.
* Product page: tick a game's category on a product that is already saved and it now tells you to click Update to load that game's settings tab, instead of appearing to do nothing.
* Boxing: the product settings no longer squash into a narrow strip when the preview drawer is open, the buttons and phrase clear away after a knockout, and its details panel on the dashboard now has screenshots.

= 3.0.71 =

* Grab Claw: the claw now has separate Drop and Grab sounds, and both play in time with the claw. There was one setting offering either, it fired before the claw had started moving, and the arms closing was always silent. An existing choice of grab1.mp3 moves to the new Grab slot.
* Darts: the hit sound now plays every time a dart lands. It was only played when the throw lost, so a winning dart landed in silence.
* Race Car: the Preview buttons on the Sound tab now play the sound you have selected, at its own volume, and stop the previous one instead of layering on top of it. Press the same button again to stop it. They used to play whatever was last saved, so on a new setup they played nothing.
* Spin Wheel: fixed the game failing to start when the Reveal All button was set to Hide. Turning it back on was the only way round it.
* Balloon Pop, Darts and Race Car: the built-in sound effects now actually play. The settings offered them but the files had never shipped, so every one failed to load.

= 3.0.64 =

* Grab Claw: the oversized claw now finishes its sweep beside the prize chute and shrinks back to normal before crossing over it to drop, rather than parking across the chute while it works.

= 3.0.63 =

* You can leave a review from the CompKit page whenever you like - there is a Leave a review link in the header. CompKit will also ask you once, on its own, after your customers have really used the games; dismiss that and it will not come back. Either way you write it straight into the dashboard, nothing is published until it has been read and approved, and you can have it taken down at any time.

= 3.0.56 =

* Grab Claw: new Grab All button. On a larger order the Drop button splits in two, and the second half sends the claw after every remaining ticket in one giant scoop - for players who would rather not play two hundred tickets one at a time.
* Grab Claw: the new button's colours and label, the confirmation panel shown before a grab, how large the claw swells, and the number of tickets the button appears at are all settable, globally and per product.

= 3.0.45 =

* Football: instant win emails now send - the game was identifying the ticket by its number where an ID was needed, so every football reveal was rejected.
* Racecar: the instant win email now arrives when the result card shows, not when the ticket loads.

= 3.0.44 =

* Football: the instant win email now goes to the ticket that actually won - the reveal could be reported against the next ticket instead.
* Football: the win cheer no longer plays twice.

= 3.0.43 =

* Racecar: the countdown is paced on animation frames instead of timers, so it keeps to the Step Speed you set even on a busy page.

= 3.0.42 =

* Racecar: the screen rumble now fires as a short burst on GO instead of running through the whole 3-2-1.

= 3.0.41 =

* Racecar: each countdown number's animation now lasts one step, so it finishes before the next number replaces it - a short step used to leave every digit stuck mid-entrance.
* Racecar: GO holds long enough for its animation to play out.

= 3.0.40 =

* Instant reveal emails now carry the prize group image.
* The final customer order email is sent once the last ticket in the order is revealed - revealing a whole item at once no longer loses the release.
* Racecar: the countdown speed set in the admin is now used by the game, and switching the countdown off works.

= 3.0.39 =

* Instant reveal emails: revealing a ticket now registers with the server, so the win email for each winning ticket and the order emails after the last game actually send.
* Order and ticket emails from the Giveaways plugin are held until every game in the order has been played, instead of arriving before the customer plays.
* Held emails are sent anyway 6 hours after the order, so a customer who never plays still gets their confirmation and ticket numbers.

= 3.0.38 =

* Football: Import/Export confined to its own tab; product panel tab list updated

= 3.0.37 =

* Football settings restructured into 18 tabs, with image import

= 3.0.36 =

* Custom Dart Image moved to the Dart tab

= 3.0.35 =

* Darts settings restructured into 12 tabs, with image import

= 3.0.34 =

* Turbo Results tab drives the admin preview into turbo, same as Turbo Mode

= 3.0.33 =

* Slots settings restructured into 19 tabs, with image import

= 3.0.32 =

* Racecar settings restructured into 23 tabs; explicit image keys and image import

= 3.0.31 =

* Game panels render on unsaved products, so a game tab opens without saving first

= 3.0.30 =

* Install/Update Games bar was hidden on Game Addons: admin tested a constant only the CompKit premium shell defines

= 3.0.29 =

* Game categories unlock on Display Prizes by Group, the actual trigger

= 3.0.28 =

* Game categories unlock live when instant wins are enabled and a prize group chosen; game tabs register again on new products

= 3.0.27 =

* Core update button fatalled on the white-label edition: shared updater used the CompKit-only GIVEAWAYS_VERSION

= 3.0.26 =

* Asset URLs now carry the plugin version, so a shipped CSS fix is not defeated by browser cache

= 3.0.25 =

* Unscoped .hidden rule in instant-reveal.css hid the whole Product data type dropdown

= 3.0.24 =

* Game panels no longer flood the create-product screen; white-label shell was missing the gate entirely

= 3.0.23 =

* Per-game update badges work again after the source move.

= 3.0.22 =

* One-click install works before premium is installed; white-label core version detected.

= 3.0.21 =

* Football engine served from the licence server; all eight games now remote.

= 3.0.20 =

* Racecar engine served from the licence server.

= 3.0.19 =

* Spin engine served from the licence server.

= 3.0.18 =

* Grabclaw, balloon pop and scratchcard engines served from the licence server.

= 3.0.17 =

* Slots engine served from the licence server.

= 3.0.16 =

* White-label gets the one-click update UI; remote-asset cache guard.

= 3.0.15 =

* Check for updates without waiting on the cache.

= 3.0.14 =

* Maintenance update.

= 3.0.13 =

* Core update button on the CompKit page.

= 3.0.12 =

* Maintenance update.

= 3.0.11 =

* Progress bar while games install; darts 2.4.4.

= 3.0.10 =

* Darts 2.4.3 - seamless upgrade test.

= 3.0.9 =

* Fixed games being switched off after a one-click update

= 3.0.8 =

* Install and update your games with one click, and see which games an update brings forward

= 3.0.7 =

* Darts now loads its game engine from the licence server

= 3.0.6 =

* The version badge on the CompKit admin page now shows the real version

= 3.0.5 =

* Premium games now update themselves even from an older build

= 3.0.4 =

* Licence checks are now cryptographically signed by the licence server
* Both plugins update themselves from your account instead of needing a manual re-upload
* Games recover straight away after a site move or plugin update rather than waiting for the daily check

= 3.0.3 =

* Each game's own version number now shows on the games list and in the corner of its settings page.
* Every game: the Import / Export button now uses that game's own colour.
* Race Card: rebuilt win and lose result cards - choose the card's orientation, image position, size, text alignment and nudge the image and text into place, with separate settings for mobile and desktop. Cards can take a background colour, your own image, or the ticket's own prize artwork, with border, corner radius and header colours. New settings tabs for race cards, header, modal, countdown and the ticket row.
* Football: the shoot button's hold-to-charge power bar is now fully configurable - show or hide it, set how long a full charge takes, and pick the three colours it ramps through plus the glow at full power. The aim marker is now bounded to the goal, so it can no longer be driven above the crossbar.
* Slots: separate mobile and desktop sizing and spacing, new settings for the results strip, ticket line, reel layout, winning symbol, symbol size, turbo results strip and the win / lose cards. Settings can now be imported and exported per product as well as globally.
* Spin: 62 settings that were only available globally can now be overridden per product. Separate mobile and desktop values with an Editing switch, so you always edit the layout you are looking at. The "View Desktop" button is replaced by a Desktop / Mobile switch that keeps the preview beside your settings, plus a full-size inspector when you want it.
* Balloon Pop: the popup gets corner radius and border settings, and the play area becomes a distinct surface with its own background, corner radius and inset - so the objects stay inside it and your logo sits clearly above the play instead of floating over it. The pops-left count moves into a small badge in the corner of the play area, and the end-of-game message now appears as a notification banner across the top of the popup rather than lying over the objects it is reporting on. Existing settings are carried across, so upgraded games look the same until you change them.
* Balloon Pop: the Auto Play and Quick Play buttons move out of the top bar into a full-width row of their own beneath the play area, matching Slots and Race Card. They now share the width evenly instead of being squeezed in beside the pops-left count, which is centred on its own. All existing button style settings still apply.
* Balloon Pop: new Header tab - add a title above the game with its own colour, size, weight, case and alignment, plus an optional logo image with its own height and alignment. New Overlay settings for the backdrop behind the game: colour, image, opacity and blur. The header bar's own background and count colour move here too, so everything header-related is in one place. Defaults match how the game already looked.
* Balloon Pop: separate mobile and desktop values for the win and lose cards, their text, the final summary banners and the Play / Auto Play / Quick Play buttons, with an Editing switch so you always edit the layout you are looking at. The "View Desktop" button is replaced by a Desktop / Mobile switch that keeps the preview beside your settings, plus a full-size inspector when you want it. Existing settings are untouched - the mobile half follows the desktop value until you move it.
* Grab Claw: per-product settings now open and display correctly, and mirror the global page; the 'Use global' tickboxes are replaced by a per-row Use Global button; the preview drawer opens with the tab; a global theme export can now be imported onto a single product.

= 3.0.2 =

* Game Addons: the "Premium Games plugin is out of date" notice now appears correctly when your installed games plugin is a different version from the engine. It previously never showed.

= 3.0.0 =

**Major release — redesigned admin, live previews everywhere, and far deeper game customisation.**

Architecture
* Premium games are now delivered as a separate **CompKit Game Engine Premium** plugin, installed in one click from the admin. The free plugin no longer downloads or unzips code into itself.
* New in-admin Download / Install flow, including an upgrade prompt when the premium plugin version doesn't match the engine.

New admin experience
* Brand-new admin design across the engine and every game's settings page — consistent CompKit header, tabbed settings, and a **sticky live preview** that updates as you edit.
* Live game previews now appear on both the global settings page **and** the per-product settings panel for every game.

Instant Reveal & Orders Email — fully rebuilt
* Redesigned settings screen matching the game pages, with a **live email preview** of the real order email and the per-prize reveal email (including your WooCommerce email header).
* New Prize Table controls: coupon-button and claim-button colours, editable cash/credit and physical-prize notes, and a prize-table heading.
* New ticket-badge, coupon-button and claim-button **corner-radius and text-size sliders**.
* "Send me a test email" button to preview the reveal email in your inbox.
* The order email message now applies only to orders containing a game.

Per-game custom settings (with per-product overrides and "Use Global" fallback)
* **Spin the Wheel** — wheel size (desktop/mobile), frame image, pointer style + custom badge, segment colours/text/borders/images, full Spin / Play / Reveal-All button styling, ticket-column layout, sound effects, behaviour.
* **Slots** — text / emoji / image reel symbols, reel styling, header, win/lose shown fields, Nudge, Turbo mode, modal, custom sounds.
* **Scratch Card** — Standard, Symbol-match and Sums modes with a Prize Vault, scratch surface, win/lose cards, win popup, full navigation-button styling, sounds.
* **Football Penalty** — stadium images, team strip/kit, advertising boards, scoreboard, controls, results strip, full theming.
* **Race Car** — player/opponent car sprites, track and start-line, trackside billboards, HUD, theme colours, win/lose, sounds.
* **Grab Claw** — built-in style presets, cabinet colours and shape, machine title, 6 custom toy-image slots.
* **Balloon Pop** — object type (balloon, bubble, star, gift box, pumpkin), game-area image, win/lose colours, sounds.
* **Darts** — dart and board styles, branding images, power-bar styling, win/lose colours, sounds.

Reliability
* All games now reliably trigger the instant-win reveal email and correctly hold then release the order confirmation email until every ticket is played.
* Game icons unified to a single source; plugin renamed to **CompKit Game Engine**.

= 2.2.1 =

* Instant Reveal bundled locally with no licence required
* Added Smart Email Delay Handler
* Added premium module interest opt-in form
* Fixed dependency check for Giveaways plugin renamed from Lottery
* Removed external CDN dependencies
* Standardised text domain to giveaways-addons
* Restored SSL verification on licence validation requests

= 2.2.0 =

* Full admin settings overhaul for Spin the Wheel
* Added CSS custom property system for game colours
* Added Reveal All button settings
* Added behaviour settings including auto-play, summary screens, and win popups

= 2.1.0 =

* Added Spin the Wheel module
* Added Slot Machine module
* Added Prize Grab Claw module

= 2.0.0 =

* Initial public release with Instant Reveal and Scratch Card
