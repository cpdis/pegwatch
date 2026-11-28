<?php

namespace Tests\Feature;

use App\Mail\LoginLinkMail;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

/** Every email wears the site's look: the Peg P header, the hi-vis button, and a readable text part. */
class MailThemeTest extends TestCase
{
    public function test_emails_render_in_the_pegwatch_theme(): void
    {
        $html = (new LoginLinkMail('https://pegwatch.test/login/x', 'jane@example.com'))->render();

        // A hosted PNG mark (mail clients drop SVG), absolute so it loads from the inbox.
        $this->assertStringContainsString('src="'.asset('mail/logo.png').'"', $html);
        $this->assertFileExists(public_path('mail/logo.png'));
        // The theme is inlined: the header bar, the hi-vis rule and the yellow button with a near-black label.
        $this->assertMatchesRegularExpression('/class="header"[^>]*background-color: #0b0b0c/', $html);
        $this->assertMatchesRegularExpression('/class="button button-primary"[^>]*background-color: #ffd400[^"]*color: #0b0b0c/', $html);
        $this->assertStringContainsString('SIGN IN', mb_strtoupper(strip_tags($html)));
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    public function test_the_plain_text_part_has_no_markup(): void
    {
        $mail = new LoginLinkMail('https://pegwatch.test/login/x', 'jane@example.com');
        $c = $mail->content();
        $text = (string) app(Markdown::class)->renderText($c->markdown, array_merge($mail->buildViewData(), $c->with));

        $this->assertStringStartsWith('Pegwatch', trim($text));
        $this->assertStringContainsString('SIGN IN', $text);
        $this->assertStringContainsString('Log in: https://pegwatch.test/login/x', $text);
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*>/i', $text);
    }
}
