# feat(forms): label submitted fields in staff notifications

touches: submission notification mail (field list), new optional
`HasFieldLabels` block interface, `FormSubmissionPayload::$fieldLabels`

Staff notifications listed a submission's fields under their raw payload keys
(`name`, `message`). A form block can now implement the optional
`Tbtop\Cms\Block\HasFieldLabels` interface,
`fieldLabels(string $locale, array $input)`, and the mail's field list shows
each field under that label in the reader's admin UI locale, not the page's.
`ContactForm` implements it from the labels it already renders
(`tbtop-cms::forms.contact.fields.*`).

- A field the map leaves out, a blank label, or a block without the interface
  still shows the raw key; values are unchanged.
- `$input` is the submitting block's stored data flattened to the reader's
  locale, so a label kept in translatable block data reads in the admin's
  language; a form addressed by name gets `[]`. The block is looked up once
  per notification; flattening runs per admin locale.
- `HandlesFormSubmission` is untouched, so existing host forms compile as
  before.
- The payload's `$fields` keeps its raw keys (a custom template may index
  them); the labels ride in a new `$fieldLabels` variable keyed the same way.
  A customised mail template keeps showing raw keys until it reads
  `$fieldLabels`.

Tests: `tests/Form/SubmissionNotifierTest.php`.
