export function initStudioPromoCodes(root = document) {
    root.querySelectorAll('[data-studio-promo-code-form]').forEach((form) => {
        if (form.dataset.promoInitialized === '1') {
            return;
        }

        form.dataset.promoInitialized = '1';
        const discountType = form.querySelector('[name="discount_type"]');

        const updateFields = () => {
            const quantityDiscount = discountType.value === 'buy_x_get_y';
            const amount = form.querySelector('[data-studio-promo-amount]');
            const quantities = form.querySelector('[data-studio-promo-quantity-only]');

            amount.hidden = quantityDiscount;
            amount.querySelector('input').disabled = quantityDiscount;
            quantities.hidden = !quantityDiscount;
            quantities.querySelectorAll('input').forEach((input) => {
                input.disabled = !quantityDiscount;
            });
            form.querySelectorAll('[data-studio-promo-plan]').forEach((plan) => {
                const unavailable = quantityDiscount && plan.dataset.quantityUnavailable === '1';
                plan.hidden = unavailable;
                plan.querySelector('input').disabled = unavailable;
            });
        };

        discountType.addEventListener('change', updateFields);
        updateFields();
    });
}
