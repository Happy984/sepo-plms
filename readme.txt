=== Sepo LMS ===
Contributors: euneolink
Tags: lms, courses, quiz, e-learning, mobile money
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Courses, lessons, quizzes, schools and teachers, with enrolment and payments by bank transfer, card, PayPal or mobile money.

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
* **Assignments** of two kinds. *Hand-in* assignments list requirements, accept files (types, count and size set per assignment) and/or a written answer with a minimum word count, and can ask learners to tick each requirement before handing in. *Quiz* assignments use one of your quizzes. Both have a due date, late rules (accept with a penalty per day, or close at the due time), points, a pass mark and a weight in the final grade. Teachers grade and give feedback, or return work for rework; learners are emailed either way.
* **Proctoring** for assignments and quizzes: full-screen lockdown, a log of every time the learner leaves the page or full screen, an optional leave limit that hands the work in automatically, and optional recording of the learner's entire screen (with their consent). Recordings stream to the server while the learner works and are kept for a retention period you choose.
* **Certificates** issued automatically when a learner completes a course and passes its grade requirement, as a PDF with a verification code. Anyone can check a code on the public verification page. Certificates can be withdrawn, restored or issued by hand.
* **Certificate designer**: design certificates visually in LMS → Certificate designs. Start from Classic, Modern, Minimal or a blank page; drag text, fields ({name}, {course}, {date}, {grade}, {code}, {verify_url} and more), your logo, images such as a signature (transparent PNGs keep their transparency), lines and boxes; set fonts, sizes, colours, alignment, letter spacing and the page size, orientation and background. Snapping guides, undo and redo, keyboard nudging, and a PDF preview in the browser. Each course chooses its design.
* **Forums** per course, of three types: *Discussion* (open), *Post to see replies* (learners see others' replies to a topic only after replying themselves) and *Announcements* (only staff post; learners are emailed).
* **Chat**: a class room for each course and, optionally, direct messages between learners as well as with staff.
* **Attendance**: sessions with optional self check-in by code, a late threshold, a register to mark present, late, absent or excused, attendance rates and CSV export.
* **Study tools**: a checklist and block-based notes (headings, lists, to-dos, quotes, callouts, code, dividers; "/" menu and Markdown shortcuts) in a drawer on every lesson and assignment, and a Notes tab under My courses.
* **Lessons as cards**: long lessons are shown one part at a time, with Previous and Next, and Mark as complete appears on the last part. Lessons split where the author adds a page break, or at each main heading when they are long; each lesson can choose.
* **Course resources**: documents to preview and download, and audio in a podcast-style player and video, on a Resources tab beside the forums. PDFs, pictures and text files open in a preview window before downloading. Only learners in the course and its staff can open them.
* **Lesson pages**: a heading with the lesson number in the margin, a large serif title, the teacher, the course and the reading time, and a drop capital on the first paragraph.
* **Proof of payment**: learners paying by bank transfer or another manual method upload a photo or PDF of the slip from the course page. Staff accept or decline it on the enrolments screen; accepting confirms the enrolment, and the learner is emailed either way.
* **Transcripts**: school records for each pupil from the first year of primary to the last year of secondary, kept apart from online courses. Enter marks by year, term and subject, one pupil at a time or a whole class on a mark sheet. Term and year averages and grades come from your own grading scale. Parents linked to a pupil, and the pupil, read the transcript on the page holding `[sepo_transcripts]` and can print it or save it as a PDF.
* Every classroom feature can be switched off in LMS → Settings → Classroom.
* Works with block themes and classic themes. Every template can be replaced from your theme.

Prices are always worked out on the server from the course's own settings: discount is taken off the price, then tax is added to the discounted price.

= Shortcodes =

* `[sepo_lms_courses]` — course index. Options: `category`, `school`, `teacher`, `level`, `ids`, `limit`, `per_page`, `title`.
* `[sepo_lms_schools]` — school index.
* `[sepo_lms_teachers]` — teacher index. Option: `school`.
* `[sepo_transcripts]` — school transcripts for the signed-in parent's children, or the pupil's own.
* `[sepo_lms_my_courses]` — the signed-in learner's courses, grades, certificates, notes and receipts, in tabs. Link straight to a tab with `#quizzes`, `#certificates` or `#notes`. A "My courses" page holding it is created on activation.

= Addresses =

Courses live at /courses/, lessons at /lessons/, quizzes at /quizzes/, assignments at /assignments/, schools at /schools/ and teachers at /teachers/. Each can be changed in LMS → Settings.

= Theme templates =

Copy any file from the plugin's `templates/` folder into `your-theme/sepo-lms/` (keep the `parts/` folder) to change it.

= Hooks =

* `sepo_lms_enrolled` (array $enrollment) — a learner enrolled.
* `sepo_lms_enrollment_paid` (array $enrollment) — payment confirmed. Fires once per enrolment.
* `sepo_lms_enrollment_removed` (array $enrollment) — an enrolment was removed.
* `sepo_lms_quiz_submitted` (array $result, array $settings) — a quiz was submitted.
* `sepo_lms_assignment_submitted` (array $submission) — a learner handed in an assignment.
* `sepo_lms_assignment_graded` (array $submission, bool $returned) — a teacher graded a hand-in, or returned it for rework.
* `sepo_lms_certificate_issued` (array $certificate) — a certificate was issued.
* `sepo_lms_progress_changed` (int $courseId, int $userId) — a lesson was completed or a quiz submitted.

= Private files =

Hand-in files and screen recordings are stored in `wp-content/uploads/sepo-lms-private/`, closed to the web with `.htaccess` (Apache) and `web.config` (IIS), and served only through the plugin to the learner who owns them and the course staff. On nginx, add the rule shown in LMS → Settings → Classroom to your server block.

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

Enrolment records hold names, email addresses and, if you ask for them, ID numbers, gender, location, date of birth and phone numbers. They are included in WordPress's personal data export and erase tools. Paid enrolments are kept as financial records with the personal details removed. Hand-ins, grades, proctoring sessions and recordings, certificates, forum posts, chat messages, attendance, checklists and notes are exported and erased with the rest; forum posts are kept with the author and text removed so threads stay readable. Deleting a user removes their hand-in files and recordings. Suggested privacy policy text is added under Settings → Privacy.

= Can proctoring stop learners opening other tabs? =

No browser lets a web page block other tabs or apps. Proctored work runs in full screen and records every time the learner leaves the page, switches tab or window, leaves full screen or stops sharing their screen, with the time it happened. Set a leave limit to hand the work in automatically once it is passed. Teachers see the full timeline, and the recording when screen recording is on.

Full screen is handled with care so learners are not counted for things they did not do:

* Pressing Esc asks before leaving full screen (Chrome and Edge). Choosing to leave is recorded.
* If full screen closes without being asked (the browser, holding Esc, F11), the learner has 15 seconds to return before it counts.
* Choosing files to upload, or downloading a file, opens a browser window that can close full screen or take focus. Nothing is counted while it is open; the learner is asked to return to full screen afterwards, and the timeline shows how long the file chooser was open.
* Following a link off the page, reloading or closing the tab asks first. Leaving ends the session.
* Study tools are hidden while proctored work is in progress.

== Frequently Asked Questions ==

= Can a learner see a paid lesson without paying? =

No. Lessons and quizzes check enrolment and payment on the page, in the REST API and in the quiz submission. Lesson content is not shown in search results, feeds or embeds.

= What happens if a learner closes the checkout? =

Their enrolment waits for payment. They can press "Complete payment" on the course page to try again.

= Can learners see the correct answers? =

Only when "Let learners review their answers" is on and they have no attempts left.

== Screenshots ==

1. A course page with its outline, teacher, forums and resources.
2. A lesson with its heading, reading time and drop capital.
3. Course resources: audio in the player, and documents to preview or download.
4. Settings.

== Changelog ==

= 1.6.3 =
* A lesson shown as cards is read through the content filters as a whole, then cut into cards. Notes and a bibliography made by a citations plugin appear on the card where they are placed (the last card, when they go at the end), and note numbers run on from card to card.
* A section with headings of its own, such as the notes and bibliography, stays whole on one card when a lesson is cut at its main headings.
* A link to a place on another card, such as a note number, a contents entry or a back-to-the-text arrow, opens that card and goes to the place.

= 1.6.2 =
* The plugin is by Euneolink Solutions. Its plugin page and author page are edu.sepodesk.com.

= 1.6.1 =
* Readme: screenshots and their captions for the plugin directory.

= 1.6.0 =
* Course resources: audio plays in a custom player (15-second skips, speed, volume, seek bar and download), one at a time.
* Course resources: Preview opens PDFs, pictures and text files in a window on the page, with Previous and Next, before downloading. Browsers that cannot show PDFs inside a page offer Open and Download instead.
* Lessons: a new heading with the lesson's number in the margin, a large serif title, the teacher and the course on a ruled line, the reading time, and a drop capital on the first paragraph.
* Proof of payment: a file box that shows the chosen file's name, size and picture, and takes a dropped file.
* Admin screens: settings shown as cards, lists in a rounded frame, rounded fields and buttons, pill-shaped tabs and filters, and notices on tinted backgrounds.

= 1.5.0 =
* Proof of payment: learners upload their slip on the course page; staff accept or decline it from the enrolments list, and the learner is emailed.
* Lessons as cards, one part at a time, with Mark as complete on the last part. A "Show as cards" setting on each lesson.
* Course resources: a Resources tab with documents to download and audio and video to play on the page.
* Transcripts: student records, parents and guardians, marks by year, term and subject, a class mark sheet, a grading scale, and printable transcripts for parents and pupils through `[sepo_transcripts]`.
* A "Can keep student records and enter marks" permission on each user's profile.
* Confirmations in the admin open as an in-page dialog, and save buttons show that they are working.

= 1.4.2 =
* Uploads are stored through the WordPress file-system API.
* The enrolment export file is protected from direct access.
* Passes the WordPress Plugin Check: translator comments on every string with placeholders, escaped output, sanitised request data and documented database access to the plugin's own tables.
* Tested up to WordPress 7.1.

= 1.4.1 =
* Proctoring: delete a screen recording on its own — from the session or submission screen, or several at once from LMS → Proctoring — keeping the session details, timeline, submission and grade. The deletion, and who made it, is noted in the timeline; recordings removed after the retention period are noted the same way.

= 1.4.0 =
* Certificate designer: visual drag-and-drop designs with fields, images, lines and boxes, starter templates, a PDF preview, and a design choice per course. Existing certificates use the Classic design, which matches the previous layout.
* Handed-in files open in the page: PDFs in the browser's own PDF viewer, and images, text, audio and video in the browser's viewers, for the learner and on the grading screen. Other formats download as before.
* Notes: block handles line up with the first line of every block, and dragging shows the moved block with a drop line where it will land. The study checklist drags the same way.

= 1.3.1 =
* Proctoring: Esc asks before leaving full screen, a short grace period when full screen closes by itself, the file chooser and downloads are no longer counted as leaving, and leaving the page asks first. Quiz hand-in and error messages use in-page dialogs instead of the browser's, which closed full screen.
* Light and dark follow the active theme, whatever way it switches: the plugin reads the page's real background, so any theme's dark mode (a class or attribute, the device setting, or a toggle) is picked up, and a theme's named colour tokens are used when present. LMS → Settings can also fix the mode to light or dark, and set a separate accent for dark pages (otherwise a lighter version of the accent is used). Dropdown lists, search fields and buttons keep their look under themes that style form controls globally.
* Notices, feedback and quiz answers are marked with tinted backgrounds.
* Stylesheets and scripts are versioned by their modification time, so browsers and page caches pick up every update.
* Text in dark mode uses the theme's bright text colour, and the Grades tab's Open all and Close all are accent buttons.

= 1.3.0 =
* Assignments: hand-in assignments with requirements, file uploads and written answers, and quiz assignments. Due dates, late penalties, grading with feedback, return for rework, and a Submissions screen with a pending count.
* Proctoring: full-screen lockdown, leave detection with an automatic hand-in limit, and optional entire-screen recording with a review screen and timeline.
* Certificates: automatic and manual issue, PDF download and public verification page.
* Forums: discussion, post-to-see-replies and announcement forums per course.
* Chat: class chat per course, with optional direct messages.
* Attendance: sessions, self check-in with codes, register and CSV export.
* Study tools: checklist and block-based notes on lessons and assignments, and a Notes tab under My courses.
* Final grades include graded assignments.

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
