# KIMTECH Online Admission Portal — Full QA Audit Report

**Audit Date**: 6 July 2026
**Auditor**: Principal QA Engineer / Software Architect / Security Engineer  
**Project**: `kimtech.myonlineadmission.com`  
**Stack**: PHP 8.0+ / MySQL 5.7+ / Apache (Laragon local, Hostinger production)

---

## Executive Summary

The KIMTECH Online Admission Portal is a functional PHP/MySQL application serving a real production need — CSSPS student placement verification, online admission fee payment via Paystack, multi-step registration, and PDF document generation. The codebase demonstrates solid foundational patterns: parameterized queries, CSRF tokens, rate limiting on critical paths, session security awareness, and audit logging.

However, the application exhibits several **critical security vulnerabilities**, significant **code quality debt**, **zero test coverage**, and **missing DevOps infrastructure** that must be addressed before production deployment can be considered safe.

| Category | Score | Verdict |
|---|---|---|
| **Overall** | **52/100** | Production Ready with Major Fixes |
| **Security** | **45/100** | Critical issues require immediate remediation |
| **Performance** | **60/100** | Acceptable for current scale, will not scale |
| **Maintainability** | **50/100** | High coupling, no separation of concerns |
| **Architecture** | **55/100** | Monolithic with good patterns but no layering |
| **Test Coverage** | **0/100** | Zero automated tests |
| **Production Readiness** | **40/100** | Missing CI/CD, monitoring, backup strategy |
| **Documentation** | **65/100** | README is good; API docs missing |

---

## Project Overview

### Architecture Summary

- **Pattern**: Monolithic PHP application with inline HTML templates
- **Frontend**: Bootstrap 4, UIKit 3, jQuery, Chart.js, SweetAlert2, Paystack inline.js
- **Backend**: Procedural PHP with PDO for database access
- **Database**: MySQL with InnoDB engine
- **Payments**: Paystack (inline popup + server-to-server webhook)
- **SMS**: Hubtel API via queued cron worker
- **PDF**: HTML-to-PDF via browser print (not a dedicated library)

### Tech Stack

| Component | Technology |
|---|---|
| Language | PHP 8.0+ |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Web Server | Apache with mod_rewrite |
| Payments | Paystack (inline.js + webhook) |
| SMS | Hubtel API |
| PDF Generation | Browser print from styled HTML |
| CSS Frameworks | Bootstrap 4.6, UIKit 3, Custom CSS |
| JS Libraries | jQuery 3.6, Chart.js 4.4, SweetAlert2 |

### Folder Structure

```
/
├── admin/               Admin panel (dashboard, students, settings, SMS, houses, import, users)
│   ├── assets/          Admin CSS/JS/theme
│   └── includes/        Sidebar, topbar partials
├── assets/              Public CSS/JS/img/particles
├── cron/                CLI worker (send_sms.php)
├── includes/            db.php, helpers.php, db_prod.php (production)
├── migrations/          SQL + PHP migration scripts
├── pdf/                 PDF generators (letter, record, prospectus, bond)
├── seeds/               Database seeder
├── uploads/             Passport photos, settings files
├── admissions.php       Login/verification page
├── autenticate.php      Index number authentication handler
├── dashboard.php        Student dashboard after registration
├── index.php            Public landing page
├── logout.php           Session destroy
├── payment.php          Paystack payment page
├── payment_verify.php   Paystack callback handler
├── paystack_webhook.php Server-to-server webhook endpoint
├── register.php         5-step registration form
├── save_nf.php          "Not Found" (unlisted student) login handler
├── search.php           AJAX autocomplete search endpoint
├── setup.php            One-time web installer
└── .htaccess            URL rewriting, security headers, caching
```

---

## Findings by Category

### Functional Issues

