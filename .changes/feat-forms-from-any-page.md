# feat(forms): HasForm makes a form reachable by name from any page

touches: form submit route accepts a form name; new `Cms::forms()`

A `HandlesFormSubmission` block that uses the new `Tbtop\Cms\Form\HasForm`
trait registers its form by name in the `Forms` singleton
(`Cms::forms()`). A page type template renders it from
`Cms::forms()->props('ServiceForm', $page)` (`action` + `honeypotField`) and
posts to the usual `tbtop-cms.form.submit` route with the name in the
`{block}` segment. `FormBlockLocator` still tries a placed block row of that
id first, then the name, so the hidden footer block a template form needed
until now can go. A name-addressed submit runs with empty `$blockData` and is
stored with `block_id` = the name, which admin retries resolve the same way.
`ContactForm` uses the trait. A block without it is not reachable by kind.
