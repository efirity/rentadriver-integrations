# rentadriver-mcp as a container (stdio MCP server). Used by MCP directories such as Glama to build the server
# and list its tools. Quotes and coverage work without an account; set RENTADRIVER_API_KEY to book.
FROM node:22-slim
WORKDIR /app
COPY mcp/package.json ./
RUN npm install --no-audit --no-fund
COPY mcp/ ./
RUN npm run build && npm prune --omit=dev
ENTRYPOINT ["node", "dist/index.js"]
