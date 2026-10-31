<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\QrToken;
use App\Services\QrImage;
use App\Services\QrTokenService;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Task #46: QR images drawn on the server.
 */
class QrImageTest extends EventTestCase
{
    public function test_svg_is_a_vector_image_of_the_text(): void
    {
        $svg = app(QrImage::class)->svg('https://example.test/qr/redeem/abc.def');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    public function test_png_is_a_real_png_that_scales_with_the_size(): void
    {
        $small = app(QrImage::class)->png('hello', 200);
        $large = app(QrImage::class)->png('hello', 800);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $small);
        [$smallWidth] = getimagesizefromstring($small);
        [$largeWidth] = getimagesizefromstring($large);
        $this->assertGreaterThan($smallWidth, $largeWidth);
        $this->assertLessThanOrEqual(800, $largeWidth);
    }

    public function test_the_size_is_kept_within_limits(): void
    {
        [$tiny] = getimagesizefromstring(app(QrImage::class)->png('x', 1));
        [$huge] = getimagesizefromstring(app(QrImage::class)->png('x', 99999));

        $this->assertGreaterThanOrEqual(100, $tiny);
        $this->assertLessThanOrEqual(QrImage::MAX_SIZE, $huge);
    }

    public function test_the_png_decodes_back_to_the_scan_link(): void
    {
        // Compare the QR matrix of the PNG with the matrix of the text it was drawn from.
        $text = 'https://example.test/qr/redeem/token.signature';
        $png = imagecreatefromstring(app(QrImage::class)->png($text, 600));
        imagepalettetotruecolor($png);
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $cells = $matrix->getWidth() + 8;
        $cell = intdiv(600, $cells);

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $pixel = imagecolorat($png, ($x + 4) * $cell + intdiv($cell, 2), ($y + 4) * $cell + intdiv($cell, 2)) & 0xFF;
                $this->assertSame($matrix->get($x, $y) === 1, $pixel < 128, "module {$x},{$y}");
            }
        }
    }

    public function test_an_organiser_can_issue_and_download_a_code(): void
    {
        $organiser = $this->staff([Capability::PointsCredit, Capability::QrCodesManage]);

        $issued = $this->actingAs($organiser)->postJson("/events/{$this->event->id}/qr-tokens", ['type' => 'slip'])
            ->assertCreated()
            ->assertJsonStructure(['id', 'type', 'url', 'svg', 'png'])
            ->assertJsonPath('type', 'slip');

        $this->actingAs($organiser)->get($issued->json('svg'))
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->actingAs($organiser)->get($issued->json('png').'?size=300')
            ->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_images_need_the_qr_capability_and_belong_to_their_event(): void
    {
        $organiser = $this->staff([Capability::PointsCredit, Capability::QrCodesManage]);
        $token = app(QrTokenService::class)->issue($organiser, $this->event);
        $otherEvent = $this->makeEvent();

        $creditOnly = $this->staff([Capability::PointsCredit]);
        $this->actingAs($creditOnly)->get("/events/{$this->event->id}/qr-tokens/{$token->id}/image.svg")->assertRedirect('/');
        $this->actingAs($creditOnly)->postJson("/events/{$this->event->id}/qr-tokens", ['type' => 'slip'])->assertForbidden();
        $this->actingAs($this->student())->get("/events/{$this->event->id}/qr-tokens/{$token->id}/image.svg")->assertNotFound();
        $this->actingAs($organiser)->get("/events/{$otherEvent->id}/qr-tokens/{$token->id}/image.svg")->assertNotFound();

        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/qr-tokens/{$token->id}/revoke")->assertOk();
        $this->actingAs($organiser)->get("/events/{$this->event->id}/qr-tokens/{$token->id}/image.svg")->assertNotFound();
    }

    public function test_the_issue_request_is_validated(): void
    {
        $organiser = $this->staff([Capability::PointsCredit, Capability::QrCodesManage]);

        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/qr-tokens", ['type' => 'sticker'])->assertUnprocessable();
        // Posters and the parts of a task are no longer made (Task #84): only single-use slips.
        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/qr-tokens", ['type' => 'poster'])
            ->assertUnprocessable()->assertJsonPath('errors.type.0', 'Only printed single-use slips can be made.');
        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/qr-tokens", ['type' => 'slip', 'total_steps' => 3])->assertUnprocessable();
        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/qr-tokens", ['type' => 'slip', 'step_number' => 1])->assertUnprocessable();
        $this->assertSame(0, QrToken::count());
    }
}
