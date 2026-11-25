<?php

namespace App\Models;

use App\Events\PlayerProfileUpdated;
use App\Models\Concerns\SearchString;
use App\Services\Textures\TextureReferences;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int     $pid
 * @property int     $uid
 * @property string  $name
 * @property int     $tid_skin
 * @property int     $tid_cape
 * @property Carbon  $last_modified
 * @property User    $user
 * @property Texture $skin
 * @property Texture $cape
 * @property string  $model
 */
class Player extends Model
{
    use HasFactory;
    use SearchString;

    public const CREATED_AT = null;
    public const UPDATED_AT = 'last_modified';

    public $primaryKey = 'pid';
    protected $fillable = [
        'uid', 'name', 'tid_skin', 'tid_cape', 'last_modified',
    ];

    protected $casts = [
        'pid' => 'integer',
        'uid' => 'integer',
        'tid_skin' => 'integer',
        'tid_cape' => 'integer',
    ];

    protected $dispatchesEvents = [
        'retrieved' => \App\Events\PlayerRetrieved::class,
        'updated' => PlayerProfileUpdated::class,
    ];

    protected $searchStringColumns = [
        'pid', 'uid',
        'tid_skin' => '/^(?:tid_)?skin$/',
        'tid_cape' => '/^(?:tid_)?cape$/',
        'name' => ['searchable' => true],
        'last_modified' => ['date' => true],
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public function skin()
    {
        return $this->belongsTo(Texture::class, 'tid_skin');
    }

    public function cape()
    {
        return $this->belongsTo(Texture::class, 'tid_cape');
    }

    public function getModelAttribute()
    {
        return optional($this->skin)->model ?? 'default';
    }

    /**
     * CustomSkinAPI R1.
     */
    public function toJson($options = 0)
    {
        return json_encode($this->publicProfile(app(TextureReferences::class)), $options | JSON_UNESCAPED_UNICODE);
    }

    public function publicProfile(TextureReferences $references): array
    {
        $skin = $references->gameTexture($this->user, $this->skin, 'skin');
        $cape = $references->gameTexture($this->user, $this->cape, 'cape');

        return [
            'username' => $this->name,
            'skins' => [($skin?->model ?? 'default') => $skin?->hash],
            'cape' => $cape?->hash,
        ];
    }

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