| ID | Severity | Title |
|---|---|---|
| F01 | **High** | `payment_reference` collision risk across students |
| F02 | **Medium** | `enrolment_code` stored in plaintext; insufficient verification |
| F03 | **Medium** | Registration step navigation allows skipping required fields |
| F04 | **Low** | `disableViewBack()` breaks browser UX expectations |

#### F01 — Payment Reference Collision Risk
**Description**: Payment references are generated as `CDTI-` + first 12 chars of `md5(student_id . '_' . date('Ymd'))`. Within the same day, two different students with similar IDs could theoretically produce the same truncated hash.  
**Evidence**: `payment.php:40`  
**Recommendation**: Use `bin2hex(random_bytes(12))` or a UUID-based reference.  
**Effort**: 30 minutes

#### F02 — Weak Enrolment Code Verification
**Description**: The enrolment code is compared directly as plaintext (`save_nf.php:37`). The code is also visible in the `students` table in plaintext. If an attacker gains DB read access, they can authenticate as any unlisted student.  
**Recommendation**: Hash enrolment codes with bcrypt on import; verify with `password_verify()`.  
**Effort**: 2 hours

#### F03 — Skip Validation on Multi-Step Form
**Description**: Client-side validation in `register.php` uses JavaScript that can be bypassed via browser DevTools or direct POST requests. While server-side validation exists for most fields, some are only validated client-side (e.g., step navigation guards).  
**Recommendation**: Ensure every field validated client-side has equivalent server-side validation.  
**Effort**: 4 hours

---

### Security Issues

| ID | Severity | Title | OWASP |
|---|---|---|---|
| S01 | **Critical** | Paystack secret key stored in plaintext in DB | A02:2021 |
| S02 | **Critical** | Hubtel SMS API credentials stored in plaintext in DB | A02:2021 |
| S03 | **Critical** | CSRF token is static per session (never rotated) | A01:2021 |
| S04 | **High** | No brute-force protection on admin login | A07:2021 |
| S05 | **High** | Session timeout not enforced on admin login page | A07:2021 |
| S06 | **High** | Search endpoint leaks student PII before authentication | A01:2021 |
| S07 | **High** | File upload MIME check is bypassable | A03:2021 |
| S08 | **Medium** | No Content-Security-Policy on admin pages | A05:2021 |
| S09 | **Medium** | `setup.php` overwrites `db.php` — destructive action | A08:2021 |
| S10 | **Medium** | Session cookie not marked `Secure` in production check | A07:2021 |
| S11 | **Medium** | No rate limiting on AJAX search endpoint | A01:2021 |
| S12 | **Low** | XSS in error messages via `$errors[]` array (though sanitized) | A03:2021 |

#### S01 — Paystack Secret Key in Plaintext (CRITICAL)
**Description**: The Paystack secret key (`sk_live_...`) is stored in the `system_settings` table as plaintext. Any SQL injection vulnerability, DB backup leak, or admin account compromise exposes the payment secret key. An attacker with this key can decrypt transactions, refund payments, or pivot to the Paystack dashboard.  
**Evidence**: `admin/settings.php:69` and `system_settings` table schema. The value is echoed back into the HTML input at line 367.  
**Remediation**: Encrypt the secret key at rest using `openssl_encrypt()` with an application-level key (from environment variable, not the DB). Display a masked value (e.g., `sk_live_****xxxx`) in the settings form.  
**Effort**: 4 hours

#### S02 — Hubtel Credentials in Plaintext (CRITICAL)
**Description**: Same as S01 for Hubtel SMS credentials (`hubtel_client_id`, `hubtel_client_secret`). These allow sending SMS from the school's sender ID, enabling phishing attacks.  
**Evidence**: `admin/settings.php:70-71`  
**Remediation**: Same as S01 — encrypt all secrets at rest.  
**Effort**: 1 hour (same fix as S01)

