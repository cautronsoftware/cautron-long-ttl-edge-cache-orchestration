<?php
/**
* Cache Warmup — Scheduled (every night at 03:07 GMT)
* - Batch: 50 URLs
* - Concurrency: 5
* - Source: All subsitemaps in sitemap_index.xml
*/

/* ==== Settings ==== */
if (!defined('CTR_SCH_BATCH'))        define('CTR_SCH_BATCH',        50);
if (!defined('CTR_SCH_CONCURRENCY'))  define('CTR_SCH_CONCURRENCY',  5);
if (!defined('CTR_SCH_TIMEOUT'))      define('CTR_SCH_TIMEOUT',      20);
if (!defined('CTR_SCH_TKEY'))         define('CTR_SCH_TKEY',         'ctrn_warmup_queue_scheduled');
if (!defined('CTR_SCH_RKEY'))         define('CTR_SCH_RKEY',         'ctrn_warmup_result_scheduled');
if (!defined('CTR_SCH_MAX_RETRY'))    define('CTR_SCH_MAX_RETRY',    3);

/* ==== 1) Schedule a daily cron for 03:07 (Site Timezone) ==== */
add_action('init', function () {
    if (wp_next_scheduled('ctrn_warmup_scheduled')) return;

    // Get current time in site’s configured timezone
    $tz     = wp_timezone(); // WP 5.3+
    $now    = new DateTime('now', $tz);
    $target = new DateTime('today 03:07:00', $tz);

    // If now is past today's 03:07, schedule for tomorrow
    if ($now >= $target) {
        $target->modify('+1 day');
    }
    // Convert to UTC timestamp
    $dt_utc = clone $target;
    $dt_utc->setTimezone(new DateTimeZone('UTC'));
    $timestamp = $dt_utc->getTimestamp();

    // Schedule daily cron job
    wp_schedule_event($timestamp, 'daily', 'ctrn_warmup_scheduled');
});

/* ==== 2) Daily cron handler ==== */
add_action('ctrn_warmup_scheduled', function () {
    $queue = get_transient(CTR_SCH_TKEY);

    if (!is_array($queue) || empty($queue)) {
        $sitemaps = [
            home_url('/sitemap_index.xml'),
        ];

        $all = [];
        foreach ($sitemaps as $map) {
            $all = array_merge($all, ctrn_fetch_sitemap_urls_scheduled($map, 5000));
        }
        $all = ctrn_filter_warm_urls_scheduled(array_values(array_unique($all)));

        if (empty($all)) {
            error_log('[Warmup Scheduled] URL not found.');
            return;
        }

        set_transient(CTR_SCH_TKEY, $all, HOUR_IN_SECONDS);

        update_option(CTR_SCH_RKEY, [
            'started_at' => current_time('mysql'),
            'ok'         => 0,
            'fail'       => 0,
            'total'      => count($all),
        ], false);

        $queue = $all;
        error_log('[Warmup Scheduled] Queue ready: ' . count($queue));
    }

    // Get Batch
    $batch = array_splice($queue, 0, CTR_SCH_BATCH);
    set_transient(CTR_SCH_TKEY, $queue, HOUR_IN_SECONDS);

    // Warm (5 concurrency)
    $res = ctrn_warm_urls_scheduled($batch, CTR_SCH_CONCURRENCY);

    // Update counters
    $agg = get_option(CTR_SCH_RKEY, []);
    $agg['ok']   = intval($agg['ok'] ?? 0)   + intval($res['ok']);
    $agg['fail'] = intval($agg['fail'] ?? 0) + intval($res['fail']);
    update_option(CTR_SCH_RKEY, $agg, false);

    if (!empty($queue)) {
        wp_schedule_single_event(time() + 5, 'ctrn_warmup_scheduled');
        error_log('[Warmup Scheduled] Remaining: ' . count($queue));
    } else {
        $agg['ended_at'] = current_time('mysql');
        update_option(CTR_SCH_RKEY, $agg, false);
        delete_transient(CTR_SCH_TKEY);
        error_log('[Warmup Scheduled] Ended. OK=' . $agg['ok'] . ' FAIL=' . $agg['fail']);
    }
});

/* ==== 3) Helpers ==== */

