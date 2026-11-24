=== Takedown Desk: Take It Down Act Removal Requests and DMCA Takedown Notices ===
Contributors: raselhk
Tags: take it down act, dmca, takedown, content moderation, bbpress
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take It Down Act and DMCA takedown plugin for WordPress and bbPress: request forms, a deadline clock, one-step removal and a dated record.

== Description ==

Takedown Desk is a free Take It Down Act plugin for WordPress. It lets you handle removal requests for intimate images inside your own dashboard, from the first report to the dated record. It also gives you a DMCA takedown notice form for copyright complaints, in the same inbox.

The Take It Down Act asks sites that host user content to give people a clear way to report intimate images shared without consent, and to remove them within 48 hours. Takedown Desk gives you that process in your own dashboard: a notice link, a request form, a deadline clock, one-step removal of the item and its identical copies, and a dated record of every action. It helps you handle requests the way the Act describes.

= What it does =

1. **Notice.** A "Request removal of intimate images" link in your site footer, and where you choose: under posts, on media pages, under comments and under bbPress topics and replies. It leads to a notice page that the setup creates for you.
2. **Request.** A short form with the four parts the Act requires: where the image is, a statement that it was shared without consent, a signature and contact details. No account is needed and the form never asks for the image. The person gets a request ID right away and a private status link.
3. **Review.** Every request lands in an inbox with a live 48-hour clock. Your moderators get an email when it arrives and reminders at 24 and 40 hours, and an overdue alert if it is still open. You can ask the person for more details through their private status link.
4. **Remove.** One action takes the reported item and its identical copies offline, or deletes them. Items taken offline can be restored for 7, 30 or 90 days before they are deleted for good. Image files move to a protected folder, and the page cache is cleared for common caching plugins and, once you add an API token, for Cloudflare. Removed images stay removed: a new upload that is an exact copy of one is refused.
5. **Close.** The person gets an email with the outcome. Every step is written to a dated, tamper-evident log that you can export as CSV.
6. **Spam protection.** An invisible check stops automated spam on the form, with no CAPTCHA, no third-party service and no cookies. It is on by default; if you prefer Google reCAPTCHA (v3 or v2), turn it off and add your reCAPTCHA keys in Settings.
7. **Your emails.** Settings shows when each email is sent. Turn any of them off, or change the subject and text with fields such as the request ID and the deadline, and see a live preview as you type.
8. **Abuse signals.** Amber signals in the inbox point out the same item reported again and again, one requester reporting many items, and requesters on your watch list. Signals are advice only and never decline a request.
9. **Hide on arrival (optional).** Turn on "Hide reported items as soon as a request arrives" to take the item offline while you review. It is off by default, never hides content by an administrator and stops after a person's first 3 requests in a day.
10. **Tell the person who posted it (optional).** After a removal, the person who posted the content gets an email with the reason and a private link to ask for a review. It never says who sent the request. A review request shows up on the request for a moderator to decide, and the content stays offline meanwhile.
11. **Other laws.** Turn on "This site has visitors in the EU" and outcome emails say how to contest the decision, as the EU Digital Services Act asks. A request still open 6 days after it arrived sends the requester one status update, as California asks of social media platforms.
12. **Leave this page.** The form and the status pages show a small "Leave this page" button that goes straight to a weather search and clears the form. Pressing Esc twice does the same. You can turn it off in Settings.
13. **Public numbers and policy text (optional).** The "Takedown Desk summary" block shows requests received, removed and not removed, the median time to removal and the share closed within 48 hours, with counts under 5 never shown exactly. Settings also suggests a short section for your terms of service that links to your notice page.

= Built from the text of the law =

* **Clear notice of the process** becomes the "Request removal" link and the notice page.
* **The four parts of a valid request** (signature, where the image is, a good-faith statement, contact details) become the four required parts of the form.
* **Removal within 48 hours** becomes a clock on every request, reminders and an overdue alert.
* **Reasonable efforts to remove known identical copies** becomes the exact-copy finder, which matches the same file across your media library, posts, featured images and comments.
* **Acting in good faith** becomes a dated record of who did what and when, with CSV export.

