# tbtop/cms

This context covers the notification system: registered message types, their delivery channels, and the merchant-editable templates that render them.

## Language

**Notification type**:
A registered kind of message with a payload, one Blade template per rendering format, the admin/customer audiences allowed to receive it, and a default channel list. `form.submission` and `order.placed` are examples.
_Avoid_: "event", "alert".

**Channel**:
A delivery route a notification type can go out on — mail, telegram, bell.
_Avoid_: "transport", "provider".

**Format**:
The rendering shape a channel consumes: html, text, or short. A channel reads exactly one format; a type registers a template per format it supports.

**Channel list**:
The merchant's per-type set of enabled channels, stored in Settings and read through the registry's default when unset.
_Avoid_: "flags", "toggles" for the stored concept.

**Template override**:
A merchant-written Blade template — subject and body — stored per type, format and locale, that replaces the built-in view for that combination. An empty override means the built-in template is used; a broken override falls back to the built-in template at send time.
_Avoid_: "custom template", "theme".

**Built-in template**:
The package- or host-shipped Blade view a notification type registers for a format. What a template override replaces, and what it falls back to when absent or broken.

**Owner**:
The admin marked `is_owner`, the only admin permitted to edit template overrides, since a template runs as real Blade. The owner also manages other admins.
_Avoid_: "superadmin", "root".

**Admin manager**:
The admin who may edit and revoke other admins' accounts: the owner, plus, when roles are on, holders of the host gate `tbtop-cms.admins`. Other admins edit only themselves and users without CMS access. Holding the gate does not allow editing template overrides.
_Avoid_: "superadmin", "root".
