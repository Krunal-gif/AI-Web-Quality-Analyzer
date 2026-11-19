<?php

return [
    // Ollama endpoint — its default address on this computer.
    'ollama_host' => env('OLLAMA_HOST', 'http://127.0.0.1:11434'),

    'ollama_model' => env('OLLAMA_MODEL', 'qwen2.5-coder:7b'),

    'ollama_timeout' => (int) env('OLLAMA_TIMEOUT', 180),

    // Where analyses (state, reports, patches, fixed copies, backups) are stored.
    'storage' => env('WEBQA_STORAGE', storage_path('app/webqa/analyses')),

    'demo_path' => base_path('samples/demo-site'),

    // Site profile: find a website's hosting country and provider from its IP address. The IP (never page content)
    // is sent to this geolocation service; {ip} is replaced. Set WEBQA_GEO_LOOKUP=false to turn it off.
    'geo_lookup' => (bool) env('WEBQA_GEO_LOOKUP', true),

    'geo_url' => env('WEBQA_GEO_URL', 'https://ipwho.is/{ip}'),

    // Pictures of each analysed page's first screen, taken with headless Chrome/Chromium after a website analysis.
    // WEBQA_CHROME: path to the browser if it isn't found automatically.
    'screenshots' => (bool) env('WEBQA_SCREENSHOTS', true),

    'chrome' => env('WEBQA_CHROME'),
];
