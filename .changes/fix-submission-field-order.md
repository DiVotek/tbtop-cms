# fix(forms): keep the form's field order in a stored submission on MySQL

touches: form submissions list (Lead column), submission detail page, dashboard
latest submissions, submission notifications and mail

MySQL's JSON column normalizes object keys (shorter keys first), so a stored
submission lost the order its form submitted the fields in. The Lead column
falls back to the first non-empty field when the payload has no name or email,
so `{subject: 'Partnership inquiry', notes: 'urgent'}` showed `urgent` on
MySQL. The detail page, the notification fields and the mail listed the
fields in the same reordered way.

The payload's key order is now stored next to it in a new nullable
`payload_order` JSON list column (lists keep their order on every driver), and
`FormSubmission::$payload` reapplies it on read.

- Rows stored before this change have no order: MySQL already reordered their
  keys, so it cannot be recovered, and they read as before.
- `payload_order` is written only through the `payload` cast and is hidden
  from the model's array form.

Migration: `2026_10_13_000000_add_payload_order_to_form_submissions_table`.

Tests: `tests/Admin/FormSubmissionsLifecycleTest.php` (the Lead fallback test
no longer skips on MySQL), `tests/Admin/FormSubmissionDetailPageTest.php`
(payload field order); run on SQLite and `composer test:mysql`.

**Read by eye:**
- `src/Form/Casts/OrderedPayload.php`
