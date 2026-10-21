# fix: notification text, health checks and Languages::setDefault()

touches: bell channel, mail subject, submission errors, health checks (notifications configured, scheduler heartbeat), Languages

- The bell renders the Short (Telegram HTML) template as plain text: tags stripped, entities decoded.
- A mail subject override no longer reaches the header HTML-escaped (`O&#039;Brien`).
- A host channel that fails without its own `errors.{channel}` key gets the new generic `errors.channel` text instead of the raw key.
- The notifications health check parses recipients and chat ids with the parsers delivery uses, so `admin@` or `@channel` no longer reads as configured.
- The scheduler heartbeat message shows whole minutes on Carbon 3.
- `Languages::setDefault()` dispatches `LanguagesChanged` after its transaction, as the observer does for model saves.
