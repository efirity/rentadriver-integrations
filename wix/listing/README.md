# Wix App Market listing images

Created 11 September 2026 from the RentADriver source.

Five upload-ready PNG images, each **1600 × 1200 px (4:3)**. Use `01-main.png` as the main image and `02`–`05` as additional images, in order. `contact-sheet.png` is a review preview, not a listing upload.

| File | Feature |
| --- | --- |
| 01-main.png | Brand and delivery dashboard |
| 02-orders.png | Order management and delivery status |
| 03-delivery-settings.png | Delivery radius, preparation time and customer pricing |
| 04-pickup.png | Automatic booking, pickup details and proof settings |
| 05-mobile.png | Responsive order dashboard |

These are real screenshots of the Wix merchant UI running against the existing local in-memory Wix preview. All stores, orders, balances and customer names are sample data; no production requests, payments, messages or bookings were made. Sandbox notices remain visible. The mobile frame is an illustrative browser presentation of the responsive web app, not a native mobile app screenshot. No Wix admin chrome or checkout screen was fabricated.

The screenshots use the existing app styles. Only Next.js development tooling was hidden. The outer compositions use the existing RentADriver logo, Sora font, blue background and short benefit captions. Source screenshots and self-contained HTML compositions are included; `index.html` previews the set.

Requirements checked against [Wix Add Your Media](https://dev.wix.com/docs/build-apps/launch-your-app/market-listing/add-your-media#additional-images): 5–6 images recommended, PNG/JPG, minimum 1200 × 900, consistent solid background, actual app features and a mobile view. This set does not imply App Market approval or completion of the production-readiness checklist.

To regenerate on macOS with dependencies, Node 22+, Bun and Chrome installed:

1. Start the local Wix preview on the capture ports: `WIX_API_PORT=8931 WIX_WEB_PORT=3431 pnpm wix:dev` (Bun must be on PATH).
2. In a separate terminal, run `node capture.mjs` from this folder, with `BRAND_DIR` pointing at a folder containing
   `mark-reversed.svg` and `fonts/Sora-SemiBold.ttf` (defaults to this folder). Port 9491 must be unused.
3. Stop the local preview afterward.

`rentadriver-wix-listing-images.zip` contains only the five numbered PNG files, ready to extract and upload under Wix App Profile → Media.