= Who needs it and who does not =

The Act covers public sites and apps that mainly host content from their users: forums, communities, membership sites, galleries and sites that accept photos or videos from visitors. A site that mostly publishes its own content, where comments are a small extra, is generally outside the Act. The setup step asks a few questions to help you check whether it applies to your site. Many site owners add the process anyway, because it gives people a clear and respectful way to ask for help.

= For bbPress forums and community sites =

Forums and community sites are the sites the Act has most in mind. For them, Takedown Desk works as a content moderation tool for one job: reports of intimate images. A "Request removal" link can sit under every bbPress topic and reply, anyone can report an image without an account, the report lands in your moderators' inbox with a deadline clock, and one action removes the topic or reply together with identical copies of the image. bbPress has no public report link of its own; this adds one.

= Copyright (DMCA) takedown notices =

Turn on "Accept copyright notices" in Settings and Takedown Desk adds a DMCA takedown notice form to your site, on its own "Copyright notices" page with a footer link.

* **The six parts of a DMCA notice.** The form asks for the parts listed in 17 U.S.C. 512(c)(3)(A): a signature, the copyrighted work, where the material is on your site, contact details, a good-faith statement, and a statement of accuracy and authority under penalty of perjury.
* **One inbox.** Copyright notices arrive next to your other requests with a "Copyright" label and their own filter.
* **Your own target time.** The law asks you to act "expeditiously" and sets no hour count, so you pick a target of 1 to 10 business days (2 by default). Moderators get reminders before it runs out.
* **Removal and record.** Remove the material in one step, tell the sender the outcome, optionally tell the person who posted it, and keep every step in the dated record.
* **Designated agent reminder.** Enter the date you registered your agent with the US Copyright Office and the status card reminds you before the 3-year renewal.

Counter-notices are not part of this version. The form is off until you turn it on.

= What it never does =

* It never asks for, accepts or stores an image upload. A person should not have to send the image again, and your site should not collect it.
* It never sends request data to us or to any outside service. There is no tracking.
* It does not promise a legal outcome. It gives you the process; how you run it is up to you.

= Works with =

* Any public post type, pages, the media library and comments.
* bbPress topics and replies.
* The block editor: "Removal request form", "Copyright notice form", "Removal request link" and "Takedown Desk summary" blocks.
* Shortcodes `[takedown_desk_form]`, `[takedown_desk_copyright_form]`, `[takedown_desk_link]` and `[takedown_desk_summary]`, so any builder that runs shortcodes, such as Bricks or Elementor.
* WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, SiteGround Optimizer, WP Fastest Cache, Cache Enabler, Breeze and Nginx Helper for clearing the cache after a removal.

= For developers =

* Actions such as `tkdesk_request_received`, `tkdesk_request_status_changed`, `tkdesk_item_removed`, `tkdesk_item_restored` and `tkdesk_log_written`.
* Filters such as `tkdesk_object_types`, `tkdesk_resolve_url`, `tkdesk_find_copies`, `tkdesk_form_fields`, `tkdesk_email`, `tkdesk_notify_recipients` and `tkdesk_csv_columns`.
* The admin app runs on the REST API under the `takedown-desk/v1` namespace; every admin route checks the user's capability.
* Three capabilities (`tkdesk_manage_requests`, `tkdesk_manage_settings`, `tkdesk_export`) and a "Takedown moderator" role.

= Privacy and external services =

**What is stored.** For each request: the reported link, the description, the good-faith statement, the name typed as a signature, the email address and, if given, a phone number. These details are encrypted in your database with libsodium (secretbox). The key comes from a `TKDESK_KEY` line in wp-config.php or, if there is none, from a random key the plugin creates once and keeps in your database (Settings shows the line that moves it to wp-config.php). It never depends on your site's security keys or salts. The IP address is not stored; a keyed one-way code made from it limits abuse of the form. A keyed one-way code of the email address lets the WordPress privacy tools find a person's requests.