#### S03 — Static CSRF Token (CRITICAL)
**Description**: The CSRF token is generated once per session and never rotated. This means if an attacker obtains the token (via XSS or other means), it can be reused for all future requests. The token is also not regenerated after use.  
**Evidence**: `includes/helpers.php:94-102` — `generateCsrfToken()` only sets token if empty; `verifyCsrfToken()` does not regenerate after verification.  
**Remediation**: Regenerate the CSRF token after each successful verification. Also implement per-form tokens by hashing the token with a form identifier.  
**Effort**: 2 hours

#### S04 — No Brute-Force Protection on Admin Login
**Description**: The admin login endpoint (`admin/login.inc.php`) has no rate limiting. An attacker can brute-force credentials without restriction.  
**Evidence**: `admin/login.inc.php` — no IP-based or account-based rate limiting. Compare with `autenticate.php:20-25` which implements rate limiting for student login.  
**Remediation**: Add rate limiting (e.g., 5 attempts per IP per 15 minutes, lock account after 10 failed attempts for 30 minutes).  
**Effort**: 2 hours

#### S05 — Admin Session Timeout Not Enforced on Login Page
**Description**: If an admin leaves their session idle for over an hour, `requireAdminAuth()` destroys the session. However, the login page (`admin/index.php`) does not check for existing sessions, and expired session redirects show a `?timeout=1` parameter that could be spoofed.  
**Evidence**: `includes/helpers.php:43-50`, `admin/index.php:7`  
**Remediation**: Show an explicit "Session Expired" message; implement progressive timeout warnings (5 minutes before expiry).  
**Effort**: 1 hour

#### S06 — Search Endpoint Leaks Student PII
**Description**: The `search.php` AJAX endpoint returns full student data (gender, program, residency, aggregate) for any unauthenticated user who types at least 2 characters. This enables enumeration of student records.  
**Evidence**: `search.php:18-38` — no authentication required; returns masked index but full name, gender, program, residency.  
**Remediation**: Require at least CSRF protection or rate limiting. Mask the full name (show only first name + last initial).  
**Effort**: 2 hours

#### S07 — File Upload MIME Type Bypass
**Description**: The registration form validates uploaded photo MIME types using `finfo_file()` which checks the file content magic bytes. While better than checking extension alone, it is still bypassable (e.g., a PHP webshell disguised as a JPEG with correct magic bytes).  
**Evidence**: `register.php:108-117`  
**Remediation**: Store uploaded files outside the web root or serve them via a PHP proxy script that sets proper Content-Type headers. Re-process/re-compress images server-side using GD or Imagick instead of trusting client upload.  
**Effort**: 4 hours

---

### Code Quality Issues

| ID | Severity | Title |
|---|---|---|
| CQ01 | **Medium** | Duplicate COUNT query in `admin/students.php` |
| CQ02 | **Medium** | Business logic mixed with presentation in all PHP files |
| CQ03 | **Medium** | Hardcoded strings and magic numbers scattered |
| CQ04 | **Low** | Dead/unreachable code in `admin/students.php:37` |
| CQ05 | **Low** | Typo in `admin/dashboard.php:172` — duplicated header text |
| CQ06 | **Low** | Missing type declarations on function parameters |
| CQ07 | **Low** | Long files: `register.php` (695 lines), `admin/settings.php` (459 lines) |

#### CQ01 — Duplicate COUNT Query
**Description**: Line 37 executes `$totalStmt` twice — once as a condition check in a ternary, then again for actual assignment. The first call also returns a boolean, not the count.  
**Evidence**: `admin/students.php:37-40`  
```php
$totalRows  = (int)$pdo->prepare("SELECT COUNT(*) FROM students $whereSql")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM students $whereSql")->execute($params) : 0;
$totalStmt  = $pdo->prepare("SELECT COUNT(*) FROM students $whereSql");
$totalStmt->execute($params);
$totalRows  = (int)$totalStmt->fetchColumn();
```
The first line is dead code — line 40 overwrites `$totalRows`.  
**Remediation**: Remove line 37.

