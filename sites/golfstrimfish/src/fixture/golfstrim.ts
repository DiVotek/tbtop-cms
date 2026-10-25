import type { Fixture } from "@tbtop/cms";

const aboutBody = `Наша місія — впливати на добробут та здоров'я нації шляхом забезпечення людей високоякісною, натуральною, екологічно чистою продукцією із риби та морепродуктів, виготовленою за інноваційними та класичними технологіями, а також всіляко підтримувати і допомагати нашому суспільству.

За 20 років ми перетворилися з невеликого сімейного бізнесу в успішне підприємство, в якому дружний колектив однодумців втілює головну мету: забезпечити ринок України свіжою та натуральною продукцією з риби високої якості.

В асортименті продукції ТМ «Гольфстрім» представлена риба, виловлена в різних частинах світу. Сьогодні ПВТП «Гольфстрім» пропонує понад триста найменувань риби і рибної продукції: гарячого та холодного копчення, в'ялена та солона риба, рибні пресерви, морські делікатеси та багато іншого.`;

type ProductSeed = { key: string; name: string; price: number; unit: string };

const coldSmoked: ProductSeed[] = [
	{ key: "p-bream-steak", name: "Лящ холодного копчення стейк", price: 440, unit: "1 кг" },
	{ key: "p-marlin", name: "Марлін холодного копчення балик", price: 1200, unit: "1 кг" },
	{ key: "p-blue-whiting", name: "Путасу холодного копчення", price: 195, unit: "1 кг" },
	{ key: "p-delicacy-roll", name: "Рулет делікатесний холодного копчення", price: 114, unit: "100 г" },
	{ key: "p-sprat", name: "Салака холодного копчення", price: 209, unit: "1 кг" },
	{ key: "p-salmon-belly", name: "Черевця лосося холодного копчення", price: 320, unit: "1 кг" },
	{ key: "p-pink-salmon", name: "Горбуша без голови холодного копчення", price: 640, unit: "1 кг" },
	{ key: "p-squid", name: "Кальмар філе холодного копчення", price: 530, unit: "1 кг" },
	{ key: "p-crucian", name: "Карась холодного копчення", price: 205, unit: "1 кг" },
	{ key: "p-sea-bream", name: "Лящ морський без голови холодного копчення", price: 340, unit: "1 кг" },
	{ key: "p-bream-plast", name: "Лящ пласт холодного копчення", price: 370, unit: "1 кг" },
	{ key: "p-capelin", name: "Масляна філе холодного копчення", price: 1020, unit: "1 кг" },
];

const hotSmoked: ProductSeed[] = [
	{ key: "p-mackerel-hot", name: "Скумбрія гарячого копчення", price: 280, unit: "1 кг" },
	{ key: "p-trout-hot", name: "Форель гарячого копчення", price: 520, unit: "1 кг" },
];

const dried: ProductSeed[] = [
	{ key: "p-vobla", name: "Вобла в'ялена", price: 165, unit: "1 кг" },
	{ key: "p-roach", name: "Плотва в'ялена", price: 145, unit: "1 кг" },
];

const preserves: ProductSeed[] = [
	{ key: "p-sprats-oil", name: "Шпроти в олії", price: 95, unit: "160 г" },
	{ key: "p-sardine", name: "Сардина атлантична в олії", price: 88, unit: "240 г" },
];

function productPage(seed: ProductSeed) {
	return {
		key: seed.key,
		type: "article" as const,
		subtype: "product" as const,
		name: { uk: seed.name },
		summary: { uk: `${seed.price} ₴ за ${seed.unit}` },
		fields: { price: seed.price, unit: seed.unit },
		blocks: [{ kind: "ProductDetail", data: { note: { uk: "Ціни орієнтовні. Уточнюйте наявність у менеджера." } } }],
	};
}

