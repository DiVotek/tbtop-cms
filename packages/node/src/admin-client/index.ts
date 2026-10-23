/**
 * @tbtop/cms/admin-client: the CMS field clients for the admin bundle
 * (@tbtop/inertia-admin). Register them next to the admin's own:
 * `registerFieldClient(linkFieldClient)` or the host's field map.
 */
export { LinkCell, LinkForm, linkFieldClient } from "./link/linkField";
export type { LinkOptions, LinkType, LinkValue } from "./link/types";
