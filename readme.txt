=== Sepo LMS ===
Contributors: euneolink
Tags: lms, courses, quiz, e-learning, mobile money
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Courses, lessons, quizzes, schools and teachers, with enrolment and payments by bank transfer, payment link, Stripe, PayPal, pawaPay mobile money or WooCommerce.

== Description ==

Sepo LMS turns WordPress into a small learning platform.

* **Courses** with price, discount, tax, level, dates, objectives, teacher, school, related courses and categories.
* **Lessons and quizzes** that belong to a course. Drag them into order in the course's Outline box. Lock items until a date, or make the course sequential so each item needs the one before it.
* **Visual quiz builder.** Pick a question type, type the question, tick the right answers, drag to reorder. Nine question types: multiple choice, checkboxes, short text, paragraph, number, date, email, phone and web address. Points per question, pass mark, time limit, attempts, retakes, answer review and a weight for the final grade.
* **Enrolment** with a form (name, email, and optionally national ID, gender, location and date of birth). Free courses open straight away; paid courses open once payment is confirmed.
* **Payments.** Manual methods (bank transfer, cash) and payment links are confirmed by an admin. Stripe Checkout, PayPal Checkout and pawaPay mobile money confirm themselves: the plugin always asks the payment service whether a payment went through, on the learner's return, on webhooks, and in an hourly check.
* **WooCommerce.** With WooCommerce active, a "WooCommerce checkout" method lets learners pay with any gateway your shop has. Each enrolment creates an order; the course opens when the order is paid and closes again if it is refunded or cancelled.
* **Receipts** by email and as a printable page.
* **Progress tracking**, course reviews, and a **My courses** page with progress, final grades (weighted when every quiz has a weight) and receipts.
* **Schools and teachers** with their own pages listing their courses.
* **Admin:** an enrolments list with filters, bulk actions and CSV export; quiz results with statistics and per-learner answers.
* Works with block themes and classic themes. Every template can be overridden from your theme.

Prices are always worked out on the server from the course's own settings: discount is taken off the price, then tax is added to the discounted price.

= Shortcodes =

* `[sepo_lms_courses]` — course index. Options: `category`, `school`, `teacher`, `level`, `ids`, `limit`, `per_page`, `title`.
* `[sepo_lms_schools]` — school index.
* `[sepo_lms_teachers]` — teacher index. Option: `school`.
* `[sepo_lms_my_courses]` — the signed-in learner's courses, grades and receipts, in Courses and Quiz results tabs. Link straight to the quiz tab with `#quizzes`. A "My courses" page holding it is created on activation.

= Addresses =

Courses live at /courses/, lessons at /lessons/, quizzes at /quizzes/, schools at /schools/ and teachers at /teachers/. Each can be changed in LMS → Settings.

= Theme templates =

Copy any file from the plugin's `templates/` folder into `your-theme/sepo-lms/` (keep the `parts/` folder) to change it.

= Hooks =

* `sepo_lms_enrolled` (array $enrollment) — a learner enrolled.
* `sepo_lms_enrollment_paid` (array $enrollment) — payment confirmed. Fires once per enrolment.
* `sepo_lms_enrollment_removed` (array $enrollment) — an enrolment was removed.
* `sepo_lms_quiz_submitted` (array $result, array $settings) — a quiz was submitted.

== Installation ==

1. Upload the `sepo-lms` folder to `/wp-content/plugins/` and activate it.
2. Go to LMS → Settings: set your currency and add payment methods.
3. Add schools and teachers (optional), then courses, then lessons and quizzes.

= WooCommerce =

Activate WooCommerce, then in LMS → Settings add a payment method of type "WooCommerce checkout". Learners are sent to the order's "pay for order" page and can use any gateway enabled in WooCommerce → Settings → Payments. The order carries the course at its discounted price plus a tax line, so it charges exactly what the course page shows. The course opens when WooCommerce marks the order Processing or Completed. Gateways that leave the order On hold (bank transfer, cheque) open the course when you move the order to Processing or Completed. Note that WooCommerce treats Cash on delivery orders as Processing, so the course opens straight away. Works with WooCommerce's order tables (HPOS).

= Stripe =

Enter your secret key. For the most reliable confirmation, add a webhook endpoint in Stripe for `/wp-json/sepo-lms/v1/webhook/stripe` with the `checkout.session.completed` and `checkout.session.async_payment_succeeded` events, and paste its signing secret.

