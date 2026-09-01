<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Cms\Admin\Pages\Support\NotificationTemplateFields as Fields;
use Tbtop\Cms\Admin\Support\AdminAccess;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Mail\CmsMailer;
use Tbtop\Cms\Mail\Recipients;
use Tbtop\Cms\Mail\TestMail;
use Tbtop\Cms\Notifications\Contracts\Channel;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Models\NotificationTemplate;
use Tbtop\Cms\Notifications\NotificationRegistry;
use Tbtop\Cms\Notifications\Support\DatabaseTemplateStore;
use Tbtop\Cms\Notifications\Support\InvalidTemplate;
use Tbtop\Cms\Notifications\Support\TemplateProbe;
use Tbtop\Cms\Notifications\TypeDefinition;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Telegram\ChatIds;
use Tbtop\Cms\Telegram\TelegramApiException;
use Tbtop\Cms\Telegram\TelegramClient;
use Tbtop\Cms\Telegram\TelegramInvites;
use Throwable;

class NotificationSettingsPage extends SettingsPageBase
{
    public static function path(): string
    {
        return 'notification-settings';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'settings',
            'label' => __('tbtop-cms::nav.items.notification_settings'),
            'order' => 40,
            'icon' => 'bell',
        ];
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::settings.notifications.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::settings.notifications.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => $this->title()],
        ];
    }

    /** @return list<ActionBuilder> */
    #[\Override]
    public function headerActions(S $s): array
    {
        return [
            $this->sendTestEmailAction($s),
            $this->sendTestTelegramMessageAction($s),
            $this->createTelegramInviteAction($s),
            $this->checkTelegramInviteAction($s),
        ];
    }

    /** @return array<string, string> */
    protected function map(): array
    {
        return [
            'notification_mail_recipients' => 'notifications.mail.recipients',
            'mail_host' => 'mail.host',
            'mail_port' => 'mail.port',
            'mail_username' => 'mail.username',
            'mail_password' => 'mail.password',
            'mail_encryption' => 'mail.encryption',
            'mail_from_address' => 'mail.from_address',
            'mail_from_name' => 'mail.from_name',
            'notification_telegram_bot_token' => 'notifications.telegram.bot_token',
            'notification_telegram_chat_ids' => 'notifications.telegram.chat_ids',
            ...self::channelsMap(),
        ];
    }

    /** @return array<string, string> */
    private static function channelsMap(): array
    {
        $map = [];

        foreach (app(NotificationRegistry::class)->types() as $type) {
            $map[Fields::channelsField($type->key())] = "notifications.{$type->key()}.channels";
        }

        return $map;
    }

    /** Mirrors CmsMailer::transportConfig()'s encryption fallback, plus each type's registered default channel list. */
    #[\Override]
    protected function defaults(): array
    {
        $defaults = ['mail_encryption' => 'tls'];

        foreach (app(NotificationRegistry::class)->types() as $type) {
            $defaults[Fields::channelsField($type->key())] = $type->defaultChannelValues();
        }

        return $defaults;
    }

    /** @return list<Field|Node|TextBlock> */
    protected function fields(S $s): array
    {
        $registry = app(NotificationRegistry::class);
        $isOwner = $this->isOwner();

        $tabs = [
            [
                'label' => __('tbtop-cms::settings.notifications.tabs.channels'),
                'children' => $this->channelFields($s),
            ],
        ];

        foreach (Fields::groupedTypes($registry) as $group => $types) {
            $tabs[] = [
                'label' => self::groupLabel($group),
                'children' => $this->groupFields($s, $types, $registry, $isOwner),
            ];
        }

        return [$s->tabs($tabs)];
    }

    private static function groupLabel(string $group): string
    {
        $key = $group === '' ? 'other' : $group;
        $translationKey = "tbtop-cms::settings.notifications.groups.{$key}";

        return Lang::has($translationKey) ? __($translationKey) : Str::headline($key);
    }

    /** @return list<Field|Node|TextBlock> */
    private function channelFields(S $s): array
    {
        return [
            $s->tags('notification_mail_recipients')->label(__('tbtop-cms::settings.notifications.fields.recipients'))
                ->rules('nullable|array')->helperText(__('tbtop-cms::settings.notifications.helpers.recipients')),
            $s->displayDivider(),
            $s->displayText(__('tbtop-cms::settings.notifications.sections.mail_transport'))->variant('subheading'),
            $s->text('mail_host')->label(__('tbtop-cms::settings.notifications.fields.mail_host'))
                ->rules('nullable|max:255'),
            $s->number('mail_port')->label(__('tbtop-cms::settings.notifications.fields.mail_port'))
                ->rules('nullable|integer'),
            $s->text('mail_username')->label(__('tbtop-cms::settings.notifications.fields.mail_username'))
                ->rules('nullable|max:255'),
            $s->password('mail_password')->label(__('tbtop-cms::settings.notifications.fields.mail_password'))
                ->rules('nullable|max:255')->helperText(__('tbtop-cms::settings.notifications.helpers.mail_password')),
            $s->select('mail_encryption')->label(__('tbtop-cms::settings.notifications.fields.mail_encryption'))
                ->options([
                    ['value' => 'none', 'label' => __('tbtop-cms::settings.notifications.encryption.none')],
                    ['value' => 'tls', 'label' => __('tbtop-cms::settings.notifications.encryption.tls')],
                    ['value' => 'ssl', 'label' => __('tbtop-cms::settings.notifications.encryption.ssl')],
                ])
                ->rules('nullable|in:none,tls,ssl'),
            $s->text('mail_from_address')->label(__('tbtop-cms::settings.notifications.fields.mail_from_address'))
                ->rules('nullable|email'),
            $s->text('mail_from_name')->label(__('tbtop-cms::settings.notifications.fields.mail_from_name'))
                ->rules('nullable|max:255'),
            $s->displayDivider(),
            $s->displayText(__('tbtop-cms::settings.notifications.sections.telegram'))->variant('subheading'),
            $s->password('notification_telegram_bot_token')->label(__('tbtop-cms::settings.notifications.fields.telegram_bot_token'))
                ->rules('nullable|max:255')->helperText(__('tbtop-cms::settings.notifications.helpers.mail_password')),
            $s->tags('notification_telegram_chat_ids')->label(__('tbtop-cms::settings.notifications.fields.telegram_chat_ids'))
                ->rules('nullable|array')->helperText(__('tbtop-cms::settings.notifications.helpers.telegram_chat_ids')),
        ];
    }

    /**
     * @param  list<TypeDefinition>  $types
     * @return list<Field|Node|TextBlock>
     */
    private function groupFields(S $s, array $types, NotificationRegistry $registry, bool $isOwner): array
    {
        return array_map(
            fn (TypeDefinition $type): Node => $this->typeSection($s, $type, $registry, $isOwner),
            $types,
        );
    }

    private function typeSection(S $s, TypeDefinition $type, NotificationRegistry $registry, bool $isOwner): Node
    {
        $children = [$this->channelsField($s, $type, $registry)];

        if ($isOwner) {
            array_push($children, ...$this->templateEditors($s, $type));
        }

        return $s->section(['title' => $type->labelValue(), 'variant' => 'card'], $children);
    }

    private function channelsField(S $s, TypeDefinition $type, NotificationRegistry $registry): Field
    {
        $options = array_map(
            static fn (Channel $channel): array => ['value' => $channel->key(), 'label' => $channel->label()],
            Fields::channelsFor($type, $registry),
        );

        return $s->checkboxlist(Fields::channelsField($type->key()))
            ->label(__('tbtop-cms::settings.notifications.fields.channels'))
            ->options($options)
            ->rules('nullable|array');
    }

    /** @return list<Node> */
    private function templateEditors(S $s, TypeDefinition $type): array
    {
        $locales = self::adminLocales();

        return array_map(
            fn (Format $format): Node => $this->formatEditor($s, $type, $format, $locales),
            Fields::formatsFor($type),
        );
    }

    /** @param  list<string>  $locales */
    private function formatEditor(S $s, TypeDefinition $type, Format $format, array $locales): Node
    {
        $fields = [];
        foreach ($locales as $locale) {
            array_push($fields, ...$this->localeTemplateFields($s, $type, $format, $locale));
        }

        $fields[] = $s->actionsRow([$this->resetTemplateAction($s, $type, $format)]);

        return $s->collapsible([
            'label' => __("tbtop-cms::settings.notifications.formats.{$format->value}"),
            'collapsed' => true,
        ], $fields);
    }

    /** @return list<Field> */
    private function localeTemplateFields(S $s, TypeDefinition $type, Format $format, string $locale): array
    {
        $placeholder = Fields::builtInSource($type, $format);
        $helperText = __('tbtop-cms::settings.notifications.helpers.template', [
            'variables' => Fields::payloadVariables($type),
        ]);

        return [
            $s->text(Fields::subjectField($type->key(), $format, $locale))
                ->label(__('tbtop-cms::settings.notifications.fields.subject') . " ({$locale})")
                ->rules('nullable|max:255'),
            $s->textarea(Fields::bodyField($type->key(), $format, $locale))
                ->label(__('tbtop-cms::settings.notifications.fields.body') . " ({$locale})")
                ->placeholder($placeholder)
                ->helperText($helperText)
                ->rules('nullable|string'),
        ];
    }

    private function resetTemplateAction(S $s, TypeDefinition $type, Format $format): ActionBuilder
    {
        return $s->action(Fields::resetActionName($type->key(), $format))
            ->label(__('tbtop-cms::settings.notifications.actions.reset_template'))
            ->confirm(__('tbtop-cms::settings.notifications.actions.reset_template_confirm'))
            ->handle(fn (ActionCtx $ctx): Effects => $this->resetTemplate($type, $format));
    }

    private function resetTemplate(TypeDefinition $type, Format $format): Effects
    {
        if (! $this->isOwner()) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.errors.owner_only'), 'error');
        }

        $locales = self::adminLocales();

        NotificationTemplate::query()
            ->where('type', $type->key())
            ->where('format', $format->value)
            ->delete();

        $cleared = [];
        foreach ($locales as $locale) {
            $cleared[Fields::subjectField($type->key(), $format, $locale)] = '';
            $cleared[Fields::bodyField($type->key(), $format, $locale)] = '';
        }

        return Effects::make()
            ->notify(__('tbtop-cms::settings.notifications.actions.template_reset'))
            ->setFormData($cleared);
    }

    private function sendTestEmailAction(S $s): ActionBuilder
    {
        return $s->action('sendTestEmail')->label(__('tbtop-cms::settings.notifications.actions.send_test_email'))
            ->handle($this->sendTestEmail(...));
    }

    private function sendTestEmail(ActionCtx $ctx): Effects
    {
        $settings = app(Settings::class);
        $recipients = Recipients::parse($settings->get('notifications.mail.recipients'));

        if ($recipients === []) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.test_email_no_recipients'), 'error');
        }

        try {
            app(CmsMailer::class)->send(new TestMail, $recipients);
        } catch (Throwable $e) {
            return Effects::make()->notify(
                __('tbtop-cms::settings.notifications.actions.test_email_failed', ['error' => $e->getMessage()]),
                'error',
            );
        }

        return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.test_email_sent'));
    }

    private function sendTestTelegramMessageAction(S $s): ActionBuilder
    {
        return $s->action('sendTestTelegramMessage')->label(__('tbtop-cms::settings.notifications.actions.send_test_telegram'))
            ->handle($this->sendTestTelegramMessage(...));
    }

    private function sendTestTelegramMessage(ActionCtx $ctx): Effects
    {
        $settings = app(Settings::class);
        $token = $this->telegramToken($settings);
        $chatIds = ChatIds::parse($settings->get('notifications.telegram.chat_ids'));

        if ($token === null) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_no_token'), 'error');
        }

        if ($chatIds === []) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_no_chat_ids'), 'error');
        }

        $client = app(TelegramClient::class);
        $errors = [];

        foreach ($chatIds as $chatId) {
            try {
                $client->sendMessage($token, $chatId, __('tbtop-cms::notifications.telegram.test_message'));
            } catch (TelegramApiException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            return Effects::make()->notify(
                __('tbtop-cms::settings.notifications.actions.test_telegram_failed', ['error' => implode(' | ', $errors)]),
                'error',
            );
        }

        return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.test_telegram_sent'));
    }

    private function createTelegramInviteAction(S $s): ActionBuilder
    {
        return $s->action('createTelegramInvite')->label(__('tbtop-cms::settings.notifications.actions.create_telegram_invite'))
            ->handle($this->createTelegramInvite(...));
    }

    private function createTelegramInvite(ActionCtx $ctx): Effects
    {
        $token = $this->telegramToken(app(Settings::class));

        if ($token === null) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_no_token'), 'error');
        }

        try {
            $invite = app(TelegramInvites::class)->create($token);
        } catch (TelegramApiException $e) {
            return Effects::make()->notify(
                __('tbtop-cms::settings.notifications.actions.telegram_invite_failed', ['error' => $e->getMessage()]),
                'error',
            );
        }

        return Effects::make()
            ->notify(__('tbtop-cms::settings.notifications.actions.telegram_invite_created', ['link' => $invite['deepLink']]))
            ->copyToClipboard($invite['deepLink']);
    }

    private function checkTelegramInviteAction(S $s): ActionBuilder
    {
        return $s->action('checkTelegramInvite')->label(__('tbtop-cms::settings.notifications.actions.check_telegram_invite'))
            ->handle($this->checkTelegramInvite(...));
    }

    private function checkTelegramInvite(ActionCtx $ctx): Effects
    {
        $token = $this->telegramToken(app(Settings::class));

        if ($token === null) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_no_token'), 'error');
        }

        $result = app(TelegramInvites::class)->checkCurrent($token);

        return match ($result['status']) {
            'found' => $this->appendChatIdAndNotify($result['chatId'], $result['chatTitle']),
            'expired' => Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_invite_expired'), 'error'),
            'error' => Effects::make()->notify(
                __('tbtop-cms::settings.notifications.actions.telegram_invite_check_failed', ['error' => $result['error'] ?? '']),
                'error',
            ),
            default => Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_invite_pending')),
        };
    }

    private function appendChatIdAndNotify(string $chatId, string $chatTitle): Effects
    {
        $settings = app(Settings::class);
        $updated = ChatIds::append($settings->get('notifications.telegram.chat_ids'), $chatId, $chatTitle);
        $settings->set('notifications.telegram.chat_ids', $updated);

        return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_invite_claimed', [
            'chat' => $chatTitle,
            'id' => $chatId,
        ]));
    }

    private const array TAG_FIELDS = ['notification_mail_recipients', 'notification_telegram_chat_ids'];

    /**
     * Recipients and chat ids are stored as one separator-joined string
     * (Recipients/ChatIds parse it at send time) but edited as tag chips —
     * one chip per entry, a labeled chat id ("123 # Dima") staying one chip.
     * Owner-only template subject/body values come from NotificationTemplate
     * rows (empty string when no row) — never part of map(), so they need
     * merging in here rather than through SettingsForm::record().
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    #[\Override]
    protected function presentRecord(array $record): array
    {
        foreach (self::TAG_FIELDS as $field) {
            $raw = $record[$field] ?? null;
            $pieces = is_string($raw) ? (preg_split('/[,;\r\n]+/', $raw) ?: []) : [];
            $record[$field] = array_values(array_filter(
                array_map(static fn (string $piece): string => trim($piece), $pieces),
                static fn (string $piece): bool => $piece !== '',
            ));
        }

        if ($this->isOwner()) {
            $record = [...$record, ...$this->templateRecordValues()];
        }

        return $record;
    }

    /** @return array<string, string> */
    private function templateRecordValues(): array
    {
        $map = Fields::fieldMap(app(NotificationRegistry::class), self::adminLocales());

        $rows = NotificationTemplate::query()->get()->keyBy(
            static fn (NotificationTemplate $row): string => $row->type . '|' . $row->format->value . '|' . $row->locale,
        );

        $values = [];
        foreach ($map as $fieldName => [$typeKey, $format, $locale, $part]) {
            $row = $rows->get("{$typeKey}|{$format->value}|{$locale}");
            $values[$fieldName] = $row === null ? '' : (string) $row->{$part};
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    #[\Override]
    protected function normalizeForm(array $form): array
    {
        foreach (self::TAG_FIELDS as $field) {
            if (array_key_exists($field, $form) && is_array($form[$field])) {
                $form[$field] = implode("\n", array_map(strval(...), $form[$field]));
            }
        }

        foreach (app(NotificationRegistry::class)->types() as $type) {
            $field = Fields::channelsField($type->key());
            if (! array_key_exists($field, $form) || ! is_array($form[$field])) {
                $form[$field] = [];
            }
        }

        return $form;
    }

    /**
     * Owner-only: every non-empty posted template body must render against a
     * sample payload before anything is persisted. A non-owner's tpl__*
     * fields are never read here — they are dropped entirely.
     *
     * @param  array<string, mixed>  $form
     */
    #[\Override]
    protected function validateForm(array $form): void
    {
        if (! $this->isOwner()) {
            return;
        }

        foreach ($this->templatePairs($form) as [$typeKey, $format, $locale, $subjectField, $bodyField, $subject, $body]) {
            if (trim($body) === '') {
                continue;
            }

            $type = app(NotificationRegistry::class)->type($typeKey);

            try {
                app(TemplateProbe::class)->check($type->payloadClass(), DatabaseTemplateStore::compose($subject, $body), $locale);
            } catch (InvalidTemplate $e) {
                throw ValidationException::withMessages([
                    $bodyField => __('tbtop-cms::settings.notifications.errors.template_invalid', ['error' => $e->getMessage()]),
                ]);
            }
        }
    }

    /**
     * Owner-only: persists a row for every non-empty posted body, deletes the
     * row for every emptied one. Runs after SettingsForm::apply() so the
     * channel toggles are already saved.
     *
     * @param  array<string, mixed>  $form
     */
    #[\Override]
    protected function afterSave(array $form): void
    {
        if (! $this->isOwner()) {
            return;
        }

        foreach ($this->templatePairs($form) as [$typeKey, $format, $locale, , , $subject, $body]) {
            $trimmedBody = trim($body);

            if ($trimmedBody === '') {
                NotificationTemplate::query()
                    ->where('type', $typeKey)->where('format', $format->value)->where('locale', $locale)
                    ->delete();

                continue;
            }

            NotificationTemplate::query()->updateOrCreate(
                ['type' => $typeKey, 'format' => $format->value, 'locale' => $locale],
                ['subject' => trim($subject), 'body' => $body],
            );
        }
    }

    /**
     * Every (type, format, locale) pair present in the current registry,
     * paired with the posted subject/body — '' when the form omitted them
     * (a non-owner's POST, or a locale/format the client never rendered).
     *
     * @param  array<string, mixed>  $form
     * @return list<array{0: string, 1: Format, 2: string, 3: string, 4: string, 5: string, 6: string}>
     */
    private function templatePairs(array $form): array
    {
        $map = Fields::fieldMap(app(NotificationRegistry::class), self::adminLocales());

        $pairs = [];
        $seen = [];
        foreach ($map as $fieldName => [$typeKey, $format, $locale, $part]) {
            $key = "{$typeKey}|{$format->value}|{$locale}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $subjectField = Fields::subjectField($typeKey, $format, $locale);
            $bodyField = Fields::bodyField($typeKey, $format, $locale);
            $subject = self::stringField($form, $subjectField);
            $body = self::stringField($form, $bodyField);

            $pairs[] = [$typeKey, $format, $locale, $subjectField, $bodyField, $subject, $body];
        }

        return $pairs;
    }

    /** @param  array<string, mixed>  $form */
    private static function stringField(array $form, string $field): string
    {
        $value = $form[$field] ?? '';

        return is_string($value) ? $value : '';
    }

    private function isOwner(): bool
    {
        $userId = auth()->guard(CurrentAdmin::guard())->id();

        return $userId !== null && AdminAccess::isOwner($userId);
    }

    /**
     * Content locales to offer a template editor for.
     *
     * @return list<string>
     */
    private static function adminLocales(): array
    {
        return array_column(app(Languages::class)->adminActive(), 'locale');
    }

    private function telegramToken(Settings $settings): ?string
    {
        $token = $settings->get('notifications.telegram.bot_token');

        return is_string($token) && trim($token) !== '' ? $token : null;
    }
}
