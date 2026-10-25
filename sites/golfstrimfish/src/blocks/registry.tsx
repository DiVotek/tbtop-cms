import { defineRegistry } from "@json-render/react";
import type { PropsOf } from "@tbtop/cms/catalog";
import { cmsCatalog, cmsComponents } from "@tbtop/cms/react";
import { Hero } from "./ui/Hero";
import { catalog as blocks } from "./catalog";
import { AboutSection } from "./ui/AboutSection";
import { CategoryCards } from "./ui/CategoryCards";
import { CoopBenefits } from "./ui/CoopBenefits";
import { FeatureGrid } from "./ui/FeatureGrid";
import { ProcessSteps } from "./ui/ProcessSteps";
import { ProductDetail } from "./ui/ProductDetail";
import { ProductGrid } from "./ui/ProductGrid";
import { SiteFooter } from "./ui/SiteFooter";
import { SiteNav } from "./ui/SiteNav";
import { StatsStrip } from "./ui/StatsStrip";
import { StoreList } from "./ui/StoreList";

export const catalog = cmsCatalog(blocks);

export const { registry } = defineRegistry(catalog, {
	components: {
		...cmsComponents,
		Hero,
		SiteNav,
		SiteFooter,
		CategoryCards,
		StatsStrip,
		AboutSection,
		FeatureGrid,
		ProcessSteps,
		ProductGrid,
		ProductDetail,
		StoreList,
		CoopBenefits,
	},
});

export type BlockProps<K extends keyof typeof blocks> = { props: PropsOf<typeof blocks, K> };