**How long.** Contact details and request text are deleted 90 days after a request is closed (you can pick 30, 90, 180, 365 or 730 days). Spam requests are deleted after 30 days. Items taken offline are kept for 30 days (7, 30 or 90) so you can restore them, then deleted for good. The action log holds no contact details and is kept until you choose to prune it after 2, 5 or 7 years.

**Your privacy tools.** Takedown Desk adds an exporter and an eraser to the WordPress personal data tools, and suggested text for your privacy policy page.

**External services.** The plugin loads no remote files unless you choose Google reCAPTCHA (below); its font is bundled. Its own web requests go to your own site: one loads your home page to confirm the notice link is visible, one checks that the web server blocks direct access to the protected image folder, and the removal check asks every removed or hidden link (the page, and each image file in every size) whether it still answers, right after the removal and once more an hour later. If your site sits behind a CDN such as Cloudflare, these requests pass through it like any visitor's. Three outside services can be used. The first is used only after you turn on Check Jetpack image CDN copies under Settings, Removal (off by default), on a site where the Jetpack image CDN (Site Accelerator) is on: the removal check also asks that CDN, at i0.wp.com to i3.wp.com, whether it still serves a copy of each removed full-size image. Each request contains only the address of the image on your site, and nothing about the request or the requester. The CDN is run by Automattic: terms of service https://wordpress.com/tos/, privacy policy https://automattic.com/privacy/. The second is Cloudflare, used only after you add a Cloudflare zone ID and API token under Settings, Cloudflare: each time an item is removed or hidden, the plugin asks the Cloudflare API (api.cloudflare.com) to purge that item's links from the Cloudflare cache, and Test connection sends one purge request for a test link on your site. Each request contains your zone ID, your API token and links on your own site, and nothing about the request or the requester. Cloudflare terms of service https://www.cloudflare.com/website-terms/, privacy policy https://www.cloudflare.com/privacypolicy/. The third is Google reCAPTCHA, used only after you turn off the built-in bot protection and turn on Use Google reCAPTCHA with your own keys under Settings, Spam protection (off by default). Then the request form and the review form load Google's script from www.google.com, which runs in the visitor's browser and sends Google information about the visit, as Google describes; when a form is sent, the plugin sends the form's reCAPTCHA token and your secret key to Google's verify service (www.google.com/recaptcha/api/siteverify), and nothing else about the request or the requester. Test the secret key sends your secret key and a test token. Google terms of service https://policies.google.com/terms, privacy policy https://policies.google.com/privacy. The form and the outcome email link to NCMEC's Take It Down service (https://takeitdown.ncmec.org/) and StopNCII.org (https://stopncii.org/), which help people stop an image from spreading on other platforms. These are plain links; nothing is sent to either service. Emails go through your site's own mail setup.

**Uninstall.** Your requests and log stay when you delete the plugin, unless you turn on the setting to delete all data on uninstall.

= Credits =

