import { fileURLToPath, URL } from "node:url";
import tailwindcss from "@tailwindcss/vite";
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
        tailwindcss(),
    ],
    resolve: {
        dedupe: ["react", "react-dom", "@inertiajs/react"],
        alias: {
            "@cms": fileURLToPath(new URL("./resources/js/cms", import.meta.url)),
        },
    },
});
