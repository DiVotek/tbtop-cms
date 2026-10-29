/**
 * @tbtop/cms/admin-client: the CMS field clients for the admin bundle
 * (@tbtop/inertia-admin). Importing this entry registers them
 * (`defineFieldClient` adds the kind to the admin's block registry).
 */
export { CollectionCell, CollectionForm, collectionFieldClient } from "./collection/collectionField";
export type { CollectionOptions, CollectionValue, PickMode } from "./collection/types";
export { LinkCell, LinkForm, linkFieldClient } from "./link/linkField";
export type { LinkOptions, LinkType, LinkValue } from "./link/types";
