<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\TranslationLoader\LanguageLine;

class TranslationsControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function testListRequiresSuperAdmin(): void
    {
        $line = LanguageLine::create(['group' => 'general', 'key' => 'submit', 'text' => ['en' => 'Example']]);
        $this->getJson('/admin/i18n/list')->assertOk()->assertJsonPath('data.0.id', $line->id);
        $this->actingAs(User::factory()->admin()->create());
        $this->getJson('/admin/i18n/list')->assertForbidden();
    }

    public function testCreateValidatesAndStoresBoundedTranslations(): void
    {
        $this->postJson('/admin/i18n', [])->assertUnprocessable()->assertJsonValidationErrors(['group', 'key', 'text']);
        $this->postJson('/admin/i18n', [
            'group' => 'front-end', 'key' => 'general..submit', 'text' => str_repeat('x', 4097),
        ])->assertUnprocessable()->assertJsonValidationErrors(['key', 'text']);
        $this->post('/admin/i18n', [
            'group' => 'front-end', 'key' => 'general.submit', 'text' => 'Example',
        ])->assertRedirect('/admin/i18n')->assertSessionHas('success', true);

        $line = LanguageLine::where('group', 'front-end')->where('key', 'general.submit')->sole();
        $this->assertSame('Example', $line->getTranslation('en'));
    }

    public function testUpdateAndDelete(): void
    {
        $line = LanguageLine::create(['group' => 'general', 'key' => 'submit', 'text' => ['en' => 'Original']]);
        $this->putJson('/admin/i18n/'.$line->id)->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->putJson('/admin/i18n/'.$line->id, ['text' => 'Updated'])->assertOk()->assertJsonPath('code', 0);
        $this->assertSame('Updated', $line->fresh()->getTranslation('en'));
        $this->deleteJson('/admin/i18n/'.$line->id)->assertOk()->assertJsonPath('code', 0);
        $this->assertNull($line->fresh());
    }
}