= PayPal =

Create a REST app in the PayPal developer dashboard and enter its client ID and secret. PayPal does not take every currency; courses priced in a currency PayPal does not support will not offer PayPal.

= pawaPay =

Uses pawaPay's Merchant API v2. Enter your API token and set both the deposit and the checkout callback URLs in the pawaPay dashboard to `/wp-json/sepo-lms/v1/webhook/pawapay`.

In a pawaPay payment method, leave the network empty to send the learner to pawaPay's payment page (a v2 checkout: they pick their network there, and can retry a failed attempt on the same page). Put a network code (for example `MTN_MOMO_ZMB`, `AIRTEL_OAPI_ZMB` or `ZAMTEL_ZMB`) to send the prompt straight to their phone instead.

A payment is only accepted for the full amount in the right currency; anything else is held with a "needs review" status for you to check. If pawaPay does not answer when a payment starts, the enrolment is kept and checked again every hour rather than forgotten. If a learner tries again, their previous pawaPay page is closed first so it cannot be paid twice.

== External services ==

This plugin connects to payment services only when you configure them and a learner chooses them:

* **Stripe** (api.stripe.com) — creates a Checkout Session and reads its status. Sends the amount, currency, course title, a reference and the learner's email. [Terms](https://stripe.com/legal) · [Privacy](https://stripe.com/privacy)
* **PayPal** (api-m.paypal.com / api-m.sandbox.paypal.com) — creates and captures an order. Sends the amount, currency, course title and a reference. [Terms](https://www.paypal.com/legalhub/home) · [Privacy](https://www.paypal.com/myaccount/privacy/privacyhub)
* **pawaPay** (api.pawapay.io / api.sandbox.pawapay.io) — requests a mobile money deposit and reads its status. Sends the amount, currency, a reference and the learner's phone number. [Terms](https://www.pawapay.io/terms-of-use) · [Privacy](https://www.pawapay.io/privacy-policy)

== Privacy ==

Enrolment records hold names, email addresses and, if you ask for them, ID numbers, gender, location, date of birth and phone numbers. They are included in WordPress's personal data export and erase tools. Paid enrolments are kept as financial records with the personal details removed. Suggested privacy policy text is added under Settings → Privacy.

== Frequently Asked Questions ==

= Can a learner see a paid lesson without paying? =

No. Lessons and quizzes check enrolment and payment on the page, in the REST API and in the quiz submission. Lesson content is not shown in search results, feeds or embeds.

= What happens if a learner closes the checkout? =

Their enrolment waits for payment. They can press "Complete payment" on the course page to try again.

= Can learners see the correct answers? =

Only when "Let learners review their answers" is on and they have no attempts left.

== Changelog ==

= 1.2.3 =
* My courses → Quiz results is grouped by course. Each course opens and closes, shows its final grade and how many quizzes are taken and passed; inside, every quiz in the course is listed with its mark, best score, attempts and date, or "Not taken yet" with a link. Search matches course or quiz titles, filter by passed / not passed / not taken, open or close all, ten courses per page.

= 1.2.2 =
* My courses: Courses and Quiz results are now tabs, each with its own search, filters (status and progress; course and result) and pages of 10. The open tab is kept in the address (#quizzes), so it survives a reload or a shared link.

= 1.2.1 =
* Fixed: a blank answer row in a multiple-choice or checkbox question moved the "correct" tick onto the wrong answer, so the right answer was marked wrong.
* Typed answers are matched sensibly: case, spacing and end punctuation are ignored; numbers accept 1,5 / 1.5 / 1,000; dates accept any common format; web addresses ignore http(s):// and www; phone numbers compare as full international numbers.
* Characters such as < > & % in questions and answers are kept as typed.
* Receipts and payment emails wrap properly on phones.

= 1.2.0 =
* pawaPay moved to the Merchant API v2: hosted checkouts and direct deposits.
* A payment start that times out is kept and checked, not forgotten; only a definite refusal is discarded.
* Retrying a payment closes the previous pawaPay or Stripe checkout first; a retry is refused while a phone prompt is still waiting.
* pawaPay underpayments and currency mismatches are held for review.

= 1.1.0 =
* WooCommerce checkout as a payment method.

= 1.0.0 =
* First release.
