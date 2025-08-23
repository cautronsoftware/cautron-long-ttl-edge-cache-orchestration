# Cautron Long TTL Edge Cache Orchestration
In today’s digital landscape, performance, reliability, and sustainability are paramount for businesses competing on a global scale. Traditional caching strategies frequently rely on uncontrolled purges or visitor-triggered refresh cycles, often leading to excessive origin load, inconsistent user experiences, and increased energy consumption across infrastructure. To address these challenges, Cautron Long TTL Edge Cache Orchestration has been designed as a professional, automated, and eco-conscious cache management framework optimized for Edge computing environments.

# Overview
This orchestration system combines Cloudflare Workers with WordPress scheduled tasks, ensuring cache updates are carried out predictably and efficiently. A daily purge everything operation at the Edge (via Cloudflare Worker) is followed by a controlled warmup routine executed through WordPress cron jobs. This guarantees up-to-date content is consistently served to all users, while minimizing stress on the origin server. HTML documents and new data are refreshed through purge-and-warm processes. Static assets (CSS, JS, images) leverage Cloudflare’s Cache Reserve, ensuring non-modified resources remain cached and are efficiently restored without repetitive origin requests.

# Sustainability Benefits
This system significantly reduces energy usage by eliminating unnecessary PHP and database invocations for each visitor. Instead, the warmup simulates organic human traffic in structured batches, preloading critical cache entries with controlled concurrency.

This results in:
	•	Lower origin server CPU usage.
	•	Faster global delivery.
	•	Alignment with green IT and energy-efficient design standards.

# Scalability & Compliance
Built with enterprise scalability in mind, this framework supports thousands of URLs and multilingual configurations, all while complying with Cloudflare and hosting provider policies. System parameters (batch size, concurrency, retry logic, and timeouts) can be fine-tuned to align with infrastructure capabilities, ensuring operational stability and avoiding overload.

# Why We Developed This

Modern websites often face a trade-off between speed and sustainability:
	•	Global Edge caching is essential for performance.
	•	Frequent edge cache purges harm speed and waste energy.
	•	Static assets rarely change, yet are constantly re-fetched from the origin.

Cautron’s two-step orchestration solves this:
	1.	Cloudflare Worker (03:00 UTC)
→ Executes a full purge at the Edge.
→ Thanks to Cache Reserve, static assets are retained on disk.
	2.	WordPress Cron (03:07 TR UTC)
→ Sequentially re-fetches all sitemap URLs, simulating natural traffic.
→ Static assets are restored from Cache Reserve.
→ Only updated HTML is re-downloaded from the origin.

Result:
	•	Visitors always receive fast, fresh content.
	•	Origin usage remains minimal.
	•	A more energy-efficient web experience is achieved.

Note: The time examples are based on Greenwich longitude. You can base your website's traffic times on the time of day or your local time around 3:00 AM. If your website is an e-commerce site with consistent global traffic, it's recommended to select 3:00 AM based on the location where it receives the most traffic. During the process, visitors to your site on the other side of Greenwich may experience a temporary slowdown, depending on the number of your site URLs, until the warm-up is complete due to cache purge.
 
⸻

⚙️ Cache Rules & TTL Strategy
	•	Edge TTL: 1 month
	•	Browser TTL:
	•	HTML: Respect origin
	•	JS/CSS/IMG: 1 year
	•	Daily renewal ensures freshness despite long TTL.
	•	Cache Reserve: Enabled ✅
	•	Static files are preserved and rehydrated from reserve.
	•	Tiered Cache ensures updated files are sync’d efficiently while unchanged files are reused, boosting sustainability.
 
⸻

 ⚠️ Legal Notice
	•	Do not trigger cache purge more than once per day.
	•	Avoid manual re-triggering of the cron job, just once in a day.
	•	Do not exceed recommended batch/concurrency values.
	•	Excessive or misconfigured purge actions may violate Cloudflare or host terms of service, resulting in penalties or bans.
	•	This is an open-source optimization example shared by Cautron. You accept full responsibility for your usage and acknowledge all provided warnings.

 This project is an open-source optimization example shared by Cautron. You acknowledge that any consequences arising from your use are your sole responsibility, and that Cautron has provided appropriate warnings regarding the recommended values.
 
