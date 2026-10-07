import type { IncomingMessage, ServerResponse } from "node:http";

export const MAX_REQUEST_BYTES = 1024 * 1024;
export class RequestBodyError extends Error {
  constructor(readonly status: number, readonly code: number, message: string) { super(message); }
}

/** Bound both declared and streamed bodies before parsing or constructing an MCP server. */
export async function readRequestBody(req: IncomingMessage): Promise<unknown> {
  const declared = Number(req.headers["content-length"] ?? 0);
  if (declared > MAX_REQUEST_BYTES) throw new RequestBodyError(413, -32600, "Request body too large");
  const chunks: Buffer[] = [];
  let bytes = 0;
  await new Promise<void>((resolve, reject) => {
    const cleanup = () => {
      req.off("data", data); req.off("end", end); req.off("error", error); req.off("aborted", aborted);
    };
    const error = (cause: Error) => { cleanup(); reject(cause); };
    const aborted = () => error(new Error("Request aborted"));
    const end = () => { cleanup(); resolve(); };
    const data = (chunk: Buffer | string) => {
      const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
      bytes += buffer.length;
      if (bytes > MAX_REQUEST_BYTES) {
        req.pause();
        error(new RequestBodyError(413, -32600, "Request body too large"));
        return;
      }
      chunks.push(buffer);
    };
    req.on("data", data); req.on("end", end); req.on("error", error); req.on("aborted", aborted);
  });
  if (!bytes) return undefined;
  try { return JSON.parse(Buffer.concat(chunks).toString("utf8")); }
  catch { throw new RequestBodyError(400, -32700, "Invalid JSON"); }
}

/** Node does not consume rejected promises returned by a request listener. */
export async function containRequestErrors(res: ServerResponse, action: () => Promise<void>): Promise<void> {
  try { await action(); }
  catch (error) {
    if (res.destroyed) return;
    if (res.headersSent) { res.destroy(); return; }
    const known = error instanceof RequestBodyError;
    res.writeHead(known ? error.status : 500, { "content-type": "application/json", connection: "close" });
    res.end(JSON.stringify({ jsonrpc: "2.0", id: null, error: {
      code: known ? error.code : -32603, message: known ? error.message : "Internal server error",
    } }));
  }
}
