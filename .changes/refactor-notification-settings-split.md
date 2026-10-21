# refactor: split NotificationSettingsPage's test actions and template editors

touches: nothing visible; the notification settings page renders and behaves the same

`NotificationSettingsPage` (641 lines) now delegates its header actions (test
email, test Telegram message, Telegram invite create/check) to
`NotificationTestActions` and the owner-only template editors (per-locale
subject/body, reset) to `NotificationTemplateEditors`. The owner check moves
to `CurrentAdmin::isOwner()`.

Third of five splits from arti `spec-cms-tbtop-split-large-classes`.
