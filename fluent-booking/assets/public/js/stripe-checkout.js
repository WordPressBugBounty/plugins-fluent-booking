class StripeCheckout {
    constructor(form, response) {
        this.form = form;
        this.data = response.data;
        this.intent = response.data?.intent;
    }

    init() {
        const formItems = this.form.querySelectorAll('.fcal_form_item');
        formItems.forEach(item => {
            item.style.display = 'none';
        });

        const paymentProcessor = this.form.querySelector('.fluent_booking_payment_processor');
        paymentProcessor.style.display = 'block';

        const submitButton = document.createElement('button');
        submitButton.id = 'fluent_booking_stipe_pay';
        submitButton.style.marginTop = '23px';
        submitButton.type = 'submit';
        submitButton.textContent = window.fcal_translate('Pay Now');

        const stripe = Stripe(this.data?.data?.payment_args?.public_key);

        const elements = stripe.elements({
            clientSecret: this.intent.client_secret,
        });

        const paymentElement = elements.create('payment', {});
        const paymentMethods = this.form.querySelector('.fluent_booking_payment_methods');

        paymentElement.mount(paymentMethods);

        const loadingMessage = document.createElement('p');
        loadingMessage.classList.add('fluent_booking_loading_payment_processor') ;
        loadingMessage.textContent = 'Loading Payment Processor...';

        paymentProcessor.appendChild(loadingMessage);

        const submit = this.form.querySelector('.fcal_submit');
        submit.style.display = 'none';

        const that = this;

        paymentElement.on('ready',  (event)=> {
            const loadingPaymentProcessor = this.form.querySelector('.fluent_booking_loading_payment_processor');
            if (loadingPaymentProcessor) {
                loadingPaymentProcessor.remove();
            }

            paymentMethods.appendChild(submitButton);

            const stripePayButton = this.form.querySelector('#fluent_booking_stipe_pay');
            stripePayButton.addEventListener('click', function (e) {
                e.preventDefault();

                elements.submit().then(result => {
                    stripePayButton.textContent = 'Processing...';
                    stripePayButton.disabled = true;

                    const confirmParams = {
                        // return_url: that.data?.data?.payment_args?.success_url
                    };

                    stripe.confirmPayment({
                        elements,
                        confirmParams,
                        redirect: 'if_required'
                    }).then(result => {
                        if (result?.paymentIntent?.id) {
                            stripePayButton.disabled = true;
                            fetch(window.fluentCalendarPublicVars.ajaxurl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `action=fluent_cal_confirm_stripe_payment&intentId=${result?.paymentIntent?.id}`,
                            }).then(response => {
                                response.json().then(data => {
                                    window.location.href = that.data?.data?.payment_args?.success_url;
                                });
                            });
                        }
                        if (result?.error) {
                            stripePayButton.textContent = window.fcal_translate('Pay Now');
                            stripePayButton.disabled = false;
                        }
                    });
                }).catch(error => {
                    stripePayButton.textContent = window.fcal_translate('Pay Now');
                    stripePayButton.disabled = false;
                });
            });
        });
    }
}

window.addEventListener('fluent_booking_payment_next_action_stripe', function (e) {
    new StripeCheckout(e.detail.form, e.detail.response).init();
});
