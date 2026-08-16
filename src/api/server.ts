import express from "express";
import type { NextFunction, Request, Response } from "express";
import { pathToFileURL } from "node:url";
import { getDefaultDb, type Db } from "../db/index.js";
import { createSubmitRouter } from "./routes/submit.js";
import { createAuditRouter } from "./routes/audit.js";
import { createBatchRouter } from "./routes/batch.js";
import { createVerifyRouter } from "./routes/verify.js";
import { createScoreRouter } from "./routes/score.js";
import { createKeysRouter } from "./routes/keys.js";
import { ApiError } from "./errors.js";

export function createApp(db: Db): express.Express {
  const app = express();
  app.use(express.json({ limit: "2mb" }));

  app.get("/health", (_req, res) => {
    res.json({ status: "ok" });
  });

  app.use(createSubmitRouter(db));
  app.use(createAuditRouter(db));
  app.use(createBatchRouter(db));
  app.use(createVerifyRouter(db));
  app.use(createScoreRouter(db));
  app.use(createKeysRouter(db));

  app.use((req, res) => {
    res.status(404).json({ error: { code: "not_found", message: `no route for ${req.method} ${req.path}` } });
  });

  app.use((err: unknown, _req: Request, res: Response, _next: NextFunction) => {
    if (err instanceof ApiError) {
      res.status(err.status).json({ error: { code: err.code, message: err.message } });
      return;
    }
    if (err instanceof SyntaxError && "body" in err) {
      res.status(400).json({ error: { code: "invalid_json", message: "request body is not valid JSON" } });
      return;
    }
    // eslint-disable-next-line no-console
    console.error(err);
    res.status(500).json({ error: { code: "internal_error", message: "unexpected server error" } });
  });

  return app;
}

function main(): void {
  const db = getDefaultDb();
  const app = createApp(db);
  const port = Number(process.env["PORT"] ?? 3000);
  app.listen(port, () => {
    // eslint-disable-next-line no-console
    console.log(`Provenance API listening on http://localhost:${port}`);
  });
}

const isMainModule =
  process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href;

if (isMainModule) {
  main();
}
