# Xophz Compass Fresh Mints WordPress Plugin

Turnkey lead discovery, license registry audit, skip-tracing, and practice website launcher platform integrated with Questbook CRM and the WP Connectors API.

## Capabilities

* **Standalone Svelte 5 SPA Router:** Deployable at default `/fresh-mints`, custom slugs, or site front page.
* **WP Connectors API Gateway:** Secure Gemini AI cold email/SMS generation and live web search grounding without exposing API keys.
* **Credentials stay on the server:** every key and webhook URL is read through the WordPress Connectors API (Settings > Connectors) and used only by PHP. Nothing secret is stored in the browser, bundled, returned by a REST route or placed in a URL; Gemini and YouTube keys travel in the `x-goog-api-key` header.
* **Social dispatch webhook:** `POST /xophz-freshmints/v1/social/dispatch` relays a dispatched Social Radar reply to the Slack, Discord or Zapier URL held in the `social_dispatch_webhook_url` connector (constant and env var `COMPASS_SOCIAL_DISPATCH_WEBHOOK_URL`). With no URL configured it answers `freshmints_webhook_not_configured` and the app falls back to copy-and-paste.
* **Questbook CRM Sync:** 1-Click contact sync into WordPress CPT `questbook_contact` with full provenance tracking.
* **Dev Server Proxy:** Transparent HMR proxying to local Vite dev server on port 8091 during development.
