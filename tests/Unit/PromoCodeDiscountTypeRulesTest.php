<?php

namespace Tests\Unit;

use App\Enums\PromoCodeDiscountType;
use App\Http\Requests\FestivalPromoCodeRequest;
use App\Http\Requests\SaveEventPromoCodeRequest;
use App\Http\Requests\StudioPromoCodeRequest;
use PHPUnit\Framework\TestCase;

class PromoCodeDiscountTypeRulesTest extends TestCase
{
    public function test_quantity_promotions_are_studio_only(): void
    {
        $studioRule = (new StudioPromoCodeRequest)->rules()['discount_type'][1];
        $eventRule = (new SaveEventPromoCodeRequest)->rules()['discount_type'][1];
        $festivalRule = (new FestivalPromoCodeRequest)->rules()['discount_type'][1];

        $this->assertTrue($studioRule->passes('discount_type', PromoCodeDiscountType::BuyXGetY->value));
        $this->assertFalse($eventRule->passes('discount_type', PromoCodeDiscountType::BuyXGetY->value));
        $this->assertFalse($festivalRule->passes('discount_type', PromoCodeDiscountType::BuyXGetY->value));

        foreach ([PromoCodeDiscountType::Fixed, PromoCodeDiscountType::Percent] as $type) {
            $this->assertTrue($studioRule->passes('discount_type', $type->value));
            $this->assertTrue($eventRule->passes('discount_type', $type->value));
            $this->assertTrue($festivalRule->passes('discount_type', $type->value));
        }
    }
}
