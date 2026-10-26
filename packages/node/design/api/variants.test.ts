process.env.BETTER_AUTH_URL ??= "http://site.test";
process.env.BETTER_AUTH_SECRET ??= "design-secret-design-secret-design";
process.env.TBTOP_ADMIN_EMAIL ??= "admin@divotek.test";
process.env.TBTOP_ADMIN_PASSWORD ??= "correct-horse-battery";

import { prove, proveWorker } from "./shared/prove";
import { boot as v1 } from "./v1-compose/node";
import v1Worker from "./v1-compose/worker";

prove("V1 · composition · Node", v1);
proveWorker("V1 · composition · Workers", v1Worker);
import { boot as v2 } from "./v2-config/node";
import v2Worker from "./v2-config/worker";

prove("V2 · one config · Node", v2);
proveWorker("V2 · one config · Workers", v2Worker);
import { boot as v3 } from "./v3-plugins/node";
import v3Worker from "./v3-plugins/worker";

prove("V3 · plugins · Node", v3);
proveWorker("V3 · plugins · Workers", v3Worker);
import { boot as v4 } from "./v4-builder/node";
import v4Worker from "./v4-builder/worker";

prove("V4 · builder · Node", v4);
proveWorker("V4 · builder · Workers", v4Worker);
import { boot as v5 } from "./v5-zero/node";
import v5Worker from "./v5-zero/worker";

prove("V5 · zero glue · Node", v5);
proveWorker("V5 · zero glue · Workers", v5Worker);
