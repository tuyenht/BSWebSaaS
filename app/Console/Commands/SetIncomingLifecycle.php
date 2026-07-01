<?php

namespace App\Console\Commands;

use App\Modules\Media\Support\MediaKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Applies the R2 object-lifecycle rule that auto-expires abandoned staging uploads: every object under
 * the `_incoming/` prefix is deleted 1 day after creation. Uploads land at `_incoming/{site}/{ulid}`
 * and are copied to their final content-hash key within seconds (RegisterMediaUpload), so a 1-day TTL
 * can only ever remove orphans — a browser that closed mid-upload, or a file that failed the register
 * gate. The rule is native R2 (server-side), so no app cron/queue runs; this command just declares it.
 *
 * Uses the Cloudflare account API token (services.cloudflare.api_token) — the R2 S3 credentials only
 * grant object access, not bucket configuration. The account id is derived from the R2 endpoint and
 * the bucket from the media disk, so one run targets whatever bucket this environment writes to.
 *
 * Idempotent — the PUT replaces the bucket's lifecycle config with this single rule. Run once per R2
 * bucket; re-run if a bucket is ever recreated. Never touches committed media (never under `_incoming/`).
 */
class SetIncomingLifecycle extends Command
{
    private const RULE_ID = 'expire-incoming-staging';

    private const PREFIX = '_incoming/';

    private const MAX_AGE_SECONDS = 86400; // 1 day — orphans are finalized within seconds, so this only hits abandoned staging objects.

    protected $signature = 'media:set-incoming-lifecycle';

    protected $description = 'Set the R2 lifecycle rule that deletes _incoming/ staging orphans 1 day after upload';

    public function handle(): int
    {
        $tokenConfig = config('services.cloudflare.api_token');
        $token = is_string($tokenConfig) ? $tokenConfig : '';
        if ($token === '') {
            $this->error('CLOUDFLARE_API_TOKEN is not set — needed to administer R2 bucket lifecycle. Nothing changed.');

            return self::FAILURE;
        }

        $bucketConfig = config('filesystems.disks.'.MediaKey::disk().'.bucket');
        $bucket = is_string($bucketConfig) ? $bucketConfig : '';
        $endpointConfig = config('filesystems.disks.'.MediaKey::disk().'.endpoint');
        $endpoint = is_string($endpointConfig) ? $endpointConfig : '';

        // R2 endpoint is https://{account_id}.r2.cloudflarestorage.com — the account id is the first label.
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $accountId = str_replace('.r2.cloudflarestorage.com', '', $host);
        if ($bucket === '' || $accountId === '' || str_contains($accountId, '.')) {
            $this->error('Could not resolve the R2 account id / bucket (endpoint="'.$endpoint.'", bucket="'.$bucket.'"). Nothing changed.');

            return self::FAILURE;
        }

        $url = 'https://api.cloudflare.com/client/v4/accounts/'.$accountId.'/r2/buckets/'.$bucket.'/lifecycle';

        $put = Http::withToken($token)->put($url, [
            'rules' => [[
                'id' => self::RULE_ID,
                'enabled' => true,
                'conditions' => ['prefix' => self::PREFIX],
                'deleteObjectsTransition' => [
                    'condition' => ['type' => 'Age', 'maxAge' => self::MAX_AGE_SECONDS],
                ],
            ]],
        ]);

        if ($put->failed()) {
            $this->error('Cloudflare API rejected the lifecycle rule ('.$put->status().'): '.$put->body());
            $this->line('The token needs the "Workers R2 Storage: Edit" permission on this account.');

            return self::FAILURE;
        }

        $this->info('Lifecycle rule "'.self::RULE_ID.'" applied to R2 bucket "'.$bucket.'": delete "'.self::PREFIX.'" objects '.intdiv(self::MAX_AGE_SECONDS, 86400).' day after upload.');
        $this->line((string) Http::withToken($token)->get($url)->body());

        return self::SUCCESS;
    }
}
