# fix(pages): the type/subtype pair holds for every writer; unknown schema keys no longer 500

touches: page save (PageObserver), schema selection, SEO tab schema select, cms:doctor

> ⚠️ **Contract** A `Page` save that changes `type` or `subtype` throws a `DomainException` when `type` is a registered subtype, or when `subtype` is not a registered type whose `base()` is `type` — for seeders and host code too, not only the admin form. A save that leaves both untouched is not checked.
> ⚠️ **Contract** `SchemaSelector` skips `schemaTypes()` keys that `SchemaTypeRegistry` does not know (new `availableKeys()`); the page publishes the next known key, or `WebPage`. It used to throw, which made the admin page edit and the site render 500.

"`type` is a base, `subtype` belongs to it" was only checked by the admin
form. Rows written before, or past Eloquent, are not migrated: `cms:doctor`
lists them under `page_type_pairs`. A subtype whose `base()` is unregistered
or itself a subtype is reported (`page_subtype_bases`) rather than thrown at
registry boot, which would take the site down for one misdeclared class.

New doctor warnings also cover unknown schema keys per type
(`unknown_schema_types`) and back-references named like one of the type's
base or subtype fields (`page_field_collisions`); the back-reference still
wins in `page.fields`.

Tests: `PageSubtypeTest` (observer, 3), `SubtypeSchemaAndDefaultsTest`
(unknown key: admin edit and site 200, select filtered, doctor line),
`tests/Health/PageTypeChecksTest.php` (four checks, warning and ok).