// Collect URLs from sitemap (index → child .xml → <loc>)
function ctrn_fetch_sitemap_urls_scheduled($sitemap_url, $limit = 2000) {
    $urls = [];
    $res  = wp_remote_get($sitemap_url, ['timeout'=>CTR_SCH_TIMEOUT, 'redirection'=>5]);
    if (is_wp_error($res)) return $urls;
    $body = wp_remote_retrieve_body($res);
    if (!$body) return $urls;
  
    // sitemapindex
    if (strpos($body, '<sitemapindex') !== false) {
        if (preg_match_all('#<loc>\s*([^<]+)\s*</loc>#i', $body, $m)) {
            foreach ($m[1] as $child) {
                $child = trim($child);
                $urls  = array_merge($urls, ctrn_fetch_sitemap_urls_scheduled($child, $limit));
                if (count($urls) >= $limit) break;
            }
        }
        return array_slice($urls, 0, $limit);
    }

    if (preg_match_all('#<loc>\s*([^<]+)\s*</loc>#i', $body, $m)) {
        foreach ($m[1] as $loc) {
            $urls[] = trim($loc);
            if (count($urls) >= $limit) break;
        }
    }
    return $urls;
}

function ctrn_filter_warm_urls_scheduled(array $urls) {
    $urls = array_map('trim', $urls);
    $urls = array_filter($urls, function($u){
        if (!filter_var($u, FILTER_VALIDATE_URL)) return false;
        if (preg_match('#/(cart|checkout|my-account|account)(/|$)#i', $u)) return false;
        if (preg_match('#[?&](add-to-cart|orderby|wpf_[^=]+|wc-ajax|preview|customize_changeset_uuid)=#i', $u)) return false;
        if (strpos($u, '/wp-json/') !== false) return false;
        return true;
    });
    return array_values($urls);
}

function ctrn_warm_urls_scheduled(array $urls, int $concurrency = 5) {
    $ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    $ok = 0; $fail = 0;

    $commonHeaders = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7,es;q=0.6,it;q=0.6,fr;q=0.6,de;q=0.6',
        'Accept-Encoding: gzip, deflate, br',
    ];

    $retries = [];

    if (function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $handles = [];
        $queue   = array_values($urls);

        $make_ch = function($u) use ($ua, $commonHeaders) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $u,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => defined('CTR_SCH_TIMEOUT') ? CTR_SCH_TIMEOUT : 20,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_DNS_CACHE_TIMEOUT => 300,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_NOBODY         => false,
                CURLOPT_HTTPHEADER     => $commonHeaders,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
            ]);
            return $ch;
        };

        for ($i=0; $i<$concurrency && !empty($queue); $i++) {
            $u  = array_shift($queue);
            $ch = $make_ch($u);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = $u;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($status > 0) break;

            while ($info = curl_multi_info_read($mh)) {
                $ch   = $info['handle'];
                $u    = $handles[(int)$ch] ?? null;
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($handles[(int)$ch]);

                $shouldRetry = ($code == 429 || $code == 503);

                if ($code >= 200 && $code < 400) {
                    $ok++;
                } else {
                    if ($shouldRetry && ($retries[$u] ?? 0) < CTR_SCH_MAX_RETRY) {
                        usleep(mt_rand(100000, 300000));
                        $retries[$u] = ($retries[$u] ?? 0) + 1;
                        $queue[] = $u;
                    } else {
                        $fail++;
                    }
                }

                if (!empty($queue)) {
                    $next = array_shift($queue);
                    $ch2  = $make_ch($next);
                    curl_multi_add_handle($mh, $ch2);
                    $handles[(int)$ch2] = $next;
                }
            }

            usleep(mt_rand(10000, 30000));
        } while ($running);

        curl_multi_close($mh);

    } else {
        foreach ($urls as $u) {
            $attempts = 0;
            do {
                $attempts++;
                $res = wp_remote_get($u, [
                    'timeout'     => defined('CTR_SCH_TIMEOUT') ? CTR_SCH_TIMEOUT : 20,
                    'redirection' => 5,
                    'user-agent'  => $ua,
                    'headers'     => [
                        'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7,es;q=0.6,it;q=0.6,fr;q=0.6,de;q=0.6',
                        'Accept-Encoding' => 'gzip, deflate, br',
                    ],
                ]);

                if (is_wp_error($res)) {
                    $fail++;
                    break;
                }

                $code = wp_remote_retrieve_response_code($res);
                if ($code >= 200 && $code < 400) {
                    $ok++;
                    break;
                }

                if ($code == 429 || $code == 503) {
                    usleep(mt_rand(100000, 300000));
                } else {
                    $fail++;
                    break;
                }
            } while ($attempts <= CTR_SCH_MAX_RETRY);

            usleep(mt_rand(10000, 30000));
        }
    }

    return ['ok'=>$ok,'fail'=>$fail,'total'=>count($urls)];
}
