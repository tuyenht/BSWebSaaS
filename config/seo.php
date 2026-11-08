<?php

/*
|--------------------------------------------------------------------------
| AI crawler check (admin "Google & mạng xã hội")
|--------------------------------------------------------------------------
|
| The bots the check knows, in three groups:
|   crawlers       — fetch pages on their own; read in robots.txt;
|   robots_only    — only a name in robots.txt (a use-of-content switch), never a visitor;
|   user_triggered — fetch when a person asks a chatbot about a page; may not follow robots.txt,
|                    so a block on them is only ever information.
| `probes` are the four crawlers the check also calls the home page as (their published
| User-Agent strings); every other bot is judged from robots.txt alone. `browser` is the
| User-Agent of the plain visit the bots are compared with.
|
| `platform_per_minute` caps how many checks run across the whole platform each minute (each is
| six requests); a check over the cap waits in the queue.
|
*/

return [
    'ai_crawlers' => [
        'crawlers' => ['GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent'],
        'robots_only' => ['Google-Extended', 'Applebot-Extended'],
        'user_triggered' => ['ChatGPT-User', 'Claude-User', 'Perplexity-User'],
        'probes' => [
            'GPTBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1; +https://openai.com/gptbot',
            'OAI-SearchBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot',
            'ClaudeBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
            'PerplexityBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
        ],
        'browser' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
        'platform_per_minute' => 30,
    ],
];