#### CQ02 — No Separation of Concerns
**Description**: Every PHP file combines database queries, business logic, HTML rendering, and CSS styling. This is the single worst maintainability issue. A change to any business rule requires modifying files that also contain presentation markup.  
**Evidence**: Every `.php` file in the project roots.  
**Remediation**: Adopt a simple MVC or service-repository pattern. Extract database operations into dedicated repository files. At minimum, separate PHP logic from HTML output.  
**Effort**: 40+ hours (phased)

---

### Performance Issues

| ID | Severity | Title |
|---|---|---|
| P01 | **Medium** | No caching for `getSettings()` beyond single request |
| P02 | **Medium** | Audit logs table has no retention policy — unbounded growth |
| P03 | **Low** | Multiple CSS/JS frameworks cause render blocking |
| P04 | **Low** | No database query plan optimization for dashboard aggregates |
| P05 | **Low** | `SELECT *` in multiple queries fetching unnecessary columns |

#### P01 — Settings Not Cached Across Requests
**Description**: `getSettings()` uses a static cache within a single request, but queries the entire `system_settings` table on every page load. For high-traffic admission periods, this adds unnecessary DB load.  
**Evidence**: `includes/helpers.php:106-115`  
**Remediation**: Cache settings in Redis, Memcached, or at minimum a local JSON file that is invalidated on settings update.  
**Effort**: 3 hours

#### P02 — Unbounded Audit Log Growth
**Description**: The `audit_logs` table has no archival or purging mechanism. Every login attempt, payment, and action is logged indefinitely. With thousands of applicants, this table can grow to millions of rows per admission cycle, slowing queries.  
**Evidence**: `migrations/001_create_tables.sql:109-119` — no retention policy.  
**Remediation**: Implement a cron job to archive logs older than 12 months, or partition the table by month.  
**Effort**: 4 hours

---

### Database Issues

| ID | Severity | Title |
|---|---|---|
| D01 | **Medium** | Missing index on `students.payment_reference` |
| D02 | **Medium** | Missing index on `parent_guardian_info.student_id` |
| D03 | **Medium** | Missing composite indexes for common query patterns |
| D04 | **Low** | No transactions on critical write operations |
| D05 | **Low** | `enrolment_code` length mismatch (VARCHAR(20) in SQL, VARCHAR(10) validated) |

#### D01 — Missing Index on payment_reference
**Description**: The webhook (`paystack_webhook.php`) queries `students` by `payment_reference`. Without an index, this is a full table scan. Under load, this will cause timeouts.  
**Evidence**: `paystack_webhook.php:65` — `SELECT ... WHERE payment_reference = ?`  
**Remediation**: `CREATE INDEX idx_payment_ref ON students(payment_reference);`  
**Effort**: 15 minutes

#### D02 — Missing Index on parent_guardian_info.student_id
**Description**: Although a foreign key exists on `student_id`, MySQL does not automatically index foreign keys. The `DELETE` and `SELECT` on this table by `student_id` will scan.  
**Evidence**: `migrations/001_create_tables.sql:50,65` — FK exists but no explicit index.  
**Remediation**: `CREATE INDEX idx_pgi_student_id ON parent_guardian_info(student_id);`  
**Effort**: 15 minutes

---

### API Issues

| ID | Severity | Title |
|---|---|---|
| A01 | **Medium** | No standardized JSON error format |
| A02 | **Low** | No API versioning |
| A03 | **Low** | Paystack webhook returns 200 for student-not-found (should be 422) |

---

### Frontend & Accessibility Issues

| ID | Severity | Title | WCAG |
|---|---|---|---|
| FE01 | **High** | No skip-navigation link on any page | 2.4.1 |
| FE02 | **High** | Modal focus management incomplete | 2.4.3 |
| FE03 | **Medium** | Color contrast insufficient in many areas | 1.4.3 |
| FE04 | **Medium** | Form fields missing explicit label associations in some places | 1.3.1 |
| FE05 | **Medium** | No `prefers-reduced-motion` respect | 1.4.4 |
| FE06 | **Low** | Missing `lang` attribute on some admin pages | 3.1.1 |
| FE07 | **Low** | Keyboard-navigation traps in multi-step form | 2.1.2 |

