# Notifications

> Part of the host-builder guide. Start at [./README.md](./README.md).

This file owns the notification type/channel registry, the built-in `mail`/`telegram`/`bell` channels, and the form-submission delivery pipeline. Block-level form definition (`HandlesFormSubmission`, `submitRules()`) belongs to [./blocks.md](./blocks.md) — one pointer below.

## Submission lifecycle

A visitor POSTs to the named route `tbtop-cms.form.submit` (`{locale}/{page}/{block}`, throttled per `tbtop-cms.forms.throttle`, config default `'10,1'`). `Tbtop\Cms\Frontend\Http\Controllers\FormSubmitController::__invoke()`:

1. Rejects a tripped honeypot (`tbtop-cms.forms.honeypot_field`, default `website`) by returning a plain `back()` — no validation errors, nothing stored or delivered, so a bot's script learns nothing. Set the config value to `null`/`false` to disable the check.
2. Validates the payload against the block's own `HandlesFormSubmission::submitRules($blockData, $ctx)` — see [./blocks.md](./blocks.md) for that contract.
3. Stores a `Tbtop\Cms\Form\Models\FormSubmission` row (table `tbtop_form_submissions`) unless `tbtop-cms.forms.store` is `false`, in which case `$submission` is `null` for the rest of the request.
4. Fires `Tbtop\Cms\Form\Events\SubmissionReceived` ($submission, $payload, $page, $blockId, $locale) — a host listener hooks this to forward a lead to a CRM or run its own logic. It fires whether or not storage is enabled.
5. Runs the block's own `handleSubmit($payload, $blockData, $ctx)` synchronously; a thrown exception there is logged, fails the request (`back()->withErrors(['form' => ...])`) and, if a row was stored, sets `status = Failed` with the exception message (`handled_at` stays null) before returning. The deferred fan-out below then finds the row no longer `Pending`, its claim fails, and **no bell/mail/telegram notification is sent** until the row is retried.

`SubmissionReceived` also drives the built-in admin fan-out: `Tbtop\Cms\Form\Listeners\NotifiesAdminsOfSubmission` is wired in `CmsServiceProvider::wireSubmissionNotifications()` and defers `Tbtop\Cms\Form\SubmissionNotifier::notify()` to `app()->terminating()` — after the visitor's response is sent, since this package has no queue requirement. It's a no-op when `$submission` is `null` (nothing to track delivery against or retry). `SubmissionNotifier::notify()` calls `Notifications::send('form.submission', …)` (see below) and turns the returned deliveries into `FormSubmission::status`.

`FormSubmission::error` joins the distinct failure texts with ` | ` (two recipients failing with the same text show it once). A send failure reads through `tbtop-cms::notifications.errors.{channel}` (`Mail delivery failed: …`); a render failure (`Delivery::$stage === DeliveryStage::Render`) reads through `tbtop-cms::notifications.errors.render` (`Couldn't render the :channel notification: :error`), where `:channel` is the short name from `tbtop-cms::notifications.channels.{key}` (Email / Telegram / Bell; a host channel without that key falls back to its `label()`).

### Statuses

- `SubmissionStatus` (`Tbtop\Cms\Form\SubmissionStatus`): `Pending` → `Processing` (claimed) → `Handled` | `Failed`. This is delivery status, not workflow.
- `LeadStatus` (`Tbtop\Cms\Form\LeadStatus`): `New` → `Read` → `Processed` | `Discarded` — the manager's own workflow, independent of delivery. Only `New` counts toward the inbox badge; `FormSubmissionPage::markRead()` flips it to `Read` on open.

### Claim, race safety, and retry

`Tbtop\Cms\Form\SubmissionClaim` is a compare-and-swap over `status`: `take($submission, $from)` does a conditional `UPDATE … WHERE status = $from` to `Processing` and only the caller whose update actually affected one row proceeds; `release($submission, $attributes)` writes the final status guarded on `Processing`. Callers must not pre-check status and then act — that reintroduces the race the claim closes.

Once `notify()` or `retry()` holds the claim, any exception — the page or block lookup, the `handled_at` write, a throwing `NotificationSent` listener — ends the row `Failed` with the exception's message, released in a `finally`, so a row never stays `Processing`. The original exception goes to `report()` with `submission_id` set in Laravel's `Context` for the duration of the call (so it lands in the log entry; a `reportable()` callback can read `Context::get('submission_id')`), and is not rethrown: the admin retry action shows the error toast instead of a 500.

