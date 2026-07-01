import { fileURLToPath, URL } from "node:url";
import react from "@vitejs/plugin-react";
import laravel from "laravel-vite-plugin";
import { defineConfig } from "vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ["workbench/resources/js/app.tsx"],
            publicDirectory: "workbench/public",
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            "@cms": fileURLToPath(new URL("./resources/js/cms", import.meta.url)),
        },
    },
});