export const golfstrimFixture: Fixture = {
	languages: [{ locale: "uk", name: "Українська", ogLocale: "uk_UA", default: true }],
	settings: {
		"site.name": "Гольфстрім",
		"site.contacts.phones": [{ number: "+380 67 007 42 42" }],
		"site.contacts.emails": [{ address: "info@golfstrimfish.test" }],
		"site.contacts.socials": [],
		"seo.title_template": { uk: "{title} {sep} {site_name}" },
		"seo.default_description": {
			uk: "Риба та морепродукти високої якості: копчення, в'ялення, пресерви. Доставка по Україні з 2004 року.",
		},
		"seo.identity.type": "organization",
		"seo.identity.name": { uk: "ПВТП «Гольфстрім»" },
		"seo.identity.address.locality": { uk: "Дніпро" },
		"seo.identity.address.country": "UA",
	},
	sharedBlocks: [
		{
			key: "contact",
			name: "Contact form",
			kind: "ContactForm",
			data: {
				heading: { uk: "Залиште заявку" },
				submitLabel: { uk: "Надіслати" },
				successMessage: { uk: "Дякуємо! Ми зв'яжемося з вами найближчим часом." },
			},
		},
	],
	layout: {
		header: [
			{
				kind: "SiteNav",
				data: {
					siteName: "Гольфстрім",
					tagline: { uk: "Риба & морепродукти" },
					links: [
						{ label: { uk: "Продукція" }, href: "/catalog" },
						{ label: { uk: "Виробництво" }, href: "/nase-virobnictvo" },
						{ label: { uk: "Співробітництво" }, href: "/spivrobitnictvo" },
						{ label: { uk: "Контакти" }, href: "/iak-nas-znaiti" },
					],
				},
			},
		],
		footer: [
			{
				kind: "SiteFooter",
				data: {
					siteName: "Гольфстрім",
					phone: "+380 67 007 42 42",
					email: "info@golfstrimfish.test",
					note: { uk: "Пропонуємо висококласну рибу і морепродукти з 2004 року." },
					links: [
						{ label: { uk: "Продукція" }, href: "/catalog" },
						{ label: { uk: "Контакти" }, href: "/iak-nas-znaiti" },
					],
				},
			},
		],
	},
	pages: [
		{
			key: "home",
			system: true,
			name: { uk: "Головна" },
			title: { uk: "Якість та смак назустріч до Вас" },
			summary: { uk: "Риба та морепродукти TM «Гольфстрім» — понад 300 найменувань." },
			blocks: [
				{
					kind: "Hero",
					data: {
						title: { uk: "Якість та смак назустріч до Вас" },
						subtitle: { uk: "Натуральна риба та морепродукти власного виробництва з 2004 року" },
						ctaLabel: { uk: "Каталог продукції" },
						ctaHref: "/catalog",
					},
				},
				{
					kind: "CategoryCards",
					data: {
						heading: { uk: "Наша продукція" },
						items: [
							{ title: { uk: "В'ялена риба" }, image: null, link: { type: "page", page: "cat-dried", label: { uk: "В'ялена риба" } } },
							{ title: { uk: "Гаряче копчення" }, image: null, link: { type: "page", page: "cat-hot", label: { uk: "Гаряче копчення" } } },
							{ title: { uk: "Холодне копчення" }, image: null, link: { type: "page", page: "cat-cold", label: { uk: "Холодне копчення" } } },
							{ title: { uk: "Пресерви" }, image: null, link: { type: "page", page: "cat-preserves", label: { uk: "Пресерви" } } },
						],
					},
				},
				{ kind: "StatsStrip", data: { items: [{ value: "300+", label: { uk: "Найменувань продукції" } }, { value: "1500", label: { uk: "Постійних клієнтів" } }, { value: "20", label: { uk: "Років на ринку" } }] } },
				{ kind: "AboutSection", data: { heading: { uk: "Про нас" }, body: { uk: aboutBody } } },
			],
		},
		{
			key: "catalog",
			type: "category",
			name: { uk: "Продукція" },
			slug: { uk: "catalog" },
			summary: { uk: "Каталог риби та морепродуктів TM «Гольфстрім»." },
			blocks: [
				{
					kind: "ProductGrid",
					data: {
						heading: { uk: "Уся продукція" },
						products: [],
					},
				},
			],
			children: [
				{
					key: "cat-cold",
					type: "category",
					name: { uk: "Холодне копчення" },
					slug: { uk: "holodne-kopchennya" },
					blocks: [{ kind: "ProductGrid", data: { heading: { uk: "Холодне копчення" }, products: [] } }],
					children: coldSmoked.map(productPage),
				},
				{
					key: "cat-hot",
					type: "category",
					name: { uk: "Гаряче копчення" },
					slug: { uk: "garyache-kopchennya" },
					blocks: [{ kind: "ProductGrid", data: { heading: { uk: "Гаряче копчення" }, products: [] } }],
					children: hotSmoked.map(productPage),
				},
				{
					key: "cat-dried",
					type: "category",
					name: { uk: "В'ялена риба" },
					slug: { uk: "vyalena-ryba" },
					blocks: [{ kind: "ProductGrid", data: { heading: { uk: "В'ялена риба" }, products: [] } }],
					children: dried.map(productPage),
				},
				{
					key: "cat-preserves",
					type: "category",
					name: { uk: "Пресерви" },
					slug: { uk: "preservy" },
					blocks: [{ kind: "ProductGrid", data: { heading: { uk: "Пресерви" }, products: [] } }],
					children: preserves.map(productPage),
				},
			],
		},
		{
			key: "production",
			name: { uk: "Наше виробництво" },
			slug: { uk: "nase-virobnictvo" },
			summary: { uk: "HACCP, авторські технології копчення та в'ялення." },
			blocks: [
				{
					kind: "Hero",
					data: {
						title: { uk: "Наше виробництво" },
						subtitle: { uk: "Сьогодні ми випускаємо понад 150 найменувань продукції власного виробництва" },
					},
				},
				{
					kind: "FeatureGrid",
					data: {
						heading: { uk: "Наші переваги" },
						items: [
							{ title: { uk: "Сертифікація HACCP" }, text: { uk: "Контроль якості на кожному етапі." } },
							{ title: { uk: "Доставка по Україні" }, text: { uk: "Власний спеціалізований транспорт." } },
							{ title: { uk: "Фірмова торгівля" }, text: { uk: "Мережа магазинів у Дніпрі та області." } },
							{ title: { uk: "Авторське в'ялення" }, text: { uk: "Власна інженерна розробка камер." } },
							{ title: { uk: "Унікальне копчення" }, text: { uk: "Вільхова тріска, камери TERMIX." } },
							{ title: { uk: "Якісна сировина" }, text: { uk: "Риба з перевірених джерел." } },
						],
					},
				},
				{
					kind: "ProcessSteps",
					data: {
						heading: { uk: "Технологічний процес" },
						steps: [
							{ title: { uk: "Дефростація" }, text: { uk: "Природним шляхом контрольованим потоком повітря." } },
							{ title: { uk: "Посол" }, text: { uk: "Класичні та авторські технології головного технолога." } },
							{ title: { uk: "Копчення" }, text: { uk: "На вільховій трісці в автоматизованих камерах TERMIX." } },
							{ title: { uk: "В'ялення" }, text: { uk: "Камери закритого типу з комп'ютерним управлінням." } },
							{ title: { uk: "Кулінарія та пресерви" }, text: { uk: "Продукція для витончених смакових уподобань." } },
							{ title: { uk: "Доставка" }, text: { uk: "Спеціалізованим власним транспортом." } },
						],
					},
				},
			],
		},
		{
			key: "cooperation",
			name: { uk: "Співробітництво" },
			slug: { uk: "spivrobitnictvo" },
			blocks: [
				{
					kind: "Hero",
					data: { title: { uk: "Співробітництво" }, subtitle: { uk: "Компанія «Гольфстрім» завжди відкрита до співпраці!" } },
				},
				{
					kind: "CoopBenefits",
					data: {
						heading: { uk: "З нами Ви отримаєте" },
						items: [
							{ text: { uk: "Гарантію високої якості товару" } },
							{ text: { uk: "Наявність сертифікатів та необхідних документів" } },
							{ text: { uk: "Індивідуальні комерційні умови" } },
							{ text: { uk: "Доставку фірмовим транспортом" } },
							{ text: { uk: "Багатосторонню підтримку" } },
						],
						body: {
							uk: "У нас працюють висококваліфіковані менеджери і технологи. Власний парк спеціально обладнаного автотransportу дозволяє доставляти продукцію в усі куточки України.",
						},
					},
				},
				{ kind: "ContactForm", shared: "contact" },
			],
		},
		{
			key: "contacts",
			name: { uk: "Як нас знайти" },
			slug: { uk: "iak-nas-znaiti" },
			seo: { schema: { type: "ContactPage" } },
			blocks: [
				{
					kind: "Hero",
					data: {
						title: { uk: "Як нас знайти" },
						subtitle: { uk: "Пропонуємо висококласну рибу і морепродукти з 2004 року" },
					},
				},
				{
					kind: "StoreList",
					data: {
						heading: { uk: "Фірмова торгівля GOLFSTRIM РИБА & МОРЕПРОДУКТИ" },
						locations: [
							{ city: { uk: "Дніпро" }, address: { uk: "вул. Журналістів, 13 К (головний офіс)" } },
							{ city: { uk: "Дніпро" }, address: { uk: "вул. Метробудівська, 10 (навпроти м-ну АТБ)" } },
							{ city: { uk: "Дніпро" }, address: { uk: "бул. Слави, 3 М (ж/м Перемога)" } },
							{ city: { uk: "Кам'янське" }, address: { uk: "бульвар Будівельників, 37 Р (лівий берег)" } },
							{ city: { uk: "Дніпро" }, address: { uk: "вул. Калинова, 76 (ринок Образцова)" } },
							{ city: { uk: "Дніпро" }, address: { uk: "вул. Новокримська, 4 К" } },
							{ city: { uk: "Дніпро" }, address: { uk: "бул. Слави, 43 (ж/м Сокіл-2)" } },
							{ city: { uk: "Дніпро" }, address: { uk: "вул. Широка, 43 Б (навпроти АТБ)" } },
							{ city: { uk: "Дніпро" }, address: { uk: "пров. Вільний, 2 А" } },
							{ city: { uk: "Дніпро" }, address: { uk: "вул. Інженерна, 3" } },
							{ city: { uk: "Дніпро" }, address: { uk: "вул. Гагаріна, 2 Е (Братське)" } },
							{ city: { uk: "Солоне" }, address: { uk: "вул. Героїв України, 26 Г" } },
							{ city: { uk: "Самар" }, address: { uk: "вул. Гетьманська, 35" } },
							{ city: { uk: "Кам'янське" }, address: { uk: "проспект Конституції, 17 А (правий берег)" } },
						],
					},
				},
				{ kind: "ContactForm", shared: "contact" },
			],
		},
	],
	redirects: [{ from: "kontakty", to: "iak-nas-znaiti" }],
};
