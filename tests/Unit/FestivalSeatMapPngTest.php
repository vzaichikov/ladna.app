<?php

namespace Tests\Unit;

use App\Support\Festivals\FestivalSeatMapPng;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FestivalSeatMapPngTest extends TestCase
{
    public function test_the_downloadable_example_imports_rows_and_tables_without_ghost_markers(): void
    {
        $importer = new FestivalSeatMapPng;
        $result = $importer->parse($importer->template(example: true));

        $this->assertSame([], $result['warnings']);
        $this->assertCount(20, $result['seats']);
        $this->assertCount(3, array_unique(array_column($result['seats'], 'color')));
        $this->assertSame([], $importer->parse($result['background'])['seats']);
    }

    public function test_touching_markers_and_unknown_colors_are_flagged_while_valid_markers_survive(): void
    {
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, 0xFFFFFF);
        imagefilledellipse($image, 40, 40, 18, 18, 0xE11D48);
        imagefilledellipse($image, 57, 40, 18, 18, 0xE11D48);
        imagefilledellipse($image, 110, 40, 18, 18, 0x2563EB);
        imagefilledellipse($image, 160, 40, 18, 18, 0xFF0000);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        $result = (new FestivalSeatMapPng)->parse($png);

        $this->assertSame(['malformed_markers' => 1, 'unknown_colors' => 1], $result['warnings']);
        $this->assertSame([['color' => '#2563EB', 'x' => 3679, 'y' => 2010]], $result['seats']);
    }

    public function test_arbitrary_image_content_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FestivalSeatMapPng)->parse('<svg onload="alert(1)"></svg>');
    }
}
