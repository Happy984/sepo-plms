# Sepo LMS

A learning platform for WordPress. It covers courses, lessons, quizzes, assignments, certificates, schools and teachers, with enrolment and payments.
Learners can pay by bank transfer, cash, payment link, Stripe, PayPal, pawaPay mobile money or WooCommerce. The plugin works with block themes and classic themes.

<p align="center">
  <a href="https://edu.sepodesk.com/sponsor/projects-maintenance/">
    <img src="https://img.shields.io/badge/%E2%9D%A4%20SUPPORT%20OUR%20OPEN--SOURCE%20PROJECTS-0b6b45?style=for-the-badge&logo=githubsponsors&logoColor=white" alt="Support Our Open-Source Projects" />
  </a>
</p>

<p align="center">
  Your support helps us maintain, improve and build open-source software for everyone.
  <br />
  <strong>Every contribution makes a difference. Thank you for supporting our work!</strong>
</p>

![Version](https://img.shields.io/badge/version-1.4.1-0b3d33)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)


## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Payment methods](#payment-methods)
- [Shortcodes](#shortcodes)
- [Addresses](#addresses)
- [Proctoring](#proctoring)
- [Privacy and outside services](#privacy-and-outside-services)
- [Uninstalling](#uninstalling)
- [FAQ](#faq)
- [For developers](#for-developers)
- [Changelog](#changelog)
- [License](#license)

## Features

### Courses and content

- **Courses** have a price, discount, tax, level, dates, objectives, teacher, school, related courses and categories.
- **Lessons and quizzes** belong to a course.
  - Drag them into order in the course's **Outline** box.
  - Lock items until a date, or make the course sequential so each item needs the one before it.
- **Schools and teachers** have their own pages listing their courses.

### Quizzes

- A visual quiz builder: pick a question type, type the question, tick the right answers and drag to reorder.
- Nine question types: multiple choice, checkboxes, short text, paragraph, number, date, email, phone and web address.
- Settings for:
  - points per question and a pass mark;
  - a time limit, attempts and retakes;
  - answer review;
  - a weight in the final grade.

### Enrolment and payments

- Enrolment uses a form that asks for a name and email. It can also ask for a national ID, gender, location and date of birth.
- Free courses open straight away. Paid courses open once payment is confirmed.
- Prices are always worked out on the server from the course's own settings: the discount comes off the price, then tax is added to the discounted price.
- Receipts are sent by email and are also available as a printable page.

### Progress and grades

- Progress tracking and course reviews.
- A **My courses** page with progress, final grades and receipts. Grades are weighted when every quiz has a weight.
- **Admin screens:**
  - an enrolments list with filters, bulk actions and CSV export;
  - quiz results with statistics and each learner's answers.

### Assignments

There are two kinds of assignment:

- **Hand-in** assignments:
  - list requirements;
  - accept files (types, count and size are set per assignment) and/or a written answer with a minimum word count;
  - can ask learners to tick each requirement before handing in.
- **Quiz** assignments use one of your quizzes.

Both kinds have:

- a due date;
- late rules: accept with a penalty per day, or close at the due time;
- points, a pass mark and a weight in the final grade.

Teachers grade and give feedback, or return the work for rework. Learners are emailed either way.

### Certificates

- Certificates are issued automatically when a learner completes a course and passes its grade requirement.
- Each one is a PDF with a verification code. Anyone can check a code on the public verification page.
- Certificates can be withdrawn, restored or issued by hand.
- **Certificate designer** (**LMS → Certificate designs**):
  - Start from Classic, Modern, Minimal or a blank page.
  - Drag on text, fields (`{name}`, `{course}`, `{date}`, `{grade}`, `{code}`, `{verify_url}` and more), your logo, images, lines and boxes.
  - Set fonts, colours, alignment, and the page size, orientation and background.
  - Use snapping guides, undo and redo, and a PDF preview.
  - Each course chooses its design.

### Classroom

- **Forums** for each course, in three types:
  - **Discussion:** open to all.
  - **Post to see replies:** learners see others' replies only after replying themselves.
  - **Announcements:** only staff post, and learners are emailed.
- **Chat:** a class room for each course, with optional direct messages.
- **Attendance:**
  - sessions with optional self check-in by code;
  - a late threshold;
  - a register to mark present, late, absent or excused;
  - attendance rates and CSV export.
- **Study tools:** a checklist and block-based notes in a drawer on every lesson and assignment, and a **Notes** tab under My courses.

Every classroom feature can be switched off in **LMS → Settings → Classroom**.

### Look and feel

- Light and dark mode follow the active theme, however it switches.
- **LMS → Settings** can also fix the mode to light or dark, and set a separate accent colour for dark pages.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |
| WooCommerce | Optional, for the WooCommerce checkout method |

## Installation

### Upload the zip (recommended)

1. Download `sepo-lms.zip`.
2. In your WordPress dashboard, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Click **Activate**. A **My courses** page is created.

### Upload the folder

1. Unzip the download.
2. Copy the `sepo-lms` folder into `wp-content/plugins/` on your server.
3. Go to **Plugins** and activate **Sepo LMS**.

> [!NOTE]
> GitHub's **Code → Download ZIP** names the folder after the branch, for example `sepo-lms-main`. Rename it to `sepo-lms` before uploading.

## Getting started

1. Go to **LMS → Settings**. Set your currency and add payment methods.
2. Optionally, add schools and teachers.
3. Add courses, then their lessons, quizzes and assignments.
4. Arrange each course in its **Outline** box.

## Payment methods

All payment methods are added in **LMS → Settings**, where you paste each service's keys.

### Manual and payment links

Bank transfer, cash and payment links are confirmed by an admin from the enrolments list.

### Stripe

1. Paste your secret key.
2. For the most reliable confirmation, add a webhook endpoint in Stripe for `/wp-json/sepo-lms/v1/webhook/stripe`.
3. Give the webhook these events:
   - `checkout.session.completed`
   - `checkout.session.async_payment_succeeded`
4. Paste the webhook's signing secret.

### PayPal

1. Create a REST app in the PayPal developer dashboard.
2. Paste its client ID and secret.

Courses priced in a currency PayPal does not take will not offer PayPal.

### pawaPay

The plugin uses pawaPay's Merchant API v2.

1. Paste your API token.
2. In the pawaPay dashboard, set both the deposit and the checkout callback URLs to `/wp-json/sepo-lms/v1/webhook/pawapay`.
3. Choose how the payment method sends the learner to pay:
   - Leave the network empty to send learners to pawaPay's payment page. They pick their network there.
   - Enter a network code, such as `MTN_MOMO_ZMB`, to send the prompt straight to their phone.

How pawaPay payments are handled:

- A payment is accepted only for the full amount in the right currency. Anything else is held with a **needs review** status.
- If pawaPay does not answer when a payment starts, the enrolment is checked again every hour.
- When a learner tries again, their previous pawaPay page is closed first, so it cannot be paid twice.

### WooCommerce

1. Activate WooCommerce.
2. Add a payment method of type **WooCommerce checkout**.

Learners can then pay with any gateway enabled in WooCommerce. How orders work:

- Each enrolment creates an order for the discounted course price, plus a tax line.
- The course opens when the order is **Processing** or **Completed**.
- The course closes again if the order is refunded or cancelled.
- Gateways that leave the order **On hold**, such as bank transfer or cheque, open the course when you move the order on.
- WooCommerce's order tables (HPOS) are supported.

> [!NOTE]
> Stripe, PayPal and pawaPay confirm themselves. The plugin always asks the payment service whether a payment went through: when the learner returns, when a webhook arrives, and in an hourly check.

## Shortcodes

| Shortcode | What it shows |
|---|---|
| `[sepo_lms_courses]` | The course index. Options: `category`, `school`, `teacher`, `level`, `ids`, `limit`, `per_page`, `title`. |
| `[sepo_lms_schools]` | The school index. |
| `[sepo_lms_teachers]` | The teacher index. Option: `school`. |
| `[sepo_lms_my_courses]` | The signed-in learner's courses, grades, certificates, notes and receipts, in tabs. Link to a tab with `#quizzes`, `#certificates` or `#notes`. |

## Addresses

| Content | Address |
|---|---|
| Courses | `/courses/` |
| Lessons | `/lessons/` |
| Quizzes | `/quizzes/` |
| Assignments | `/assignments/` |
| Schools | `/schools/` |
| Teachers | `/teachers/` |

Each address can be changed in **LMS → Settings**.

## Proctoring

Proctoring is available for assignments and quizzes:

- Proctored work runs in full screen.
- Every time the learner leaves the page, switches tab or window, or leaves full screen is logged with the time.
- An optional leave limit hands the work in automatically once it is passed.
- Optional recording of the learner's entire screen, with their consent.
  - Recordings stream to the server while the learner works.
  - They are kept for a retention period you choose.
  - They can be deleted on their own, keeping the session's timeline and grade.

Learners are not counted for things they did not do:

- Pressing Esc asks before leaving full screen (Chrome and Edge).
- If full screen closes by itself, the learner has 15 seconds to return before it counts.
- File choosers and downloads are not counted as leaving.
- Following a link off the page, reloading or closing the tab asks first.

## Privacy and outside services

### What is stored

- Enrolment records hold names and email addresses. If you ask for them, they also hold ID numbers, gender, location, date of birth and phone numbers.
- All of this is included in WordPress's personal data export and erase tools.
- Paid enrolments are kept as financial records with the personal details removed.
- Forum posts are kept with the author and text removed, so threads stay readable.

### Private files

- Hand-in files and screen recordings are stored in `wp-content/uploads/sepo-lms-private/`.
- They are served only to the learner who owns them and to course staff.
- On nginx, ask your host to add the rule shown in **LMS → Settings → Classroom**.

### Outside services

The plugin contacts a payment service only when you have set it up and a learner chooses it.

| Service | What is sent | Terms and privacy |
|---|---|---|
| Stripe (`api.stripe.com`) | Amount, currency, course title, a reference and the learner's email | [Terms](https://stripe.com/legal) · [Privacy](https://stripe.com/privacy) |
| PayPal (`api-m.paypal.com`) | Amount, currency, course title and a reference | [Terms](https://www.paypal.com/legalhub/home) · [Privacy](https://www.paypal.com/myaccount/privacy/privacyhub) |
| pawaPay (`api.pawapay.io`) | Amount, currency, a reference and the learner's phone number | [Terms](https://www.pawapay.io/terms-of-use) · [Privacy](https://www.pawapay.io/privacy-policy) |

## Uninstalling

**Deactivating** the plugin (**Plugins → Deactivate**) stops it and keeps everything.

**Deleting** the plugin (**Plugins → Deactivate**, then **Delete**) removes the plugin files and the scheduled checks.

- **Courses, lessons, quizzes, assignments, schools and teachers are never deleted.** They are WordPress posts and stay on your site.
- **By default all learner data is kept as well.**
- **To remove learner data**, first tick **Delete data on uninstall** under **LMS → Settings** and save, then delete the plugin. This removes:
  - enrolments, progress, quiz results and reviews;
  - submissions and their files, and screen recordings;
  - certificates and certificate designs;
  - forum posts, chat, attendance, checklists and notes;
  - the settings.

## FAQ

**Can a learner see a paid lesson without paying?**
No. Lessons and quizzes check enrolment and payment on the page, in the REST API and in the quiz submission. Lesson content is not shown in search results, feeds or embeds.

**What happens if a learner closes the checkout?**
Their enrolment waits for payment. They can press **Complete payment** on the course page to try again.

**Can learners see the correct answers?**
Only when **Let learners review their answers** is on and they have no attempts left.

**Can proctoring stop learners opening other tabs?**
No browser lets a web page block other tabs or apps. Proctoring records every time the learner leaves, and can hand the work in once a leave limit is passed.

## For developers

### Theme templates

Copy any file from the plugin's `templates/` folder into `your-theme/sepo-lms/` to change it. Keep the `parts/` folder structure.

### Actions

| Action | Arguments |
|---|---|
| `sepo_lms_enrolled` | `( array $enrollment )` |
| `sepo_lms_enrollment_paid` | `( array $enrollment )`, once per enrolment |
| `sepo_lms_enrollment_removed` | `( array $enrollment )` |
| `sepo_lms_quiz_submitted` | `( array $result, array $settings )` |
| `sepo_lms_assignment_submitted` | `( array $submission )` |
| `sepo_lms_assignment_graded` | `( array $submission, bool $returned )` |
| `sepo_lms_certificate_issued` | `( array $certificate )` |
| `sepo_lms_progress_changed` | `( int $courseId, int $userId )` |

## Changelog

### 1.4.1
- Proctoring: delete a screen recording on its own and keep the session details, timeline, submission and grade.
  - Delete from the session or submission screen, or several at once from **LMS → Proctoring**.
  - The deletion, and who made it, is noted in the timeline.

### 1.4.0
- Certificate designer, with:
  - drag-and-drop designs with fields, images, lines and boxes;
  - starter templates and a PDF preview;
  - a design choice per course.
- Handed-in files open in the page, for both the learner and the grader.
- Notes: aligned block handles, and dragging shows a drop line.

### 1.3.1
- Proctoring:
  - Esc asks before leaving full screen;
  - a grace period when full screen closes by itself;
  - file choosers and downloads are not counted as leaving;
  - in-page dialogs.
- Light and dark mode follow the active theme, however it switches, and use the theme's colour tokens when present. Settings can fix the mode and set a dark-mode accent.
- Notices and quiz answers use tinted backgrounds.
- Stylesheets and scripts are versioned by modification time.

### 1.3.0
- Assignments, proctoring, certificates, forums, chat, attendance and study tools.
- Final grades include graded assignments.

### 1.2.3
- My courses → Quiz results is grouped by course, with search, filters and pages.

### 1.2.2
- My courses has Courses and Quiz results tabs, each with search, filters and pages.

### 1.2.1
- A blank answer row no longer moves the "correct" tick onto the wrong answer.
- Typed answers are matched sensibly: case, spacing, number formats, dates, web addresses and phone numbers.
- Characters such as `<`, `>`, `&` and `%` are kept as typed.
- Receipts and payment emails wrap properly on phones.

### 1.2.0
- pawaPay uses the Merchant API v2, with hosted checkouts and direct deposits.
- Timed-out payment starts are kept and checked.
- Retrying a payment closes the previous checkout first.
- Underpayments are held for review.

### 1.1.0
- WooCommerce checkout as a payment method.

### 1.0.0
- First release.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
