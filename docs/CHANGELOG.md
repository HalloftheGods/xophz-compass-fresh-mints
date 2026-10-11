# Changelog

All notable changes to the Xophz Compass Fresh Mints plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [2026-10-10]

### Security
- **CRM webhook URL moved onto a Connector ([class-freshmints-crm-webhook-rest.php](file:///home/xopher/www/x/Xophz-COMPASS/wp-content/plugins/xophz-compass-fresh-mints/includes/crm/class-freshmints-crm-webhook-rest.php), #5961)**: The CRM webhook URL (a bearer credential) was stored in browser `localStorage` (`licensify_crm_webhook`) and POSTed to from the page. It is now the `crm_webhook_url` connector (Settings > Connectors; constant and env var `COMPASS_CRM_WEBHOOK_URL`), and the new administrator-only route `POST /xophz-freshmints/v1/crm/webhook` validates the event and leads, then makes the one outbound POST. Errors carry a code and the webhook's HTTP status, never the URL. The client deletes any `licensify_crm_webhook` value it finds on load. Anyone who pasted a CRM webhook URL into the old Settings field should treat it as exposed and rotate it.

## [2026-10-07]

### Fixed
- **Public Registry Data Integrity ([class-freshmints-api.php](file:///home/xopher/www/x/Xophz-COMPASS/wp-content/plugins/xophz-compass-fresh-mints/includes/class-freshmints-api.php))**: Removed fabricated PHP `uniqid()` license numbers and default city fallbacks in NY Socrata, NPPES, and FINRA branches. Incomplete records lacking valid license identifiers are now cleanly skipped, double "NY-NY-" prefixing is eliminated, and missing cities remain unpopulated instead of defaulting to New York City.

## [2026-09-18]

### Fixed
- **Dedicated Subdomain Routing ([class-xophz-compass-freshmints-public.php](file:///home/xopher/www/x/Xophz-COMPASS/wp-content/plugins/xophz-compass-fresh-mints/public/class-xophz-compass-freshmints-public.php))**: Added automatic detection for `freshmints.*` domains, dynamically resolving `load_mode` to `homepage` and mounting `appBase` to `/` so apex and subpath requests cleanly load the Fresh Mints dashboard.
- **Hook Priority Elevation ([xophz-compass-fresh-mints.php](file:///home/xopher/www/x/Xophz-COMPASS/wp-content/plugins/xophz-compass-fresh-mints/xophz-compass-fresh-mints.php))**: Adjusted `template_redirect` hook priority to 5 to ensure early routing resolution.
