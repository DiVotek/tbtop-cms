# fix: secret settings show their state and can be cleared

touches: Settings, notification settings page (SMTP password, Telegram bot token)

- A set secret shows a mask and a "value is saved" hint instead of an empty field; the stored value is never sent to the browser.
- An unset secret shows its own hint; the Telegram token no longer reuses the SMTP password text.
- A Clear tick, shown only while a secret is set, deletes the stored value on save.
