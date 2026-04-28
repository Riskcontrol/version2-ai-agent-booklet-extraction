# Credit-Based Upload System Plan (Credit Extraction)

## 1. Goal
Build a credit system where:
- 1 uploaded page consumes 1 credit.
- Admin can add/deduct credits and set a maximum credit cap per user.
- User can submit top-up invoices.
- Admin can approve/reject invoices.
- Full audit trail exists for user and admin actions.
- Audit shows API tier used (`free` or `paid`) and cost impact.
- Paid tier pricing: 1 page = 1 credit = 0.06 USD.
- Track pages that successfully returned results.
- Send invoice notifications to `peldargconsulting@gmail.com`.
- Display payment instructions on invoice: `Peldarg Consulting Limited`, `Moniepoint Bank`, `8107837073`.

---

## 2. Current Codebase Findings (Important)
Based on current app behavior:
- Auth is session-based (`Session::put('user_id')`) and not yet role-based.
- Upload endpoint: `POST /api/upload` in `DocumentController@upload`.
- Upload dispatches a GitHub workflow (`repository_dispatch`) asynchronously.
- Result callback enters through `GithubController@callback` and `uploadResults`.
- Documents table has no `user_id`, no page or credit columns.
- Users table has no admin role flag and no credit fields.

Implication:
- We need schema and flow changes in both Laravel app and GitHub workflow payload handling.

---

## 3. Recommended Design (Better Idea Included)
Use a **credit ledger + reservation/finalization** model instead of direct subtraction only.

Why:
- Upload is async.
- Real processed pages can differ from requested pages.
- Workflow may fail, partially succeed, or retry.

### 3.1 Credit Lifecycle
1. User uploads PDF.
2. System estimates target pages (from selected range or total pages).
3. System creates a `reserved` ledger entry (holds credits).
4. Workflow runs and reports success metrics (pages processed, pages with rows, api tier).
5. System finalizes:
   - Convert reserved credits to `consumed` for successful processed pages.
   - Refund unconsumed reserved credits.
6. All actions are auditable and immutable.

This gives accurate charging and clean reconciliation.

---

## 4. Data Model Changes

## 4.1 `users` table
Add:
- `is_admin` boolean default false
- `credit_balance` decimal(12,2) default 0
- `credit_cap` decimal(12,2) default 0
- `status` enum(`active`,`suspended`) default `active`

Note:
- `credit_balance` can be derived from ledger, but keeping it denormalized improves performance.
- Use DB transactions and row locking when mutating balance.

## 4.2 `documents` table
Add:
- `user_id` foreign key nullable false (for historical backfill, start nullable then enforce)
- `api_tier` enum(`free`,`paid`) nullable
- `credit_rate_usd` decimal(10,4) nullable (0.0600 for paid)
- `pages_requested` integer nullable
- `pages_reserved` integer nullable
- `pages_processed` integer nullable
- `pages_with_results` integer nullable
- `credits_reserved` decimal(12,2) nullable
- `credits_consumed` decimal(12,2) nullable
- `credits_refunded` decimal(12,2) nullable
- `credit_status` enum(`none`,`reserved`,`finalized`,`refunded`,`failed`) default `none`
- `failed_reason` text nullable

## 4.3 New table: `credit_ledger`
Columns:
- `id`
- `user_id` FK
- `document_id` FK nullable
- `invoice_id` FK nullable
- `action_type` enum:
  - `admin_add`
  - `admin_deduct`
  - `reserve`
  - `consume`
  - `refund`
  - `invoice_approved`
  - `invoice_rejected`
  - `cap_update`
- `credits` decimal(12,2) (positive or negative)
- `balance_before` decimal(12,2)
- `balance_after` decimal(12,2)
- `api_tier` enum(`free`,`paid`) nullable
- `unit_price_usd` decimal(10,4) nullable
- `amount_usd` decimal(12,4) nullable
- `meta` json nullable (store context: page range, reason, admin notes)
- `created_by_user_id` FK nullable (admin/user actor)
- timestamps

Rules:
- Ledger rows are append-only (never update/delete).

## 4.4 New table: `credit_audit_logs`
Track explicit audit events for UI timeline.
- `id`
- `actor_user_id` FK nullable
- `target_user_id` FK nullable
- `entity_type` enum(`user`,`document`,`invoice`,`credit`)
- `entity_id` bigint nullable
- `event_key` string (example: `credit.reserve.created`)
- `old_values` json nullable
- `new_values` json nullable
- `ip_address` string nullable
- `user_agent` text nullable
- `request_id` string nullable
- timestamps

