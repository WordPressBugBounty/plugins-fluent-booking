document.addEventListener('DOMContentLoaded', () => {

    const fluentBookingIconStyles = `
        .fluent-booking-icon {
            width: 28px;
            height: 28px;
            display: block;
            margin: 0 auto;
            text-align: center;
            background-size: contain;
            background-repeat: no-repeat;
            background-image: url('${fcal_elementor_ajax_object.svgIcon}');
        }
    `;

    const styleElement = document.createElement('style');

    styleElement.appendChild(document.createTextNode(fluentBookingIconStyles));

    document.head.appendChild(styleElement);

    elementor.hooks.addAction('panel/open_editor/widget', (panel, model, view) => {

        const calendarContainer = document.querySelector('.elementor-control-selected_cal_id select');

        let calId = '';
        if (calendarContainer) {
            calId = calendarContainer.value;
            fetchEvents().catch(error => console.error('Initial fetch error:', error));

            calendarContainer.addEventListener('change', async (event) => {
                calId = event.target.value;
                try {
                    await fetchEvents();
                } catch (error) {
                    console.error('Error fetching events on change:', error);
                }
            });
        }

        async function fetchEvents() {
            if (!calId) {
                return;
            }
            try {
                const response = await fetch(window.fcal_elementor_ajax_object.ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({
                        action: 'get_calendar_events',
                        cal_id: calId,
                        security: window.fcal_elementor_ajax_object.nonce // this is the nonce
                    })
                });

                const result = await response.json();

                if (result.success) {
                    const eventControl = document.querySelector('.elementor-control-selected_event_ids select');

                    if (eventControl) {
                        eventControl.innerHTML = '';

                        const currentSelectedIds = elementor.getPanelView().getCurrentPageView().getControlViewByName('selected_event_ids').getControlValue();

                        Object.entries(result.data).forEach(([key, value]) => {
                            const option = document.createElement('option');
                            if (currentSelectedIds && currentSelectedIds.includes(key)) {
                                option.selected = true;
                            }
                            option.value = key;
                            option.textContent = value;
                            eventControl.appendChild(option);
                        });
                    }
                } else {
                    console.error(result.data.message);
                }
            } catch (error) {
                console.error('Error fetching events:', error);
            }
        }

        const eventControl = document.querySelector('.elementor-control-selected_event select');

        if (eventControl) {
            eventControl.addEventListener('change', async (event) => {
                const eventId = event.target.value;
                const eventHash = await fetchEventHash(eventId);

                const controlView = elementor?.getPanelView()?.getCurrentPageView()?.getControlViewByName('event_hash');
                if (controlView) {
                    controlView.setValue(eventHash);
                    controlView.triggerMethod('input:change');
                } else {
                    console.error('Event hash control not found');
                }
            });
        }

        async function fetchEventHash(eventId) {
            const response = await fetch(window.fcal_elementor_ajax_object.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: new URLSearchParams({
                    action: 'get_event_hash',
                    event_id: eventId,
                    security: window.fcal_elementor_ajax_object.nonce
                })
            });

            const result = await response.json();

            if (result.success) {
                return result.data.hash;
            } else {
                console.error(result.data.message);
            }
        }
    });
});
