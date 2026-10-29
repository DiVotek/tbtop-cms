# notifications: a template may read a submitted field by its key

touches: the notification template editor's save check (`TemplateProbe`)

Saving an override such as `{{ $fields['phone'] }}` for `form.submission` was
refused with "Undefined array key": the save-time check renders against a stub
payload whose arrays are empty. The check now lets a missing array key through
and keeps rendering the rest of the template, so an undefined variable or a
broken directive after it is still refused.
