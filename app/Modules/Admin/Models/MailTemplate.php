<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * A website's own wording of one automatic mail in one language (the «Mẫu thư» tab). Tenant table
 * (site_id + RLS). Only an edited copy is stored; MailTemplateStore writes and removes rows, and
 * MailTemplates reads them (a website's rows in one query).
 *
 * @property int $id
 * @property int $site_id
 * @property string $template_key
 * @property string $locale
 * @property string $subject plain text, at most 200 characters
 * @property string $body HTML cleaned by RichTextSanitizer, the variables left as `{name}` words
 */
final class MailTemplate extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $table = 'mail_templates';

    #[\Override]
    protected $guarded = ['id', 'site_id'];
}
