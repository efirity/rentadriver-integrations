# Contributing

Thanks for looking at the RentADriver integrations. This repository is a **published mirror**: the source of truth is
the `apps/integrations` folder of RentADriver's private monorepo, where the API, the merchant apps and the deploy
pipeline live. Every change lands there first and is mirrored here after it has passed the monorepo's checks and
shipped. That shapes how contributions work.

## How the mirror works

1. A change is merged to the monorepo's `main` (it is reviewed, tested and deployed there).
2. A sync job cuts the `apps/integrations` folder out of that commit with `git subtree split` and pushes the result
   to this repository's `main` as a fast-forward. Commit messages and authorship are preserved; the paths lose the
   `apps/integrations/` prefix.
3. `main` here is protected: nobody merges into it directly, because a merge would make the next sync diverge.
   The sync is the only writer.

So `main` here always equals the folder as it was last shipped.

## Pull requests

Pull requests are welcome and are the normal way to propose a change:

1. Fork, branch, make the change, open a PR against `main`. CI runs on it (WordPress coding standards and the plugin
   tests for WooCommerce changes, manifest validation, a secret scan, a check that no driver-pay wording appears).
2. A maintainer reviews it here. If accepted, the maintainer imports the PR's commits into the monorepo
   (`git am --directory=apps/integrations` of the PR patch), where the full test suite and the deploy run.
3. After the next sync your commit appears on `main` here, with you as the author. The PR is closed with a link to
   the published commit (GitHub may not mark it "merged" because the mirrored commit has a different hash).

Please do not merge PRs with the GitHub button, even if you have the permission; it will be reverted by the next sync.

## Rules that apply to every change

- **No secrets, ever.** No API keys, tokens, passwords, store passwords, signed URLs or customer data, not even in
  examples. `.gitignore` lists the files that hold credentials locally; they must never be committed.
- **No driver pay.** Prices shown to merchants are fine; driver earnings, hourly rates, pay shares or advances are not
  published anywhere in this repository.
- **WooCommerce plugin versions move together.** The `Version:` header in `rentadriver-delivery.php`, `Stable tag`
  and the changelog in `readme.txt` must agree, and every change to the plugin bumps the version.
- **Listing copy** (titles, descriptions, feature lists) starts in `ecommerce-app-listings.md` and is then applied to
  the marketplace.

## Reporting issues

Open a GitHub issue. For security reports, e-mail security@rentadriver.ai instead of opening a public issue.
