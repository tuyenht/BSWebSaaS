<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The platform's wording of one automatic mail in one language: what every website sends until it
 * edits the template, and the only editable wording of a «Hệ thống» template. Platform-wide (D6: no
 * site_id, no RLS), so it is written only behind the platform gate, never from a website screen.
 *
 * @property int $id
 * @property string $template_key
 * @property string $locale
 * @property string $subject
 * @property string $body
 */
final class PlatformMailTemplate extends Model
{
    #[\Override]
    protected $table = 'platform_mail_templates';

    #[\Override]
    protected $guarded = ['id'];
}
