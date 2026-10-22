import { defineRegistry } from "@json-render/react";
import { cmsBlocks } from "@tbtop/cms/catalog";
import { cmsCatalog, cmsComponents } from "@tbtop/cms/react";

// The browser half of the block catalog: the shared zod props from
// @tbtop/cms/catalog plus the package's built-in components. A host adds its
// own kinds to both: cmsCatalog({ ...cmsBlocks, Faq }) and { ...cmsComponents, Faq }.
export const catalog = cmsCatalog(cmsBlocks);

export const { registry } = defineRegistry(catalog, { components: cmsComponents });
