# Publishing the RentADriver n8n node

The package is prepared for an initial npm release; preparation does not mean it is published or verified.

## Validate

Use Node.js 24 and run `npm ci`, `npm test`, and `npm pack --dry-run`. The test command runs the unmodified official n8n Cloud lint configuration, builds the node and checks request validation, credential selection and redaction. No runtime dependencies are shipped.

Before a public release, validate OAuth consent, cancellation, refresh, revocation and sandbox preservation in the target n8n instance. Check event pagination and workflow retry handling as well as the action examples. Use sandbox credentials; validation-only booking creates nothing.

## Publish through GitHub Actions

The public source repository is `efirity/rentadriver-integrations`; this package lives in `n8n/`. The workflow is `.github/workflows/publish-n8n.yml` at the repository root.

1. Configure the GitHub environment `npm-n8n`, with a release reviewer if desired.
2. For an existing npm package, configure a Trusted Publisher: owner `efirity`, repository `rentadriver-integrations`, workflow `publish-n8n.yml`, environment `npm-n8n`.
3. For a first publication where npm cannot yet configure that package, store a short-lived granular npm publish token as the environment secret `NPM_TOKEN`. Configure Trusted Publishing after the initial release, then remove the token.
4. Run **Publish RentADriver n8n node** on `main` and enter the exact version from `package.json`.
5. The workflow checks the version, installs locked dependencies, runs the package tests and publishes with provenance. Verify the npm version, repository link and provenance before announcing availability.

Do not publish locally: n8n verification requires GitHub Actions provenance. Never include a token in source files or example workflows.

## Submit for verification

Once the package is published and runtime checks are complete, submit `n8n-nodes-rentadriver` through the [n8n Creator Portal](https://creators.n8n.io/nodes). Provide the public source repository, npm package and usage documentation. The package is MIT-licensed; the included brand mark has its own attribution in `NOTICE.md`.

Until verification succeeds, describe it as a public community package for self-hosted n8n. Only describe it as available in n8n Cloud after n8n verifies it.