`Tbtop\Cms\Form\SubmissionRetrier::retry()` re-runs both original delivery paths a failed submission attempted, in the same order and by the same rule as the first submit. First the block's `handleSubmit()`, skipped if `handled_at` is already set (that column marks the handler's own, non-idempotent success, independent of `status`). If it throws, the row stays `Failed` with the handler's error, `handled_at` stays null and **no bell/mail/telegram notification is sent**: admins are never told about a submission the block did not handle. If it succeeds (or already had), `handled_at` is set and `SubmissionNotifier::deliver()` runs the fan-out to every enabled channel, including the ones that succeeded last time (duplicates there are accepted). The row ends `Handled` with no error when it succeeds, `Failed` with the channel errors otherwise; the next retry then skips the handler and only re-sends. The claim is released once, with that outcome. A second retry while one is running cannot take the claim and does nothing. A missing page or form block leaves the row `Failed` (`Page no longer exists.` / `Form block no longer exists on the page.`) without running either step. `FormSubmissionsPage`'s row action and `FormSubmissionPage`'s detail action both call this — visible only when `status = Failed`.

### What the admin pages offer

- `Tbtop\Cms\Admin\Pages\FormSubmissionsPage` (`form-submissions`, hidden from the sidebar, reached via the top-bar Inbox button): a table with tabs for All/New/Read/Processed/Discarded (`LeadStatus`), filters on delivery status and locale, row actions retry + hard delete, bulk actions mark-processed/discard/delete. Row click routes to the detail page.
- `Tbtop\Cms\Admin\Pages\FormSubmissionPage` (`form-submissions/{submission}`): payload viewer, delivery status + error, free `LeadStatus` transitions (any status to any other, current one omitted), a comments thread (`Tbtop\Cms\Form\Models\FormSubmissionComment`, oldest-first), retry when failed, and hard delete.
- Delete is a hard delete everywhere, not soft — a lead's contact details must be actually removable on request (see `FormSubmissionsPage::deleteAction()`'s docblock).

### What a host listener can hook

