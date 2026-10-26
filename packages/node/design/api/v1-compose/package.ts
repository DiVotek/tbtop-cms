/**
 * V1, what the package adds (on top of what #333 exports): Q15 sugar only.
 * Every piece stays a separate factory the host composes, as Kysely and
 * Better Auth themselves are used. Would ship as:
 *   @tbtop/cms/better-auth  -> cmsBetterAuth(db, options)
 *   @tbtop/cms              -> cmsAdmin(cms, options), migrateAll
 *   @tbtop/cms/cloudflare   -> d1Kysely(env.DB), kvCache, r2Disk (building blocks, no entry)
 */
export { defaultAuth as cmsBetterAuth } from "../kit/auth";
export { createCmsAdmin as cmsAdmin, cmsRoutes, migrateAll } from "../kit/serve";