* Plus Jakarta Sans font by the Plus Jakarta Sans Project Authors (https://github.com/tokotype/PlusJakartaSans), SIL Open Font License 1.1. The license is in assets/fonts/OFL.txt.
* Admin icons from Lucide (https://lucide.dev), ISC License.

= Documentation =

The [Takedown Desk documentation](https://swiftwebdev.com/takedown-desk/docs/) covers setup, the request form, reviewing and removing requests, and each setting. The [Takedown Desk plugin page](https://swiftwebdev.com/takedown-desk/) has an overview and screenshots.

= Not legal advice =

Takedown Desk is software, not legal advice. Talk to a lawyer about your duties under the Act.

== Installation ==

1. In your dashboard, go to Plugins, Add New Plugin, search for "Takedown Desk", then install and activate it.
2. Open Takedown Desk in the admin menu and follow the setup: check whether the Act applies to your site, create the notice page and choose where the "Request removal" link appears.
3. Give a second person the "Takedown moderator" role, so weekends and holidays are covered.
4. Send a test request.

== Frequently Asked Questions ==

= What is the Take It Down Act and when did it take effect? =

A United States federal law signed on May 19, 2025. It makes it a crime to publish intimate images of a person without consent, including AI-generated fakes. Sites that host user content had one year to set up a notice and removal process, so that part applies from May 19, 2026.

= Does the Take It Down Act apply to my WordPress site? =

It applies to a "covered platform": a public website, online service or app that mainly provides a forum for user-generated content such as messages, images, videos or audio. Sites that mostly publish their own content, where comments or chat are incidental, are generally excluded. The setup step in Takedown Desk walks you through these questions. If you are unsure, ask a lawyer.

= Does it apply to a blog with comments? =

Usually not, when the comments are a small extra to your own posts. It can apply when visitors can post images or videos, or when the community is the main purpose of the site. Many owners add the process either way.

= Is this a content moderation plugin for bbPress? =

It covers one moderation job in full: reports of intimate images shared without consent. It adds a report link under bbPress topics and replies, an inbox for your moderators, a 48-hour clock and one-step removal. It does not filter spam or hold new posts for approval; bbPress and anti-spam plugins do that.

= What must a Take It Down Act removal request include? =

Four things: a physical or electronic signature, enough information to find the image, a short statement that the person believes in good faith that the image was shared without consent, and contact information. The form asks for exactly these four parts.

= How fast do I have to remove content after a request? =

As soon as possible and no later than 48 hours after you receive a valid request. Takedown Desk starts the clock when the request arrives and shows the deadline in your time zone.

= What happens if nobody on my team sees the request in time? =

Moderators get an email when the request arrives and reminders at 24 and 40 hours, then an overdue alert. The open count also shows in the admin menu and the admin bar. The status card warns you when only one person can act on requests or when scheduled tasks are not running on time.

= Can I change the emails? =

Yes. Go to Takedown Desk > Settings > Email templates. Each email shows when it is sent and has an on/off switch. Edit opens its subject and text with a list of fields, such as {request_id}, {deadline} and the private status link, and a live preview with sample details. Reset to default brings back the original text, which follows the language of the person who gets it. If you turn off an email to requesters, Settings warns you, because requesters rely on it to hear the outcome.

= Do I have to find copies of the image? =

The Act asks you to make reasonable efforts to remove known identical copies. The exact-copy finder looks for the same file in your media library and in the posts, featured images and comments that show it, and one action removes them with the original. It matches exact copies only; an edited or re-saved version is a different file.

= Does the form ask victims to upload the image? =

No. A person should not have to send the image again, and your site should not collect it. The form asks where the image is instead.

= Can people without an account send a request? =

Yes. The form works for everyone, with or without an account. Spam is filtered with an invisible check, a time check and a hidden field, so there is no CAPTCHA service.

= How does Takedown Desk stop spam? =

The form runs an invisible check in the visitor's browser: a small calculation that takes a moment for one person but adds up quickly for a bot sending many requests. If JavaScript is turned off, the form asks a simple sum instead. Everything runs on your own site, with no third-party service and no cookies. A hidden field, a time check and a limit of 5 requests an hour per connection also stay on. You can turn the invisible check off in Settings, under Spam protection. There you can use Google reCAPTCHA v3 (invisible) or v2 (the "I'm not a robot" checkbox) instead, with your own keys; it checks the request form and the review form. reCAPTCHA sends visit information to Google and needs JavaScript, so the built-in check is the default. If Google cannot be reached or rejects your secret key, forms still go through and the status card tells you, so no removal request is lost.

= What stops people from abusing the removal form? =

Every request needs a signature and a good-faith statement, and the spam checks and the limit of 5 requests an hour per connection apply to everyone. Nothing goes offline until a moderator decides, unless you turn on "Hide reported items as soon as a request arrives" in Settings, under Removal. Even then the item is only hidden while you review, content by an administrator is never hidden, and nothing is hidden after a person's first 3 requests in a day. The inbox shows amber signals when the same item is reported 3 or more times in 30 days, when one requester reports 5 or more items in 7 days, and when the requester is on your watch list. The watch list keeps a one-way code made from the email address, never the address itself. Signals are advice only and never decline a request. If you decide a request was not made in good faith, choose that reason when you close it; the requester gets a neutral sentence, not an accusation.

= My site is behind Cloudflare or a proxy =

The form accepts up to 5 requests an hour from one internet address. Behind Cloudflare or another proxy, every visitor can arrive with the proxy's address, so real requests may be blocked. Ask your host to pass the real visitor IP to WordPress (Cloudflare calls this restoring visitor IPs). If that is not possible, a developer can return the real IP with the `tkdesk_client_ip` filter.

To have removals purge the Cloudflare cache, add your zone ID and an API token with the Cache Purge permission under Settings, Cloudflare. Other CDNs and hosts can purge through the `tkdesk_purge_urls` action.

= What does the plugin store, and for how long? =

The request details, encrypted, until 90 days after the request is closed (30 to 730 days, your choice), and a log of actions without contact details. See "Privacy and external services" above for the full list.

= What happens when the person in the image was under 18? =

The requester can say so on the form, and a moderator can mark any request. The request then shows the steps to report to the NCMEC CyberTipline (18 U.S.C. 2258A asks services to report and to keep the material for 1 year), with a field for the report number. While the flag is on, items can only be taken offline, never deleted; removed files stay out of public reach and are never deleted automatically, whatever your retention settings say; image previews are off; and the privacy eraser and uninstall keep the request. After you save a report, the flag can be cleared only 1 year after the report date. This is not legal advice.

= Does it work with bbPress, BuddyPress, BuddyBoss or wpForo? =

bbPress is built in: the link appears under topics and replies, and a reported topic or reply can be removed from the request. For BuddyPress, BuddyBoss and wpForo, requests still arrive with the reported link, and you remove the item in that plugin.

= What is the difference between this and NCMEC's Take It Down service or StopNCII? =

NCMEC's Take It Down (for people who were under 18 in the image) and StopNCII.org (for adults) help people stop an image from spreading across participating platforms. Takedown Desk handles requests sent to your own site. It does not connect to either service; it links to both so people know where to go next.

= Do you tell the person who posted the image? =

Only if you choose to. Turn on "Notify the person who posted the item" in Settings, under Notices and other laws. It is off by default, and always on while "This site has visitors in the EU" is on. After a removal, the person who posted the content gets an email with the reason and a private link to ask for a review. The email never says who sent the request and never includes anything the requester wrote. It is not sent when the person in the image was under 18, or when your team filed the request. Each link works once. A review request does not put the content back: the request shows "Review requested", the content stays offline, and a moderator keeps it offline or an administrator restores it. The deadline and the record of the removal do not change.

= Does Takedown Desk help with the EU Digital Services Act? =

It covers the parts that fit a removal desk. Turn on "This site has visitors in the EU" in Settings. The outcome emails to the requester then say how to contest the decision, and the person who posted removed content is always told the reason and how to ask for a review (Articles 16 and 17 of the Act). Takedown Desk does not cover the rest of the Act, such as transparency reports or the extra duties of online platforms. This is not legal advice.

= Does using this plugin make my site meet the law? =

It gives you the process the Act describes. Whether your site meets the law depends on how you run that process, for example how quickly your team acts. Talk to a lawyer about your situation.

= What is the FTC penalty for missing the deadline? =

The Federal Trade Commission enforces the notice and removal duties. Failing to follow them counts as an unfair or deceptive practice, which can bring civil penalties for each violation at the amount the FTC sets each year (over $50,000 per violation in 2025).

= Can I use it for copyright (DMCA) takedown notices? =

Yes, from version 1.1. Turn on "Accept copyright notices" in Settings. Takedown Desk creates a "Copyright notices" page with a DMCA takedown notice form that asks for the six parts the law lists, and the notices arrive in the same inbox with a target time you choose. It does not handle counter-notices. To rely on the DMCA safe harbor, a site also needs a designated agent registered with the US Copyright Office and a repeat infringer policy; the plugin reminds you about the agent renewal, and a lawyer can advise you on the rest.

= What must a DMCA takedown notice include? =

Six things under 17 U.S.C. 512(c)(3)(A): a physical or electronic signature, the copyrighted work, the material that is claimed to infringe and where it is, contact information, a statement of good-faith belief that the use is not authorized, and a statement that the notice is accurate and, under penalty of perjury, that the sender may act for the copyright owner. The copyright notice form asks for exactly these.

= Is there a paid version? =

Yes, an optional add-on: [Takedown Desk Pro](https://swiftwebdev.com/takedown-desk-pro/). It adds altered-copy matching, integrations with more community plugins, escalation alerts, evidence packets, monthly reports and agency tools. Everything described on this page is in the free plugin and works without the add-on.

== Screenshots ==

1. The request inbox with a live 48-hour clock on every case
2. The public request form: Four required parts, no account, no image upload
3. Confirmation with a request ID
4. Request detail: The reported item, identical copies and the decision
5. One action removes the item and its copies
6. The outcome email the requester receives
7. The record of actions with CSV export
8. Setup: Check whether the Act applies to your site
9. Settings: Where the "Request removal" link appears
10. The notice link in a site footer and on a forum reply
11. The public copyright notice form: The six parts of a DMCA notice
12. A copyright notice in the request detail, with its target in business days
13. Settings: Turn on copyright notices and set the target time

== Changelog ==

= 1.1.1 =
* Fix: In Settings, the editor for the copyright notice emails described its fields and filled its preview as for an intimate image request (48-hour deadline, removal request page). It now shows the target time, the sender and the copyright notice page, like Preview.
* Fix: Preview and Send me a test for the copyright notice emails use a sample notice with its business-day target and copyright wording.
* Fix: Log a request no longer accepts a link that does not start with http:// or https://.
* Fix: In Log a request, the list of errors follows the order of the fields.
* New: A link to the documentation at the bottom of the Takedown Desk screens.
* Readme: Links to the plugin page and the documentation, and three screenshots of copyright notices.

= 1.1.0 =
* New: Copyright (DMCA) takedown notice form. Turn it on in Settings to add a "Copyright notices" page, a footer link, a block and the shortcode `[takedown_desk_copyright_form]`.
* New: Copyright notices arrive in the same inbox, with a target time in business days, reminders, one-step removal, outcome emails and the dated record.
* New: Designated agent renewal reminder on the status card.

= 1.0.1 =
* Security: the encryption key no longer depends on your site's security keys or salts. Takedown Desk creates its own random key, or uses a TKDESK_KEY line in wp-config.php.
* Security: form fields are sanitized as soon as they arrive.
* Privacy: moderators are shown by their initials. No profile pictures load from Gravatar.
* New look for the public request form, confirmation, status and review pages, matching the admin screens.
* The open-request count stays on one line in the admin menu.
* New: Spam protection settings. The built-in check stays on by default; turn it off to use Google reCAPTCHA v3 or v2 on the request and review forms instead, with your own keys.

= 1.0.0 =
* First release: notice link and page, request form with four required parts, 48-hour clock with reminders, one-step removal of the item and its identical copies, outcome emails, tamper-evident log with CSV export, setup check, privacy exporter and eraser, retention purge.

== Upgrade Notice ==

= 1.1.1 =
Fixes for the copyright notice email editor and the Log a request dialog, and a link to the documentation.

= 1.1.0 =
Adds an optional DMCA takedown notice form for copyright complaints. Off until you turn it on.

= 1.0.1 =
Security and privacy improvements, a new look for the public request pages, and optional Google reCAPTCHA.

= 1.0.0 =
First release.
