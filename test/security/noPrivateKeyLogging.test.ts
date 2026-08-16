import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

/** F1.1 metric: 0 occurrences of private key material in logs (grep in repo). */
function listTsFiles(dir: string): string[] {
  if (!existsSync(dir)) return [];
  const out: string[] = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const stat = statSync(full);
    if (stat.isDirectory()) {
      out.push(...listTsFiles(full));
    } else if (entry.endsWith(".ts")) {
      out.push(full);
    }
  }
  return out;
}

describe("security: private keys are never logged", () => {
  it("finds no console.* call whose arguments mention privateKey", () => {
    const files = [...listTsFiles(join(process.cwd(), "src")), ...listTsFiles(join(process.cwd(), "cli"))];
    const offenders: string[] = [];

    const consoleCallWithPrivateKeyRe = /console\.\w+\([^)]*privateKey[^)]*\)/i;

    for (const file of files) {
      const content = readFileSync(file, "utf8");
      if (consoleCallWithPrivateKeyRe.test(content)) {
        offenders.push(file);
      }
    }

    expect(offenders).toEqual([]);
  });
});
