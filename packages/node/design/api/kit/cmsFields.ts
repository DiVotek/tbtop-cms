import type { S } from "@tbtop/admin";
import { linkField, pagesField } from "../../../src";

/**
 * The CMS's own fields handed to a form callback next to `s`, so a block
 * file imports nothing from the server side: `form: (s, f) => [s.text("title"), f.link("cta")]`.
 * Today a block file imports `linkField` from `@tbtop/cms`, which pulls the
 * admin classes into any bundle that imports the file.
 */
export const cmsFields = { link: linkField, pages: pagesField };
export type CmsFields = typeof cmsFields;

/** A form callback with the CMS fields, turned into the `(s) => fields` the core takes. */
export type CmsForm = (s: S, f: CmsFields) => unknown[];
export const withCmsFields = (form: CmsForm) => (s: S) => form(s, cmsFields);