#### FE02 — Modal Focus Management
**Description**: The "Must Read" modal on `index.php` uses Bootstrap's modal with `data-keyboard="false"` and `data-backdrop="static"`. However, focus is not trapped within the modal, and when the modal is dismissed, focus is not returned to the trigger element.  
**Evidence**: `index.php:241`  
**Remediation**: Use Bootstrap's focus management or implement custom focus trapping.  
**Effort**: 2 hours

---

### Testing Issues

| ID | Severity | Title |
|---|---|---|
| T01 | **Critical** | Zero automated tests exist in the entire codebase |
| T02 | **High** | No regression test suite for payment flows |
| T03 | **High** | PDF generation not tested (browser-print dependent) |
| T04 | **Medium** | No integration test for webhook signature verification |
| T05 | **Medium** | No unit tests for validation logic |

#### T01 — Zero Automated Tests (CRITICAL)
**Description**: There are no unit tests, integration tests, or end-to-end tests. Every release requires full manual regression testing. This is especially dangerous for the payment flow where a bug means lost revenue or double-charging students.  
**Remediation**: Adopt PHPUnit for unit tests (validation utilities, helper functions) and a lightweight HTTP testing library for integration tests. Start with payment verification tests as highest priority.  
**Effort**: 40+ hours initial setup + ongoing

---

### DevOps Issues

| ID | Severity | Title |
|---|---|---|
| DO01 | **High** | No CI/CD pipeline |
| DO02 | **High** | No automated backup strategy documented |
| DO03 | **Medium** | `db_prod.php` template missing from repository |
| DO04 | **Medium** | No `composer.json` for dependency management |
| DO05 | **Low** | No deployment script |
| DO06 | **Low** | No monitoring or alerting |

---

## Quick Wins (< 1 day)

| ID | Effort | Impact |
|---|---|---|
| D01 (Index payment_reference) | 15 min | Prevents webhook timeout at scale |
| D02 (Index pgi.student_id) | 15 min | Speeds up parent info queries |
| CQ01 (Remove dead code) | 5 min | Removes confusion |
| S04 (Rate limit admin login) | 2 hr | Prevents brute force |
| S10 (Fix Secure cookie flag) | 30 min | Proper session security |
| P02 (Audit log retention) | 1 hr | Prevents unbounded table growth |

---

## High-Impact Improvements

| ID | Effort | Impact |
|---|---|---|
| S01+S02 (Encrypt secrets) | 4 hr | **Critical** — prevents payment credential leak |
| S03 (Rotate CSRF tokens) | 2 hr | **Critical** — prevents CSRF replay |
| S06 (Secure search endpoint) | 2 hr | **High** — prevents data scraping |
| T01 (Start test suite) | 40 hr | **Foundation** — enables safe refactoring |
| P01 (Cache settings) | 3 hr | **Medium** — reduces DB load |
| CQ02 (Separation of concerns) | 40+ hr | **Strategic** — long-term maintainability |

---

## Technical Debt Summary

| Category | Debt | Effort to Clear |
|---|---|---|
| Security (critical) | Plaintext secrets, static CSRF, no rate limiting | 12 hours |
| Code Quality | No layering, mixed concerns, no types | 80+ hours |
| Testing | Complete absence of tests | 60+ hours |
| Database | Missing indexes, no partitioning | 8 hours |
| DevOps | No CI/CD, no backup strategy | 40 hours |
| Accessibility | WCAG failures on critical paths | 20 hours |

---

## Refactoring Roadmap

### Phase 1 — Critical (Week 1)
- [ ] Encrypt all API secrets at rest (S01, S02)
- [ ] Implement CSRF token rotation (S03)
- [ ] Add brute-force protection to admin login (S04)
- [ ] Add missing database indexes (D01, D02)
- [ ] Delete `setup.php` from production
- [ ] Remove dead code in `admin/students.php`

