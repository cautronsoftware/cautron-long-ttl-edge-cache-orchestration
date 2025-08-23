/**
 * scheduled-cache-purge.js
 *
 * 📅 Scheduled Cache Purge (Once a Day)
 * 🛠️ Developed by Cautron IT Services
 *
 * This Cloudflare Worker performs a full "purge_everything" on the specified
 * Cloudflare Zone once per day at 03:00 AM Greenwich time (03:00 GMT). Please modify
 * the time for your the highest traffic zone.
 *
 *
 * ⚠️ Ensure you set the following secrets in your environment:
 *   - CF_API_TOKEN: A token with Zone → Cache Purge permissions
 *   - ZONE_ID: Your Cloudflare Zone ID
 *
 * 💡 Learn more: https://developers.cloudflare.com/workers/
 */

export default {
  async scheduled(event, env, ctx) {
    if (event.cron !== "0 3 * * *") return; // 03:00 (3AM) (UTC)

    const startedAt = Date.now();
    console.log(JSON.stringify({
      tag: "purge:start",
      cron: event.cron,
      scheduledTime: event.scheduledTime
    }));

    const ok = await purgeEverything(env);
    if (!ok) {
      console.log(JSON.stringify({ tag: "purge:retry" }));
      await purgeEverything(env);
    }

    console.log(JSON.stringify({
      tag: "purge:done",
      durationMs: Date.now() - startedAt
    }));
  }
};

// ---- Internal Function ----
async function purgeEverything(env) {
  const endpoint = `https://api.cloudflare.com/client/v4/zones/${env.ZONE_ID}/purge_cache`;
  const headers = {
    Authorization: `Bearer ${env.CF_API_TOKEN}`,
    "Content-Type": "application/json"
  };

  try {
    const res = await fetch(endpoint, {
      method: "POST",
      headers,
      body: JSON.stringify({ purge_everything: true })
    });

    const txt = await res.text();
    console.log(JSON.stringify({
      tag: "purge:response",
      status: res.status,
      body: txt.slice(0, 80)
    }));

    return res.ok;
  } catch (e) {
    console.log(JSON.stringify({
      tag: "purge:error",
      msg: String(e?.message || e)
    }));
    return false;
  }
}