⸻

 ✨ Key Features
	•	Full Purge via Worker
	•	Edge cache (RAM) fully cleared.
	•	Static files preserved via Cache Reserve.
	•	Warmup via WordPress Cron
	•	All sitemap URLs fetched in controlled 50-item batches.
	•	Simulates real traffic via concurrency (5), backoff, and jitter.
	•	Only changed HTML is reloaded from origin.

Cache Reserve Integration:
	•	No origin requests for static files.
	•	Increased sustainability and performance.
	•	Without Cache Reserve, performance drops and energy usage rises.

⸻

🕓 Scheduling Notes
	•	All time references use UTC.
	•	Choose 03:00 local time for your website’s primary traffic region.
	•	For globally accessed e-commerce sites, pick 03:00 in the region with peak traffic.
	•	Visitors in other time zones may experience a slight delay during warmup, depending on URL count.

⸻

🔄 Intelligent Purge + Warmup Flow
	•	03:00 Worker (UTC) → Triggers full purge_everything (clears Edge RAM, Cache Reserve intact).
	•	03:07 WordPress Cron → Begins warmup.
	•	Static assets restored from reserve.
	•	Updated HTML reloaded from origin.
	•	Edge cache refilled, globally synced.

⸻

🔧 Configuration Parameters

Cloudflare Worker
	•	CF_API_TOKEN: Token with Zone Purge and Zone Read permissions.
	•	ZONE_ID: Cloudflare Zone ID.
    •	Cron Trigger 0 3 * * *

WordPress Cron
	•	SIL_SCH_BATCH = 50 (max 80)
	•	SIL_SCH_CONCURRENCY = 5 (max 8)
	•	SIL_SCH_MAX_RETRY = 3
	•	SIL_SCH_TIMEOUT = 20 (max 30)
 
 ⸻

 📈 Flow Diagram
 
[03:00 Worker] → purge_everything
      ↓
[03:07 WP Cron] → Warmup starts
      ↓
[Static assets pulled from Reserve] + [Fresh HTML and new data from origin server]
      ↓
[Edge Cache replenished → fast & fresh content globally]

 ⸻
 
 ⚙️ Setup Guide

1. Cloudflare Cache Rules (in order):
	•	Bypass WooCommerce Cookies
	•	(http.cookie contains "wordpress_logged_in_") or ...
	•	Bypass Dynamic Query Strings (orderby, etc.)
	•	Cache Everything: Edge TTL: 1 month, Browser TTL: 1 year
	•	Cache File Extensions: Edge TTL: 1 month, Browser TTL: 1 year
	•	Bypass Sitemaps
	•	Bypass WooCommerce Endpoints and Payment Related Pages
	•	Bypass REST API, AJAX, Dynamic Behavior
	•	Bypass Admin & Login
	•	Enable Cache Reserve ✅

⸻

2. Cloudflare Worker
	•	Add worker in Cloudflare dashboard.
	•	Set env vars: CF_API_TOKEN, ZONE_ID.
	•	Schedule: 0 3 * * * UTC.
	•	Script: ctrn-scheduled-purge-everything.js

⸻

3. WordPress Cron
	•	Place script under mu-plugins/: ctrn-cache-warmup-scheduled.php
	•	Cron triggers at 03:07 (local server time).
	•	Sitemap parsing → recursive URL collection.
	•	Processes 50 URLs per batch, concurrency = 5.
	•	Auto-queues next batch → independent from traffic.

⸻

4. Optional: JS Support for Cart UX
	•	If cart popup or header display issues arise, use ctrn-cart-behaviour-support.js to stabilize.

⸻

🔒 Security
	•	No public endpoints exposed.
	•	All operations run internally via Worker schedules and WordPress cron.
	•	Use environment variables (not plaintext).
Mark sensitive variables as Secret.

⸻

🌍 Sustainability First
	•	With Cache Reserve:
	•	Static assets restored without origin load.
	•	Only changed HTML re-fetched.
	•	Tiered Cache handles background sync.
	•	Result: updated content, minimal energy consumption.

 ⸻

 🌱 Outcome. This system ensures:
	•	Global speed increase
	•	Sustainable performance
	•	Minimal origin server usage
	•	Long-Term Edge Caching aligned with environmental goals
 
 ⸻

📝 License

MIT License — Developed by the Cautron ecosystem.




