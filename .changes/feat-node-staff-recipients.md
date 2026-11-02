# feat(node): export staffRecipients for a host's own staff notifications

touches: @tbtop/cms export `staffRecipients`, README Forms and notifications

`staffRecipients(site, locale?, channels?)` returns the staff a notification
reaches, as the form-submission fan-out builds them: the bell's staff users
in their saved admin locale, the mail recipients and Telegram chats from the
notification settings in the given (else the panel's default) locale. A host
type registered in `notifications.types` (a shop's new order) now sends to the
same team without copying that logic. The form fan-out uses it; its errors
for a misconfigured channel are unchanged.

Upgrade: nothing to change.
