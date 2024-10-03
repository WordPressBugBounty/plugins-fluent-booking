document.addEventListener('DOMContentLoaded', () => {

    const css = `
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

    const style = document.createElement('style');

    style.appendChild(document.createTextNode(css));

    document.head.appendChild(style);

    elementor.hooks.addAction('panel/open_editor/widget', (panel, model, view) => {

        const controlContainer = document.querySelector('.elementor-control-selected_cal_id select');

        let calId = '';
        if (controlContainer) {
            calId = controlContainer.value;
            fetchEvents().catch(error => console.error('Initial fetch error:', error));

            controlContainer.addEventListener('change', async (event) => {
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
                    // Process the events
                    console.log(result.data);

                    const eventControl = document.querySelector('.elementor-control-selected_event_ids select');

                    if (eventControl) {
                        // Clear existing options
                        eventControl.innerHTML = '';

                        // Add new options
                        Object.entries(result.data).forEach(([key, value]) => {
                            const option = document.createElement('option');
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
    });
});