- `Tbtop\Cms\Form\Events\SubmissionReceived` — fires once per submission attempt (even when storage is disabled), before `handleSubmit()` runs. The natural hook for CRM forwarding or custom logic independent of cms's own bell/mail/telegram fan-out.
- `Tbtop\Cms\Notifications\Events\NotificationSent` / `Tbtop\Cms\Notifications\Events\NotificationFailed` — fire once per (recipient, channel) delivery attempt for *any* registered type, not just `form.submission`. Each carries `type`, `channel`, `Recipient`, and (`Failed` only) `error` and `stage` (`Tbtop\Cms\Notifications\DeliveryStage`: `Render` when the pair's template threw, `Send` when the channel did). A render failure fires `NotificationFailed` too.

## The registry

`Tbtop\Cms\Notifications\Notifications` is a static façade over the container-bound `Tbtop\Cms\Notifications\NotificationRegistry` singleton — the package's public surface for registering types/channels and sending.

- `Notifications::type(TypeDefinition $type): void` — registers a notification type. Throws if the key is already registered.
- `Notifications::channel(class-string<Channel> $channel): void` — registers a channel. Resolves it through the container immediately (`app($class)`) to read its `key()`, so a channel with a heavy or not-yet-configured constructor fails at boot, not at first send.
- `Notifications::send(string $typeKey, object $payload, list<Recipient> $recipients): list<Delivery>` — renders and delivers to every enabled, supporting channel for every recipient whose `Audience` the type accepts.

### Synchronous by design

`send()` dispatches via `Notification::sendNow()` and blocks until every channel for every recipient has reported success or failure — the `tbtop_notification_log` row and `NotificationSent`/`NotificationFailed` event for each is written before the call returns, and the return value is exactly the deliveries that call made. This is deliberate: a caller that needs the per-recipient, per-channel outcome to decide its own state (`SubmissionNotifier` writing `FormSubmission::status`, `SubmissionRetrier`'s claim release) cannot get that from a dispatch that returns before delivery happens. One channel throwing does not stop the others — it's caught, logged as `Failed`, and the next channel/recipient still runs. The same holds for a template that throws while rendering: that (recipient, channel) pair gets a `Failed` log row, a `Delivery` with `stage` `DeliveryStage::Render`, a `NotificationFailed` event and an error-level `notifications.template_render_failed` log entry (context: `type`, `channel`, `format`, `locale`, `error`), and `send()` moves on. Templates are picked by format, so one broken view fails every channel of its format, and only those. `send()` does not throw for a render failure.

Do not reconstruct the outcome by re-reading `tbtop_notification_log`: the log is shared by every concurrent request and carries no column tying a row to the entity that triggered a send — a window query there can attribute another caller's failure to yours (`tests/Notifications/NotificationsTest.php`, "reports only its own deliveries when an unrelated failed row shares the log").

**To make a send asynchronous**, run it from inside a queued job or listener yourself — `send()` never queues on your behalf. `SubmissionNotifier` does this already via `app()->terminating()`; `packages/storefront`'s order listeners (`Tbtop\Storefront\Notifications\Listeners\SendsOrderNotification` and its five subclasses) instead implement `Illuminate\Contracts\Queue\ShouldQueue` so a host with a queue worker never blocks the triggering request on `send()`'s synchronous delivery.

### `TypeDefinition`

Built with `TypeDefinition::make($key)` and its fluent setters, then handed to `Notifications::type()`:

- `label(string)` — display label (settings page, bell title).
- `group(string)` — which settings tab/section this type is grouped under (real values in use: `'forms'`, `'orders'`, `'inventory'`).
- `payload(class-string $payloadClass)` — **must be a `readonly` DTO whose public properties are exactly the template variables** (`FormSubmissionPayload`, `Tbtop\Storefront\Notifications\OrderPayload`). Plain values only — a template never queries; a money amount is pre-formatted before it reaches the payload, not formatted in the Blade view.
- `templates(array<value-of<Format>, string> $views)` — one view name per `Format` the type supports; `templateFor(Format $format)` returns `null` for a format with no view, which `Notifications::send()` treats as "skip this channel for this recipient" rather than an error.
- `audiences(list<Audience> $audiences)` — which `Audience` cases (`Customer`, `Staff`) may receive this type; `send()` silently skips a recipient outside this list.
- `defaultChannels(list<string> $channelKeys)` — channel keys enabled unless a merchant overrides `notifications.{typeKey}.channels` in Settings (see below).

### `Recipient`

`final readonly class Recipient(Audience $audience, string $locale, ?string $email = null, ?string $phone = null, ?object $notifiable = null)`. A recipient carries only the addresses it actually has — a telegram-only admin has no `email`, a mail-only customer has no `notifiable`. `Channel::supports(Recipient $to)` is how a channel declares which field it needs before `send()` ever calls it.

| Channel | Needs | `supports()` check |
|---|---|---|
| `mail` | `email` | `$to->email !== null` |
| `telegram` | `phone` (holds the chat id, not a real phone number — see below) | `$to->phone !== null` |
| `bell` | `notifiable` (a Laravel notifiable, typically an admin user model) | `$to->notifiable !== null` |

### `Channel` contract

`Tbtop\Cms\Notifications\Contracts\Channel`:

- `key(): string` — stable key, also the value stored in a `notifications.{type}.channels` array.
- `label(): string`
- `format(): Format` — which of `TypeDefinition::templates()` this channel reads.
- `settings(S $s): list<Field|Node|TextBlock>` — fields meant for rendering on a notification settings page; a secret field's key (e.g. `notification_telegram_bot_token`) must also be listed in `tbtop-cms.settings.secret` for `Settings` to encrypt it via `SettingCodec`. **Still not consumed**: `NotificationSettingsPage` generates its per-type channel checkboxes and template editors from the registry (see below), but it never calls a `Channel`'s own `settings()` — a third-party channel's `settings()` fields still do not automatically appear in the admin. A host channel must add its own settings fields, e.g. via a custom settings page or by extending `NotificationSettingsPage`.
- `supports(Recipient $to): bool`
- `send(Rendered $message, Recipient $to, Settings $settings): void` — a channel reports failure by *throwing*; it never writes the log or dispatches the events itself. `Tbtop\Cms\Notifications\NotificationChannelDriver` (the one Laravel notification-channel class every `TemplatedNotification` routes through) owns the `tbtop_notification_log` row and the `NotificationSent`/`NotificationFailed` events. Its public `recordFailure(string $type, string $channelKey, Recipient $recipient, string $error, DeliveryStage $stage = DeliveryStage::Send): void` writes a failure that happened before the channel ran (`Notifications::send()` calls it for a render failure), so the log row, `Delivery` and event keep one writer.

## Built-in channels

### `mail` (`Tbtop\Cms\Notifications\Channels\MailChannel`)

Sends through `Tbtop\Cms\Mail\CmsMailer`, format `Html`. Settings keys (all under the flat `mail.*` namespace, shared with `NotificationSettingsPage`'s own fields — not the host's `config/mail.php`):

| Key | Field |
|---|---|
| `mail.host` | SMTP host |
| `mail.port` | SMTP port |
| `mail.username` | SMTP username |
| `mail.password` | SMTP password (secret — encrypted, see `tbtop-cms.settings.secret`) |
| `mail.encryption` | `none` \| `tls` \| `ssl` |
| `mail.from_address` | From address |
| `mail.from_name` | From name |

`CmsMailer::mailer()` falls back to the host's default mailer (`Mail::mailer()`) whenever `mail.host` is unset/blank; only when a host is configured does it build an on-demand SMTP transport (`Mail::build($this->transportConfig())`). `encryption` maps to Symfony's `scheme`/`auto_tls` options, not the legacy `encryption` key (`ssl` → `scheme: smtps`; `tls` → `scheme: smtp` with opportunistic STARTTLS; `none` → `scheme: smtp`, `auto_tls: false`).

Each mail recipient gets its own message — `Notifications::send()` calls `CmsMailer::send()` once per (recipient, channel) pair, so N configured recipients means N separate emails, not one message with N addresses in `To:` (`.changes/feat-notification-registry.md`, "Contract change").

### `telegram` (`Tbtop\Cms\Notifications\Channels\TelegramChannel`)

Sends one Bot API `sendMessage` per recipient via `Tbtop\Cms\Telegram\TelegramClient`, format `Short`. Settings keys:

| Key | Field |
|---|---|
| `notifications.telegram.bot_token` | Bot token (secret) |
| `notifications.telegram.chat_ids` | Chat ids, comma/semicolon/newline-separated, parsed by `Tbtop\Cms\Telegram\ChatIds::parse()` |

A `Recipient`'s chat id is carried in `$phone` — the only remaining generic address field the frozen `Recipient` shape offers for a telegram-only recipient with no email and no notifiable — never treated as an actual phone number. `TelegramChannel::send()` throws `TelegramApiException` if `notifications.telegram.bot_token` is unset/blank at send time, independent of whatever gated the recipient into existence.

**Chat linking flow** (`NotificationSettingsPage`'s "Create invite" / "Check invite" actions, backed by `Tbtop\Cms\Telegram\TelegramInvites`): Telegram assigns a chat id, and it's not visible anywhere in the client UI, so `create($token)` generates a short code and a `https://t.me/{bot}?start={code}` deep link (10-minute TTL); the admin sends that to the bot, and `checkCurrent($token)` polls `getUpdates` for a matching `/start {code}` message, appending the resolved chat id (with a human label — group title, or `"First Last (@username)"`) to `notifications.telegram.chat_ids` via `Tbtop\Cms\Telegram\ChatIds::append()` once found.

### `bell`

Delivers into the admin header bell via Laravel's `database` channel through `tbtop/admin`'s `Notification` builder — same table (`notifications`, migrated by this package if the host doesn't already have one) the panel already reads. Format `Short`. `settings()` returns `[]` — the bell has no opt-out and no configurable settings.

`notifications.notifiable_id` follows the host user key (`UserKeyColumn`, see inventory.md): a ULID or UUID string on a `HasUlids`/`HasUuids` user model, an unsigned big integer otherwise. A host that set a default morph key type (`Schema::morphUsingUuids()`/`morphUsingUlids()`) gets `morphs()`'s column instead, and the alignment below leaves its table alone. A table created earlier with `morphs()` has a bigint column; strict MySQL refuses a ULID/UUID there, so every bell delivery was logged `failed`. On such a host `php artisan migrate` runs `2026_10_22_000000_update_notifications_notifiable_id_match_host_key_type`, which changes the column to the key type, a host's own table included.

## Templates

`Tbtop\Cms\Notifications\Contracts\TemplateStore::override(string $type, Format $format, string $locale): ?string` — a merchant-editable override lookup; a non-null return wins over `TypeDefinition::templates()`, `null` falls back to it. Bound to `Tbtop\Cms\Notifications\Support\DatabaseTemplateStore` (`CmsServiceProvider::packageRegistered()`), backed by the `tbtop_notification_templates` table (migration `2026_09_01_000000_create_notification_templates_table.php`; columns `type`, `format`, `locale`, `subject`, `body`, unique on `(type, format, locale)` — model `Tbtop\Cms\Notifications\Models\NotificationTemplate`). Locale lookup is **strict**: a row for `en` is never served to a `uk` request, there is no locale fallback. Rebinding `TemplateStore` (e.g. a host provider calling `$this->app->bind(TemplateStore::class, MyTemplateStore::class)`) still works for a host that wants its own override storage.

`DatabaseTemplateStore::override()` composes the stored `subject`/`body` pair into one Blade source string via `DatabaseTemplateStore::compose()`:

```php
"@section('subject')\n" . $subject . "\n@endsection\n" . $body
```

The newlines around `$subject` are load-bearing — Blade only treats `@endsection` as a directive when the character before `@` is a non-word boundary (`\B@`), so a subject ending in a letter or digit (`Order confirmed@endsection`) would leave the section unclosed and the subject swallowed into the body. `TemplateRenderer::render()` trims the extracted section value, so the padding itself never reaches the rendered subject.

`TemplateRenderer::render($template, $payload, $locale)` accepts either a registered view name or this inline Blade source: a string that looks like a view name *and* resolves via `View::exists()` is rendered with `View::make()`; otherwise it's written to a content-addressed file under `config('view.compiled')` (`tbtop-notification-{sha256}.blade.php`, reused on repeat renders with the same source) and rendered with `View::file()`. Either way it exposes the payload DTO's public properties as template variables (`get_object_vars($payload)`), sets the app locale for the duration of the render, and captures an optional `@section('subject', …)` after Blade evaluates the view but before the section stack is flushed — its absence just yields an empty subject, which a short-format channel (telegram, bell) has no use for anyway.

**Fallback on a broken override.** `Notifications::send()` renders a stored override inside a try/catch (`Notifications::renderWithFallback()`); if rendering throws, it logs a warning under the key `notifications.template_override_failed` (context: `type`, `format`, `locale`, `error`) and falls back to the type's built-in view instead of dropping the channel for that recipient. If the built-in view throws as well, the pair is a render failure (see "Synchronous by design"): the log then holds both the `template_override_failed` warning and the `template_render_failed` error.

**Validation at save time — `TemplateProbe`.** `Tbtop\Cms\Notifications\Support\TemplateProbe::check(string $payloadClass, string $template, string $locale): void` builds a stub instance of the type's payload DTO (public properties filled by declared type — `string` → `'sample'`, `int` → `1`, `bool` → `true`, a `DateTimeInterface` → `now()`, etc.; a payload class that can't be instantiated this way falls back to a compile-only `Blade::compileString()` check) and runs the composed template through the real `TemplateRenderer`. A render failure throws `Tbtop\Cms\Notifications\Support\InvalidTemplate`. `NotificationSettingsPage::validateForm()` runs this for every non-empty posted template body before anything is persisted, so a broken template is rejected at save time with a field-level validation error rather than failing later at send time.

The built-in `form.submission` views live at `resources/views/notifications/form-submission/{html,short}.blade.php`, registered as `tbtop-cms::notifications.form-submission.html` / `.short` via `TypeDefinition::templates()` in `CmsServiceProvider::wireNotificationRegistry()`. A host overrides a package view the normal Laravel way — publish/copy to `resources/views/vendor/tbtop-cms/notifications/form-submission/{html,short}.blade.php` — since a registered-view template still resolves through the standard `View::make()` lookup, which checks the host's `resources/views/vendor/{package}/…` override path before the package's own. A merchant-edited override (stored in `tbtop_notification_templates`) takes precedence over either.

## Per-type channel toggles and the delivery log

`Notifications::enabledChannels(string $typeKey): list<string>` (`Tbtop\Cms\Notifications\Notifications`) is the public way to read a type's effective channel list — the same generic gate `Notifications::send()` applies internally: it reads `notifications.{typeKey}.channels` from Settings (an array of channel keys), falling back to `TypeDefinition::defaultChannelValues()` when unset. The key is literally the type key with a `.` inserted, e.g. `notifications.order.placed.channels` for type `order.placed` (proven generically by `tests/Notifications/NotificationsTest.php`, "honors a per-type channel toggle in settings over the registered defaults").

`form.submission` now goes through this same gate rather than a special case: `Tbtop\Cms\Form\Notifications\FormSubmissionRecipients::build()` calls `Notifications::enabledChannels('form.submission')` and only builds a mail/telegram/bell recipient for a channel present in that list. `form.submission` registers `defaultChannels(['bell'])`, so a host that has never touched notification settings gets bell-only delivery — no mail, no telegram — until a merchant sets `notifications.form.submission.channels` from the settings page. The legacy flat `notifications.mail.enabled` / `notifications.telegram.enabled` toggles are no longer read anywhere in `src/`. A new host-registered type was always subject to this generic `notifications.{type}.channels` gate; `form.submission` is no longer an exception to it.

Every delivery attempt — regardless of type — writes one row to `tbtop_notification_log` (`Tbtop\Cms\Notifications\Models\NotificationLog`, migration `2026_08_27_000000_create_notification_log_table.php`): `type`, `channel`, `audience`, `recipient` (whichever address was used — email, chat id, or the notifiable's key/class — free text, not a foreign key), `locale`, `status` (`Tbtop\Cms\Notifications\NotificationLogStatus`: `Pending` → `Sent`/`Failed`), `error`, `sent_at`. It is observability-only — no pruning ships with this package, and (per the assumptions above) it must never be read back to reconstruct a specific call's outcome.

## The generated settings page

`Tbtop\Cms\Admin\Pages\NotificationSettingsPage` (`notification-settings`) builds its per-type UI entirely from `NotificationRegistry` — nothing about a specific type is hand-listed on the page. Field-name conventions and registry lookups this generation relies on live in `Tbtop\Cms\Admin\Pages\Support\NotificationTemplateFields`.

- **Tabs.** One "Channels" tab (mail transport fields, Telegram fields, the chat-invite actions — unchanged) plus one tab per distinct `TypeDefinition::groupValue()`, in first-seen registration order (`NotificationTemplateFields::groupedTypes()`). A tab's label is `tbtop-cms::settings.notifications.groups.{group}` when that translation key exists, else `Str::headline($group)`; an empty group value maps to the `other` key.
- **Per-type card.** Each type in a group tab renders as a `card`-variant section titled `TypeDefinition::labelValue()`, containing a channel checkbox list and, for an owner, the template editors.
- **Channel checkboxes.** `NotificationTemplateFields::channelsFor($type, $registry)` offers every *registered* channel (in `NotificationRegistry::channels()` order — the order channels were passed to `Notifications::channel()`, e.g. `mail`, `telegram`, `bell` for the built-ins) whose `format()` the type has a template for (`$type->templateFor($channel->format()) !== null`) — a channel whose format the type never registered is not offered, since there is nothing for it to render. Field name `channels__{typeKey with '.' → '__'}` (e.g. `channels__form__submission`), posted value an array of channel keys, saved to `notifications.{typeKey}.channels` via `map()`. Unset defaults to `TypeDefinition::defaultChannelValues()` (wired through `defaults()`).
- **Template editors — owner only.** `NotificationTemplateFields::formatsFor($type)` lists every `Format` the type has a template for; each gets a collapsible editor (label `tbtop-cms::settings.notifications.formats.{format}`, collapsed by default) with a subject + body field pair per active admin locale (`Languages::adminActive()`), plus a reset action. Field names `tpl__{slug}__{format}__{locale}__subject` / `…__body`. The body field's placeholder is the built-in view's own Blade source (`NotificationTemplateFields::builtInSource()`, read straight from the resolved view file); its helper text lists the payload DTO's public properties as `{{ $name }}` variables (`NotificationTemplateFields::payloadVariables()`). Whether the current admin is an owner is decided by `AdminAccess::isOwner($userId)` for the id from `auth()->guard(CurrentAdmin::guard())->id()`; a non-owner's structure carries no `tpl__*` fields at all, and a non-owner's POST is not read for them even if a client fabricated one (`NotificationSettingsPage::afterSave()` returns immediately for a non-owner).
- **Reset action.** Per (type, format), action name `resetTemplate__{slug}__{format}`; owner-only (a non-owner triggering it gets an error notification instead). Deletes every locale's `NotificationTemplate` row for that (type, format) and clears the corresponding form fields via a `setFormData` effect.
- **Validation and persistence** run through `SettingsPageBase`'s two hooks (available to every settings page, not just this one): `validateForm(array $form): void` runs before anything is saved — for an owner, every non-empty posted template body is checked with `TemplateProbe::check($type->payloadClass(), DatabaseTemplateStore::compose($subject, $body), $locale)`, and a failure throws `ValidationException` on the body field with `tbtop-cms::settings.notifications.errors.template_invalid` before the request touches the database. `afterSave(array $form): void` runs after `SettingsForm::apply()` has written the mapped `channels__*` settings — for an owner, it upserts a `NotificationTemplate` row per non-empty posted body and deletes the row for an emptied one.

## Where to register

Register types and channels from a host service provider's `packageBooted()`/`boot()`, **not** `packageRegistered()`/`register()`: `NotificationRegistry` is bound as a singleton in `Tbtop\Cms\CmsServiceProvider::packageRegistered()`, and `Notifications::channel()`/`::type()` resolve it eagerly through the container at call time. Laravel runs every provider's `register()` before any provider's `boot()`, so calling either method from `boot()` guarantees the binding already exists regardless of provider discovery order — `CmsServiceProvider` itself registers its own built-in channels and the `form.submission` type from `packageBooted()` (`wireNotificationRegistry()`), and `packages/storefront`'s `StorefrontServiceProvider::packageBooted()` documents the same reasoning verbatim.

## Worked example

`packages/storefront` (`tbtop-ecom/packages/storefront/src/StorefrontServiceProvider.php`) registers five order types and one inventory type, then sends from queued domain-event listeners:

```php
// StorefrontServiceProvider::packageBooted()
Notifications::type(
    TypeDefinition::make('order.placed')
        ->label(__('tbtop-storefront::notifications.order.placed.label'))
        ->group('orders')
        ->payload(OrderPayload::class)
        ->templates([
            Format::Html->value => 'tbtop-storefront::notifications.order-placed.html',
            Format::Text->value => 'tbtop-storefront::notifications.order-placed.text',
            Format::Short->value => 'tbtop-storefront::notifications.order-placed.short',
        ])
        ->audiences([Audience::Customer, Audience::Staff])
        ->defaultChannels(['mail', 'bell']),
);

Event::listen(OrderPlaced::class, SendsOrderPlacedNotification::class);
```

```php
// SendsOrderNotification (abstract base for the five order.* listeners)
abstract class SendsOrderNotification implements ShouldQueue
{
    public function __construct(
        private readonly OrderPayloadFactory $payloads,
        private readonly OrderRecipients $recipients,
    ) {}

    abstract protected function typeKey(): string;

    protected function send(string $orderId): void
    {
        $order = Order::query()->find($orderId);
        if (! $order instanceof Order) {
            return;
        }

        $recipients = $this->recipients->build($order);
        if ($recipients === []) {
            return;
        }

        Notifications::send($this->typeKey(), $this->payloads->make($order), $recipients);
    }
}
```

`OrderPayload` is a `readonly` DTO of plain, pre-formatted values (money as a decimal string, not a formatter call the Blade view would have to make). `OrderRecipients::build($order)` returns a customer `Recipient` (email, only when the order carries one) plus every admin user as staff recipients. The listener implements `ShouldQueue` so `Notifications::send()`'s synchronous delivery never blocks the request that triggered the domain event — Testbench's `sync` queue connection runs it inline in tests, a host's real queue worker runs it off-request.

## Not yet expressible

- No settings-page UI renders a third-party (or built-in) channel's own `settings()` fields — `NotificationSettingsPage` builds channel checkboxes and template editors from the registry, but never calls a `Channel::settings()`; a host channel still needs its own settings surface for its own configuration fields.
- Template rows written by `NotificationSettingsPage::afterSave()` (`NotificationTemplate` create/update/delete) produce no audit-log entry — `NotificationTemplate` does not use `LogsCmsActivity`, and `SettingsSaved` only carries the mapped `channels__*` settings keys, not template edits. A host that needs an audit trail for merchant-edited templates has to add it itself (e.g. a model observer).
- `TemplateStore::override()`'s locale lookup is strict with no fallback chain (e.g. `uk` never falls back to a default/`en` override) — a host wanting fallback locale resolution has to implement that in its own `TemplateStore` binding.