## 4.5 New table: `credit_invoices`
- `id`
- `user_id` FK
- `invoice_number` unique string
- `requested_credits` decimal(12,2)
- `unit_price_usd` decimal(10,4) default 0.0600
- `requested_amount_usd` decimal(12,2)
- `status` enum(`pending`,`approved`,`rejected`,`cancelled`) default `pending`
- `payment_reference` string nullable
- `proof_path` string nullable (uploaded receipt image/PDF)
- `admin_note` text nullable
- `reviewed_by_user_id` FK nullable
- `reviewed_at` timestamp nullable
- timestamps

## 4.6 Optional table: `pricing_rules`
For flexibility:
- `api_tier` (`free`,`paid`)
- `credit_per_page` decimal (default 1.00)
- `unit_price_usd` decimal (paid=0.06, free=0.00)
- `active` boolean

---

## 5. Backend Flow Changes

## 5.1 Upload (`DocumentController@upload`)
Before dispatch:
- Identify logged-in user from session (`Session::get('user_id')`).
- Determine `api_tier` from `api_key_tier`:
  - `GEMINI_API_KEY_PAID` => `paid`
  - otherwise `free`
- Estimate `pages_requested`:
  - If `start_page` and `end_page` present: `end - start + 1`
  - Else: detect PDF page count server-side
- `required_credits = pages_requested`
- Transaction:
  - Lock user row
  - Check `status == active`
  - Check `credit_balance >= required_credits`
  - Create/attach document with user and reservation fields
  - Add `reserve` row in `credit_ledger` with negative credits
  - Update `credit_balance`
  - Add `credit_audit_logs`

If insufficient balance:
- Return 422 JSON with message and current balance.

## 5.2 Workflow payload enrichment
Include in dispatch payload:
- `doc_id`
- `api_tier`
- `unit_price_usd`
- `pages_requested`
- `page_start/page_end`

## 5.3 Workflow result callback (`GithubController`)
Require callback to include:
- `doc_id`
- `api_tier`
- `pages_processed`
- `pages_with_results`
- `status`
- `failed_pages` optional

Finalize credits transactionally:
- `consume_credits = pages_processed`
- `refund_credits = pages_reserved - consume_credits` (never below 0)
- Write `consume` and optional `refund` ledger rows.
- Update document fields (`pages_processed`, `pages_with_results`, etc).
- Write audit logs.

If workflow fails early:
- Refund full reservation.
- Mark document failed and audit reason.

## 5.4 Admin credit management
New admin endpoints:
- `POST /admin/users/{id}/credits/add`
- `POST /admin/users/{id}/credits/deduct`
- `POST /admin/users/{id}/credit-cap`

Each action must:
- Use transaction + lock user row.
- Respect cap (`credit_balance <= credit_cap`).
- Write `credit_ledger` + `credit_audit_logs`.

## 5.5 Invoice flow
User:
- `POST /api/credit-invoices` (requested credits, payment reference, proof upload)
- `GET /api/credit-invoices` (own invoices)

Admin:
- `GET /admin/credit-invoices?status=pending`
- `POST /admin/credit-invoices/{id}/approve`
- `POST /admin/credit-invoices/{id}/reject`

Approval transaction:
- Add credits to user balance (respect cap if business wants hard cap).
- Ledger `invoice_approved`.
- Audit log.

Rejection:
- No balance change.
- Ledger `invoice_rejected` (0 credit delta with reason in meta).

---

## 6. UI/UX Scope

## 6.1 User dashboard
Add:
- Current balance
- Cap
- Estimated credits needed before upload submit
- Warning when choosing paid tier (`0.06 USD per page`)
- Upload result summary per document:
  - pages requested
  - pages processed
  - pages with results
  - credits consumed
  - api tier used

## 6.2 Admin dashboard
Add:
- User credit management panel (add/deduct/set cap)
- Pending invoice queue (approve/reject with note)
- Audit explorer with filters (actor, target user, action type, date range)

## 6.3 Invoice form payment details
Show fixed payment instruction:
- Account Name: `Peldarg Consulting Limited`
- Bank: `Moniepoint Bank`
- Account Number: `8107837073`

