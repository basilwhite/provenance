import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    environment: "node",
    include: ["test/**/*.test.ts"],
    coverage: {
      provider: "v8",
      reporter: ["text", "html", "lcov"],
      include: ["src/**/*.ts"],
      exclude: ["src/api/server.ts"],
      thresholds: {
        lines: 85,
        statements: 85,
      },
    },
    testTimeout: 15000,
    hookTimeout: 15000,
  },
});
