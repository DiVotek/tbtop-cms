import { afterEach } from "bun:test";
import { GlobalRegistrator } from "@happy-dom/global-registrator";
import { cleanup } from "@testing-library/react";

GlobalRegistrator.register({ url: "http://localhost/" });

afterEach(() => cleanup());