### Phase 2 — High (Week 2)
- [ ] Secure search endpoint with auth/rate limiting (S06)
- [ ] Implement file upload validation using image reprocessing (S07)
- [ ] Add settings caching (P01)
- [ ] Implement audit log retention policy (P02)
- [ ] Add accessibility skip-navigation (FE01)
- [ ] Fix modal focus management (FE02)

### Phase 3 — Medium (Weeks 3-4)
- [ ] Begin test suite implementation (T01)
- [ ] Separate business logic from presentation (CQ02 — start with payment flow)
- [ ] Implement proper enrollment code hashing (F02)
- [ ] Fix payment reference generation (F01)
- [ ] Add comprehensive security headers to admin pages (S08)
- [ ] Add CI/CD pipeline (DO01)
- [ ] Implement backup automation (DO02)

### Phase 4 — Low (Weeks 5-6)
- [ ] Extract CSS from inline `<style>` blocks
- [ ] Standardize API error responses (A01)
- [ ] Address remaining WCAG issues (FE03-FE07)
- [ ] Add `composer.json` for dependency management (DO04)
- [ ] Create deployment documentation

---

## Production Readiness Checklist

| Requirement | Status | Notes |
|---|---|---|
| All secrets encrypted at rest | ❌ FAIL | Paystack & Hubtel keys in plaintext |
| CSRF protection on all state-changing requests | ⚠️ PARTIAL | Tokens exist but don't rotate |
| Rate limiting on authentication | ⚠️ PARTIAL | Student login limited; admin login not |
| HTTPS enforced | ✅ PASS | HSTS configured in `.htaccess` |
| SQL injection prevention | ✅ PASS | All queries use parameterized PDO |
| XSS prevention | ✅ PASS | `htmlspecialchars()` used consistently |
| File upload restricted to safe types | ⚠️ PARTIAL | MIME check present but bypassable |
| Session security configured | ⚠️ PARTIAL | HttpOnly, SameSite set; Secure has logic issue |
| Error handling without stack leaks | ✅ PASS | Generic errors shown to users |
| Automated tests | ❌ FAIL | Zero tests |
| Database indexes on foreign keys | ❌ FAIL | Missing on parent_guardian_info.student_id |
| Backup strategy defined | ❌ FAIL | Not mentioned |
| CI/CD pipeline configured | ❌ FAIL | None |
| Monitoring and alerting | ❌ FAIL | None |
| `setup.php` removed | ❌ UNKNOWN | Must verify on production |
| Security headers (CSP, HSTS) | ⚠️ PARTIAL | CSP set on main site; admin pages lack it |
| Accessible keyboard navigation | ❌ FAIL | Multiple WCAG violations |
| `uploads/` directory hardened | ✅ PASS | `.htaccess` blocks PHP execution |
| README accurate and complete | ✅ PASS | Good documentation |

---

## Final Verdict

### Production Ready with Major Fixes

**Verdict Summary**: The application is **not safe for production deployment in its current state** due to three critical security vulnerabilities:

1. **Paystack and Hubtel credentials stored in plaintext** (S01, S02) — a single SQL injection or compromised admin account leaks payment processing credentials, enabling financial fraud.

2. **Static CSRF tokens** (S03) — allow request forgery across the entire application.

3. **No brute-force protection on admin login** (S04) — the admin panel can be credential-stuffed with no rate limiting.

However, the foundation is solid: parameterized queries are used universally, XSS is well-mitigated, session management has the right patterns (though imperfect implementation), and the business logic is correct for the core admission workflow.

**Go-to-production blocker**: S01, S02, S03 must be fixed first.  
**Recommended timeline**: 1 week of focused security remediation, then a re-audit before going live.

---

*Report generated by Principal QA Engineering Audit — 6 July 2026*
