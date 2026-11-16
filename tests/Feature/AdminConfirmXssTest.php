<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

/**
 * Admin-entered names must never be interpolated into an inline event handler
 * (onsubmit / onclick): the browser decodes HTML entities in attributes before
 * running the JS, so escaping alone does not stop a stored XSS there.
 */
class AdminConfirmXssTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private const EVIL = "x');alert(1);//";

    /** @return list<string> the values of every on*="..." attribute in the page */
    private function inlineHandlers(string $html): array
    {
        preg_match_all('/\son[a-z]+="([^"]*)"/i', $html, $m);

        return $m[1];
    }

    public function test_account_and_channel_names_stay_out_of_inline_script(): void
    {
        $account = $this->makeAccount('PK00DEMO0000001234567890', ['name' => self::EVIL, 'channel' => self::EVIL]);
        $this->actingAs($this->makeAdmin());

        foreach ([route('admin.accounts.show', $account), route('admin.accounts.index')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach ($this->inlineHandlers($html) as $handler) {
                $this->assertStringNotContainsString('alert(1)', html_entity_decode($handler, ENT_QUOTES));
            }
            // The name still shows in the confirm message, as plain text data.
            $this->assertStringContainsString('data-confirm=', $html);
        }
    }

    public function test_the_admin_layout_handles_data_confirm(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->get(route('admin.dashboard'))->assertOk()->assertSee("getAttribute('data-confirm')", false);
    }
}
