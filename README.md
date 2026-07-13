# VanillaStats

A lightweight, privacy-friendly analytics dashboard you can self-host anywhere.
No cookies. No external services. No third-party scripts. Just clean, simple insights for your site.

Built for personal projects, indie makers, and anyone who wants **real analytics without the bloat**.

---

## Features

| | |
|---|---|
| 📊 Page views & unique visitors | 🌍 Visitors by country (full country names) |
| ⏱️ Average visit duration | 🔗 Top referrers (including Direct) |
| 🚪 Bounce rate | 📄 Top pages |
| 🟢 Active visitors (live) | 🗓️ Date range selector (24h / 7d / 30d / 90d / 180d / 12 months) |
| 🖥️ Browsers, OS & Devices | 📤 CSV & JSON export |
| 🔍 Interactive filtering | 🔐 Password-protected dashboard |

### Interactive Filtering

Click any row in **Top Pages**, **Top Referrers**, or **Locations** to filter the entire dashboard — stats, charts, and all tables update instantly without a page reload. Filter chips appear at the top of each panel so you can see and remove active filters at a glance. Multiple filters stack across sections.

---

## Privacy First

- ❌ No cookies
- ❌ No fingerprinting
- ❌ No personal data stored
- ❌ No IP addresses stored (used only temporarily for country detection)
- ✅ Tracks page-level analytics only, not individual users

---

## Requirements

- PHP 7.4+ (8.x recommended)
- SQLite extension enabled (default on most PHP installs)
- Any shared hosting, VPS, or local server

No Composer. No Node. No build steps.

---

## Installation

1. Upload all files to your server
2. Open the dashboard URL in your browser
3. Log in with the default password (`admin`) and change it immediately
4. Copy the tracking snippet from the dashboard
5. Paste it before `</body>` on every page you want to track

That's it.

---

## Tracking Script

The dashboard shows your personalised snippet. It looks like this:

```html
<script src="https://yourdomain.com/tracker.js?v=1" data-site="YOUR_TOKEN" defer></script>
```

The script automatically tracks:
- Page views
- Visit duration
- Bounce rate
- Referrer source
- Country (via CDN/proxy headers when available)

It sends no cookies and stores no personal data.

---

## Dashboard

### Date Range
Select 24 hours, 7 days, 30 days, 90 days, 180 days or 12 months from the header. Switching range clears active filters.

### Filtering
Click any row in Top Pages, Top Referrers, or Locations to exclude it from all stats. The dashboard updates immediately — no reload. Click a filter chip to remove it, or click the greyed-out row again.

### Active Now
Shows visitors active in the last 5 minutes. Refreshes automatically every 30 seconds.

### Export
Download your data as CSV or JSON from the Export button. The export respects your currently selected date range.

---

## Site Token

A unique token is auto-generated on first run and stored in `.site_token`. It links pageviews to your site and is included in your tracking snippet automatically. You don't need to touch it — but keep it when updating.

---

## Bounce Rate

A bounce is a session with only one page view.

```
Bounce Rate = (single-page sessions / total sessions) × 100
```

Sessions are managed client-side and are fully privacy-safe.

---

## Country Detection

Country is detected automatically using CDN/proxy headers (Cloudflare, Vercel, etc.) when available. Falls back to PHP's geoip extension if installed. If detection isn't possible, the visit is recorded as **Unknown**.

Full country names are shown — no raw ISO codes.

---

## Database

- SQLite file (`analytics.db`), created automatically on first run
- No migrations or manual setup
- Back up by copying the file

---

## Security

- Dashboard is password-protected
- Change the default password immediately via the **Password** button in the header
- The `.password` and `.site_token` files should not be publicly accessible (the included `.htaccess` handles this on Apache)

---

## Updating

1. Keep your `analytics.db`, `.password`, and `.site_token` files
2. Replace everything else
3. If you modified `tracker.js`, bump the version to bust browser caches:

```html
<script src="tracker.js?v=2" defer></script>
```

---

## Limitations

This app is intentionally simple and focused:

- No user accounts 
- No event tracking or custom goals
- No heatmaps or session recordings
- No funnels or conversion tracking

It's designed to answer one question:

> *"Is my site being used, where from, and how much?"*

---

## Who It's For

- Personal websites & blogs
- Landing pages & indie projects
- Static sites
- Anyone who finds Google Analytics overkill
- Privacy-conscious developers a