---

## 7. Email Notification Plan
Send email to `peldargconsulting@gmail.com` when:
- New invoice submitted
- Optional: invoice proof updated

Implementation:
- Use Laravel Notification + queued mail.
- Notification contains:
  - invoice number
  - user info
  - credits requested
  - expected USD amount
  - payment reference
  - admin review URL

Optional additional notifications:
- User notified on approve/reject.

---

## 8. Security and Integrity Controls
- Admin-only endpoints guarded by `is_admin` middleware.
- Prevent direct `credit_balance` edits outside service layer.
- Use DB transactions + `SELECT ... FOR UPDATE` style locking.
- Idempotency key for callbacks to avoid double-consume.
- Store callback signature verification result in audit log.
- Keep invoice proof files in private storage with signed access.

---

## 9. Detailed Testing Plan

## 9.1 Unit tests
- Credit calculator (`pages -> credits`, tier price).
- Tier mapper (`GEMINI_API_KEY_PAID` => `paid`).
- Cap enforcement logic.
- Ledger row generation and balance math.

## 9.2 Feature tests (HTTP)
1. Upload with sufficient credits:
- reserves credits and dispatches workflow.
- document has reservation fields.

2. Upload with insufficient credits:
- returns 422.
- no document created.
- no ledger row.

3. Callback finalization success:
- consume and refund entries created correctly.
- document updated with page stats.

4. Callback failure:
- full refund applied.
- document status failed.

5. Admin add/deduct/cap:
- each action writes ledger + audit.
- cannot exceed cap.

6. Invoice create/approve/reject:
- pending invoice stored.
- approve adds credits and logs actor.
- reject stores reason and no balance change.

## 9.3 Concurrency tests
- Two uploads submitted at same time with near-zero balance:
  - only one succeeds if combined would overspend.
- Double callback replay:
  - second callback does not double-consume credits.

## 9.4 Integration tests
- Full happy path:
  - upload -> reserve -> workflow callback -> finalize -> rows inserted.
- Paid tier path:
  - verify unit_price_usd = 0.06 and amount fields recorded.

## 9.5 Audit log validation tests
- Every credit mutation has corresponding audit row.
- Actor and target are correctly stored for admin actions.

## 9.6 Email tests
- Invoice submission triggers mail to `peldargconsulting@gmail.com`.
- Mail content includes invoice number, user, requested credits.

## 9.7 Reconciliation tests
Nightly command test:
- recompute balance from ledger and compare `users.credit_balance`.
- report mismatches.

---

## 10. Rollout Plan
Phase 1:
- Schema + models + service layer + admin role.
- Reserve/finalize credit engine.

Phase 2:
- Invoice module + email notifications.
- Admin invoice approvals.

Phase 3:
- Audit dashboards + reconciliation command + exports.
- Hardening and edge-case handling.

---

## 11. Suggested Implementation Files
Laravel app (`rcs-app`):
- New migrations:
  - add fields to `users`
  - add fields to `documents`
  - create `credit_ledger`
  - create `credit_audit_logs`
  - create `credit_invoices`
- New models:
  - `CreditLedger`
  - `CreditAuditLog`
  - `CreditInvoice`
- New services:
  - `CreditService`
  - `InvoiceService`
- Controller updates:
  - `DocumentController@upload`
  - `GithubController@callback` and `uploadResults`
- New controllers:
  - `AdminCreditController`
  - `CreditInvoiceController`
- New middleware:
  - `EnsureAdmin`
- Workflow update:
  - include returned page metrics in callback payload

---

## 12. Open Questions (Need Your Confirmation)
1. Should failed pages be charged or only successfully processed pages?
2. For `pages_with_results` vs `pages_processed`, which should drive final credit consumption?
3. Should free-tier usage still deduct credits (likely yes, based on your request)?
4. Should admin be allowed to bypass cap during manual add, or cap is absolute?
5. Do you want multi-currency display (NGN + USD) or only USD internally?
6. For invoice proof, is image upload enough, or also PDF receipt required?
7. Should users be blocked from selecting paid tier unless explicitly enabled by admin?

---

## 13. Practical Recommendation
For fairness and lower dispute risk, consume credits based on `pages_processed` and record `pages_with_results` separately for performance analytics. Keep both in audit and document history.
