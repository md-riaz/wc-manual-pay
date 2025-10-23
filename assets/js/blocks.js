/**
 * WooCommerce Blocks integration
 */
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { createElement } = window.wp.element;
const { __ } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;

const settings = getSetting('wcmanualpay_data', {});
const label = decodeEntities(settings.title || __('Manual Payment', 'wc-manual-pay'));

const Content = () => {
    return createElement('div', {}, decodeEntities(settings.description || ''));
};

const Edit = ({ eventRegistration }) => {
    const { onPaymentSetup } = eventRegistration;
    const providers = settings.providers || [];

    React.useEffect(() => {
        const unsubscribe = onPaymentSetup(() => {
            const provider = document.getElementById('wcmanualpay_provider')?.value;
            const txnId = document.getElementById('wcmanualpay_txn_id')?.value;

            if (!provider || !txnId) {
                return {
                    type: 'error',
                    message: __('Please fill in all payment fields.', 'wc-manual-pay'),
                };
            }

            return {
                type: 'success',
                meta: {
                    paymentMethodData: {
                        wcmanualpay_provider: provider,
                        wcmanualpay_txn_id: txnId,
                    },
                },
            };
        });

        return unsubscribe;
    }, [onPaymentSetup]);

    return createElement(
        'div',
        { className: 'wc-block-components-payment-method-content' },
        createElement(
            'p',
            { className: 'wc-block-components-text' },
            decodeEntities(settings.description || '')
        ),
        createElement(
            'p',
            { className: 'wc-block-components-form' },
            createElement(
                'label',
                { htmlFor: 'wcmanualpay_provider' },
                __('Payment Provider', 'wc-manual-pay'),
                createElement('span', { className: 'required' }, ' *')
            ),
            createElement(
                'select',
                {
                    id: 'wcmanualpay_provider',
                    name: 'wcmanualpay_provider',
                    className: 'wc-block-components-select',
                    required: true,
                },
                createElement('option', { value: '' }, __('Select Provider', 'wc-manual-pay')),
                providers.map((provider) =>
                    createElement(
                        'option',
                        { key: provider, value: provider },
                        provider.charAt(0).toUpperCase() + provider.slice(1)
                    )
                )
            )
        ),
        createElement(
            'p',
            { className: 'wc-block-components-form' },
            createElement(
                'label',
                { htmlFor: 'wcmanualpay_txn_id' },
                __('Transaction ID', 'wc-manual-pay'),
                createElement('span', { className: 'required' }, ' *')
            ),
            createElement('input', {
                type: 'text',
                id: 'wcmanualpay_txn_id',
                name: 'wcmanualpay_txn_id',
                className: 'wc-block-components-text-input',
                required: true,
                autoComplete: 'off',
            })
        )
    );
};

registerPaymentMethod({
    name: 'wcmanualpay',
    label: label,
    content: createElement(Content),
    edit: createElement(Edit),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || [],
    },
});
